<?php
/**
 * Validator Page Minter — system-creates peepso-page CPT posts for
 * chain-indexed validators that have no operator-claimed page yet.
 *
 * Why this exists
 * ───────────────
 * The chain validator indexer (ChainRefreshService::index_validators)
 * populates `bcc_onchain_validators` with every validator the LCD/RPC
 * exposes — 97 on Akash today, plus whatever any other configured chain
 * carries. None of those rows have a `wallet_link_id`, because nobody
 * has linked a wallet to them yet. Consequence:
 *
 *   - bcc_page_read_model is fed from peepso-page CPT posts, so the
 *     indexed validators never reach /directory?kind=validator.
 *   - /v/[slug] has no slug to resolve, so a card can't link anywhere.
 *   - CardViewService::resolveClaimTarget only fires for pages that
 *     already have a wallet_link → no claim CTA surfaces.
 *
 * This minter creates a system-owned placeholder peepso-page for each
 * indexed validator that doesn't have one yet, so the existing
 * directory + per-validator + claim infrastructure picks them up with
 * zero contract changes. The Card view-model's `claim_target` resolves
 * via a meta-based fallback (see ValidatorRepository::findFirstByPageId)
 * because there's no wallet_link to join through pre-claim.
 *
 * Post shape
 * ──────────
 *   post_type    = peepso-page
 *   post_status  = publish
 *   post_author  = 0  (no human owner until a real claim transfers it;
 *                       see PeepSoIntegration::onPageClaimed for the
 *                       handoff that fires from bcc_page_claimed)
 *   post_title   = moniker  (resync-able when the indexer sees a rename)
 *   post_name    = {moniker-sanitized}-{chain-slug}-{addr-tail-6}
 *                  (locked at creation; never recomputed — links must be
 *                   stable across moniker rebrands)
 *   post_content = ''
 *
 *   post_meta:
 *     _bcc_page_type            = 'validator'         (existing convention)
 *     _bcc_onchain_validator_id = <validators.id>     (the claim-target seam)
 *
 * Idempotency
 * ───────────
 * Every call to mintForValidator() checks
 * ValidatorRepository::findPlaceholderPageId() first. Re-running the
 * backfill or running the chain-refresh cron is a no-op once placeholders
 * exist. The single ON DUPLICATE / UNIQUE constraint isn't on the page
 * table itself — it's on the validator-id meta lookup — so collisions
 * across two simultaneous mint attempts for the same validator are
 * resolved by the post-mint dedupe pass (a second post with the same
 * meta would be deleted by a future cleanup, but in practice
 * AdvisoryLock + the 4h cron interval makes this an unreachable race).
 *
 * @package BCC\Trust\Onchain\Services
 * @since V1.6 (validator directory backfill, 2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorPageMinter
{
    /** Hard cap per invocation — protects the cron from a runaway insert loop. */
    private const MAX_PER_INVOCATION = 500;

    /** Batch size for the eligibility query. Bounded; the cron and CLI
     *  paginate until findMissingPlaceholderPage() returns empty.       */
    private const BATCH_SIZE = 100;

    /**
     * Mint placeholder pages for every validator missing one.
     *
     * @param int|null $chainId   Restrict to a single chain (by id), or null for all.
     * @param bool     $dryRun    When true, enumerates but does not write.
     * @param int      $maxToMint Hard cap (defaults to MAX_PER_INVOCATION).
     * @param list<int>|null $onlyValidatorIds null = no restriction (the manual
     *     CLI backfill); [] = MINT NONE, fails closed; a list = only these ids.
     * @param (callable(int|null, int, int, list<int>|null): list<object{
     *     id: int,
     *     operator_address: string,
     *     moniker: string|null,
     *     status: string,
     *     chain_id: int,
     *     chain_slug: string,
     *     chain_name: string
     * }>)|null $rowFetcher TEST SEAM ONLY — defaults to
     *     ValidatorRepository::findMissingPlaceholderPage(). Declared with the
     *     full row shape because a bare `callable` would erase it, and every
     *     $row-> access below depends on it; the shape is the same one
     *     mintForValidator() documents for its own parameter.
     *
     * @return array{
     *     scanned: int,
     *     minted: int,
     *     skipped: int,
     *     errors: int,
     *     minted_ids: list<int>,
     *     error_log: list<string>
     * }
     */
    public static function mintMissing(
        ?int $chainId = null,
        bool $dryRun = false,
        int $maxToMint = self::MAX_PER_INVOCATION,
        ?array $onlyValidatorIds = null,
        ?callable $rowFetcher = null
    ): array {
        // ⭐ $rowFetcher is a TEST SEAM, mirroring
        // ChainRefreshService::mintForHealthyChains(?callable $minter).
        // Production passes nothing and gets the repository. It exists
        // because the offset-progress property below cannot be observed
        // from outside without controlling what each offset returns, and
        // a suite that cannot observe it is how the re-scan defect
        // survived.
        $rowFetcher ??= self::fetchCandidateRows(...);
        $maxToMint = max(0, min($maxToMint, self::MAX_PER_INVOCATION));

        // ⚠ THE THREE STATES OF $onlyValidatorIds ARE NOT INTERCHANGEABLE.
        //
        //   null — no restriction WITHIN $chainId. Used by the manual CLI
        //          backfill, and — since 2026-11-04 — by the automatic
        //          indexer for a chain that proved healthy in that run
        //          (clean, non-partial fetch AND a fresh verified baseline).
        //          It is bounded: $chainId scopes the query, $maxToMint and
        //          the caller's MINT_SCAN_BUDGET scope the volume, and
        //          findMissingPlaceholderPage() already excludes rows that
        //          have a page, are claimed, or sit on a chain with no
        //          bcc_chains row.
        //   []   — MINT NONE, immediately. An allowlist that resolved to
        //          nothing must never widen back to 'every validator'; that is
        //          the difference between failing closed and failing open.
        //   list — exactly these validator ids may receive a page.
        //
        // ⭐ WHAT CHANGED, AND WHY. The automatic path used to pass the ids of
        // the addresses that chain's fetch returned IN THIS RUN, so a
        // validator the fetch did not contain could not get a page however
        // `status` labelled it. That made page existence a function of CURRENT
        // set membership, which is not what a page is for: a page is a
        // durable, claimable identity for a validator we have ever seen.
        //
        // The cost was measurable. Measured 2026-11-02: 149 of 693 staging
        // candidates were absent from their chain's latest complete fetch and
        // all 149 read 'active'. Measured 2026-11-04 on production: 90 rows
        // had no page, ZERO of them appeared in their chain's last clean
        // fetch, 51 were labelled `inactive` and 39 `active` — i.e. the
        // pageless set and the out-of-set set were the same population, and
        // `status` did not separate them.
        //
        // ⛔ The per-chain health gate is UNCHANGED and still lives in
        // ChainRefreshService: only a chain with a clean fetch and a fresh
        // verified baseline is offered to the minter at all, so a chain whose
        // provider is failing (no baseline, e.g. THORChain on 2026-11-04)
        // mints nothing and its rows are left untouched.
        if (is_array($onlyValidatorIds) && $onlyValidatorIds === []) {
            return [
                'scanned'    => 0,
                'minted'     => 0,
                'skipped'    => 0,
                'errors'     => 0,
                'minted_ids' => [],
                'error_log'  => [],
            ];
        }

        $result = [
            'scanned'    => 0,
            'minted'     => 0,
            'skipped'    => 0,
            'errors'     => 0,
            'minted_ids' => [],
            'error_log'  => [],
        ];

        if ($maxToMint === 0) {
            return $result;
        }

        $offset = 0;
        while ($result['scanned'] < $maxToMint) {
            $remaining = $maxToMint - $result['scanned'];
            $batchSize = (int) min(self::BATCH_SIZE, $remaining);

            $rows = $rowFetcher($chainId, $batchSize, $offset, $onlyValidatorIds);
            if (empty($rows)) {
                break;
            }

            // Counters as they stood BEFORE this batch, so the offset step
            // below can be computed from this batch alone rather than from
            // the run total.
            $erroredBefore = $result['errors'];
            $skippedBefore = $result['skipped'];

            foreach ($rows as $row) {
                $result['scanned']++;

                $existing = ValidatorRepository::findPlaceholderPageId($row->id);
                if ($existing > 0) {
                    $result['skipped']++;
                    continue;
                }

                if ($dryRun) {
                    $result['minted']++;
                    continue;
                }

                try {
                    $pageId = self::mintForValidator($row);
                } catch (\Throwable $e) {
                    $result['errors']++;
                    $result['error_log'][] = sprintf(
                        'validator_id=%d chain=%s addr=%s: %s',
                        $row->id,
                        $row->chain_slug,
                        $row->operator_address,
                        $e->getMessage()
                    );
                    continue;
                }

                if ($pageId <= 0) {
                    $result['errors']++;
                    $result['error_log'][] = sprintf(
                        'validator_id=%d chain=%s addr=%s: wp_insert_post returned 0',
                        $row->id,
                        $row->chain_slug,
                        $row->operator_address
                    );
                    continue;
                }

                $result['minted']++;
                $result['minted_ids'][] = $pageId;
            }

            // ── Offset step: move past the rows that STAYED ─────────────
            //
            // The findMissingPlaceholderPage() result shrinks as we mint, so
            // a minted row needs no offset step: it stops matching the query
            // and the next call at the same offset sees the next slice.
            //
            // ⚠⚠ BUT A ROW THAT DID NOT MINT DOES NOT LEAVE THE RESULT SET,
            // and the earlier version of this block stepped for neither.
            // An errored row keeps no page, so it still matches; a skipped
            // row already had one but is still returned when the two
            // queries disagree. With the offset pinned at 0 the next
            // iteration re-read exactly those rows, and `scanned` climbed
            // without any new row being examined — so ONE permanently
            // unmintable validator consumed all of maxToMint.
            //
            // ⛔ WHY THAT WAS NOT MERELY WASTE. ChainRefreshService decrements
            // its cross-chain MINT_SCAN_BUDGET by `scanned` and `break`s at
            // zero, and it iterates chains in a stable order. So the chain
            // holding the bad row spent the entire 500-scan budget on every
            // run, and every chain after it in that order minted nothing —
            // not once, but on every run, with no self-healing.
            //
            // Stepping by errors+skips keeps the shrink property for minted
            // rows while making a non-minting row cost ONE scan per run
            // instead of up to maxToMint. It also makes `scanned` mean
            // "distinct rows examined", which is what the budget arithmetic
            // in ChainRefreshService already assumes it means.
            //
            // On dry-run nothing is written, so no row leaves the set and the
            // step is the whole batch.
            $offset += self::offsetStepFor(
                $dryRun,
                $batchSize,
                $result['errors'] - $erroredBefore,
                $result['skipped'] - $skippedBefore
            );

            if (count($rows) < $batchSize) {
                break;
            }
        }

        return $result;
    }

    /**
     * The production candidate query: the default behind $rowFetcher.
     *
     * ⚠ A separate method rather than an inline closure because a closure
     * typed `?array $only` is WIDER than
     * findMissingPlaceholderPage()'s `list<int>|null`, which PHPStan level
     * 8 rejects — correctly. The narrowing belongs in a docblock on a real
     * method, not in a cast at the call site.
     *
     * @param list<int>|null $onlyValidatorIds
     * @return list<object{
     *     id: int,
     *     operator_address: string,
     *     moniker: string|null,
     *     status: string,
     *     chain_id: int,
     *     chain_slug: string,
     *     chain_name: string
     * }>
     */
    private static function fetchCandidateRows(
        ?int $chainId,
        int $limit,
        int $offset,
        ?array $onlyValidatorIds
    ): array {
        return ValidatorRepository::findMissingPlaceholderPage(
            $chainId,
            $limit,
            $offset,
            $onlyValidatorIds
        );
    }

    /**
     * How far the scan window must move after one batch.
     *
     * Pure, and public so it is asserted BY VALUE rather than by reading
     * the call site — the defect it fixes was a missing branch, and a
     * source-spelling assertion is exactly what would have stayed green
     * while the branch was absent.
     *
     * A minted row leaves the result set on its own, so it needs no step.
     * A row that errored or was skipped does NOT leave it, so the window
     * must step past precisely those or the next query returns them again.
     * On a dry run nothing is written at all, so no row leaves and the
     * step is the whole batch.
     */
    public static function offsetStepFor(
        bool $dryRun,
        int $batchSize,
        int $erroredInBatch,
        int $skippedInBatch
    ): int {
        if ($dryRun) {
            return max(0, $batchSize);
        }

        return max(0, $erroredInBatch) + max(0, $skippedInBatch);
    }

    /**
     * Mint a single placeholder page for a validator row.
     *
     * @param object{
     *     id: int,
     *     operator_address: string,
     *     moniker: string|null,
     *     status: string,
     *     chain_id: int,
     *     chain_slug: string,
     *     chain_name: string
     * } $row
     *
     * @return int The new page id, or 0 on insert failure.
     */
    public static function mintForValidator(object $row): int
    {
        $moniker = trim((string) ($row->moniker ?? ''));
        $title   = $moniker !== '' ? $moniker : $row->operator_address;
        $slug    = self::buildSlug($moniker, $row->chain_slug, $row->operator_address);

        $insert = wp_insert_post([
            'post_type'    => 'peepso-page',
            'post_status'  => 'publish',
            'post_author'  => 0,
            'post_title'   => sanitize_text_field($title),
            'post_name'    => $slug,
            'post_content' => '',
            'post_excerpt' => '',
            'meta_input'   => [
                '_bcc_page_type'            => PageTypeMap::KIND_TO_PAGE_TYPE['validator'],
                '_bcc_onchain_validator_id' => (string) $row->id,
            ],
        ], true);

        if (is_wp_error($insert) || (int) $insert <= 0) {
            $msg = is_wp_error($insert) ? $insert->get_error_message() : 'unknown';
            throw new \RuntimeException('wp_insert_post failed: ' . $msg);
        }

        $pageId = (int) $insert;

        // ⚠ NO SCORE ROW IS SEEDED HERE, DELIBERATELY.
        //
        // A placeholder is UNOWNED by design — `post_author => 0` above,
        // because it exists to carry the WANTED claim CTA until an operator
        // claims it. `bcc_trust_page_scores` is an OWNER-KEYED table, so
        // `ScoreRepository::createIfNotExists()` resolves the owner and
        // THROWS 'No owner ID found for page N' when there is none. That
        // refusal is the designed behaviour, not an obstacle: an ownerless
        // score row would be meaningless, and production holds zero rows
        // with `page_owner_id = 0`.
        //
        // Nothing here needs one. `PageReadModelRepository::syncPage()`
        // treats a null score row as 'no score yet' and projects the neutral
        // default (it fails closed only on a read that did not RUN), which
        // is why all 2,920 production `peepso-page` rows render correctly
        // with no score row at all. The row is created later, with a REAL
        // owner: by `PeepSoIntegration::onPageCreated()` for a user-created
        // page, or lazily by `VoteService` on the first vote.
        //
        // ⚠ This previously called `createDefault($pageId, 0)` inside a
        // try/catch that swallowed the guaranteed throw and logged it at
        // ERROR — one production error line per minted page (76 of them in
        // the 2026-11-02 Cosmos backfill), for an operation that could never
        // succeed. The command still reported `errors=0`, because the catch
        // kept it out of the result counter. Pinned by
        // ValidatorPlaceholderScoreTest.

        // Project into the denormalized read model so the new page shows up
        // in /directory?kind=validator immediately (rather than waiting for
        // the next daily full sync). Same call PeepSo's own page-create
        // path makes after peepso_page_after_create.
        try {
            Plugin::instance()->pageReadModelRepository()->syncPage($pageId);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[ValidatorPageMinter] syncPage failed', [
                'page_id'      => $pageId,
                'validator_id' => $row->id,
                'error'        => $e->getMessage(),
            ]);
        }

        return $pageId;
    }

    /**
     * Build the canonical slug for a validator placeholder page.
     *
     * Format: {moniker-sanitized}-{chain-slug}-{addr-tail-6}
     *
     * Falls back to {chain-slug}-validator-{addr-tail-6} when the
     * moniker is missing or sanitizes to an empty string (rare; some
     * chains return blank monikers for jailed validators).
     *
     * The addr-tail-6 suffix guarantees uniqueness even when two
     * validators on the same chain share a moniker (which the
     * Cosmos staking module permits).
     */
    public static function buildSlug(string $moniker, string $chainSlug, string $operatorAddress): string
    {
        $tail = strtolower((string) substr($operatorAddress, -6));
        $tail = preg_replace('/[^a-z0-9]/', '', $tail) ?? '';
        if ($tail === '') {
            // Pathological address (shouldn't happen — bech32 is alnum) —
            // fall back to a CRC tag so the slug stays unique.
            $tail = strtolower(dechex(crc32($operatorAddress) & 0xFFFFFF));
        }

        $monikerSlug = sanitize_title($moniker);
        $chainPart   = sanitize_title($chainSlug);
        if ($chainPart === '') {
            $chainPart = 'chain';
        }

        if ($monikerSlug === '') {
            return $chainPart . '-validator-' . $tail;
        }

        return $monikerSlug . '-' . $chainPart . '-' . $tail;
    }
}
