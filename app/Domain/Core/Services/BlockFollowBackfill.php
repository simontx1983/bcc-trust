<?php
/**
 * BlockFollowBackfill — F-261: remove the follows that blocks made BEFORE
 * F-29 left behind.
 *
 * Since F-29 (contract v1.124) every block made through the app removes the
 * member-to-member follows between the two members in both directions
 * (WatchingService::severFollowsBetween). Blocks that predate it were never
 * cleaned, so such a pair can still follow each other. This plans and
 * applies the cleanup for every existing block row.
 *
 *   plan()  — read-only: walk every block row by keyset; keep the pairs that
 *             still have a follow either way (each unordered pair once). Any
 *             failed read fails the WHOLE plan (never a partial plan that
 *             looks complete).
 *   apply() — sever each planned pair. Idempotent: a pair with no follow
 *             left is a no-op, so a re-run is safe.
 *
 * Page follows are not touched (F-29: only member-to-member follows).
 * Entry point: `wp bcc-trust block-follows backfill` only.
 *
 * @package BCC\Trust\Core\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Repositories\WatchingRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class BlockFollowBackfill
{
    /** Block rows read per keyset page. */
    public const PAGE_SIZE = 500;

    /** A plan scanning more block rows than this refuses (needs a rethink, not a longer run). */
    public const MAX_BLOCK_ROWS = 50000;

    /**
     * @param \Closure(int, int): int $sever severs follows between two members both ways; returns how many were removed
     */
    public function __construct(
        private readonly WatchingRepository $watchingRepo,
        private readonly \Closure $sever
    ) {
    }

    /**
     * @return array{ok: true, pairs: list<array{0: int, 1: int}>, scanned: int}|array{ok: false, error: string, scanned: int}
     */
    public function plan(): array
    {
        $pairs   = [];
        $seen    = [];
        $scanned = 0;
        $afterId = 0;

        while (true) {
            $page = PeepSoBlockRepository::listBlockPairsAfter($afterId, self::PAGE_SIZE);
            if ($page === null) {
                return ['ok' => false, 'error' => 'A block page could not be read; no plan was produced.', 'scanned' => $scanned];
            }
            if ($page === []) {
                break;
            }

            foreach ($page as $row) {
                $afterId = $row['id'];
                $scanned++;
                if ($scanned > self::MAX_BLOCK_ROWS) {
                    return ['ok' => false, 'error' => 'More than ' . self::MAX_BLOCK_ROWS . ' block rows; refusing to plan.', 'scanned' => $scanned];
                }

                $a = min($row['blocker'], $row['blocked']);
                $b = max($row['blocker'], $row['blocked']);
                if ($a === $b || isset($seen[$a . ':' . $b])) {
                    continue; // self-row, or the other direction of a mutual block
                }
                $seen[$a . ':' . $b] = true;

                try {
                    $hasFollow = $this->watchingRepo->findFollowIdForPair($a, $b) > 0
                        || $this->watchingRepo->findFollowIdForPair($b, $a) > 0;
                } catch (\BCC\Trust\Onchain\Repositories\RepositoryReadFailure) {
                    return ['ok' => false, 'error' => 'A follow could not be read; no plan was produced.', 'scanned' => $scanned];
                }
                if ($hasFollow) {
                    $pairs[] = [$a, $b];
                }
            }
        }

        return ['ok' => true, 'pairs' => $pairs, 'scanned' => $scanned];
    }

    /**
     * @param list<array{0: int, 1: int}> $pairs
     * @return array{pairs: int, severed: int}
     */
    public function apply(array $pairs): array
    {
        $severed = 0;
        foreach ($pairs as [$a, $b]) {
            $severed += ($this->sever)($a, $b);
        }
        return ['pairs' => count($pairs), 'severed' => $severed];
    }

    /**
     * Bound to the environment AND the exact plan, so a token minted on
     * staging, or before the data moved, cannot apply.
     *
     * @param list<array{0: int, 1: int}> $pairs
     */
    public static function confirmationToken(string $env, array $pairs): string
    {
        $keys = array_map(static fn (array $p): string => $p[0] . ':' . $p[1], $pairs);
        sort($keys);
        return substr(hash('sha256', $env . '|' . implode(',', $keys)), 0, 16);
    }
}
