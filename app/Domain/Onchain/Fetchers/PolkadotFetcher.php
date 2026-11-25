<?php

namespace BCC\Trust\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Contracts\FetcherInterface;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\ProviderOutcomeReceipt;
use BCC\Trust\Onchain\Support\StakeAmount;

/**
 * Polkadot Validator Fetcher
 *
 * Uses the Subscan REST API to fetch validator data, from Subscan's Asset Hub
 * API ({@see self::DEFAULT_REST_URL}): Polkadot's staking moved from the
 * relay chain to Asset Hub, and the relay-chain API now answers the
 * validator list with `count: 0`.
 *
 * Subscan returns stake in Plancks (1 DOT = 1e10 Plancks).
 *
 * @phpstan-import-type ChainRow from ChainRepository
 */
class PolkadotFetcher implements FetcherInterface
{
    /**
     * The Subscan API the validator index reads when the chain row names
     * none: Asset Hub's, where Polkadot staking now lives. Verified
     * 2026-11-24 with the production key: `/api/scan/staking/validators`
     * answers `code: 0` with the validator set, and its stash addresses use
     * the same SS58 form (prefix 0, `1…`) the stored rows already hold, so
     * existing rows still match by address. Also the seed's value
     * (schema-chains.php).
     */
    public const DEFAULT_REST_URL = 'https://assethub-polkadot.api.subscan.io';

    /**
     * Earlier SEEDED values that no longer serve staking: the relay-chain
     * API. A chain row still holding EXACTLY one of these is repointed once
     * (includes/database/repoint-validator-feed-endpoints.php); any other
     * value is an operator's choice and is never touched.
     *
     * @var list<string>
     */
    public const RETIRED_REST_URLS = [
        'https://polkadot.api.subscan.io',
    ];

    /**
     * Rows per page. Subscan refuses a larger page for this key's plan with
     * HTTP 403 (measured 2026-11-24: 10 and 25 answer, 50 and 99 are
     * refused), and the old 100 failed every run on its first page.
     */
    public const PAGE_SIZE = 25;

    /**
     * 1000 validators. Subscan reports about 400 today, so a walk that
     * reaches the cap with the list still going is not a set this fetcher
     * understands, and it refuses to index it.
     */
    public const MAX_PAGES = 40;

    /**
     * The least time between two Subscan requests from this process. The
     * key allows 2 requests a second (Subscan's `ratelimit-limit`), so 550 ms
     * leaves a margin.
     */
    public const MIN_REQUEST_INTERVAL_MS = 550;

    /**
     * Seconds one full validator walk may take. Sixteen paced pages need
     * roughly 10-25 s; the rest is room for slow answers and ApiRetry's
     * backoff, without letting one chain hold the whole index run. When it
     * runs out the walk throws {@see IndexTimeBudgetSpent}.
     */
    public const INDEX_TIME_BUDGET = 120;

    /** No page is started with less than this many seconds of the budget left. */
    public const MIN_PAGE_BUDGET = 10;

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

    /**
     * The unix instant the running validator walk must stop by, or null
     * outside one. apiPost() hands it to ApiRetry as `deadline_at`, so no
     * attempt or retry starts after it.
     */
    private ?int $deadlineAt = null;

    /**
     * When this PROCESS last sent a Subscan request (microtime). Static on
     * purpose: the hourly enrichment builds a new fetcher per validator, and
     * the rate limit belongs to the key, not to an object.
     */
    private static float $lastRequestAt = 0.0;

    /** @param ChainRow $chain */
    public function __construct(object $chain)
    {
        $this->chain    = $chain;
        $restUrl        = trim((string) ($chain->rest_url ?? ''));
        $this->base_url = rtrim($restUrl !== '' ? $restUrl : self::DEFAULT_REST_URL, '/');
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
     * ⚠⚠ NO AMOUNTS AND NO RANK, BY DESIGN. On Asset Hub this call answers
     * `bonded_total: "0"` for a validator the list reports with its real
     * stake (measured 2026-11-24), and the hourly enrichment writes every
     * non-null field returned here. Mapped like a list row, it would write a
     * zero stake and rank 1 over every Polkadot validator, every hour. Stake,
     * self-stake and rank come from the list walk only; this contributes the
     * identity name, status, nominator count and commission.
     *
     * @return array<string, mixed>
     */
    public function fetch_validator(string $address): array
    {
        $data = $this->apiPost('/api/scan/staking/validator', [
            'stash' => $address,
        ]);

        if (!$data || !isset($data['info']) || !is_array($data['info'])) {
            return [];
        }

        return $this->mapSingleValidator($data['info']);
    }

    /**
     * The single-validator answer, cut to what it can be trusted for (see
     * fetch_validator()).
     *
     * Status comes from the call's own `status`: `active` (what Asset Hub
     * answers for an elected validator) or `waiting`/`inactive`. Any other
     * value is left out rather than guessed; enrichment writes only the
     * fields present, so an unknown status leaves the stored one alone.
     *
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    private function mapSingleValidator(array $info): array
    {
        $mapped = $this->mapValidator($info, 0);
        unset($mapped['total_stake'], $mapped['self_stake'], $mapped['voting_power_rank'], $mapped['status']);

        $status = $info['status'] ?? null;
        if ($status === 'active') {
            $mapped['status'] = 'active';
        } elseif ($status === 'waiting' || $status === 'inactive') {
            $mapped['status'] = 'inactive';
        }

        return $mapped;
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
     * The list endpoint IS the validator set: its rows carry `status: ""`
     * on Asset Hub, so membership, not that field, is what makes a row
     * active here (the waiting list is a different endpoint).
     *
     * The walk reads PAGE_SIZE rows a request, paced to the key's rate
     * limit, until the list ends. It never returns a list it knows is
     * incomplete. Each of these throws instead, so the index run treats the
     * chain as a partial fetch and writes nothing for it:
     *
     *  - a page that failed (F-68);
     *  - MAX_PAGES full pages with the list still going;
     *  - fewer distinct validators than Subscan's own `count`;
     *  - the walk's time budget spent ({@see IndexTimeBudgetSpent}, which is
     *    not a provider failure, so the run does not charge the breaker).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch_all_validators(?ProviderOutcomeReceipt $outcome = null): array
    {
        $validators = [];
        $page       = 0;
        $reported   = null;

        $this->deadlineAt = $this->now() + self::INDEX_TIME_BUDGET;

        try {
            while (true) {
                if ($page >= self::MAX_PAGES) {
                    throw new \RuntimeException(sprintf(
                        'Polkadot validator list did not end within %d pages of %d (%d rows fetched); refusing to index a truncated list.',
                        self::MAX_PAGES,
                        self::PAGE_SIZE,
                        count($validators)
                    ));
                }

                if ($this->deadlineAt - $this->now() < self::MIN_PAGE_BUDGET) {
                    throw new IndexTimeBudgetSpent(sprintf(
                        'Polkadot validator walk reached its %ds budget before page %d (%d rows fetched).',
                        self::INDEX_TIME_BUDGET,
                        $page,
                        count($validators)
                    ));
                }

                $data = $this->apiPost('/api/scan/staking/validators', [
                    'order'       => 'desc',
                    'order_field' => 'bonded_total',
                    'row'         => self::PAGE_SIZE,
                    'page'        => $page,
                ], $outcome);

                // F-68: a page that FAILED (transport error, non-200, Subscan
                // error code, missing key) is not the end of the list. apiPost()
                // returns null for every one of those, and the loop used to read
                // that as "no more validators" and index a truncated set as if
                // it were complete. Throw instead, so the run fails: both callers
                // catch it (ChainRefreshService marks the chain a partial fetch,
                // skips its write and the dead-validator sweep; the admin refresh
                // reports a failure).
                if ($data === null) {
                    throw new \RuntimeException(sprintf(
                        'Polkadot validator page %d failed (%d validators fetched before it); refusing to index a truncated list.',
                        $page,
                        count($validators)
                    ));
                }

                // Subscan's own size of the set, from the first page that says.
                if ($reported === null && isset($data['count']) && is_numeric($data['count'])) {
                    $reported = (int) $data['count'];
                }

                $list = $data['list'] ?? [];
                if (empty($list)) {
                    break;
                }

                foreach ($list as $v) {
                    $validators[] = $v;
                }

                // A short page is the last one.
                if (count($list) < self::PAGE_SIZE) {
                    break;
                }

                $page++;

                // So is the page that reaches Subscan's count: no request for
                // the empty page past the end.
                if ($reported !== null && $page * self::PAGE_SIZE >= $reported) {
                    break;
                }
            }
        } finally {
            $this->deadlineAt = null;
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
        // across 600 rows, well past the real set size, because rank is the
        // array INDEX (mapValidator's $rank + 1) over a batch of 3000.
        // ValidatorRepository's change detection includes voting_power_rank,
        // so each extra copy also counted as an UPDATE - that is the whole
        // 2400. (Those 600 rows carry only 269 DISTINCT ranks. That is
        // recorded as a measurement; see dedupeByStash() for why it says
        // less than it looks like it does.)
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

        // ⚠ A WALK SHORT OF SUBSCAN'S OWN COUNT IS NOT THE SET. Pages are
        // ordered by stake, so a validator whose stake changes while the walk
        // runs can cross a page boundary: seen twice (absorbed above) or not
        // at all. Not at all is a truncated list, refused like any other.
        if ($reported !== null && count($dedupe['rows']) < $reported) {
            throw new \RuntimeException(sprintf(
                'Polkadot validator walk found %d distinct validators where Subscan reports %d; refusing to index a truncated list.',
                count($dedupe['rows']),
                $reported
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
     * Duplicate copies CANNOT be ASSUMED identical, so the rule has to be
     * well defined either way. Whether any of them actually DID differ,
     * and in which fields, is UNKNOWN: it is not recoverable from retained
     * data, which keeps only the final row state and, in the logs, only
     * aggregate counts.
     *
     * ⚠⚠ AN EARLIER VERSION OF THIS COMMENT CLAIMED MORE THAN THAT,
     * AND ITS ARGUMENT DOES NOT HOLD. It read the stored ranks as proof
     * that some copies differed in `bonded_total`: five identical copies
     * of one address would sort adjacently, so each address's last copy
     * would land on an exact multiple of five and the table would hold 600
     * distinct ranks topping out at 3000 - against the 269 distinct,
     * topping out at 2820, that it actually holds.
     *
     * That arithmetic describes ONE run, and the table cannot be read as
     * one run. Within a single run every rank is an array index used
     * exactly once, so one run can never store the same rank value on two
     * rows - yet 600 rows carry only 269 distinct values. The stored ranks
     * therefore do not all come from the same run, and a single-run
     * prediction cannot be compared against them at all. They are
     * consistent with identical copies and with differing copies alike,
     * and the 600-unchanged / 2400-updated split is explained by the
     * position-derived rank on its own.
     *
     * What the retained data DOES establish is only that duplicates
     * existed: 3000 batch entries, `0 new`, 600 rows. Hence $divergent
     * below - it makes the open question observable on the next successful
     * run instead of settling it by inference.
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
     * The rule — and why both of its checks are load-bearing — now lives in
     * {@see StakeAmount::sortable()}, shared by every validator fetcher (F-66).
     *
     * @param array<string, mixed> $v
     * @return numeric-string
     */
    private static function bondedTotal(array $v): string
    {
        return StakeAmount::sortable($v['bonded_total'] ?? '0');
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

        return self::identityName($a) !== self::identityName($b);
    }

    /**
     * The validator's on-chain identity name, or null when it has none.
     *
     * The relay-chain shape put it at `stash_account_display.display`. On
     * Asset Hub, identity lives on the People chain and Subscan nests it
     * under `people`: the account's own `display`, or, for a sub-identity,
     * the parent's `display` plus `sub_symbol`, written PARENT/SUB as
     * Polkadot's own tools show it. Only some validators set one.
     *
     * @param array<string, mixed> $v
     */
    private static function identityName(array $v): ?string
    {
        $account = $v['stash_account_display'] ?? null;
        if (!is_array($account)) {
            return null;
        }

        $display = $account['display'] ?? null;
        if (is_string($display) && $display !== '') {
            return $display;
        }

        $people = $account['people'] ?? null;
        if (!is_array($people)) {
            return null;
        }

        $own = $people['display'] ?? null;
        if (is_string($own) && $own !== '') {
            return $own;
        }

        $parent = $people['parent'] ?? null;
        $name   = is_array($parent) ? ($parent['display'] ?? null) : null;
        if (!is_string($name) || $name === '') {
            return null;
        }

        $sub = $parent['sub_symbol'] ?? null;

        return is_string($sub) && $sub !== '' ? $name . '/' . $sub : $name;
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

        // Moniker: on-chain identity name, fallback to truncated address.
        $moniker = self::identityName($v);
        if ($moniker === null || $moniker === $address) {
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
     * Returns null on ANY failure; callers must not read null as "empty".
     * Protected (not private) only as a test seam for the paging loop.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    protected function apiPost(string $path, array $body, ?ProviderOutcomeReceipt $outcome = null): ?array
    {
        $apiKey = defined('BCC_SUBSCAN_API_KEY') ? BCC_SUBSCAN_API_KEY : '';

        if (!$apiKey) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] BCC_SUBSCAN_API_KEY not defined in wp-config.php. Get a free key at https://support.subscan.io/');
            return null;
        }

        $options = [
            'label'    => 'Subscan ' . $path,
            'chain_id' => (int) $this->chain->id,
            'outcome'  => $outcome,
        ];
        if ($this->deadlineAt !== null) {
            $options['deadline_at'] = $this->deadlineAt;
        }

        $this->pace();

        $response = $this->sendPost($this->base_url . $path, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'X-API-Key'    => $apiKey,
            ],
            'body' => wp_json_encode($body),
        ], $options);

        if (is_wp_error($response)) {
            // The walk's budget ran out before this request could start.
            // ApiRetry charged nothing for that, and neither may the run.
            if ($response->get_error_code() === 'deadline_exceeded') {
                throw new IndexTimeBudgetSpent(sprintf(
                    'Polkadot validator walk reached its %ds budget before %s could start.',
                    self::INDEX_TIME_BUDGET,
                    $path
                ));
            }

            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] error for ' . $path . ': ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] HTTP ' . $code . ' for ' . $path);
            return null;
        }

        return self::parseSubscanBody(wp_remote_retrieve_body($response), $path);
    }

    /**
     * The one wire call. Protected only as a test seam, so apiPost()'s own
     * handling runs in tests without a network.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $options
     * @return array<string, mixed>|\WP_Error
     */
    protected function sendPost(string $url, array $args, array $options)
    {
        return ApiRetry::post($url, $args, $options);
    }

    /**
     * Space Subscan requests at least MIN_REQUEST_INTERVAL_MS apart across
     * every fetcher in this process. The walk sends about sixteen requests,
     * which unpaced would draw 429s from a 2-per-second key. A wait is
     * blocked time, not CPU, so it does not count against PHP's execution
     * limit on Linux.
     */
    private function pace(): void
    {
        $now  = $this->clock();
        $next = self::$lastRequestAt + self::MIN_REQUEST_INTERVAL_MS / 1000;

        if ($next > $now) {
            $this->sleepMicros((int) ceil(($next - $now) * 1_000_000));
            $now = $next;
        }

        self::$lastRequestAt = $now;
    }

    /** Unix seconds. Protected as a test seam for the walk's time budget. */
    protected function now(): int
    {
        return time();
    }

    /** Unix seconds with a fraction. Protected as a test seam for pace(). */
    protected function clock(): float
    {
        return microtime(true);
    }

    /** Protected as a test seam: a test records the wait instead of sleeping. */
    protected function sleepMicros(int $micros): void
    {
        usleep($micros);
    }

    /**
     * Decode one Subscan response body. Null = failure; [] = an empty answer.
     *
     * @return array<string, mixed>|null
     */
    private static function parseSubscanBody(string $body, string $path): ?array
    {
        $json = json_decode($body, true);

        if (!is_array($json) || ($json['code'] ?? -1) !== 0) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] API error for ' . $path . ': ' . (is_array($json) ? ($json['message'] ?? 'unknown') : 'unknown'));
            return null;
        }

        // A SUCCESSFUL response (code 0) can carry "data": null — e.g. the
        // page past the end when the validator count is an exact multiple of
        // the page size. That is an EMPTY answer, not a failure: returning
        // null here would make fetch_all_validators() throw (F-68) and mark
        // the chain partial on every such run. Anything else that is not an
        // array is malformed and stays a failure.
        $data = $json['data'] ?? null;
        if ($data === null) {
            return [];
        }
        if (!is_array($data)) {
            \BCC\Core\Log\Logger::error('[Polkadot Fetcher] Malformed data for ' . $path . ': expected an object, got ' . gettype($data));
            return null;
        }
        return $data;
    }
}
