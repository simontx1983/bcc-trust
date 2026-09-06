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
     *     eligible_now: int|null,
     *     delayed_families: int|null,
     *     exhausted_families: int|null,
     *     negative_families: int|null,
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
                'eligible_now'         => null,
                'delayed_families'     => null,
                'exhausted_families'   => null,
                'negative_families'    => null,
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

            // ── PR 7.3: what a chunk could actually claim right now ─────
            //
            // ⚠ `remaining` and `eligibleNow` are NOT the same number, and a
            // session that used the first would busy-loop. A family whose
            // last fetch failed is still remaining but carries a future
            // `next_attempt_at`. Both are read here so the panel and the
            // session decision come from one place.
            $eligibleNow = CosmwasmCodeFamilyRepository::countEligibleNowOrThrow(
                $chainId,
                CosmwasmClassifier::VERSION
            );
            $exhausted = CosmwasmCodeFamilyRepository::countRetryExhaustedOrThrow($chainId);

            // ⚠ THE FIVE OUTCOMES MUST STAY DISTINCT IN THE READ MODEL, and
            // the classification column alone cannot keep them apart:
            //
            //   confirmed / probable CW-721  → collection_families
            //   confirmed NEGATIVE           → negative_families  (terminal)
            //   temporarily delayed          → delayed_families   (backoff)
            //   retry-exhausted UNRESOLVED   → exhausted_families (no answer)
            //   unreadable                   → ok = false / UNKNOWN
            //
            // `not_cw721` and a six-times-unreachable family are stored the
            // same way apart from `retry_count`. If this read model does not
            // separate them, nothing downstream can.
            $negative = CosmwasmCodeFamilyRepository::countNegativeFamiliesOrThrow($chainId);
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
        // ⚠ THREE conditions since PR 7.3, not two. `remaining` counts what a
        // pass could CLAIM, and its predicate excludes `retry_count >=
        // MAX_RETRIES` — so a family that gave up after six attempts leaves
        // the queue WITHOUT ever being resolved. With only such families
        // left, `remaining` reached 0, `scan_complete` said YES, and the
        // panel said:
        //
        //   "Scan complete. All 10 contract families were checked.
        //    No supported NFT collections were confirmed."
        //
        // Both halves false. It was checked six times and we still do not
        // know, which is not the same as knowing there is nothing there.
        // Completion means every family reached a RESOLUTION.
        $scanComplete = self::NO;
        if ($enumerationComplete === self::YES && $remaining === 0 && $exhausted === 0) {
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
            // What the NEXT chunk could claim without waiting.
            'eligible_now'         => $eligibleNow,
            // Remaining, but not claimable yet — a failed fetch's backoff.
            // ⚠ Never reported as complete and never as a negative verdict.
            'delayed_families'     => max(0, $remaining - $eligibleNow),
            // Remaining forever unless an operator requeues or the classifier
            // version moves. "We could not find out", NOT "not an NFT".
            'exhausted_families'   => $exhausted,
            // A terminal NEGATIVE — a real verdict, unlike an exhausted family.
            'negative_families'    => $negative,
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
     * ── ⚠ TWO SOURCES, BECAUSE THEY ARE TWO DIFFERENT FACTS (PR 7.4) ────
     * `$progress` is derived from the FAMILY QUEUE and describes the CHAIN:
     * how much has been classified, how much is confirmed, how much is left.
     * It knows nothing about which session did the work, and it never will —
     * a chain total cannot be attributed to one run.
     *
     * `$sessionEmitted` is the run ledger's cumulative `collections_emitted`
     * for the session being described. It is the only number here that is
     * scoped to a session, and it must be PASSED IN, because this method
     * queries nothing.
     *
     * ⚠ NULL IS NOT ZERO. Null means "no session to speak about, or its
     * totals are unknown" — the sentence then says nothing about a session
     * rather than asserting it added none. Callers must not coalesce.
     *
     * @param array<string, mixed> $progress       a {@see forChain()} result
     * @param int|null             $sessionEmitted cumulative collection rows
     *                                             emitted by the session being
     *                                             described, or null if unknown
     */
    public static function summarySentence(array $progress, ?int $sessionEmitted = null): string
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
                // ⚠ The session prefix belongs on the COMPLETE branch too.
                // "All 742 families were checked, 5 confirmed" is a statement
                // about the chain across every session that ever ran; without
                // the prefix an operator cannot tell whether the click they
                // just made contributed any of it.
                return self::addedSentence($sessionEmitted) . sprintf(
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
            // `scan_complete` proved ALL THREE conditions above.
            // ⚠ `$found === 0` here, so `addedSentence()` can only ever
            // contribute the "added no new collection record" form — a row
            // cannot be emitted from a family that was never confirmed.
            return self::addedSentence($sessionEmitted) . sprintf(
                /* translators: %s: number of contract families */
                __('Scan complete. All %s contract families were checked. No supported NFT collections were confirmed.', 'bcc-trust'),
                number_format_i18n($total)
            );
        }

        // ── ⚠ FINISHED, BUT NOT COMPLETE (PR 7.3) ───────────────────────
        //
        // Everything that CAN be attempted has been, and some families still
        // have no answer. This is a real, common resting state — a node that
        // was unreachable six times over four weeks leaves exactly this —
        // and it is neither of the other two sentences:
        //
        //   NOT "Scan complete … no supported NFT collections were
        //       confirmed", which claims a final answer we do not have;
        //   NOT "N families still need review", which invites a Continue
        //       that would find nothing to claim.
        //
        // So it says what is true: the work stopped, and N families are
        // UNRESOLVED. `unresolved` is the word on purpose — an unreachable
        // family is not a negative verdict, and `not_cw721` is terminal.
        $exhausted = (int) ($progress['exhausted_families'] ?? 0);
        $eligible  = (int) ($progress['eligible_now'] ?? 0);
        $delayed   = (int) ($progress['delayed_families'] ?? 0);

        if ($exhausted > 0 && $eligible === 0 && $delayed === 0) {
            // ⚠ The session's own result belongs here too (PR 7.4). A session
            // that emitted two collections and then ran out of resolvable
            // families used to have that erased by the unresolved sentence.
            return self::addedSentence($sessionEmitted) . sprintf(
                /* translators: 1: families checked, 2: total families, 3: unresolved families */
                _n(
                    'Scan session finished. Checked %1$s of %2$s contract families. %3$s family could not be resolved and is still unknown — that is not a result of "no NFT collection".',
                    'Scan session finished. Checked %1$s of %2$s contract families. %3$s families could not be resolved and are still unknown — that is not a result of "no NFT collection".',
                    $exhausted,
                    'bcc-trust'
                ),
                number_format_i18n($checked),
                number_format_i18n($total),
                number_format_i18n($exhausted)
            );
        }

        // ── ⚠ INCOMPLETE, AND THE THREE FACTS ARE DIFFERENT FACTS ───────
        //
        // This branch used to end with a hardcoded "No NFT collections were
        // confirmed in this pass." It was true for as long as discovery
        // found nothing, and became a contradiction the moment it worked:
        // on 2026-09-06 it printed beside "Found 2 new collection(s)" and
        // "5 NFT collection families confirmed so far".
        //
        // Three separate numbers, never collapsed:
        //
        //   $sessionEmitted — collection ROWS this session added, from the
        //                     run ledger's cumulative `collections_emitted`;
        //   $found          — collection FAMILIES confirmed overall, derived
        //                     from the chain's own classification state;
        //   $remaining      — families still to review.
        //
        // ⚠ A CONFIRMED FAMILY IS NOT AN EMITTED ROW. The live session
        // confirmed FIVE families and emitted TWO rows, because emission is
        // its own bounded stage. Calling all five "saved collections" would
        // be exactly the overstatement this method exists to prevent.
        $tail = sprintf(
            /* translators: 1: families checked, 2: total families, 3: families remaining */
            __('Checked %1$s of %2$s contract families; %3$s still need review.', 'bcc-trust'),
            number_format_i18n($checked),
            number_format_i18n($total),
            number_format_i18n($remaining)
        );

        // (a) The chain has confirmed families. Report the overall figure —
        //     preceded, when we know it, by what THIS session contributed.
        //     ⚠ Both, never one: an operator reading "5 confirmed" needs to
        //     know their click produced 2 of them, and an operator reading
        //     "2 added" needs to know the chain now stands at 5.
        if ($found > 0) {
            return self::addedSentence($sessionEmitted) . sprintf(
                /* translators: %s: collection families confirmed overall */
                _n(
                    'Overall, %s NFT collection family is confirmed so far.',
                    'Overall, %s NFT collection families are confirmed so far.',
                    $found,
                    'bcc-trust'
                ),
                number_format_i18n($found)
            ) . ' ' . $tail;
        }

        // (b) The session ran, added nothing, and nothing is confirmed on the
        //     chain either.
        //     ⚠ SCOPED TO THE SESSION, AND ONLY THE SESSION. "This session
        //     did not confirm" is the strongest true statement available: the
        //     tail immediately says how many families were never examined, so
        //     nothing here can be read as "this chain has no NFT collections".
        //     One sentence, not two — `addedSentence()`'s zero form would say
        //     the same thing twice.
        if ($sessionEmitted === 0) {
            return __('This session did not confirm a new NFT collection.', 'bcc-trust') . ' ' . $tail;
        }

        // (c) No session to speak for — or, vanishingly rarely, a session that
        //     emitted rows whose families are no longer classified as
        //     collections (a reclassification after emission). Report the
        //     chain honestly and never on the session's behalf.
        return self::addedSentence($sessionEmitted)
            . __('No NFT collection family is confirmed on this chain yet.', 'bcc-trust')
            . ' ' . $tail;
    }

    /**
     * PURE. The session-scoped half of the summary, or an empty string.
     *
     * ── ⚠ NULL, ZERO AND N ARE THREE DIFFERENT ANSWERS ──────────────────
     * null → we have no session totals to speak for, so the sentence says
     *        nothing about a session. Silence, not a claim of zero.
     * 0    → the session ran and added no collection record. Worth saying:
     *        it is the difference between "your click did nothing" and "we
     *        cannot tell you what your click did".
     * N    → the session added N records.
     *
     * ⚠ THIS COUNTS EMITTED ROWS, NOT CONFIRMED FAMILIES. The 2026-09-06
     * session confirmed 5 CW-721 families and emitted 2 collection records,
     * because emission is its own separately bounded stage. Wording that
     * blurs them would overstate what is actually stored.
     *
     * Returns with a trailing space when non-empty, so callers concatenate
     * without deciding about punctuation.
     */
    private static function addedSentence(?int $sessionEmitted): string
    {
        if ($sessionEmitted === null) {
            return '';
        }

        if ($sessionEmitted <= 0) {
            return __('This session added no new collection record.', 'bcc-trust') . ' ';
        }

        return sprintf(
            /* translators: %s: collection records added during this session */
            _n(
                'This session added %s new collection record.',
                'This session added %s new collection records.',
                $sessionEmitted,
                'bcc-trust'
            ),
            number_format_i18n($sessionEmitted)
        ) . ' ';
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
