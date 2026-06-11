<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Application;

use BCC\Core\Contracts\TrustReadServiceInterface;
use BCC\Trust\Core\Repositories\VoteRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class TrustReadService implements TrustReadServiceInterface
{
    private VoteRepository $voteRepository;

    public function __construct(VoteRepository $voteRepository)
    {
        $this->voteRepository = $voteRepository;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVoteById(int $voteId): ?array
    {
        $vote = $this->voteRepository->getById($voteId);

        if (!$vote || (int) $vote->status !== 1) {
            return null;
        }

        return [
            'id' => (int) $vote->id,
            'page_id' => (int) $vote->page_id,
            'voter_user_id' => (int) $vote->voter_user_id,
            'vote_type' => (int) $vote->vote_type,
            'weight' => (float) $vote->weight,
            'reason' => isset($vote->reason) ? (string) $vote->reason : '',
            'status' => (int) $vote->status,
            'created_at' => isset($vote->created_at) ? (string) $vote->created_at : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActiveVotesForPage(int $pageId, int $limit = 50, int $offset = 0): array
    {
        $limit = min(max(1, $limit), 500);
        $offset = max(0, $offset);

        $votes = $this->voteRepository->getAllForPage($pageId, $limit, $offset);

        return array_map(static function ($vote): array {
            return [
                'id' => (int) $vote->id,
                'voter_user_id' => (int) $vote->voter_user_id,
                'voter_name' => isset($vote->voter_name) ? (string) $vote->voter_name : 'Unknown',
                'vote_type' => (int) $vote->vote_type,
                'weight' => (float) $vote->weight,
                'reason' => isset($vote->reason) ? (string) $vote->reason : '',
                'created_at' => isset($vote->created_at) ? (string) $vote->created_at : null,
            ];
        }, $votes);
    }

    public function countActiveVotesForPage(int $pageId): int
    {
        return $this->voteRepository->countActiveForPage($pageId);
    }

    /**
     * @param int[] $voteIds
     * @return array<int, array{vote_type: int, weight: float, reason: string, created_at: ?string}>
     */
    public function getVotesByIds(array $voteIds): array
    {
        $voteIds = array_values(array_unique(array_filter(array_map('intval', $voteIds))));

        if (empty($voteIds)) {
            return [];
        }

        return \BCC\Trust\Core\Plugin::instance()->voteRepository()->getBatchByIds($voteIds);
    }

    /**
     * Select eligible panelists for a dispute panel.
     *
     * Security hardening:
     *  - Only elite/trusted tier users
     *  - Excludes users with fraud_score >= FRAUD_MEDIUM (40)
     *  - Excludes suspended users
     *  - Excludes admins (they can force-resolve from the admin UI;
     *    seating them on panels would double their influence)
     *  - Randomized selection (ORDER BY RAND)
     *  - Fetches 3× the needed pool so the IP diversity filter has room
     *
     * ## Fairness: IP diversity is a SOFT preference, not a hard exclusion
     *
     * Previous implementation applied IP diversity as a hard post-filter:
     * two candidates sharing `last_ip_address` (or both having NULL) were
     * collapsed to a single seat. That penalised legitimate co-located
     * users — roommates, coworkers on the same corporate network, mobile
     * users behind carrier NAT, privacy-conscious users whose IP isn't
     * recorded — while giving only soft sybil resistance (FraudDetector
     * owns the real anti-sybil signal).
     *
     * The new algorithm is three-pass:
     *   Pass 1: prefer unique IPs (strict).
     *   Pass 2: relax to "at most MAX_PER_IP users per IP" if still short.
     *   Pass 3: accept anyone eligible if the pool still can't fill.
     *
     * RAND-ordered candidates mean different requests hit different seat
     * assignments even when the underlying pool is small, so the old
     * "users behind shared NAT always lose their seat" bias is gone.
     */
    /**
     * @param int[] $excludedUserIds
     * @return int[]
     */
    public function getEligiblePanelistUserIds(array $excludedUserIds, int $limit): array
    {
        $limit = max(0, $limit);
        if ($limit === 0) {
            return [];
        }

        $tiers = ['elite', 'trusted'];
        $excludedUserIds = array_values(array_unique(array_filter(array_map('intval', $excludedUserIds))));

        // Fetch 3x the needed count to allow IP diversity filtering.
        $fetchLimit = $limit * 3;

        $rows = \BCC\Trust\Core\Plugin::instance()->reputationRepository()->getEligiblePanelists(
            $tiers,
            BCC_TRUST_FRAUD_MEDIUM,
            $excludedUserIds,
            $fetchLimit
        );

        if (empty($rows)) {
            return [];
        }

        // Drop admins before diversity selection. manage_options means they
        // can force-resolve disputes from the admin UI — letting them also
        // vote on panels would give a single actor multiple influence paths
        // on the same dispute. Enforced here (trust-engine selection) so
        // no caller of getEligiblePanelistUserIds() can bypass it.
        $rows = array_values(array_filter($rows, static function (object $row): bool {
            return !user_can((int) $row->user_id, 'manage_options');
        }));

        if (empty($rows)) {
            return [];
        }

        return self::selectWithSoftIpDiversity($rows, $limit);
    }

    /**
     * Three-pass fair selection from a RAND-ordered candidate list.
     *
     * Pass 1: prefer one user per unique IP.
     * Pass 2: allow up to MAX_PER_IP users per IP.
     * Pass 3: drop the IP constraint entirely if we still can't fill.
     *
     * Cap of 2 same-IP seats keeps the panel majority (3 of 5) drawn from
     * different networks — a single household or office can contribute up
     * to 2 reviewers but never hold quorum alone.
     *
     * @param list<object{user_id: int|numeric-string, last_ip_address: string|null}> $rows Candidates
     * @param int $limit Number of seats to fill
     * @return int[]
     */
    private static function selectWithSoftIpDiversity(array $rows, int $limit): array
    {
        $maxPerIp = 2;

        $selected = [];
        $seen     = []; // user_id => true, across all passes
        $ipCounts = []; // ip-key => count

        $ipKey = static function (object $row): string {
            $ip = $row->last_ip_address ?? '';
            // Normalise empty string / null to a sentinel so NULL-IP users
            // share the same counter instead of being silently excluded.
            return $ip === '' ? '__null_ip__' : (string) $ip;
        };

        // Pass 1: strict — one seat per unique IP.
        foreach ($rows as $row) {
            if (count($selected) >= $limit) {
                break;
            }
            $uid = (int) $row->user_id;
            if (isset($seen[$uid])) {
                continue;
            }
            $key = $ipKey($row);
            if (isset($ipCounts[$key])) {
                continue;
            }
            $ipCounts[$key] = 1;
            $seen[$uid]     = true;
            $selected[]     = $uid;
        }

        // Pass 2: relaxed — up to $maxPerIp per IP.
        if (count($selected) < $limit) {
            foreach ($rows as $row) {
                if (count($selected) >= $limit) {
                    break;
                }
                $uid = (int) $row->user_id;
                if (isset($seen[$uid])) {
                    continue;
                }
                $key = $ipKey($row);
                if (($ipCounts[$key] ?? 0) >= $maxPerIp) {
                    continue;
                }
                $ipCounts[$key] = ($ipCounts[$key] ?? 0) + 1;
                $seen[$uid]     = true;
                $selected[]     = $uid;
            }
        }

        // Pass 3: last resort — no IP constraint. Better to seat a panelist
        // from a shared IP than to leave the panel under quorum and let the
        // dispute auto-resolve without deliberation.
        if (count($selected) < $limit) {
            foreach ($rows as $row) {
                if (count($selected) >= $limit) {
                    break;
                }
                $uid = (int) $row->user_id;
                if (isset($seen[$uid])) {
                    continue;
                }
                $seen[$uid] = true;
                $selected[] = $uid;
            }
        }

        return $selected;
    }

    public function isSuspended(int $userId): bool
    {
        $userInfo = \BCC\Trust\Core\Plugin::instance()->userInfoRepository()->getByUserId($userId);

        return $userInfo ? (bool) $userInfo->is_suspended : false;
    }

    /**
     * Lock the vote row FOR UPDATE and report whether it is still active.
     *
     * Must be called from inside the caller's open transaction on the shared
     * $wpdb connection; the row lock is released on that transaction's
     * COMMIT/ROLLBACK. Prevents the trust engine from soft-deleting the vote
     * between dispute-create validation and the dispute INSERT.
     */
    public function lockActiveVoteForDispute(int $voteId): bool
    {
        global $wpdb;

        if ($voteId <= 0) {
            return false;
        }

        $table = \BCC\Trust\Core\Database\TableRegistry::votes();

        // LIMIT must precede FOR UPDATE in MySQL — the reversed order is a
        // syntax error that $wpdb swallows (get_var → null), which made
        // every dispute creation fail as "vote_no_longer_active".
        $locked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND status = 1 LIMIT 1 FOR UPDATE",
            $voteId
        ));

        return $locked !== null;
    }
}
