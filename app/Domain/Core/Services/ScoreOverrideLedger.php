<?php
/**
 * ScoreOverrideLedger — the undo source of truth for admin score overrides
 * (F-113).
 *
 * ModerationService::overrideScore() moves a page's score by accumulating a
 * delta into `penalty_adjustment`, the same column dispute penalties use, so
 * an override could never be separated out or undone. Each override now
 * records, per page, the exact delta it applied and the elite pin that
 * existed before it. Undo pops the latest not-yet-undone entry and applies
 * the inverse. Penalties are additive, so the inverse cancels exactly even
 * when dispute penalties landed in between.
 *
 * Stored as a bounded stack (MAX_ENTRIES, newest last) in user meta on the
 * TARGET user, so it goes with the user on deletion. The audit log keeps
 * admin_score_override / admin_score_override_undone rows as HISTORY only:
 * it is best-effort and archives at 90 days, so it is not trusted for undo.
 *
 * ⚠ Undo is possible only for the last MAX_ENTRIES overrides per user.
 *
 * @package BCC\Trust\Core\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type LedgerPage array{delta: float, prev_pin: array{admin_id: int, granted_at: string}|null}
 * @phpstan-type LedgerEntry array{id: string, at: string, admin_id: int, new_score: int, reason: string, undone: bool, pages: array<string, LedgerPage>}
 */
class ScoreOverrideLedger
{
    public const META = '_bcc_score_override_ledger';

    /** Undo depth per user. */
    public const MAX_ENTRIES = 20;

    /**
     * Record an override. Returns the entry id, or '' when the write failed
     * (the caller must then tell the admin that this override is NOT undoable).
     *
     * @param array<int, array{delta: float, prev_pin: array{admin_id: int, granted_at: string}|null}> $pages
     */
    public function push(int $userId, int $adminId, int $newScore, string $reason, string $atMysql, array $pages): string
    {
        if ($userId <= 0) {
            return '';
        }
        $entries = $this->entries($userId);
        $id      = bin2hex(random_bytes(8));

        $stored = [];
        foreach ($pages as $pageId => $page) {
            $stored[(string) $pageId] = ['delta' => (float) $page['delta'], 'prev_pin' => $page['prev_pin']];
        }
        $entries[] = [
            'id'        => $id,
            'at'        => $atMysql,
            'admin_id'  => $adminId,
            'new_score' => $newScore,
            'reason'    => $reason,
            'undone'    => false,
            'pages'     => $stored,
        ];
        $entries = array_slice($entries, -self::MAX_ENTRIES);

        return $this->write($userId, $entries) ? $id : '';
    }

    /**
     * The latest entry not yet undone, or null when there is nothing to undo.
     *
     * @return array<string, mixed>|null
     * @phpstan-return LedgerEntry|null
     */
    public function latestUndoable(int $userId): ?array
    {
        $entries = $this->entries($userId);
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            if (!$entries[$i]['undone']) {
                return $entries[$i];
            }
        }
        return null;
    }

    /** Mark an entry undone. False when it is missing or already undone. */
    public function markUndone(int $userId, string $id): bool
    {
        $entries = $this->entries($userId);
        foreach ($entries as $i => $entry) {
            if ($entry['id'] === $id) {
                if ($entry['undone']) {
                    return false;
                }
                $entries[$i]['undone'] = true;
                return $this->write($userId, $entries);
            }
        }
        return false;
    }

    /**
     * @return list<array<string, mixed>>
     * @phpstan-return list<LedgerEntry>
     */
    private function entries(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $raw = $this->readMeta($userId);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $e) {
            if (!is_array($e) || !isset($e['id'], $e['pages']) || !is_array($e['pages'])) {
                continue;
            }
            $pages = [];
            foreach ($e['pages'] as $pid => $p) {
                if (!is_array($p)) {
                    continue;
                }
                $prev = isset($p['prev_pin']) && is_array($p['prev_pin']) && (int) ($p['prev_pin']['admin_id'] ?? 0) > 0
                    ? ['admin_id' => (int) $p['prev_pin']['admin_id'], 'granted_at' => (string) ($p['prev_pin']['granted_at'] ?? '')]
                    : null;
                $pages[(string) $pid] = ['delta' => (float) ($p['delta'] ?? 0), 'prev_pin' => $prev];
            }
            $out[] = [
                'id'        => (string) $e['id'],
                'at'        => (string) ($e['at'] ?? ''),
                'admin_id'  => (int) ($e['admin_id'] ?? 0),
                'new_score' => (int) ($e['new_score'] ?? 0),
                'reason'    => (string) ($e['reason'] ?? ''),
                'undone'    => (bool) ($e['undone'] ?? false),
                'pages'     => $pages,
            ];
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $entries */
    private function write(int $userId, array $entries): bool
    {
        $json = (string) wp_json_encode($entries);
        return $this->writeMeta($userId, $json) || $this->readMeta($userId) === $json;
    }

    // ── collaborator hooks (unit seam) ─────────────────────────────────

    protected function readMeta(int $userId): mixed
    {
        return get_user_meta($userId, self::META, true);
    }

    protected function writeMeta(int $userId, string $json): bool
    {
        return update_user_meta($userId, self::META, $json) !== false;
    }
}
