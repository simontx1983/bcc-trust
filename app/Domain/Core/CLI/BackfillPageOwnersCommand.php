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
 * DRY RUN BY DEFAULT. Nothing is written without --apply.
 *
 * Candidates (PeepSoQueryRepository::claimedPagesMissingOwnerRow): a verified
 * operator/creator PAGE claim whose claimer is already the page's author and
 * has no owner row. The write is PeepSoIntegration::ensurePageOwnerMembership,
 * the exact path a claim takes — PeepSo's own member_join() + member_modify(
 * 'member_owner'), which also recounts the page's members. Idempotent:
 * a second run finds nothing.
 *
 *   wp bcc-trust pages backfill-owners              # dry run: list + count
 *   wp bcc-trust pages backfill-owners --apply      # write
 *   wp bcc-trust pages backfill-owners --apply --max=50
 *
 * @package BCC\Trust\Core\CLI
 */

declare(strict_types=1);

namespace BCC\Trust\Core\CLI;

use BCC\Trust\Core\Integration\PeepSoIntegration;
use BCC\Trust\Core\Repositories\PeepSoQueryRepository;
use WP_CLI;

if (!defined('ABSPATH')) {
    exit;
}

final class BackfillPageOwnersCommand
{
    private const BATCH = 100;

    /**
     * Record each claimed page's claimer as its PeepSo owner (F-299).
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Write the owner rows. Without it this is a dry run that lists the
     *   pages it would repair and writes nothing.
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

        $apply = isset($assoc_args['apply']);
        $max   = max(1, min(10000, isset($assoc_args['max']) ? (int) $assoc_args['max'] : 1000));

        WP_CLI::log($apply
            ? sprintf('Writing page-owner rows (up to %d pages)…', $max)
            : sprintf('DRY RUN — nothing will be written (up to %d pages). Re-run with --apply to write.', $max));

        $after   = 0;
        $seen    = 0;
        $written = 0;
        $failed  = 0;

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

                if (!$apply) {
                    WP_CLI::log(sprintf('  would set page %d owner → user %d', $row['page_id'], $row['user_id']));
                    continue;
                }

                if (PeepSoIntegration::ensurePageOwnerMembership($row['page_id'], $row['user_id'])) {
                    $written++;
                } else {
                    $failed++;
                    WP_CLI::warning(sprintf('  page %d (user %d): owner row NOT written — see the error log', $row['page_id'], $row['user_id']));
                }
            }
        }

        if (!$apply) {
            WP_CLI::success(sprintf('%d page(s) would be repaired.%s', $seen, $seen >= $max ? ' More may remain past --max.' : ''));
            return;
        }

        $summary = sprintf('%d owner row(s) written, %d failed, %d examined.', $written, $failed, $seen);
        if ($failed > 0) {
            WP_CLI::warning($summary . ' Re-run to retry the failures.');
            return;
        }
        WP_CLI::success($summary . ($seen >= $max ? ' More may remain past --max; re-run.' : ''));
    }
}
