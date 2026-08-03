<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Application;

use BCC\Core\Contracts\ScoreContributorInterface;
use BCC\Core\Log\Logger;
use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\ScoreRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Receives bonus-score contributions from external plugins and persists
 * them to trust_page_scores with proper cache invalidation and logging.
 *
 * Only the 'onchain' source is currently defined, but the $source parameter
 * allows future contributors (e.g. 'social', 'audit') without schema changes.
 */
final class ScoreContributorService implements ScoreContributorInterface
{
    /** @var array<string, string> Maps source identifiers to DB column names. */
    private const SOURCE_COLUMNS = [
        'onchain' => 'onchain_bonus',
    ];

    private ScoreRepository $scoreRepository;

    public function __construct(ScoreRepository $scoreRepository)
    {
        $this->scoreRepository = $scoreRepository;
    }

    public function applyBonus(int $pageId, string $source, float $value): bool
    {
        $column = self::SOURCE_COLUMNS[$source] ?? null;

        if ($column === null) {
            Logger::error('score_contributor_unknown_source', [
                'page_id' => $pageId,
                'source'  => $source,
            ]);
            return false;
        }

        if ($value < 0) {
            Logger::error('score_contributor_negative_value', [
                'page_id' => $pageId,
                'source'  => $source,
                'value'   => $value,
            ]);
            return false;
        }

        // Wrap in a transaction with FOR UPDATE lock to prevent phantom
        // read/write anomalies when a concurrent vote delta is being
        // applied to the same page's score row. Without this, a vote
        // delta and a bonus write can overwrite each other's total_score.
        //
        // SECURITY: createIfNotExists() runs FIRST so a bonus applied before
        // any vote/endorsement lands no longer vanishes (applyBonusColumn
        // with 0 rows affected previously returned success silently).
        // Audit HIGH-9: bonus writes bypass the per-day score velocity cap
        // that protects vote-driven deltas.  Apply the same cap to bonus
        // deltas so a fraudulent on-chain signal accepted by
        // bcc-trust's Onchain domain cannot push onchain_bonus from 0→N in a
        // single day without constraint.  The absolute bonus value is
        // also clamped to BCC_TRUST_MAX_BONUS_VALUE as a hard ceiling.
        $maxBonus = defined('BCC_TRUST_MAX_BONUS_VALUE')
            ? (float) BCC_TRUST_MAX_BONUS_VALUE
            : 20.0;
        $value = max(0.0, min($maxBonus, (float) $value));

        $result = \BCC\Trust\Core\Security\TransactionManager::run(function () use ($pageId, $column, $value): bool {
            // Ensure a score row exists before attempting the UPDATE.
            // Without this, a new owner's first onchain bonus is lost
            // (UPDATE affects 0 rows and is treated as success by legacy callers).
            try {
                $this->scoreRepository->createIfNotExists($pageId);
            } catch (\Throwable $e) {
                Logger::error('score_contributor_create_failed', [
                    'page_id' => $pageId,
                    'error'   => $e->getMessage(),
                ]);
                return false;
            }

            // Acquire row-level lock on all score rows for this page.
            // This serializes with concurrent applyVoteDeltaUpsert() and
            // recalculateScore() calls that also lock via FOR UPDATE.
            $this->scoreRepository->lockAllForPage($pageId);

            // Compute the bonus delta under the row lock and pass it
            // through applyVelocityCap so bonus score changes share the
            // per-day BCC_TRUST_MAX_SCORE_CHANGE_PER_DAY budget with
            // vote-driven deltas.  When the delta is <= 0 (bonus going
            // down) we bypass the cap — decreasing a score never needs
            // velocity protection.
            // Only the 'onchain' source is currently defined; see
             // SOURCE_COLUMNS above. If a second source is ever added we will
             // need to route to the corresponding getter on PageScore, but
             // at present $column is always 'onchain_bonus' — a ternary on
             // $column would be dead code PHPStan rightly flags.
            $existing = $this->scoreRepository->getByPageId($pageId);
            $currentBonus = $existing ? (float) $existing->getOnchainBonus() : 0.0;

            $delta = $value - $currentBonus;
            if ($delta > 0.0) {
                // applyVelocityCap operates on "weight points" where 1 wp = 2 score points.
                // Bonus writes already count as score points directly, so halve
                // before passing in to keep the budget math consistent with votes.
                $scale = \BCC\Trust\Core\Services\TrustScoreService::weightScoreScale();
                $capped = $this->scoreRepository->applyVelocityCap($pageId, $delta / $scale);
                $cappedDelta = $capped * $scale;
                if ($cappedDelta < $delta) {
                    // Cap was applied — write the capped value, not the requested one.
                    $value = $currentBonus + $cappedDelta;
                }
            }

            return $this->scoreRepository->applyBonusColumn($pageId, $column, $value);
        });

        if (!$result) {
            Logger::error('score_contributor_write_failed', [
                'page_id' => $pageId,
                'source'  => $source,
                'value'   => $value,
            ]);
            return false;
        }

        // Invalidate cached score AFTER the transaction commits so
        // subsequent reads see the new bonus, not a stale cached value.
        $this->scoreRepository->invalidateCache($pageId);

        // Notify read model sync that this page's score changed.
        // Without this, the read model stays stale until the next vote
        // or the daily full sync — bonuses applied by external plugins
        // (bcc-trust's Onchain domain) would not appear in the trust header.
        do_action('bcc.trust.recalculate_score', $pageId);

        return true;
    }
}
