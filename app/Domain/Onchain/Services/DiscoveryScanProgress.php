<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * DID THIS PASS SUCCEED, AND IS THE CHAIN SCAN FINISHED? — two questions,
 * two answers, never conflated again.
 *
 * ── THE DEFECT THIS EXISTS TO CLOSE (PR 7.2) ────────────────────────────
 * The 2026-09-04 Cosmos Hub canary produced a run that was, correctly,
 * `succeeded` / `partial = 0` / `pass_completed`, having spent 48 of 50
 * requests in 17 seconds and emitted 0 collections. Every one of those
 * values is TRUE of the pass. Read together they say "the scan finished
 * and found nothing".
 *
 * They were nowhere near that. The pass had drained the CODE LISTING —
 * all 737 code ids enumerated, cursor cleared, `cw_backfill_completed_at`
 * stamped, checkpoint `backfilled` — and had classified FIVE of those 737
 * families. 732 were still sitting at their creation-time default of
 * `inconclusive` with a NULL reason and a NULL `classified_at`, which
 * means "not looked at yet", not "looked at and undecided".
 *
 * ⚠ NOTHING WAS WRONG WITH THE ENGINE. The next administrator-requested
 * pass picks those 732 up through
 * {@see CosmwasmCodeFamilyRepository::findPendingClassification()} — the
 * incremental pass's stages (c)+(d) and the historical pass's Phase B both
 * drain the same queue. The defect was that no surface could TELL anyone
 * the work remained, and the words available to describe the run all
 * pointed at "done".
 *
 * ── THE TWO FACTS, KEPT APART ───────────────────────────────────────────
 *   PASS OUTCOME       did this one bounded run execute successfully?
 *                      Owned by the run row: status, partial, stop_reason.
 *                      Canary answer: YES. Unchanged by this class.
 *
 *   SCAN COMPLETENESS  has every discovered family reached a terminal
 *                      classification? DERIVED here, from the families
 *                      table and the checkpoint. Canary answer: NO.
 *
 * A successful pass never implies a complete scan. That is the whole
 * class.
 *
 * ── DERIVED, NEVER STORED ───────────────────────────────────────────────
 * There is no progress table and no counter column. Both would be a second
 * copy of a number the families table already holds, free to drift from the
 * queue the worker actually reads. `remaining` here comes from the SAME
 * predicate `findPendingClassification()` uses, so the figure an operator
 * sees is the figure the next pass will work through.
 *
 * ── AND IT FAILS CLOSED ─────────────────────────────────────────────────
 * Every read is the `OrThrow` variant. If a count cannot run, this returns
 * {@see UNKNOWN} — never zero-remaining, never complete. `0` is an answer,
 * and "the database did not answer" is not the same answer as "there is
 * nothing left".
 */
final class DiscoveryScanProgress
{
    /** Tri-state values. Deliberately strings — they reach the panel. */
    public const YES     = 'yes';
    public const NO      = 'no';
    public const UNKNOWN = 'unknown';

    /**
     * The progress of one chain's discovery, derived at read time.
     *
     * @return array{
     *     ok: bool,
     *     chain_id: int,
     *     enumeration_complete: string,
     *     total_families: int|null,
     *     classified_families: int|null,
     *     remaining_families: int|null,
     *     collection_families: int|null,
     *     scan_complete: string,
     *     more_work_available: string,
     *     reason: string
     * }
     */
    public static function forChain(int $chainId): array
    {
        $unknown = static function (string $reason) use ($chainId): array {
            return [
                'ok'                   => false,
                'chain_id'             => $chainId,
                'enumeration_complete' => self::UNKNOWN,
                'total_families'       => null,
                'classified_families'  => null,
                'remaining_families'   => null,
                'collection_families'  => null,
                'scan_complete'        => self::UNKNOWN,
                'more_work_available'  => self::UNKNOWN,
                'reason'               => $reason,
            ];
        };

        if ($chainId <= 0) {
            return $unknown('unknown_target');
        }

        // ── enumeration: has the code listing been drained? ─────────────
        //
        // ⚠ THIS IS ONE OF TWO CONDITIONS, NEVER THE ANSWER ON ITS OWN.
        // `cw_backfill_completed_at` says the code ids are all known. It
        // says nothing about whether any of them were examined, and the
        // canary is the proof: it was stamped while 732 families had never
        // been looked at.
        $checkpoint = ChainCheckpointRepository::get($chainId);
        $completedAt = $checkpoint !== null && isset($checkpoint->cw_backfill_completed_at)
            ? $checkpoint->cw_backfill_completed_at
            : null;

        $enumerationComplete = self::NO;
        if (is_string($completedAt) && trim($completedAt) !== '' && !str_starts_with($completedAt, '0000-00-00')) {
            $enumerationComplete = self::YES;
        }

        // A chain that has never been walked has no checkpoint row at all.
        // That is "not started", which is a definite NO, not an UNKNOWN —
        // it is exactly the state a fresh chain is in.

        // ── classification: how much of it has actually been examined? ──
        try {
            $total       = CosmwasmCodeFamilyRepository::countForChainOrThrow($chainId);
            $remaining   = CosmwasmCodeFamilyRepository::countPendingClassificationOrThrow(
                $chainId,
                CosmwasmClassifier::VERSION
            );
            $collections = CosmwasmCodeFamilyRepository::countCollectionFamiliesOrThrow($chainId);
        } catch (RepositoryReadFailure $e) {
            // ⚠ NOT zero, NOT complete. The read did not run, so the only
            // honest answer is that we do not know — and a surface that
            // cannot say how much is left must not conclude anything.
            return $unknown('progress_unavailable');
        }

        // `classified` is the complement of the queue, not a separate read:
        // deriving it keeps the three numbers reconciled by construction.
        $classified = max(0, $total - $remaining);

        // ── THE COMPLETION RULE ─────────────────────────────────────────
        //
        // BOTH conditions, proven. Neither alone, and never inferred from
        // the run row: not from `succeeded`, not from `partial = 0`, not
        // from `pass_completed`, not from zero collections emitted, not
        // from the checkpoint saying `backfilled`.
        $scanComplete = self::NO;
        if ($enumerationComplete === self::YES && $remaining === 0) {
            $scanComplete = self::YES;
        }

        return [
            'ok'                   => true,
            'chain_id'             => $chainId,
            'enumeration_complete' => $enumerationComplete,
            'total_families'       => $total,
            'classified_families'  => $classified,
            'remaining_families'   => $remaining,
            'collection_families'  => $collections,
            'scan_complete'        => $scanComplete,
            // More administrator-requested work is available exactly when
            // the queue is non-empty. This is what the Continue button is
            // offered on — never on "the last pass emitted nothing".
            'more_work_available'  => $remaining > 0 ? self::YES : self::NO,
            'reason'               => '',
        ];
    }

    /**
     * PURE. The operator sentence for a chain's progress.
     *
     * ── WHY THE WORDING LIVES WITH THE RULE ─────────────────────────────
     * The three sentences are not interchangeable and the difference between
     * them is the entire point of this PR. Keeping them beside the
     * completion rule means a future edit to the rule cannot leave the
     * wording behind — and it makes them testable without rendering a page.
     *
     * @param array<string, mixed> $progress a {@see forChain()} result
     */
    public static function summarySentence(array $progress): string
    {
        if (($progress['ok'] ?? false) !== true || ($progress['scan_complete'] ?? self::UNKNOWN) === self::UNKNOWN) {
            // ⚠ No completion conclusion, and no internal detail. The
            // operator learns the truth — we cannot tell — without an SQL
            // error, a provider body or an exception message.
            return __(
                'Scan progress is temporarily unavailable. No completion conclusion can be made.',
                'bcc-trust'
            );
        }

        $total     = (int) ($progress['total_families'] ?? 0);
        $checked   = (int) ($progress['classified_families'] ?? 0);
        $remaining = (int) ($progress['remaining_families'] ?? 0);
        $found     = (int) ($progress['collection_families'] ?? 0);

        if (($progress['scan_complete'] ?? self::NO) === self::YES) {
            if ($found > 0) {
                return sprintf(
                    /* translators: 1: families checked, 2: collection families found */
                    _n(
                        'Scan complete. All %1$s contract family was checked.',
                        'Scan complete. All %1$s contract families were checked.',
                        $total,
                        'bcc-trust'
                    ) . ' ' . sprintf(
                        _n('%2$s NFT collection family was confirmed.', '%2$s NFT collection families were confirmed.', $found, 'bcc-trust'),
                        number_format_i18n($total),
                        number_format_i18n($found)
                    ),
                    number_format_i18n($total),
                    number_format_i18n($found)
                );
            }

            // ⚠ THE ONLY PLACE A FINAL ZERO MAY BE SAID, and only because
            // `scan_complete` proved BOTH conditions above.
            return sprintf(
                /* translators: %s: number of contract families */
                __('Scan complete. All %s contract families were checked. No supported NFT collections were confirmed.', 'bcc-trust'),
                number_format_i18n($total)
            );
        }

        // ⚠ INCOMPLETE. "No NFT collections were confirmed IN THIS PASS" —
        // scoped to the pass, never to the chain. Saying the chain has none
        // while 732 families are unexamined is the sentence this class was
        // written to make impossible.
        return sprintf(
            /* translators: 1: families checked, 2: total families, 3: families remaining */
            __('Pass completed. Checked %1$s of %2$s contract families. No NFT collections were confirmed in this pass. %3$s families still need review.', 'bcc-trust'),
            number_format_i18n($checked),
            number_format_i18n($total),
            number_format_i18n($remaining)
        );
    }

    /**
     * PURE. The label for the action button.
     *
     * `Continue scan` when work remains — never `Start over`, which would
     * misdescribe what the next pass does: enumeration is not restarted,
     * and the pass resumes from the pending queue.
     *
     * @param array<string, mixed> $progress a {@see forChain()} result
     */
    public static function actionLabel(array $progress): string
    {
        if (($progress['ok'] ?? false) === true && ($progress['more_work_available'] ?? self::UNKNOWN) === self::YES) {
            return __('Continue scan', 'bcc-trust');
        }

        return __('Scan On-Chain for Easy Discovery', 'bcc-trust');
    }

    /**
     * PURE. The one-line explanation of what pressing it does.
     *
     * @param array<string, mixed> $progress a {@see forChain()} result
     */
    public static function actionHint(array $progress): string
    {
        if (($progress['ok'] ?? false) === true && ($progress['more_work_available'] ?? self::UNKNOWN) === self::YES) {
            return __('Each click runs one more bounded pass. It resumes where the last pass stopped.', 'bcc-trust');
        }

        return '';
    }
}
