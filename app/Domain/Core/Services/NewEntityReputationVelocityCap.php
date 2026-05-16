<?php
/**
 * NewEntityReputationVelocityCap — read-time score clamp for new
 * entity cards.
 *
 * Plan §9 critical-risk-mitigation item #2. Plan §6.3 contract:
 *   `cap(int $targetId, int $rawScore): int`
 * For the first 60 days of an entity card's existence, clamps the
 * reputation score to ≤ 50. After 60 days, the cap lifts and the
 * raw score flows through unchanged.
 *
 * **Why** (per risk-assessment §5 item 2):
 *
 * A fresh validator/builder/creator page can be artificially pumped
 * to high score by a coordinated burst of attestations on day one.
 * Without the velocity cap, that pumped score becomes the public
 * "first impression" that drives the next wave of attention and
 * follow-on attestations — a self-fulfilling reputation farm. The
 * 60-day cap forces newly-created entities to demonstrate sustained
 * trust over weeks before claiming a high-tier score publicly.
 *
 * **Scope (entity cards only, NOT user accounts):**
 *
 * Despite the name, this clamp applies ONLY to peepso-page CPT posts
 * — the underlying storage for validator/builder/creator/nft cards.
 * User-account reputation is governed by `ReputationRepository::getScore`
 * and is NOT capped here. The Phase 1 plan §6.3 says explicitly:
 * "Service name reflects entity-card scope; not a user-account scope."
 *
 * **NOT to be confused with `ScoreRepository::applyVelocityCap`:**
 *
 * The pre-existing `applyVelocityCap` is a WRITE-time daily-delta
 * cap on `bcc_trust_score_velocity` rows — prevents one day's vote
 * momentum from exceeding `BCC_TRUST_MAX_SCORE_CHANGE_PER_DAY`. This
 * new service is a READ-time age clamp on the FINAL composed score.
 * Different layer, different table, different math. The naming is
 * unfortunate but matches plan §6.3 verbatim.
 *
 * **Plumb point (single seam):**
 *
 *   `CardViewService::getPageCard` finalizes the entity-card score at
 *   `$trustScore = $rm !== null ? (int) round((float) $rm->trust_score) : 50;`
 *   PR-7 wraps that result through this cap. The member-card branch
 *   (separate score finalization for user profiles) is NOT wrapped —
 *   per spec, this cap is entity-only.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-7 (2026-05-14)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

final class NewEntityReputationVelocityCap
{
    /**
     * §6.3 — "first 60 days of entity existence". Tunable; closed-
     * network testing retunes if pumped-entity incidents surface
     * past the 60-day mark.
     */
    public const NEW_ENTITY_AGE_DAYS = 60;

    /**
     * §6.3 — "score ≤ 50". 50 is the neutral midpoint of the
     * 0-100 reputation scale — a new entity can show "promising"
     * but never "trusted" until it accumulates real history.
     */
    public const NEW_ENTITY_SCORE_CAP = 50;

    /**
     * Apply the new-entity cap to a finalized reputation score.
     *
     * @param int $targetId  peepso-page post ID. Non-page IDs (validator
     *                       entries without backing page, etc.) bypass
     *                       the cap — they have no `post_date_gmt`
     *                       to age against.
     * @param int $rawScore  Pre-cap score, 0..100.
     * @return int  Either rawScore (if entity is mature or no age
     *              resolvable) or min(rawScore, NEW_ENTITY_SCORE_CAP).
     */
    public function cap(int $targetId, int $rawScore): int
    {
        if ($targetId <= 0) {
            return $rawScore;
        }

        $post = get_post($targetId);
        if (!($post instanceof WP_Post)) {
            // Target not a WordPress post — no age to clamp against.
            // Defensive default: pass through. (Real cards always
            // have a backing peepso-page; this path covers stale
            // foreign-key resolution.)
            return $rawScore;
        }

        // post_date_gmt is the canonical UTC creation timestamp.
        // Fall back to post_date (site-local) only when GMT is
        // missing — older posts on some installs lack the GMT field.
        $dateRaw = (string) ($post->post_date_gmt !== '' && $post->post_date_gmt !== '0000-00-00 00:00:00'
            ? $post->post_date_gmt
            : $post->post_date);
        if ($dateRaw === '' || $dateRaw === '0000-00-00 00:00:00') {
            return $rawScore;
        }

        $createdTs = strtotime($dateRaw . ' UTC');
        if ($createdTs === false) {
            return $rawScore;
        }

        $ageSeconds = time() - $createdTs;
        if ($ageSeconds < self::NEW_ENTITY_AGE_DAYS * 86400) {
            return min($rawScore, self::NEW_ENTITY_SCORE_CAP);
        }

        return $rawScore;
    }
}
