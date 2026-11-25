<?php
/**
 * One-shots: point a validator feed's chain row off a retired seeded endpoint.
 *
 * WHY
 * ---
 * The seed (schema-chains.php) is `INSERT IGNORE`, so changing it never
 * reaches an existing row. Two chains may still hold a value an earlier seed
 * wrote, and neither index has run since:
 *
 *  - THORChain: thornode.ninerealms.com (no longer resolves) or
 *    thornode.thorchain.liquify.com (expired certificate; Liquify moved to
 *    its gateway). Every /thorchain/nodes call fails.
 *  - Polkadot: polkadot.api.subscan.io, the relay chain. Staking moved to
 *    Asset Hub, so the relay API answers the validator list with count 0.
 *
 * WHAT IT TOUCHES
 * ---------------
 * Only the named chain's row, and only when its `rest_url` is EXACTLY one of
 * that fetcher's RETIRED_REST_URLS. It moves to the fetcher's
 * DEFAULT_REST_URL through
 * {@see \BCC\Trust\Onchain\Repositories\ChainRepository::updateRestUrl()},
 * a byte-exact compare-and-swap that also binds the slug and is_active. Any
 * other value (an operator's choice, the live default, NULL, a variant with
 * a trailing slash or space) is never touched.
 *
 * OUTCOMES (migration-runner.php)
 * -------------------------------
 *  - complete    no such row; a value that is not a retired seed; or the
 *                row moved
 *  - more        the row changed between the read and the swap: re-read
 *                on the next request
 *  - incomplete  the read failed, or the write errored: retried after the
 *                runner's backoff, never marked done on an outage
 *
 * @package BCC_Trust
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_repoint_seeded_endpoint')) {
    /**
     * The guarded repoint both one-shots share.
     *
     * @param list<string> $retired the exact seeded values that may move
     */
    function bcc_trust_repoint_seeded_endpoint(string $slug, string $chainType, array $retired, string $live): string
    {
        try {
            $chain = \BCC\Trust\Onchain\Repositories\ChainRepository::findBySlugAnyStateStrict($slug);
        } catch (\BCC\Trust\Onchain\Repositories\RepositoryReadFailure $e) {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        if ($chain === null || (string) $chain->chain_type !== $chainType) {
            return BCC_TRUST_MIGRATION_COMPLETE; // nothing of ours to repoint
        }

        $current = $chain->rest_url;
        if (!is_string($current) || !in_array($current, $retired, true)) {
            return BCC_TRUST_MIGRATION_COMPLETE; // an operator's choice, or already live: never touched
        }

        $moved = \BCC\Trust\Onchain\Repositories\ChainRepository::updateRestUrl(
            (int) $chain->id,
            $live,
            ['rest_url' => $current, 'slug' => $slug, 'is_active' => (int) $chain->is_active]
        );

        if ($moved === 1) {
            \BCC\Core\Log\Logger::info('[bcc-trust] Chain moved off a retired seeded endpoint to the live default', [
                'chain'    => $slug,
                'chain_id' => (int) $chain->id,
            ]);

            return BCC_TRUST_MIGRATION_COMPLETE;
        }

        // 0: the row changed under us (an operator edit) — re-read next time.
        return $moved === 0 ? BCC_TRUST_MIGRATION_MORE : BCC_TRUST_MIGRATION_INCOMPLETE;
    }
}

if (!function_exists('bcc_trust_repoint_thorchain_endpoint')) {
    function bcc_trust_repoint_thorchain_endpoint(): string
    {
        $fetcher = \BCC\Trust\Onchain\Fetchers\ThorchainFetcher::class;

        return bcc_trust_repoint_seeded_endpoint('thorchain', 'thorchain', $fetcher::RETIRED_REST_URLS, $fetcher::DEFAULT_REST_URL);
    }
}

if (!function_exists('bcc_trust_repoint_polkadot_endpoint')) {
    function bcc_trust_repoint_polkadot_endpoint(): string
    {
        $fetcher = \BCC\Trust\Onchain\Fetchers\PolkadotFetcher::class;

        return bcc_trust_repoint_seeded_endpoint('polkadot', 'polkadot', $fetcher::RETIRED_REST_URLS, $fetcher::DEFAULT_REST_URL);
    }
}
