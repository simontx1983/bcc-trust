<?php
/**
 * Post Shortcode Repository — read/write access to bcc_post_shortcodes.
 *
 * Sidecar mapping peepso_activities.act_id → the 8-letter public
 * shortcode used in post permalinks (`/u/{handle}/post/{shortcode}`).
 * Codes mint lazily: the feed hydration links stage (and the blog-tab
 * emitter) call `ensureForActIds()` over each page's act_ids, so a
 * post gets its permanent code the first time any surface emits it.
 * The resolver route (`GET /feed/{code}`) reads via `resolveActId()`.
 *
 * A code is assigned once and never rewritten — permalinks are
 * forever. No cache layer: reads are bounded per-page IN lists against
 * a PK/unique index, writes are insert-once.
 *
 * @package BCC\Trust\Core\Repositories
 * @since 1.2.23 (post-shortcode permalinks)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class PostShortcodeRepository
{
    /** Shortcode length — locked; the resolver route regex ({8}) and the
     *  frontend route both encode it. */
    public const LENGTH = 8;

    /** Explicit column list (§2 — no SELECT *). */
    private const COLUMNS = 'act_id, short_id';

    /**
     * 52-letter alphabet: [a-z] + [A-Z], NO digits. Letters-only keeps
     * the code space disjoint from the numeric /feed/{id} route (an
     * 8-letter code can never parse as an act_id), and 52^8 ≈ 5.3e13
     * codes keeps collision retries vanishingly rare. Note the table's
     * default utf8mb4 collation is case-insensitive, so uniqueness is
     * effectively case-folded (26^8 ≈ 2.1e11 classes) — still far more
     * than enough headroom.
     */
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Unique-violation regeneration budget per act_id. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Reserved words a generated code must never equal (case-folded).
     * Belt-and-braces only: 'hot' and 'tag' are 3 letters and can never
     * collide with an 8-letter code — the list exists so any future
     * 8-letter sibling route (or a length change) is already covered.
     *
     * @var list<string>
     */
    private const RESERVED = ['hot', 'tag'];

    /** Bounded IN-list cap (§4) — feed pages cap at 50; the schema
     *  backfill batches at exactly this size. */
    private const MAX_IDS = 500;

    /**
     * Batch lookup: act_id → short_id for every act_id that already has
     * a code. Missing ids are simply absent from the map.
     *
     * @param list<int> $actIds
     * @return array<int, string>
     */
    public function findManyByActIds(array $actIds): array
    {
        $ids = self::sanitizeIds($actIds);
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $table = TableRegistry::postShortcodes();
        if (!TableRegistry::exists($table)) {
            // Pre-migration request (deploy race) — feed links keep their
            // numeric fallback rather than erroring the page.
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE act_id IN ({$placeholders})",
            $ids
        ));

        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_object($row)) {
                continue;
            }
            $actId   = isset($row->act_id) ? (int) $row->act_id : 0;
            $shortId = isset($row->short_id) && is_string($row->short_id) ? $row->short_id : '';
            if ($actId > 0 && $shortId !== '') {
                $map[$actId] = $shortId;
            }
        }
        return $map;
    }

    /**
     * Return a COMPLETE act_id → short_id map for the given ids,
     * minting codes for any act_id that has none yet. Ids that could
     * not be assigned (table missing, retry budget exhausted) are
     * absent from the map — callers must treat absence as "keep the
     * fallback link", never as an error.
     *
     * @param list<int> $actIds
     * @return array<int, string>
     */
    public function ensureForActIds(array $actIds): array
    {
        $ids = self::sanitizeIds($actIds);
        if ($ids === []) {
            return [];
        }

        $table = TableRegistry::postShortcodes();
        if (!TableRegistry::exists($table)) {
            return [];
        }

        $map = $this->findManyByActIds($ids);
        foreach ($ids as $actId) {
            if (isset($map[$actId])) {
                continue;
            }
            $code = $this->insertNewCode($actId);
            if ($code !== null) {
                $map[$actId] = $code;
            }
        }
        return $map;
    }

    /**
     * Resolve a shortcode back to its act_id. Format-validates first
     * ([A-Za-z]{8}) so garbage never reaches SQL; returns null for
     * unknown codes (the endpoint maps that to bcc_not_found).
     *
     * NB: the unique lookup is case-insensitive under the table's
     * default collation — a case-variant URL resolves to the same
     * post, which is the forgiving (and safe) behavior since case
     * variants can never belong to different rows.
     */
    public function resolveActId(string $shortId): ?int
    {
        if (preg_match('/^[A-Za-z]{' . self::LENGTH . '}$/', $shortId) !== 1) {
            return null;
        }

        global $wpdb;
        $table = TableRegistry::postShortcodes();
        if (!TableRegistry::exists($table)) {
            return null;
        }

        $actId = $wpdb->get_var($wpdb->prepare(
            "SELECT act_id FROM {$table} WHERE short_id = %s LIMIT 1",
            $shortId
        ));

        return is_numeric($actId) && (int) $actId > 0 ? (int) $actId : null;
    }

    // ─────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────

    /**
     * Mint + persist a code for one act_id. INSERT IGNORE absorbs both
     * failure modes silently; the follow-up SELECT disambiguates:
     *   - act_id PK collision (a concurrent request minted first) →
     *     adopt the winner's code.
     *   - short_id unique collision (astronomically rare) → regenerate,
     *     up to MAX_ATTEMPTS.
     */
    private function insertNewCode(int $actId): ?string
    {
        global $wpdb;
        $table = TableRegistry::postShortcodes();

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = self::generate();

            // created_at in UTC via gmdate — NEVER the DB clock (clock-split
            // norm: MySQL session time_zone is SYSTEM, not WP's UTC).
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (act_id, short_id, created_at) VALUES (%d, %s, %s)",
                $actId,
                $code,
                gmdate('Y-m-d H:i:s')
            ));

            if ($inserted === 1) {
                return $code;
            }

            // 0 rows affected — did another writer win the act_id race?
            $existing = $this->findManyByActIds([$actId]);
            if (isset($existing[$actId])) {
                return $existing[$actId];
            }
            // Otherwise the short_id collided — loop regenerates.
        }

        Logger::warning('[bcc-trust] post shortcode mint exhausted retries', [
            'act_id' => $actId,
        ]);
        return null;
    }

    /**
     * 8 crypto-random letters from the 52-letter alphabet (see the
     * ALPHABET const for why letters-only). Regenerates on the off
     * chance the code case-folds onto the RESERVED list.
     */
    private static function generate(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, 51)];
            }
        } while (in_array(strtolower($code), self::RESERVED, true));

        return $code;
    }

    /**
     * Positive, unique, bounded (§4) id list.
     *
     * @param list<int> $actIds
     * @return list<int>
     */
    private static function sanitizeIds(array $actIds): array
    {
        $seen = [];
        foreach ($actIds as $raw) {
            $id = (int) $raw;
            if ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                if (count($seen) >= self::MAX_IDS) {
                    break;
                }
            }
        }
        return array_keys($seen);
    }
}
