<?php
/**
 * One-shot backfill — evaluate the §J.12 elite-eligibility gate for every
 * page currently at or above the elite score threshold.
 *
 * Why this exists at all: the gate columns ship with
 * `elite_eligible = 0, elite_eligible_at = NULL`, and
 * TrustScoreService::tierSql() treats a NULL evaluation timestamp as
 * GRANDFATHERED. That is deliberate — without it, deploying the column would
 * itself be a mass demotion, dropping every existing elite to trusted on their
 * next score write with no evaluation having happened. This migration is what
 * converts "never looked at" into a real verdict, one page at a time.
 *
 * Scoped to `total_score >= BCC_TRUST_TIER_ELITE`. Below that threshold the
 * gate cannot change the resolved tier, so those rows are left unevaluated
 * (and harmlessly grandfathered) rather than burning the migration budget on
 * answers nobody can observe. They get evaluated if and when they climb, via
 * the event subscribers or the nightly sweep.
 *
 * Expect visible churn on the first pass. Demotions here are the POINT — a
 * page that was elite purely on wallet depth, or on a concentrated backer
 * set, is supposed to come down. A second-order effect follows: a smaller
 * elite set means fewer attestation casts hit the §J.4 40% elite-source cap,
 * so OTHER subjects' attestation_bonus rises on the next synthesis. That is
 * convergent (the gate only tightens) but the log volume is not a fault.
 *
 * Idempotent: EliteEligibilityService::recomputeFor() is a pure re-evaluation
 * plus a SET, so re-running produces the same verdict from the same state. The
 * completion option is owned by the migration runner, not this function.
 *
 * Status contract (this function processes one bounded batch and RETURNS):
 *   - the service or repository is unavailable → INCOMPLETE (fail closed)
 *   - a page's recompute throws                → INCOMPLETE
 *   - batch cap hit with rows remaining        → INCOMPLETE (resume next request)
 *   - eligible set fully drained clean         → COMPLETE
 *
 * Invoked by the migration runner on plugins_loaded (independent of the
 * schema-version gate). See includes/database/migration-runner.php.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since §J.12 elite gate
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_backfill_elite_eligibility')) {

    function bcc_trust_backfill_elite_eligibility(): string
    {
        // No self-guard on the completion option: the migration runner owns
        // guarding, locking and completion. This function processes one
        // bounded batch and RETURNS a status.

        if (!class_exists('\\BCC\\Trust\\Core\\Plugin')) {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        $plugin  = \BCC\Trust\Core\Plugin::instance();
        $service = $plugin->eliteEligibilityService();
        $scores  = $plugin->scoreRepository();

        // The migration runner fires on plugins_loaded INDEPENDENTLY of the
        // schema-version gate, so on a fresh install it can reach here before
        // dbDelta has added the §J.12 columns. Stay INCOMPLETE (retryable)
        // rather than marking a backfill complete that evaluated nothing —
        // the next request, after the schema lands, does the real work.
        if (!\BCC\Trust\Core\Repositories\ScoreRepository::eliteGateAvailable()) {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        $minScore  = defined('BCC_TRUST_TIER_ELITE') ? (int) BCC_TRUST_TIER_ELITE : 80;
        $batchSize = 100;
        // Runaway guard only — pre-launch scale is a handful of pages.
        $maxIterations = 100;
        $evaluated     = 0;
        $hadFailure    = false;
        $drained       = false;

        // A far-future staleness boundary so EVERY unevaluated row matches on
        // the first pass. Match-set-SHRINKING paging: recomputeFor() stamps
        // elite_eligible_at, which removes the row from this query, so the
        // offset stays at 0 and each iteration re-queries what remains.
        // Stepping an offset here would skip rows as the set contracts.
        $staleBefore = gmdate('Y-m-d H:i:s', time() + YEAR_IN_SECONDS);

        for ($i = 0; $i < $maxIterations; $i++) {
            $pageIds = $scores->listPagesForEliteSweep($minScore, $staleBefore, $batchSize, 0);

            if ($pageIds === []) {
                $drained = true;
                break;
            }

            foreach ($pageIds as $pageId) {
                try {
                    // recomputeFor returns FALSE when the verdict could not be
                    // persisted (schema vanished mid-run). Counting that as
                    // progress would mark the migration complete with pages
                    // still unevaluated — i.e. silently still elite.
                    if (!$service->recomputeFor((int) $pageId)) {
                        $hadFailure = true;
                        break;
                    }
                    $evaluated++;
                } catch (\Throwable $e) {
                    // Leave the migration retryable rather than marking it
                    // complete with pages still unevaluated — an unevaluated
                    // page stays grandfathered, i.e. silently still elite.
                    $hadFailure = true;
                    \BCC\Core\Log\Logger::warning(
                        '[bcc-trust] elite-eligibility backfill: per-page evaluation failed',
                        ['page_id' => (int) $pageId, 'error' => $e->getMessage()]
                    );
                }
            }

            if ($hadFailure) {
                break;
            }
        }

        if (!$drained || $hadFailure) {
            \BCC\Core\Log\Logger::info(
                '[bcc-trust] elite-eligibility backfill: partial pass, will resume',
                ['evaluated' => $evaluated, 'had_failure' => $hadFailure]
            );
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        \BCC\Core\Log\Logger::info(
            '[bcc-trust] elite-eligibility backfill complete',
            ['evaluated' => $evaluated]
        );

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
