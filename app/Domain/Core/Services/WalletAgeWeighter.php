<?php
/**
 * WalletAgeWeighter — wallet-age Sybil-mitigation multiplier.
 *
 * Plan §9 critical-risk-mitigation item #1: wallet-age weighting.
 * Plan §6.3 contract: `weightFor(int $attestorUserId): float` returns
 * a [0.5, 1.0] multiplier based on the attestor's oldest verified
 * wallet-link age.
 *
 * The intent (per risk-assessment §5 item 1): a Sybil farming attestation
 * pressure can cheaply spin up brand-new accounts and link a wallet today.
 * Without wallet-age weighting, those casts count exactly as much as a
 * five-year-old operator's. The multiplier doesn't reject — it dampens —
 * so legitimate new operators still participate, just with less weight
 * until they've accumulated on-chain history.
 *
 * §12 q5 — Wallet-age weight curve. The plan suggests piecewise based on
 * months. This implementation ships a three-tier piecewise-constant curve
 * via named constants below. Closed-network testing can retune by editing
 * the constants — no schema change, no migration.
 *
 *   < 3 months  → 0.50× (very new, max Sybil suspicion)
 *   3 – 12 mo   → 0.75× (newer operator, still ramping)
 *   12+ months  → 1.00× (mature, no penalty)
 *
 * Edge cases:
 *   - User has NO verified wallet → 0.50× (maximum penalty). Without
 *     a verified wallet there's no on-chain identity at all; the
 *     operator may still cast attestations (cast endpoint doesn't gate
 *     on wallet-presence today) but their weight is the floor.
 *   - User has multiple verified wallets → uses the OLDEST. A late-
 *     linked second wallet doesn't degrade the operator's standing.
 *   - Future-dated verified_at (clock skew, etc.) → treated as age 0,
 *     i.e. 0.50× floor. Defensive against weird data.
 *
 * The multiplier is applied at cast-time, captured in
 * `bcc_trust_attestations.weight_at_time` per §J.4 ledger semantics:
 * later wallet aging doesn't retroactively reweight historical casts.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-4 (2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletAgeWeighter
{
    /** §12 q5 — wallet-age curve. Tunable; closed-network testing retunes. */
    public const WEIGHT_VERY_NEW = 0.50;  // < 3 months
    public const WEIGHT_NEW      = 0.75;  // 3 – 12 months
    public const WEIGHT_MATURE   = 1.00;  // 12+ months

    /** Tier boundaries in seconds. 30-day month for tractability. */
    private const SECONDS_3_MONTHS  = 90  * 86400;
    private const SECONDS_12_MONTHS = 365 * 86400;

    /**
     * Resolve the wallet-age multiplier for the given user.
     *
     * @param int $attestorUserId The operator casting an attestation.
     * @return float A multiplier in [0.5, 1.0] suitable for direct
     *               multiplication into `weight_at_time` at cast.
     */
    public function weightFor(int $attestorUserId): float
    {
        if ($attestorUserId <= 0) {
            return self::WEIGHT_VERY_NEW;
        }

        $oldest = WalletRepository::oldestVerifiedAt($attestorUserId);
        if ($oldest === null) {
            // No verified wallet on file — operator hasn't proven on-chain
            // identity. Apply the floor. Defensible: the surface still
            // works (operator can cast), just at minimum weight.
            return self::WEIGHT_VERY_NEW;
        }

        $linkedTs = strtotime($oldest . ' UTC');
        if ($linkedTs === false) {
            return self::WEIGHT_VERY_NEW;
        }

        $ageSeconds = time() - $linkedTs;
        if ($ageSeconds < self::SECONDS_3_MONTHS) {
            return self::WEIGHT_VERY_NEW;
        }
        if ($ageSeconds < self::SECONDS_12_MONTHS) {
            return self::WEIGHT_NEW;
        }
        return self::WEIGHT_MATURE;
    }
}
