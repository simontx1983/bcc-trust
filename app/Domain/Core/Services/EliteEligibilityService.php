<?php

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\PageReadModelRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * §J.12 Elite eligibility — the cross-table gate on the top reputation tier.
 *
 * Reaching BCC_TRUST_TIER_ELITE is necessary but no longer sufficient. Two
 * further conditions apply, and they close two DIFFERENT attacks:
 *
 *   1. The native-conduct floor (owned by TrustScoreService, evaluated inline
 *      in SQL) closes "wallet depth alone". onchain_bonus contributes up to 20
 *      points from wallet age, transaction depth and contract deployments —
 *      all properties of a wallet that can simply be acquired. Before this
 *      gate, an aged wallet plus roughly eight vouches reached score 90 with
 *      no conduct on the platform at all.
 *
 *   2. This service's gates close "vouch ring", which the floor does NOT,
 *      because attestation_bonus counts as native conduct and weight_at_time
 *      is itself multiplied by wallet age. A concentrated set of backers can
 *      push a subject over the native floor; requiring a minimum number of
 *      DISTINCT backers, a tenure floor, and a clean dispute record cannot be
 *      satisfied by concentration.
 *
 * Shape follows ReliabilityStandingComputer: the decision is a pure static
 * function over already-resolved inputs, and the I/O wrapper resolves those
 * inputs and persists. Everything DB-touching lives in repositories (§1) —
 * this class holds no $wpdb.
 *
 * The result is denormalized to `bcc_trust_page_scores.elite_eligible` so the
 * eight inline tier-writing UPDATEs can read it without a join. That mirrors
 * the `recalculate_required` flag lifecycle on the same row, and the
 * cron-owns-all-writes discipline of bcc_attestor_reliability_cache.
 *
 * @package BCC\Trust\Core\Services
 */
final class EliteEligibilityService
{
    private ScoreRepository $scoreRepository;
    private AttestationRepository $attestationRepository;

    /**
     * MemberSelfPageService is deliberately NOT injected: this service only
     * needs isSelfPage()/ownerOfSelfPage(), which are static id-arithmetic
     * over ID_BASE and touch nothing. Injecting the instance would imply a
     * collaborator relationship that does not exist. The one caller that does
     * need the instance method (ensureSelfPage, which can CREATE a page) is
     * the event subscriber in Plugin.php, and it resolves the page id before
     * calling in here.
     */
    public function __construct(
        ScoreRepository $scoreRepository,
        AttestationRepository $attestationRepository
    ) {
        $this->scoreRepository       = $scoreRepository;
        $this->attestationRepository = $attestationRepository;
    }

    /**
     * The gate decision. Pure — same inputs, same output, no I/O.
     *
     * Returned as a breakdown rather than a bare bool so the caller can log
     * WHICH condition failed. "Why am I not elite yet" is otherwise
     * unanswerable without re-running the whole evaluation.
     *
     * @return array{
     *   eligible: bool,
     *   distinct_attestors: int,
     *   tenure_days: int,
     *   has_upheld_dispute: bool,
     *   failed: list<string>
     * }
     */
    public static function evaluate(
        int $distinctAttestors,
        int $tenureDays,
        bool $hasUpheldDispute,
        int $minAttestors,
        int $minTenureDays
    ): array {
        $failed = [];

        if ($distinctAttestors < $minAttestors) {
            $failed[] = 'distinct_attestors';
        }
        if ($tenureDays < $minTenureDays) {
            $failed[] = 'tenure_days';
        }
        if ($hasUpheldDispute) {
            $failed[] = 'upheld_dispute';
        }

        return [
            'eligible'           => $failed === [],
            'distinct_attestors' => $distinctAttestors,
            'tenure_days'        => $tenureDays,
            'has_upheld_dispute' => $hasUpheldDispute,
            'failed'             => $failed,
        ];
    }

    /**
     * Resolve the gate inputs for one page, persist the flag, and refresh the
     * tier so a downgrade actually materializes.
     *
     * refreshTier() is not optional: flipping the flag alone only changes what
     * the NEXT write computes, so a dormant page would keep a tier it no
     * longer qualifies for indefinitely.
     *
     * @return bool TRUE when the verdict was PERSISTED. False means the
     *         §J.12 columns are not on this database yet (plugin code running
     *         ahead of its schema) — the caller should treat it as retryable,
     *         NOT as "this page is ineligible". Returning the evaluated
     *         eligibility here would be a lie: nothing was written.
     */
    public function recomputeFor(int $pageId): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        // Cheap pre-check so a pre-migration request does no evaluation work
        // (and issues no attestation/dispute queries) it cannot persist.
        if (!ScoreRepository::eliteGateAvailable()) {
            \BCC\Core\Observability\DegradationMetrics::record('elite_eligibility', 'schema_unavailable');
            return false;
        }

        $minAttestors  = (int) apply_filters(
            'bcc_trust_elite_min_attestors',
            defined('BCC_TRUST_ELITE_MIN_ATTESTORS') ? BCC_TRUST_ELITE_MIN_ATTESTORS : 5
        );
        $minTenureDays = (int) apply_filters(
            'bcc_trust_elite_min_tenure_days',
            defined('BCC_TRUST_ELITE_MIN_TENURE_DAYS') ? BCC_TRUST_ELITE_MIN_TENURE_DAYS : 90
        );
        $windowDays    = (int) apply_filters(
            'bcc_trust_elite_dispute_window_days',
            defined('BCC_TRUST_ELITE_DISPUTE_WINDOW_DAYS') ? BCC_TRUST_ELITE_DISPUTE_WINDOW_DAYS : 180
        );

        [$targetKinds, $targetId, $tenureDays] = $this->resolveSubject($pageId);

        $distinctAttestors = $this->attestationRepository->countDistinctActiveAttestorsForTarget(
            $targetKinds,
            $targetId
        );

        $since = gmdate('Y-m-d H:i:s', time() - ($windowDays * DAY_IN_SECONDS));
        $hasUpheldDispute = DisputeRepository::countUpheldSince($pageId, $since) > 0;

        $verdict = self::evaluate(
            $distinctAttestors,
            $tenureDays,
            $hasUpheldDispute,
            $minAttestors,
            $minTenureDays
        );

        $wasEligible = $this->currentFlag($pageId);
        $eligible    = $verdict['eligible'];

        // Racy tail: the columns could vanish from under us only if someone
        // dropped them, but setEliteEligibility re-checks and reports honestly.
        // If the write did not land, do NOT refresh the tier off a gate we
        // could not store and do NOT announce a transition — both would
        // publish a decision that has no persisted backing.
        if (!$this->scoreRepository->setEliteEligibility($pageId, $eligible, gmdate('Y-m-d H:i:s'))) {
            \BCC\Core\Observability\DegradationMetrics::record('elite_eligibility', 'schema_unavailable');
            return false;
        }

        $this->scoreRepository->refreshTier($pageId);

        // §5 — the read model is a separate store; without this the flag flip
        // is invisible to every REST surface until the next dirty sweep.
        PageReadModelRepository::enqueueDirty($pageId);

        if ($wasEligible !== $eligible) {
            $this->onTransition($pageId, $eligible, $verdict);
        }

        return true;
    }

    /**
     * Map a score-row page id onto its attestation target space and resolve
     * the tenure that applies to it.
     *
     * Member self-pages measure ACCOUNT age; entity cards measure PAGE age.
     * Deliberately not WalletAgeWeighter, which measures verified-wallet age:
     * using it here would re-import the on-chain axis this gate exists to
     * remove, and would fail a decade-old account that has never linked a
     * wallet.
     *
     * @return array{0: list<string>, 1: int, 2: int} [targetKinds, targetId, tenureDays]
     */
    private function resolveSubject(int $pageId): array
    {
        if (MemberSelfPageService::isSelfPage($pageId)) {
            $userId = MemberSelfPageService::ownerOfSelfPage($pageId);
            return [
                ['user_profile'],
                $userId,
                ContributionScoreService::accountAgeDays($userId),
            ];
        }

        return [
            AttestationRepository::PAGE_TARGET_KINDS,
            $pageId,
            self::pageAgeDays($pageId),
        ];
    }

    /** Age in whole days of an entity page, from post_date_gmt. 0 if unresolvable. */
    private static function pageAgeDays(int $pageId): int
    {
        $dateStr = get_post_field('post_date_gmt', $pageId);
        if (!is_string($dateStr)) {
            return 0;
        }
        $dateStr = trim($dateStr);
        if ($dateStr === '' || str_starts_with($dateStr, '0000')) {
            return 0;
        }
        try {
            $ts = (new \DateTimeImmutable($dateStr, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception $e) {
            return 0;
        }
        return (int) max(0, intdiv(time() - $ts, DAY_IN_SECONDS));
    }

    /**
     * The page's currently persisted gate, resolved through the same
     * grandfather rule the SQL ladder uses so a first evaluation of a
     * never-seen row reads as a transition only when it actually denies.
     */
    private function currentFlag(int $pageId): bool
    {
        $row = $this->scoreRepository->getRawRow($pageId);
        if ($row === null) {
            return true;
        }
        return TrustScoreService::resolveEliteEligible(
            $row->elite_eligible ?? null,
            isset($row->elite_eligible_at) ? (string) $row->elite_eligible_at : null
        );
    }

    /**
     * Announce a gate transition.
     *
     * The 0→1 case needs an event because TierUpgradeListener only fires on
     * ACTIVITY. When the nightly sweep is what finally makes someone eligible,
     * nothing else would notice until their next post — the celebration would
     * arrive days late, attached to an unrelated action.
     *
     * @param array{eligible: bool, distinct_attestors: int, tenure_days: int, has_upheld_dispute: bool, failed: list<string>} $verdict
     */
    private function onTransition(int $pageId, bool $eligible, array $verdict): void
    {
        $ownerId = MemberSelfPageService::isSelfPage($pageId)
            ? MemberSelfPageService::ownerOfSelfPage($pageId)
            : 0;

        /**
         * Fires when a page crosses the §J.12 elite-eligibility gate in either
         * direction.
         *
         * @param int   $ownerId  Member id for self-pages, 0 for entity cards.
         * @param int   $pageId   The score-row page id.
         * @param bool  $eligible The new gate state.
         * @param array $verdict  The full breakdown, including which conditions failed.
         */
        do_action('bcc_elite_eligibility_changed', $ownerId, $pageId, $eligible, $verdict);
    }
}
