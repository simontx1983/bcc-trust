<?php
/**
 * BackfillPageOwnersCommand — one-off WP-CLI repair for F-299.
 *
 * Until F-299 a verified page claim moved `post_author` and nothing else, so
 * no claimed page has a PeepSo `peepso_page_members` `member_owner` row for
 * its claimer, and every owner signal that reads that row (the /members
 * VALIDATORS filter and chip counts, owned-page counts on member cards) leaves
 * those operators out. New claims write the row; this repairs the claims that
 * came before.
 *
 * DRY RUN BY DEFAULT. Nothing is written without --apply (and `--no-apply` /
 * `--apply=0` are dry runs too — the flag is read through WP-CLI's own
 * get_flag_value(), never isset()).
 *
 * Candidates (PeepSoQueryRepository::claimedPagesMissingOwnerRow): a verified
 * operator/creator PAGE claim whose claimer is already the page's author and
 * has no owner row. Each is planned conservatively (plan()):
 *   - JOIN     — the claimer has no row on the page;
 *   - PROMOTE  — the claimer has a row (e.g. `member`); it is promoted;
 *   - SKIP     — the claimer is `banned` on the page, or the page already has
 *                a DIFFERENT `member_owner`. Listed for manual review, never
 *                written: a repair must not lift a ban or create a second owner.
 * The write is PeepSoIntegration::ensurePageOwnerMembership, the exact path a
 * claim takes. Every written pair is printed with its PRIOR state ("joined" /
 * "promoted from <role>"), which is the exact rollback recipe: delete a joined
 * row, set a promoted row back to its old role.
 *
 *   wp bcc-trust pages backfill-owners              # dry run: plan + counts
 *   wp bcc-trust pages backfill-owners --apply      # write
 *   wp bcc-trust pages backfill-owners --apply --max=50
 *
 * @package BCC\Trust\Core\CLI
 */

declare(strict_types=1);

namespace BCC\Trust\Core\CLI;

use BCC\Trust\Core\Integration\PeepSoIntegration;
use BCC\Trust\Core\Repositories\PeepSoQueryRepository;
use BCC\Trust\Core\Support\PageOwnerDecision;
use WP_CLI;

if (!defined('ABSPATH')) {
    exit;
}

final class BackfillPageOwnersCommand
{
    private const BATCH = 100;

    // The decision is PageOwnerDecision's, shared with the live claim path.
    public const ACTION_JOIN             = PageOwnerDecision::JOIN;
    public const ACTION_PROMOTE          = PageOwnerDecision::PROMOTE;
    public const ACTION_SKIP_BANNED      = PageOwnerDecision::SKIP_BANNED;
    public const ACTION_SKIP_OTHER_OWNER = PageOwnerDecision::SKIP_OTHER_OWNER;

    /**
     * Record each claimed page's claimer as its PeepSo owner (F-299).
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Write the owner rows. Without it this is a dry run that lists the
     *   plan and writes nothing.
     *
     * [--max=<n>]
     * : Most pages to examine in this run. Default 1000, capped at 10000.
     *
     * ## EXAMPLES
     *
     *   wp bcc-trust pages backfill-owners
     *   wp bcc-trust pages backfill-owners --apply
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc_args
     */
    public function backfill_owners(array $args, array $assoc_args): void
    {
        unset($args);

        $apply = self::wantsApply($assoc_args);
        $max   = max(1, min(10000, isset($assoc_args['max']) ? (int) $assoc_args['max'] : 1000));

        WP_CLI::log($apply
            ? sprintf('Writing page-owner rows (up to %d pages)…', $max)
            : sprintf('DRY RUN — nothing will be written (up to %d pages). Re-run with --apply to write.', $max));

        $after  = 0;
        $seen   = 0;
        $counts = ['written' => 0, 'failed' => 0, 'skipped' => 0, 'planned' => 0];

        while ($seen < $max) {
            $batch = PeepSoQueryRepository::claimedPagesMissingOwnerRow($after, min(self::BATCH, $max - $seen));
            if ($batch === null) {
                WP_CLI::error(sprintf(
                    'The candidate read failed after %d page(s) (is PeepSo Pages active?). Nothing past this point was examined.',
                    $seen
                ));
                return;
            }
            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $after = $row['page_id'];
                $seen++;
                $plan = self::plan($row);
                $pair = sprintf('page %d user %d', $row['page_id'], $row['user_id']);

                if ($plan['action'] === self::ACTION_SKIP_BANNED || $plan['action'] === self::ACTION_SKIP_OTHER_OWNER) {
                    $counts['skipped']++;
                    WP_CLI::log(sprintf('  SKIP %s — %s (manual review)', $pair, $plan['detail']));
                    continue;
                }

                if (!$apply) {
                    $counts['planned']++;
                    WP_CLI::log(sprintf('  PLAN %s — %s', $pair, $plan['detail']));
                    continue;
                }

                if (PeepSoIntegration::ensurePageOwnerMembership($row['page_id'], $row['user_id'])) {
                    $counts['written']++;
                    // ⚠ The rollback recipe: the PRIOR state of this exact pair.
                    WP_CLI::log(sprintf('  WROTE %s — %s', $pair, $plan['detail']));
                } else {
                    $counts['failed']++;
                    WP_CLI::warning(sprintf('  FAILED %s — owner row NOT written (%s); see the error log', $pair, $plan['detail']));
                }
            }
        }

        $more = $seen >= $max ? ' More may remain past --max; re-run.' : '';

        if (!$apply) {
            WP_CLI::success(sprintf('%d page(s) would be repaired, %d skipped for manual review.%s', $counts['planned'], $counts['skipped'], $more));
            return;
        }

        $summary = sprintf(
            '%d owner row(s) written, %d failed, %d skipped for manual review, %d examined.',
            $counts['written'],
            $counts['failed'],
            $counts['skipped'],
            $seen
        );
        if ($counts['failed'] > 0) {
            WP_CLI::warning($summary . ' Re-run to retry the failures.');
            return;
        }
        WP_CLI::success($summary . $more);
    }

    /**
     * `--apply` through WP-CLI's own flag reader: `--no-apply` arrives as
     * `apply => false` and `--apply=0` as `'0'`, both of which isset() would
     * have read as WRITE.
     *
     * @param array<string, mixed> $assocArgs
     */
    public static function wantsApply(array $assocArgs): bool
    {
        return (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'apply', false);
    }

    /**
     * Decide what to do with one candidate, conservatively, and say what the
     * pair looked like BEFORE (the rollback recipe).
     *
     * @param array{page_id: int, user_id: int, current_role: ?string, other_owner: int} $row
     * @return array{action: string, detail: string}
     */
    public static function plan(array $row): array
    {
        $action = PageOwnerDecision::decide($row['current_role'], $row['other_owner']);

        return match ($action) {
            PageOwnerDecision::SKIP_OTHER_OWNER => [
                'action' => self::ACTION_SKIP_OTHER_OWNER,
                'detail' => sprintf('page already has a different member_owner (user %d)', $row['other_owner']),
            ],
            PageOwnerDecision::SKIP_BANNED => [
                'action' => self::ACTION_SKIP_BANNED,
                'detail' => 'claimer is banned on this page',
            ],
            PageOwnerDecision::JOIN => [
                'action' => self::ACTION_JOIN,
                'detail' => 'joined (no prior row; rollback = delete the row)',
            ],
            // ALREADY_OWNER cannot reach here (the candidate query excludes
            // pairs that already have the owner row); treated like PROMOTE.
            default => [
                'action' => self::ACTION_PROMOTE,
                'detail' => sprintf('promoted from %s (rollback = set it back to %s)', (string) $row['current_role'], (string) $row['current_role']),
            ],
        };
    }
}
