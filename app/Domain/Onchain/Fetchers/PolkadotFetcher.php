<?php

namespace BCC\Trust\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Contracts\FetcherInterface;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\ProviderOutcomeReceipt;

/**
 * Polkadot Validator Fetcher
 *
 * Uses the Subscan REST API (free tier) to fetch validator data.
 * Endpoint: https://polkadot.api.subscan.io
 *
 * Subscan returns stake in Plancks (1 DOT = 1e10 Plancks).
 *
 * @phpstan-import-type ChainRow from ChainRepository
 */
class PolkadotFetcher implements FetcherInterface
{
    /**
     * The raw Subscan keys mapValidator() reads, minus the address it is
     * keyed by. Used ONLY to report whether a dropped duplicate carried
     * different values from the copy kept - never to decide the keep.
     *
     * ⚠ Keep this in step with mapValidator(). It is a diagnostic, so a
     * missing key under-reports divergence; it cannot change which row wins.
     */
    private const DUP_COMPARE_KEYS = [
        'bonded_total',
        'bonded_owner',
        'validator_prefs_value',
        'count_nominators',
        'validator_status',
        'is_active',
    ];

    /** @var ChainRow */
    private object $chain;
    private string $base_url;
    private int    $timeout = 20;

    /** @param ChainRow $chain */
    public function __construct(object $chain)
    {
        $this->chain    = $chain;
        $this->base_url = rtrim($chain->rest_url ?? 'https://polkadot.api.subscan.io', '/');
    }

    /** @return ChainRow */
    public function get_chain(): object
    {
        return $this->chain;
    }

    public function last_fetch_error(): ?string
    {
        // Stub — wire to actual apiGet error tracking when a real
        // transport-failure UX gap surfaces here.
        return null;
    }

    public function supports_feature(string $feature): bool
    {
        return $feature === 'validator';
    }

    /**
     * Fetch a single validator by stash address.
     *
     * @return array<string, mixed>
     */
    public function fetch_validator(string $address): array
    {
        $data = $this->apiPost('/api/scan/staking/validator', [
            'stash' => $address,
        ]);

        if (!$data || !isset($data['info'])) {
            return [];
        }

        return $this->mapValidator($data['info'], 0);
    }

    /** @return array<int, array{validator_address: string, shares?: string|null, amount?: float|null}> */
    public function fetch_delegations(string $delegatorAddress): array
    {
        return []; // Polkadot nominations discovery not implemented yet.
    }

    public function count_holdings(string $wallet, string $contract): ?int
    {
        return 0; // No NFT ownership model — definite "no holdings," not an outage.
    }

    /**
     * `complete: true` — no NFT ownership model on this chain, so the empty
     * list is definitional and contacts no provider. It is never an outage.
     *
     * @return array{items: list<array{contract_address: string, token_id: string, chain_id: int, collection_name: ?string, name: ?string, image_url: ?string, metadata_uri: ?string, token_standard: ?string}>, truncated: bool, cursor: ?string, complete: bool}
     */
    public function list_holdings(string $wallet, ?string $cursor = null): array
    {
        return ['items' => [], 'truncated' => false, 'cursor' => null, 'complete' => true];
    }

    /**
     * Fetch all active validators sorted by stake.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch_all_validators(?ProviderOutcomeReceipt $outcome = null): array
    {
        $validators = [];
        $page       = 0;
        $perPage    = 100;

        // Paginate through all validators (Subscan returns max 100 per page).
        while (true) {
            $data = $this->apiPost('/api/scan/staking/validators', [
                'order'      => 'desc',
                'order_field' => 'bonded_total',
                'row'        => $perPage,
                'page'       => $page,
            ], $outcome);

            $list = $data['list'] ?? [];
            if (empty($list)) {
                break;
            }

            foreach ($list as $v) {
                $validators[] = $v;
            }

            // Stop if we got fewer than requested (last page).
            if (count($list) < $perPage) {
                break;
            }

            $page++;

            // Safety cap — don't fetch more than 10 pages (1000 validators).
            if ($page >= 10) {
                break;
            }
        }

        if (empty($validators)) {
            return [];
        }

        // ⛔⛔ DE-DUPLICATE BEFORE RANKING, NOT AFTER.
        //
        // MEASURED on production: every successful run logged the identical
        // `Indexed Polkadot: 3000 total, 0 new, 2400 updated, 600 unchanged`
        // - 335 times - while the table held exactly 600 rows. `0 new` proves
        // all 3000 entries matched a row that already existed, so the batch
        // carried at most 600 distinct addresses and `total` was never a
        // validator count.
        //
        // The damage is still in the table: voting_power_rank runs to 2820
        // across 600 rows with only 269 distinct values. Rank is the array
        // INDEX (mapValidator's $rank + 1), so duplicates both inflate it
        // past the real set size and collide. ValidatorRepository's change
        // detection includes voting_power_rank, so each extra copy also
        // counted as an UPDATE - that is the whole 2400.
        //
        // Ranking a de-duplicated list is the fix. Nothing here widens the
        // set: de-duplication can only ever shrink a batch, and on a clean
        // response it is a no-op.
        $dedupe = self::dedupeByStash($validators);

        if ($dedupe['dropped'] > 0) {
            \BCC\Core\Log\Logger::warning(sprintf(
                '[Polkadot Fetcher] Dropped %d of %d fetched rows as duplicate'
                    . ' or addressless before ranking; %d unique validators remain.'
                    . ' %d dropped copies carried DIFFERENT values from the copy kept.',
                $dedupe['dropped'],
                count($validators),
                count($dedupe['rows']),
                $dedupe['divergent']
            ));
        }

        return $this->rankActive($dedupe['rows']);
    }

    /**
     * Sort by bonded stake, assign ranks, keep only active validators.
     *
     * ⚠⚠ THE ACTIVE FILTER RUNS AFTER RANKING, AND THAT IS UNCHANGED
     * HERE. A waiting validator therefore still consumes a rank position,
     * so ranks can have gaps whenever the response carries any. That is
     * pre-existing behaviour, it is pinned by a test so it cannot be
     * mistaken for fallout from this change, and it is deliberately NOT
     * fixed in this PR - doing so would renumber every stored rank.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function rankActive(array $rows): array
    {
        // Sort by bonded_total descending for rank assignment.
        usort($rows, function ($a, $b) {
            return bccomp(self::bondedTotal($b), self::bondedTotal($a));
        });

        $results = [];
        foreach ($rows as $rank => $v) {
            $mapped = $this->mapValidator($v, $rank);
            if ($mapped['status'] === 'active') {
                $results[] = $mapped;
            }
        }

        return $results;
    }

    /**
     * Collapse rows that share a stash address.
     *
     * ⛔⛔ THE KEEP RULE, AND WHY IT IS THIS ONE.
     *
     * Duplicate copies CANNOT be assumed identical. The observed
     * 600-unchanged / 2400-updated split is explained by the
     * position-derived rank alone, but the ranks left in the table are
     * not: five identical copies of one address would sort adjacently and
     * leave ranks at exact multiples of five up to 3000, whereas the table
     * holds 269 distinct values topping out at 2820. So at least some
     * copies differed in `bonded_total`, which is both a stored field and
     * the sort key. Which fields differ, and how often, is NOT
     * recoverable from retained data - hence $divergent below, which makes
     * the answer observable on the next successful run instead of leaving
     * it open.
     *
     * The rule, in two steps:
     *
     *   1. KEEP THE GREATEST `bonded_total`.
     *   2. On a TIE, keep the copy whose canonical JSON sorts first.
     *
     * Greatest-stake wins step 1 rather than first-seen because rank is
     * derived from `bonded_total`, so the kept copy and the rank it
     * receives agree with each other.
     *
     * ⛔⛔ STEP 2 REPLACES "a tie keeps the earliest occurrence", AND THE
     * REASON IS MEASURED. Keeping the earliest made the result depend on
     * input order exactly when the stakes were equal: two copies with the
     * same `bonded_total` but different monikers returned 'alpha' for the
     * input (alpha, beta) and 'beta' for (beta, alpha). So conflicting
     * fields could flip with page order, run to run - one spurious UPDATE
     * per address per run, which is a quarter of the defect this whole
     * change exists to remove rather than none of it.
     *
     * ⚠⚠ IT ALSO COVERED A SECOND CASE. bccomp() is called at the
     * default scale of 0, so values differing only in their fraction -
     * '100.9' against '100.1' - compare EQUAL and used to fall back to
     * first-wins. Scale is deliberately left alone (it matches `main`
     * byte-for-byte, and Plancks are integers), so step 2 is what makes
     * that case deterministic too.
     *
     * ⭐ Step 2 is STABLE, not MEANINGFUL, and the distinction is the
     * honest part. When two copies claim the same stake there is no
     * evidence to prefer either, so the tie-break does not pretend to pick
     * the better one - it picks the SAME one every time, from the set of
     * copies alone and never from their arrival order. And when the
     * canonical forms are equal the copies are equivalent, so which is
     * kept is unobservable.
     *
     * ⚠ The residual uncertainty is stated rather than hidden: nothing in
     * the payload timestamps a copy, so greatest-stake is NOT provably the
     * freshest. The rule is deterministic and self-consistent, which is
     * what a de-duplication rule can honestly promise.
     *
     * ⚠ Addressless rows are dropped, not kept as one empty-address row.
     * They cannot be addressed, and `uq_chain_operator` means the table
     * could hold only one of them anyway. Production has 0 such rows, so
     * this removes a latent garbage row rather than changing a live count.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, dropped: int, divergent: int}
     */
    private static function dedupeByStash(array $rows): array
    {
        /** @var array<string, array<string, mixed>> $kept */
        $kept      = [];
        $dropped   = 0;
        $divergent = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $dropped++;
                continue;
            }

            $address = self::stashAddress($row);
            if ($address === '') {
                $dropped++;
                continue;
            }

            if (!isset($kept[$address])) {
                $kept[$address] = $row;
                continue;
            }

            $dropped++;
            $incumbent = $kept[$address];

            if (self::copiesDiffer($incumbent, $row)) {
                $divergent++;
            }

            $byStake = bccomp(self::bondedTotal($row), self::bondedTotal($incumbent));

            if (
                $byStake > 0
                || ($byStake === 0 && strcmp(self::tieKey($row), self::tieKey($incumbent)) < 0)
            ) {
                $kept[$address] = $row;
            }
        }

        return [
            'rows'      => array_values($kept),
            'dropped'   => $dropped,
            'divergent' => $divergent,
        ];
    }

    /**
     * The stash address a row is keyed by.
     *
     * ⚠⚠ mapValidator() CALLS THIS TOO, deliberately. De-duplicating on
     * one spelling of the address while the stored row used another would
     * be worse than not de-duplicating at all: it would collapse rows that
     * are distinct in the table, or keep rows that collide in it. One
     * extraction, used by both, cannot drift.
     *
     * @param array<string, mixed> $v
     */
    private static function stashAddress(array $v): string
    {
        $address = $v['stash_account_display']['address']
                ?? $v['stash_account']
                ?? '';

        return is_string($address) ? $address : '';
    }

    /**
     * `bonded_total` as a string bccomp() can take.
     *
     * ⚠ A non-numeric value previously reached bccomp() raw, which THROWS
     * on PHP 8 - a fatal inside the index cron. It now sorts as 0. Scale is
     * left at the default, so numeric comparisons are unchanged.
     *
     * ⚠⚠ TWO CHECKS, AND BOTH ARE LOAD-BEARING. The regex is the
     * correctness guard: bcmath accepts only well-formed decimals, so an
     * exponent form like '1e5' passes is_numeric() and still makes bccomp()
     * throw. is_numeric() is what makes the numeric-string return type TRUE
     * rather than merely asserted - it is the narrowing the analyser can
     * verify, and it is a real statement about the value, not a suppression.
     *
     * @param array<string, mixed> $v
     * @return numeric-string
     */
    private static function bondedTotal(array $v): string
    {
        $raw = $v['bonded_total'] ?? '0';

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (
            !is_string($raw)
            || preg_match('/^\d+(\.\d+)?$/', $raw) !== 1
            || !is_numeric($raw)
        ) {
            return '0';
        }

        return $raw;
    }

    /**
     * Does a dropped copy carry different values from the one kept?
     * Diagnostic only - see DUP_COMPARE_KEYS.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function copiesDiffer(array $a, array $b): bool
    {
        foreach (self::DUP_COMPARE_KEYS as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                return true;
            }
        }

        $displayA = $a['stash_account_display']['display'] ?? null;
        $displayB = $b['stash_account_display']['display'] ?? null;

        return $displayA !== $displayB;
    }

    /**
     * A total, order-independent sort key for one copy of a validator.
     *
     * Used ONLY to break an equal-`bonded_total` tie. It depends on the
     * row's CONTENT and nothing else, which is the whole point: the same
     * set of copies must yield the same winner however the pages arrived.
     *
     * ⚠⚠ THE KEY MUST NOT DEPEND ON ARRAY KEY ORDER. json_encode()
     * and serialize() both preserve insertion order, so two copies with
     * identical content but differently-ordered keys would produce
     * different keys and the tie-break would silently become
     * order-sensitive again - the exact defect it exists to remove. Hence
     * canonicalCopy() sorts keys recursively first.
     *
     * ⚠⚠ JSON RATHER THAN serialize(), AND THAT IS NOT COSMETIC.
     * serialize() embeds a LENGTH PREFIX before every string, so
     * `s:2:"mu"` sorts before `s:5:"alpha"` and the rule silently becomes
     * "shortest value wins" - deterministic, but impossible to state in a
     * sentence a reviewer can check. Canonical JSON compares on the content
     * itself, so the rule is exactly "the lexicographically smallest
     * canonical JSON wins".
     *
     * ⚠ serialize() remains the fallback for the one case json_encode()
     * cannot represent - invalid UTF-8. Both are derived from the content
     * alone, so the key stays total and order-independent either way.
     *
     * @param array<string, mixed> $row
     */
    private static function tieKey(array $row): string
    {
        $canonical = self::canonicalCopy($row);
        $json      = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : serialize($canonical);
    }

    /**
     * Recursively key-sort an array so its serialization is canonical.
     *
     * ⚠ A LIST IS LEFT ALONE. Sorting a list's keys as strings puts 10
     * before 2, which would reorder nothing semantically but makes the
     * canonical form needlessly confusing to read in a failure message.
     *
     * ⚠ Anything that is neither an array nor a scalar nor null becomes
     * null. The payload is json_decode output so it cannot contain objects
     * or resources, and serialize() must stay total regardless.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalCopy($value)
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            foreach ($value as $key => $nested) {
                $value[$key] = self::canonicalCopy($nested);
            }

            return $value;
        }

        return is_scalar($value) || $value === null ? $value : null;
    }

    /**
     * Enrich a validator. Subscan returns full data in one call.
     *
     * @return array<string, mixed>
     */
    public function enrich_validator(string $address, ?object $existingRow = null): array
    {
        return $this->fetch_validator($address);
    }

    /**
     * Not supported — Polkadot doesn't have NFT collections in this fetcher.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch_collections(string $walletAddress, int $chainId = 0): array
    {
        return [];
    }

    /** @return array<int, array<string, mixed>> */
    public function fetch_top_collections(int $limit = 100): array
    {
        return [];
    }

    // ── Internal ────────────────────────────────────────────────────

    /**
     * Map a Subscan validator response to the standard schema.
     *
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    private function mapValidator(array $v, int $rank): array
    {
        $chainId    = (int) $this->chain->id;
        $decimals   = (int) ($this->chain->decimals ?? 10);
        $divisor    = pow(10, $decimals);

        // Address: stash account. Shared with dedupeByStash() so the key
        // de-duplication uses and the value stored cannot diverge.
        $address = self::stashAddress($v);

        // Moniker: on-chain identity display name, fallback to truncated address.
        $moniker = $v['stash_account_display']['display'] ?? null;
        if (!$moniker || $moniker === $address) {
            $moniker = $address;
            if (strlen($moniker) > 16) {
                $moniker = substr($moniker, 0, 6) . '...' . substr($moniker, -4);
            }
        }

        // Stake values: Plancks → DOT
        $bondedTotal = round((float) ($v['bonded_total'] ?? '0') / $divisor, 6);
        $bondedOwner = isset($v['bonded_owner'])
            ? round((float) $v['bonded_owner'] / $divisor, 6)
            : null;

        // Commission: Subscan returns as perbill (1e9 = 100%).
        // Some responses return it as a percentage string already.
        $commissionRaw = $v['validator_prefs_value'] ?? null;
        $commission = null;
        if ($commissionRaw !== null) {
            $commVal = (float) $commissionRaw;
            // If > 100, it's perbill format (1e9 = 100%).
            $commission = $commVal > 100 ? round($commVal / 1e7, 2) : round($commVal, 2);
        }

        // Nominator count (delegators).
        $nominators = isset($v['count_nominators']) ? (int) $v['count_nominators'] : null;

        // Status: Subscan uses 'validator' for active, 'waiting' for inactive.
        $isActive = ($v['validator_status'] ?? '') !== 'waiting'
                 && ($v['is_active'] ?? true);

        return [
            'operator_address'  => $address,
            'chain_id'          => $chainId,
            'moniker'           => $moniker,
            'status'            => $isActive ? 'active' : 'inactive',
            'commission_rate'   => $commission,
            'total_stake'       => $bondedTotal,
            'self_stake'        => $bondedOwner,
            'delegator_count'   => $nominators,
            'uptime_30d'        => null, // Would need era points tracking
            'jailed_count'      => 0,
            'voting_power_rank' => $rank + 1,
        ];
    }

    /**
     * Make a POST request to the Subscan API.
     * Requires BCC_SUBSCAN_API_KEY defined in wp-config.php.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function apiPost(string $path, array $body, ?ProviderOutcomeReceipt $outcome = null): ?array
    {
        $apiKey = defined('BCC_SUBSCAN_API_KEY') ? BCC_SUBSCAN_API_KEY : '';

        if (!$apiKey) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] BCC_SUBSCAN_API_KEY not defined in wp-config.php. Get a free key at https://support.subscan.io/');
            return null;
        }

        $url     = $this->base_url . $path;
        $chainId = (int) $this->chain->id;

        $response = ApiRetry::post($url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'X-API-Key'    => $apiKey,
            ],
            'body' => wp_json_encode($body),
        ], [
            'label'    => 'Subscan ' . $path,
            'chain_id' => $chainId,
            'outcome'  => $outcome,
        ]);

        if (is_wp_error($response)) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] error for ' . $path . ': ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] HTTP ' . $code . ' for ' . $path);
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($json) || ($json['code'] ?? -1) !== 0) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] API error for ' . $path . ': ' . ($json['message'] ?? 'unknown'));
            return null;
        }

        return $json['data'] ?? null;
    }
}
