<?php
/**
 * SuggestionService — personalized "who to follow" recommender.
 *
 * AFFINITY-ONLY by hard product rule. This service answers "who is
 * relevant to you," NOT "who is popular / trusted / most-followed."
 * There is NO trust-score term and NO follower-count term anywhere in
 * the scoring model — see {@see FeedColdStartService} for the sibling
 * doctrine (discovery is never ranked by trust/followers). Reputation
 * enters this service ONLY as an exclusion (caution/risky/suspended
 * users are removed from the candidate set); it never adds to a score.
 *
 * Pipeline:
 *   1. Candidate generation (UNION of affinity sources):
 *        - reciprocity   : people who follow the viewer but aren't
 *                          followed back (PeepSoFollowerRepository)
 *        - 2nd-degree    : friends-of-friends + mutual-follow counts
 *                          (PeepSoFollowerRepository::getSecondDegreeCandidates)
 *        - co-membership : shared Locals / holder communities
 *                          (PeepSoGroupRepository::getCoMembersOfGroups)
 *        - co-validator  : shared validator backing
 *                          (DelegationRepository::getSharedValidatorBackers)
 *   2. Exclusions (security-sensitive — applied to the candidate set):
 *        self, already-following, caution/risky, active-suspended,
 *        mutual blocks (either direction), watching-graph opt-out.
 *   3. Scoring (affinity weights only — see WEIGHT constants).
 *   4. Reason derivation: the single highest-CONTRIBUTING signal.
 *   5. Cold-start top-up: if fewer than `$limit` survive, top up with
 *      the EXISTING civic recent-operators source (reused from
 *      UserSyncRepository::getRecentlyActiveUserIds + the same stable
 *      daily shuffle FeedColdStartService uses), apply the SAME
 *      exclusions, dedupe, reason = null.
 *   6. View-model: reuse UserViewService::getSummary + the shared
 *      MemberSummaryPrefetcher bundle, projected to the 8 wire keys.
 *
 * No $wpdb here — every read is a repository call (§1).
 *
 * @package BCC\Trust\Core\Services
 * @since   v1.25 (who-to-follow suggestions)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Core\Repositories\PeepSoFollowerRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\SuspensionRepository;
use BCC\Trust\Core\Support\MemberSummaryPrefetcher;
use BCC\Trust\Core\Support\PrivacySettings;
use BCC\Trust\Onchain\Repositories\DelegationRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class SuggestionService
{
    // ── Affinity weights (tunable; AFFINITY ONLY — never trust/popularity) ──
    /** Candidate already follows the viewer (reciprocity nudge). */
    private const W_RECIP     = 3.0;
    /** Per mutual follow, capped at MUTUAL_CAP. */
    private const W_MUTUAL    = 2.0;
    /** Per shared Local membership. */
    private const W_LOCAL     = 2.0;
    /** Per shared validator backed. */
    private const W_VALIDATOR = 2.5;
    /** Per shared NFT holder community. */
    private const W_NFT       = 2.0;
    /** Mutual-follow contribution saturates here (anti-celebrity). */
    private const MUTUAL_CAP  = 5;

    /** Hard cap on the unioned candidate pool before scoring. */
    private const CANDIDATE_POOL_CAP = 200;

    /** Recent-operators (cold-start top-up) recency window + pool. */
    private const COLD_WINDOW_DAYS  = 7;
    private const COLD_CANDIDATES   = 60;

    private UserViewService $userViewService;
    private ReputationRepository $reputationRepository;
    private SuspensionRepository $suspensionRepository;

    public function __construct(
        UserViewService $userViewService,
        ReputationRepository $reputationRepository,
        SuspensionRepository $suspensionRepository
    ) {
        $this->userViewService      = $userViewService;
        $this->reputationRepository = $reputationRepository;
        $this->suspensionRepository = $suspensionRepository;
    }

    /**
     * Suggested members for the viewer, best-first.
     *
     * @return list<array{
     *   id: int,
     *   handle: string,
     *   display_name: string,
     *   avatar_url: string,
     *   card_tier: string|null,
     *   tier_label: string|null,
     *   rank_label: string,
     *   is_in_good_standing: bool,
     *   suggestion_reason: array{code: string, label: string}|null
     * }>
     */
    public function getSuggestions(int $viewerId, int $limit): array
    {
        if ($viewerId <= 0) {
            return [];
        }
        $limit = max(1, min(24, $limit));

        // ── 1. Candidate generation (affinity sources) ────────────────
        $following  = PeepSoFollowerRepository::getFollowing($viewerId, self::CANDIDATE_POOL_CAP);
        $followers  = PeepSoFollowerRepository::getFollowers($viewerId, self::CANDIDATE_POOL_CAP);
        $secondDeg  = PeepSoFollowerRepository::getSecondDegreeCandidates($viewerId, self::CANDIDATE_POOL_CAP);
        $viewerGrp  = PeepSoGroupRepository::getUserMemberGroupIds($viewerId);
        $coMembers  = $viewerGrp === []
            ? []
            : PeepSoGroupRepository::getCoMembersOfGroups($viewerGrp, $viewerId, self::CANDIDATE_POOL_CAP);
        $coValidator = DelegationRepository::getSharedValidatorBackers($viewerId, self::CANDIDATE_POOL_CAP);

        $followingSet = array_fill_keys($following, true);

        // Reciprocity = follows-you minus already-followed-back.
        $reciprocal = [];
        foreach ($followers as $fid) {
            if (!isset($followingSet[$fid])) {
                $reciprocal[$fid] = true;
            }
        }

        // Classify shared groups (Local vs holder community) once.
        $sharedGroupIds = [];
        foreach ($coMembers as $info) {
            $sharedGroupIds[$info['shared_group_id']] = true;
        }
        $groupInfo = $sharedGroupIds === []
            ? []
            : PeepSoGroupRepository::findManyByIds(array_keys($sharedGroupIds));

        // ── 2/3. Build the candidate set with per-signal contributions ──
        /** @var array<int, array{score: float, signals: array<string, array{contribution: float, label: string}>}> $pool */
        $pool = [];

        $touch = static function (int $candidateId) use (&$pool): void {
            if (!isset($pool[$candidateId])) {
                $pool[$candidateId] = ['score' => 0.0, 'signals' => []];
            }
        };

        // Reciprocity (binary).
        foreach (array_keys($reciprocal) as $cid) {
            $touch($cid);
            $contribution = self::W_RECIP;
            $pool[$cid]['score'] += $contribution;
            $pool[$cid]['signals']['follows_you'] = [
                'contribution' => $contribution,
                'label'        => 'Follows you',
            ];
        }

        // Mutual follows (capped).
        foreach ($secondDeg as $cid => $mutualCount) {
            $touch($cid);
            $capped       = min($mutualCount, self::MUTUAL_CAP);
            $contribution = self::W_MUTUAL * $capped;
            if ($contribution <= 0.0) {
                continue;
            }
            $pool[$cid]['score'] += $contribution;
            $pool[$cid]['signals']['mutual_follows'] = [
                'contribution' => $contribution,
                'label'        => sprintf(
                    'Followed by %d you follow',
                    $mutualCount
                ),
            ];
        }

        // Shared Locals / holder communities.
        foreach ($coMembers as $cid => $info) {
            $touch($cid);
            $sharedGroup = $groupInfo[$info['shared_group_id']] ?? null;
            $groupName   = $sharedGroup !== null ? (string) $sharedGroup->post_title : '';
            $isLocal     = $groupName !== '' && self::looksLikeLocal($groupName);

            $weight       = $isLocal ? self::W_LOCAL : self::W_NFT;
            $contribution = $weight * $info['shared_count'];
            $code         = $isLocal ? 'co_local' : 'co_nft_community';
            $label        = $isLocal
                ? sprintf('In %s with you', $groupName !== '' ? $groupName : 'a Local')
                : sprintf('In %s with you', $groupName !== '' ? $groupName : 'a community');

            $pool[$cid]['score'] += $contribution;
            $pool[$cid]['signals'][$code] = [
                'contribution' => $contribution,
                'label'        => $label,
            ];
        }

        // Shared validator backing — resolve monikers in one batch.
        if ($coValidator !== []) {
            $pairs = [];
            foreach ($coValidator as $info) {
                $pairs[] = [
                    'chain_id'         => $info['chain_id'],
                    'operator_address' => $info['validator_address'],
                ];
            }
            $monikers = ValidatorRepository::getMonikersByAddresses($pairs);

            foreach ($coValidator as $cid => $info) {
                $touch($cid);
                $contribution = self::W_VALIDATOR * $info['shared_count'];
                $key          = $info['chain_id'] . ':' . $info['validator_address'];
                $moniker      = $monikers[$key] ?? '';
                $pool[$cid]['score'] += $contribution;
                $pool[$cid]['signals']['co_validator'] = [
                    'contribution' => $contribution,
                    'label'        => sprintf(
                        'Backs %s too',
                        $moniker !== '' ? $moniker : 'the same validator'
                    ),
                ];
            }
        }

        // ── 2 (exclusions). Security-sensitive: applied to the pool. ───
        // Prime user meta for the whole pool before the privacy filter —
        // isPrivacyOptedOut reads per-candidate user meta, which would be
        // one uncached query each on a cold cache (the pool can run to
        // hundreds). One update_meta_cache collapses it.
        update_meta_cache('user', array_keys($pool));
        $exclude = $this->buildExclusionSet($viewerId, $following);
        foreach (array_keys($pool) as $cid) {
            if (isset($exclude[$cid]) || $this->isPrivacyOptedOut($cid)) {
                unset($pool[$cid]);
            }
        }

        // ── 3. Order by score desc (tiebreak: id asc for stability) ────
        $scored = [];
        foreach ($pool as $cid => $entry) {
            $scored[] = ['id' => $cid, 'score' => $entry['score'], 'signals' => $entry['signals']];
        }
        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return $a['id'] <=> $b['id'];
            }
            return $b['score'] <=> $a['score'];
        });
        $scored = array_slice($scored, 0, $limit);

        // ── 4. Resolve reason = single highest-contributing signal. ────
        /** @var array<int, array{code: string, label: string}|null> $reasonByUser */
        $reasonByUser = [];
        /** @var list<int> $chosenIds */
        $chosenIds = [];
        foreach ($scored as $row) {
            $chosenIds[]              = $row['id'];
            $reasonByUser[$row['id']] = self::topReason($row['signals']);
        }

        // ── 5. Cold-start top-up (reuse civic recent-operators source). ─
        if (count($chosenIds) < $limit) {
            $chosenSet = array_fill_keys($chosenIds, true);
            $topUp     = $this->coldStartTopUp($viewerId, $exclude, $chosenSet, $limit - count($chosenIds));
            foreach ($topUp as $cid) {
                $chosenIds[]        = $cid;
                $reasonByUser[$cid] = null; // civic fallback rows carry no reason
            }
        }

        if ($chosenIds === []) {
            return [];
        }

        // ── 6. View-model (reuse getSummary + shared prefetch bundle). ─
        $prefetched = MemberSummaryPrefetcher::primeFor($chosenIds);

        $items = [];
        foreach ($chosenIds as $cid) {
            $summary = $this->userViewService->getSummary($cid, $viewerId, $prefetched);
            if ($summary === null) {
                continue; // user vanished between candidate-gen and hydration
            }
            $items[] = [
                'id'                  => (int) $summary['id'],
                'handle'              => (string) $summary['handle'],
                'display_name'        => (string) $summary['display_name'],
                'avatar_url'          => (string) $summary['avatar_url'],
                'card_tier'           => is_string($summary['card_tier']) ? $summary['card_tier'] : null,
                'tier_label'          => is_string($summary['tier_label']) ? $summary['tier_label'] : null,
                'rank_label'          => (string) $summary['rank_label'],
                'is_in_good_standing' => (bool) $summary['is_in_good_standing'],
                'suggestion_reason'   => $reasonByUser[$cid] ?? null,
            ];
        }

        return $items;
    }

    /**
     * Pick the single highest-contributing signal as the reason.
     * Ties broken by a deterministic code priority so the wire output
     * is stable across requests for the same inputs.
     *
     * @param array<string, array{contribution: float, label: string}> $signals
     * @return array{code: string, label: string}|null
     */
    private static function topReason(array $signals): ?array
    {
        if ($signals === []) {
            return null;
        }

        // Deterministic priority for equal contributions.
        $priority = [
            'follows_you'      => 5,
            'mutual_follows'   => 4,
            'co_validator'     => 3,
            'co_local'         => 2,
            'co_nft_community' => 1,
        ];

        $bestCode    = null;
        $bestContrib = -INF;
        $bestPrio    = -1;
        foreach ($signals as $code => $signal) {
            $contrib = $signal['contribution'];
            $prio    = $priority[$code] ?? 0;
            if ($contrib > $bestContrib || ($contrib === $bestContrib && $prio > $bestPrio)) {
                $bestContrib = $contrib;
                $bestPrio    = $prio;
                $bestCode    = $code;
            }
        }

        if ($bestCode === null) {
            return null;
        }
        return ['code' => $bestCode, 'label' => $signals[$bestCode]['label']];
    }

    /**
     * Build the exclusion lookup. SECURITY-SENSITIVE — every key in the
     * returned map MUST NOT appear in suggestions. Popularity/trust is
     * NEVER a ranking term, but reputation IS an exclusion (caution/risky
     * and active-suspended accounts are removed here).
     *
     * @param list<int> $following  viewer→candidate uf_follow=1 set
     * @return array<int, true>
     */
    private function buildExclusionSet(int $viewerId, array $following): array
    {
        $exclude = [$viewerId => true];

        // already-following
        foreach ($following as $fid) {
            $exclude[$fid] = true;
        }

        // caution / risky reputation tiers
        foreach ($this->reputationRepository->getCautionAndRiskyUserIds() as $uid) {
            $exclude[$uid] = true;
        }

        // active suspensions
        foreach ($this->suspensionRepository->getActiveSuspendedUserIds() as $uid) {
            $exclude[$uid] = true;
        }

        // blocks — BOTH directions
        foreach (PeepSoBlockRepository::getBlockedIds($viewerId) as $uid) {
            $exclude[$uid] = true;
        }
        foreach (PeepSoBlockRepository::getBlockerIds($viewerId) as $uid) {
            $exclude[$uid] = true;
        }

        return $exclude;
    }

    /**
     * Privacy opt-out: a candidate who hid their watching graph must not
     * be suggested — surfacing someone who concealed their follow graph
     * is a leak. Read via the canonical PrivacySettings seam.
     */
    private function isPrivacyOptedOut(int $candidateId): bool
    {
        $profile = PrivacySettings::readProfile($candidateId);
        return ($profile['watching_hidden'] ?? false) === true;
    }

    /**
     * Cold-start top-up. Dominant case for an empty-graph viewer.
     *
     * REUSE: the same civic recent-operators source
     * FeedColdStartService draws on — UserSyncRepository::
     * getRecentlyActiveUserIds (recency, NOT ranked) plus a stable
     * per-(viewer,day) shuffle so the panel rotates daily without
     * accumulating gravity on the same accounts. We deliberately do NOT
     * invent a second recency query.
     *
     * Applies the SAME exclusion set as the scored path and dedupes
     * against already-chosen ids.
     *
     * @param array<int, true> $exclude
     * @param array<int, true> $alreadyChosen
     * @return list<int>
     */
    private function coldStartTopUp(int $viewerId, array $exclude, array $alreadyChosen, int $need): array
    {
        if ($need <= 0) {
            return [];
        }

        $candidates = \BCC\Trust\Core\Repositories\UserSyncRepository::getRecentlyActiveUserIds(
            self::COLD_WINDOW_DAYS,
            self::COLD_CANDIDATES,
            $viewerId
        );
        if ($candidates === []) {
            return [];
        }

        $candidates = self::stableShuffle($candidates, $viewerId);

        // Prime meta before the per-candidate privacy read (same N+1 as
        // the main pool filter above).
        update_meta_cache('user', $candidates);

        $out = [];
        foreach ($candidates as $cid) {
            if ($cid <= 0 || isset($exclude[$cid]) || isset($alreadyChosen[$cid])) {
                continue;
            }
            if ($this->isPrivacyOptedOut($cid)) {
                continue;
            }
            $out[]               = $cid;
            $alreadyChosen[$cid] = true;
            if (count($out) >= $need) {
                break;
            }
        }
        return $out;
    }

    /**
     * Is this group title a BCC Local? Mirrors the §E3 naming
     * convention PeepSoGroupRepository filters on ('Local %'). A
     * non-Local shared group is treated as a holder community.
     */
    private static function looksLikeLocal(string $title): bool
    {
        return preg_match('/^Local\b/u', $title) === 1;
    }

    /**
     * Deterministic Fisher–Yates shuffle seeded by (viewerId, UTC date).
     * Identical idiom to FeedColdStartService::stableShuffle — daily
     * rotation, no reliance on process-global mt_srand state. Kept local
     * to this service (the cold-start service's copy is private); the two
     * are intentionally paired civic-rotation helpers, not a shared util.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function stableShuffle(array $ids, int $viewerId): array
    {
        $count = count($ids);
        if ($count <= 1) {
            return $ids;
        }

        $seedInput = $viewerId . '|' . gmdate('Y-m-d');

        $arr = $ids;
        for ($i = $count - 1; $i > 0; $i--) {
            $hash = hash('sha256', $seedInput . '|' . $i, true);
            /** @var array{1: int} $bytes */
            $bytes = unpack('N', substr($hash, 0, 4));
            $rand  = $bytes[1];
            $j     = $rand % ($i + 1);

            $tmp     = $arr[$i];
            $arr[$i] = $arr[$j];
            $arr[$j] = $tmp;
        }
        return array_values($arr);
    }
}
