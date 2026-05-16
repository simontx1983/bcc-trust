<?php
/**
 * BackfillValidatorPagesCommand — one-shot WP-CLI helper that mints the
 * peepso-page placeholder posts for every chain-indexed validator that
 * doesn't have one yet, so /directory?kind=validator surfaces them with
 * the existing WANTED claim CTA.
 *
 * Examples:
 *
 *   # Dry-run across every active chain — prints what WOULD be minted.
 *   wp bcc-trust validators backfill-pages --dry-run
 *
 *   # Live run, all chains.
 *   wp bcc-trust validators backfill-pages
 *
 *   # Scoped to a single chain.
 *   wp bcc-trust validators backfill-pages --chain=akash
 *
 *   # Cap per-invocation work (default 500).
 *   wp bcc-trust validators backfill-pages --max=100
 *
 * The minter is also wired into the chain-refresh cron tail so
 * newly-indexed validators get pages without a manual CLI run. This
 * command exists for the first-time backfill and for operator-driven
 * re-runs (e.g., after a new chain is enabled).
 *
 * @package BCC\Trust\Onchain\CLI
 * @since V1.6 (validator directory backfill, 2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\CLI;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Services\ValidatorPageMinter;
use WP_CLI;

if (!defined('ABSPATH')) {
    exit;
}

final class BackfillValidatorPagesCommand
{
    /**
     * Mint placeholder peepso-page posts for chain-indexed validators
     * that have no operator-claimed page yet.
     *
     * ## OPTIONS
     *
     * [--chain=<slug>]
     * : Restrict to one chain (e.g. `akash`). Default: all active chains.
     *
     * [--dry-run]
     * : Enumerate eligible validators and print the slugs that WOULD be
     *   created; make no DB writes.
     *
     * [--max=<n>]
     * : Hard cap for the number of pages minted in this invocation.
     *   Default 500 (matches ValidatorPageMinter's safety ceiling).
     *
     * ## EXAMPLES
     *
     *   wp bcc-trust validators backfill-pages --dry-run
     *   wp bcc-trust validators backfill-pages --chain=akash
     *   wp bcc-trust validators backfill-pages --max=100
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc_args
     */
    public function backfill_pages(array $args, array $assoc_args): void
    {
        unset($args);

        $chainSlug = isset($assoc_args['chain']) ? trim((string) $assoc_args['chain']) : '';
        $dryRun    = isset($assoc_args['dry-run']);
        $maxRaw    = isset($assoc_args['max']) ? (int) $assoc_args['max'] : 500;
        $max       = max(0, min($maxRaw, 500));

        $chainId = null;
        $chainLabel = 'all active chains';
        if ($chainSlug !== '') {
            $resolved = ChainRepository::getBySlug($chainSlug);
            if ($resolved === null) {
                WP_CLI::error(sprintf(
                    'Unknown chain slug "%s". Use `wp bcc-trust validators list-chains` or check bcc_onchain_chains.',
                    $chainSlug
                ));
                return;
            }
            $chainId    = (int) $resolved->id;
            $chainLabel = sprintf('chain=%s (id=%d)', $resolved->slug, $chainId);
        }

        $eligible = ValidatorRepository::countMissingPlaceholderPage($chainId);

        WP_CLI::line('');
        WP_CLI::line(sprintf(
            'Validator placeholder-page backfill: %s, eligible=%d, max=%d, mode=%s',
            $chainLabel,
            $eligible,
            $max,
            $dryRun ? 'DRY-RUN' : 'LIVE'
        ));
        WP_CLI::line('');

        if ($eligible === 0) {
            WP_CLI::success('No validators need a placeholder page. Nothing to do.');
            return;
        }

        if ($max === 0) {
            WP_CLI::warning('--max=0; refusing to do anything. Pass a positive --max.');
            return;
        }

        $result = ValidatorPageMinter::mintMissing($chainId, $dryRun, $max);

        if ($dryRun) {
            WP_CLI::line(sprintf(
                'DRY-RUN summary: scanned=%d, would_mint=%d, already_present=%d, errors=%d',
                $result['scanned'],
                $result['minted'],
                $result['skipped'],
                $result['errors']
            ));
        } else {
            WP_CLI::line(sprintf(
                'LIVE summary: scanned=%d, minted=%d, already_present=%d, errors=%d',
                $result['scanned'],
                $result['minted'],
                $result['skipped'],
                $result['errors']
            ));
        }

        if (!empty($result['error_log'])) {
            WP_CLI::line('');
            WP_CLI::warning('Errors encountered:');
            foreach ($result['error_log'] as $err) {
                WP_CLI::line('  • ' . $err);
            }
        }

        if (!$dryRun && $result['errors'] > 0) {
            WP_CLI::error(sprintf(
                'Completed with %d error(s). See messages above. Partial progress preserved — re-run to retry the failed rows.',
                $result['errors']
            ), false);
            return;
        }

        WP_CLI::success($dryRun
            ? 'Dry-run complete — no DB writes performed.'
            : 'Backfill complete.'
        );
    }
}
