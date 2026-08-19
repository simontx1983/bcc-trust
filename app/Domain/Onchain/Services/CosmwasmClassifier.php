<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CW-721 classification state machine — PURE.
 *
 * Zero I/O, zero WordPress, zero $wpdb. It takes the outcomes of a small
 * fixed probe set and returns a verdict plus bounded, structured
 * evidence. The worker performs the probes; this class decides.
 *
 * ── THE PROBE SET (deliberately minimal, staged) ────────────────────────
 * Start from what already existed in {@see \BCC\Trust\Onchain\Fetchers\CosmosFetcher::cw721CollectionInfoQuery}:
 *
 *   1. `contract_info {}`
 *        Classic cw721 (≤ v0.18 — Injective/Talis and most deployed
 *        contracts) answers this with `{name, symbol}`.
 *   2. `get_collection_info_and_extension {}`
 *        cw721 v0.19+ renamed the variant. The Stargaze collections
 *        re-instantiated on the Cosmos Hub (SG721, code 434 — measured)
 *        REJECT `contract_info` outright and answer only this one.
 *
 * ONE probe is added: `num_tokens {}`.
 *
 * WHY `num_tokens` EARNS ITS ROUND TRIP — it materially prevents the
 * minter/factory false-positive class, which is the dominant false
 * positive in practice. A launchpad MINTER answers `config` and
 * `minter`, and many minters ALSO carry a collection-shaped name in
 * their own state, so "something answered a collection-info query" is
 * not evidence of NFT-ness. `num_tokens` is part of the CW-721 base
 * query enum (`QueryMsg::NumTokens`) and a minter does not implement it:
 * it answers with an "unknown variant" parse error. Measured on Jackal:
 * code 3 (`Ticket-Minter-…`) and code 5 (`bindings factory`) both fail
 * `num_tokens` with `Error parsing into type …`, while code 2 answers
 * `{"data":{"count":81}}` and also answers `contract_info` with
 * `{"name":"Event SESHIVERSARY","symbol":"EVENT"}`. So `num_tokens`
 * converts "answered something" into a positive assertion of NFT-ness.
 *
 * WHY CW2 `contract_version` IS **NOT** ADDED — evaluated and rejected:
 *   - It is not a smart query at all. CW2 stores its version under the
 *     raw state key `contract_info`, so reading it means a THIRD round
 *     trip against a different endpoint shape
 *     (`/cosmwasm/wasm/v1/contract/{addr}/raw/{key}`), not the smart
 *     query the other probes share.
 *   - Its `contract` field is a free-form crate name with no registry:
 *     `crates.io:cw721-base`, `crates.io:sg721-base`,
 *     `crates.io:cw721-metadata-onchain`, plus every fork's own name.
 *     Matching it is a name heuristic with unbounded false negatives on
 *     forks — strictly weaker than the behavioural assertion
 *     `num_tokens` already gives us, and it would add a second, softer
 *     notion of "is a CW-721" alongside the behavioural one.
 *   - It adds nothing the pair does not already decide: every case where
 *     CW2 would say "cw721" is a case where `num_tokens` already
 *     answered.
 * If a future measurement shows a real CW-721 family that answers CW2
 * but neither `num_tokens` nor either collection-info variant, bump
 * {@see VERSION} and add it then.
 *
 * ── ERROR DISCRIMINATION IS LOAD-BEARING ────────────────────────────────
 * `temporarily_unreachable` MUST be distinguishable from `not_cw721`,
 * because `not_cw721` is terminal and never routinely revisited — so
 * mistaking a node hiccup for a negative verdict is permanent data loss.
 * wasmd's error BODY carries the distinction and the HTTP status does
 * not (both arrive as non-200):
 *
 *   "Error parsing into type …" / "unknown variant" / "expected one of"
 *       → the contract genuinely does not implement that query.
 *         DECISIVE NEGATIVE evidence.
 *   "Error calling the VM" / "panicked" / 5xx / timeout / transport
 *       → node-side. NOT evidence about the contract at all.
 *
 * Matching is on a PREFIX/token basis against the JSON `message` field
 * only, never the whole raw body, and only a bounded sanitized excerpt is
 * ever persisted. See {@see errorKindFromMessage()}.
 *
 * ── THE STATE MACHINE ───────────────────────────────────────────────────
 * Evaluated top-down; the first matching rule wins.
 *
 *   1. num_tokens OK  AND  (contract_info OK OR collection_info OK)
 *          → confirmed_cw721            reason: num_tokens_and_info
 *   2. num_tokens OK  AND  every info probe DECISIVELY unsupported
 *          → probable_cw721             reason: num_tokens_only
 *   3. (contract_info OK OR collection_info OK)  AND  num_tokens
 *      DECISIVELY unsupported
 *          → probable_cw721             reason: info_only
 *   4. ANY probe outcome is non-decisive (node error / transport /
 *      5xx / malformed)
 *          → temporarily_unreachable    reason: node_unreachable
 *   5. EVERY probe decisively unsupported
 *          → not_cw721                  reason: no_cw721_queries
 *   6. anything else (contract not found, 4xx that is not a query
 *      rejection, an OK body we could not read)
 *          → inconclusive               reason: <specific>
 *
 * Rule 4 sits ABOVE rule 5 on purpose: a mix of "unsupported" and "node
 * error" must NEVER settle as a terminal negative. Rules 2 and 3 require
 * the OTHER probe to have been DECISIVE — a half-answer with an unknown
 * remainder is `temporarily_unreachable`, retried, rather than a guess.
 *
 * Jackal expected outcomes (the live fixture this was written against):
 *   code 1 `Numbered Collection`  VM cache error → temporarily_unreachable
 *   code 2 `Event SESHIVERSARY`   num_tokens 81 + contract_info → confirmed_cw721
 *   code 3 `Ticket-Minter-…`      parse errors  → not_cw721
 *   code 4 `bindings contract-…`  parse errors  → not_cw721
 *   code 5 `bindings factory`     parse errors  → not_cw721
 *
 * @phpstan-type ProbeOutcome array{probe: string, ok: bool, kind: string, excerpt: string}
 * @phpstan-type Verdict array{
 *     classification: string,
 *     reason: string,
 *     probes_ok: string,
 *     probes_failed: string,
 *     last_error: string,
 *     classifier_version: int
 * }
 */
final class CosmwasmClassifier
{
    // ── Classification states (existing naming style: snake_case) ────────

    public const CONFIRMED    = 'confirmed_cw721';
    public const PROBABLE     = 'probable_cw721';
    public const NOT_CW721    = 'not_cw721';
    public const INCONCLUSIVE = 'inconclusive';
    public const UNREACHABLE  = 'temporarily_unreachable';

    /**
     * Bump when the probe set or the decision rules change.
     *
     * A bump does NOT sweep the whole inventory: only the classifications
     * named by {@see requeueableClassifications()} are requeued.
     *
     * ── 1 → 2 (2026-08-19): THE DECISION RULES DID CHANGE ───────────────
     * PR #195 stopped a cosmos LCD's HTTP 500 "unknown variant" answer
     * from being read as a node fault. Before it, probing a non-CW-721
     * contract retried four times, charged the CHAIN-WIDE circuit breaker,
     * and — once the breaker opened mid-probe — turned the remaining
     * probes into transport errors, so the family settled
     * `temporarily_unreachable` instead of the terminal, correct
     * `not_cw721`. Measured on Dungeon: one White Whale incentive factory
     * did exactly that, and took 15 unrelated families down with it.
     *
     * #195 fixed the code but could not fix the ROWS. Every non-terminal
     * classification written under the old behaviour is a verdict this
     * classifier would no longer reach, and `classifier_version` is the
     * mechanism that says so — {@see \BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository::requeueForClassifierVersion()}
     * clears `classified_at`, `next_attempt_at` and `retry_count` for
     * them, chain by chain, on the chain's next pass.
     *
     * That bump belonged in #195 and was missed there. It is here instead,
     * on its own, so the requeue is reviewable as the data change it is.
     *
     * NOTHING HAPPENS AT DEPLOY TIME. This constant is only read; rows
     * move when an ELIGIBLE chain runs a pass, and only that chain's rows.
     * Terminal `not_cw721` and settled `confirmed_cw721` are excluded by
     * {@see requeueableClassifications()} and stay excluded.
     */
    public const VERSION = 2;

    // ── Probe identifiers (stored in probes_ok / probes_failed) ──────────

    public const PROBE_NUM_TOKENS      = 'num_tokens';
    public const PROBE_CONTRACT_INFO   = 'contract_info';
    public const PROBE_COLLECTION_INFO = 'get_collection_info_and_extension';

    // ── Error kinds surfaced by the fetcher's structured query seam ──────

    /** The query succeeded. */
    public const KIND_NONE = 'none';
    /** The contract does not implement this query. DECISIVE NEGATIVE. */
    public const KIND_QUERY_UNSUPPORTED = 'query_unsupported';
    /** Node-side failure (VM/cache/panic/5xx). Says nothing about the contract. */
    public const KIND_NODE_ERROR = 'node_error';
    /** Transport failure / breaker open. Says nothing about the contract. */
    public const KIND_TRANSPORT = 'transport';
    /** A 4xx that is not a query rejection. */
    public const KIND_HTTP_4XX = 'http_4xx';
    /** The address has no contract (or the code id does not exist). */
    public const KIND_NOT_FOUND = 'not_found';
    /** 200 with a body we could not read as the documented envelope. */
    public const KIND_MALFORMED = 'malformed';

    /**
     * Error-message tokens that mean NODE-SIDE failure.
     *
     * Checked BEFORE the unsupported tokens so a node problem can never
     * be read as a decisive negative. Matched case-insensitively as a
     * substring of the JSON `message` FIELD (never the raw body).
     *
     * @var list<string>
     */
    private const NODE_ERROR_TOKENS = [
        'Error calling the VM',
        'panicked',
        'out of gas',
        'Querier system error',
        'rpc error',
        'connection refused',
    ];

    /**
     * Error-message tokens that mean THE CONTRACT DOES NOT IMPLEMENT THIS
     * QUERY. Decisive negative evidence.
     *
     * `Error parsing into type` is what wasmd emits when serde cannot
     * match the query variant against the contract's QueryMsg enum; it is
     * usually followed by `unknown variant \`contract_info\`, expected one
     * of …`. Both halves are listed because different wasmd versions
     * truncate differently.
     *
     * @var list<string>
     */
    private const QUERY_UNSUPPORTED_TOKENS = [
        'Error parsing into type',
        'unknown variant',
        'expected one of',
        'missing field',
        'Error parsing into type ',
    ];

    /** Sanitized-evidence cap — the existing 255-char column convention. */
    public const EXCERPT_MAX = 255;

    /**
     * Classifications a classifier-version bump is allowed to requeue.
     *
     * `not_cw721` is TERMINAL and is deliberately absent: a settled
     * negative is never routinely reclassified, and a version bump is
     * routine. `confirmed_cw721` is absent because re-proving a
     * confirmed family costs requests and changes nothing. Callers may
     * pass an explicit override for a one-off remediation, but the
     * DEFAULT must never sweep settled negatives.
     *
     * @return list<string>
     */
    public static function requeueableClassifications(): array
    {
        return [self::INCONCLUSIVE, self::UNREACHABLE, self::PROBABLE];
    }

    /** Every valid classification value. */
    /** @return list<string> */
    public static function allClassifications(): array
    {
        return [
            self::CONFIRMED,
            self::PROBABLE,
            self::NOT_CW721,
            self::INCONCLUSIVE,
            self::UNREACHABLE,
        ];
    }

    /**
     * TRUE when a classification is settled — no routine revisit, ever.
     *
     * `not_cw721` is the only routinely-terminal state (owner decision).
     * `confirmed_cw721` is settled for CLASSIFICATION purposes but its
     * family is still swept daily for NEW contracts — that is a different
     * question and a different code path.
     */
    public static function isTerminal(string $classification): bool
    {
        return $classification === self::NOT_CW721;
    }

    /** TRUE when a classification means "this is (probably) a CW-721". */
    public static function isCw721(string $classification): bool
    {
        return $classification === self::CONFIRMED || $classification === self::PROBABLE;
    }

    // ── Error discrimination ────────────────────────────────────────────

    /**
     * PURE. Map a wasmd error message to an error kind.
     *
     * Matching is on the `message` FIELD of the wasmd JSON error envelope
     * (`{"code":3,"message":"…"}`), never on the raw body, and by
     * substring token rather than whole-body equality so wasmd's varying
     * prefixes (`query wasm contract failed: …`) do not defeat it.
     *
     * Node tokens are checked first: a node problem must never be read as
     * a decisive negative.
     *
     * $httpCode is consulted only when the message is unhelpful.
     */
    public static function errorKindFromMessage(string $message, int $httpCode): string
    {
        $message = trim($message);

        if ($message !== '') {
            foreach (self::NODE_ERROR_TOKENS as $token) {
                if (stripos($message, $token) !== false) {
                    return self::KIND_NODE_ERROR;
                }
            }
            foreach (self::QUERY_UNSUPPORTED_TOKENS as $token) {
                if (stripos($message, $token) !== false) {
                    return self::KIND_QUERY_UNSUPPORTED;
                }
            }
            if (stripos($message, 'not found') !== false) {
                return self::KIND_NOT_FOUND;
            }
        }

        if ($httpCode >= 500) {
            return self::KIND_NODE_ERROR;
        }
        if ($httpCode === 404) {
            return self::KIND_NOT_FOUND;
        }
        if ($httpCode >= 400) {
            return self::KIND_HTTP_4XX;
        }

        return self::KIND_MALFORMED;
    }

    /**
     * PURE. TRUE when an error kind is DECISIVE evidence about the
     * CONTRACT (as opposed to the node or the wire).
     */
    public static function isDecisive(string $kind): bool
    {
        return $kind === self::KIND_NONE || $kind === self::KIND_QUERY_UNSUPPORTED;
    }

    /**
     * PURE. TRUE when an error kind is a TRANSIENT infrastructure fault —
     * the node, the wire, or an unreadable body.
     *
     * Deliberately narrower than `!isDecisive()`: {@see KIND_NOT_FOUND}
     * is neither decisive about NFT-ness NOR a transient fault. "There is
     * no contract at this address" is a stable fact that will not change
     * on retry, so it settles `inconclusive` rather than looping through
     * the retry schedule as if the node were flaky.
     */
    public static function isTransientFault(string $kind): bool
    {
        return in_array(
            $kind,
            [self::KIND_NODE_ERROR, self::KIND_TRANSPORT, self::KIND_MALFORMED, self::KIND_HTTP_4XX],
            true
        );
    }

    /**
     * PURE. Single-line, control-char-free, length-capped excerpt of an
     * upstream message. This is the ONLY form of an upstream error we
     * ever persist — raw LCD bodies are never stored, and nothing longer
     * than {@see EXCERPT_MAX} reaches a column.
     */
    public static function sanitizeExcerpt(string $raw): string
    {
        $cleaned = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw);
        if (!is_string($cleaned)) {
            $cleaned = $raw;
        }
        $cleaned = trim((string) preg_replace('/\s+/', ' ', $cleaned));

        return function_exists('mb_substr')
            ? mb_substr($cleaned, 0, self::EXCERPT_MAX)
            : substr($cleaned, 0, self::EXCERPT_MAX);
    }

    // ── The verdict ─────────────────────────────────────────────────────

    /**
     * PURE. Decide a classification from a probe-outcome set.
     *
     * @param list<ProbeOutcome> $outcomes
     * @return Verdict
     */
    public static function classify(array $outcomes): array
    {
        $byProbe = [];
        foreach ($outcomes as $o) {
            $byProbe[$o['probe']] = $o;
        }

        $numTokens  = $byProbe[self::PROBE_NUM_TOKENS]      ?? null;
        $contract   = $byProbe[self::PROBE_CONTRACT_INFO]   ?? null;
        $collection = $byProbe[self::PROBE_COLLECTION_INFO] ?? null;

        $okProbes     = [];
        $failedProbes = [];
        $firstError   = '';
        $anyIndecisive = false;

        foreach ($outcomes as $o) {
            if ($o['ok']) {
                $okProbes[] = $o['probe'];
                continue;
            }
            // Store the probe with the kind that made it fail so the
            // evidence answers "which probes failed, and why".
            $failedProbes[] = $o['probe'] . ':' . $o['kind'];
            if ($firstError === '' && $o['excerpt'] !== '') {
                $firstError = $o['excerpt'];
            }
            if (self::isTransientFault($o['kind'])) {
                $anyIndecisive = true;
            }
        }

        $numTokensOk  = $numTokens !== null && $numTokens['ok'];
        $infoOk       = ($contract !== null && $contract['ok'])
            || ($collection !== null && $collection['ok']);

        $numTokensUnsupported = $numTokens !== null
            && !$numTokens['ok']
            && $numTokens['kind'] === self::KIND_QUERY_UNSUPPORTED;

        $infoDecisivelyUnsupported =
            ($contract === null   || (!$contract['ok']   && $contract['kind']   === self::KIND_QUERY_UNSUPPORTED))
            && ($collection === null || (!$collection['ok'] && $collection['kind'] === self::KIND_QUERY_UNSUPPORTED))
            && ($contract !== null || $collection !== null);

        // 1. The strongest evidence: it counts tokens AND names itself.
        if ($numTokensOk && $infoOk) {
            return self::verdict(self::CONFIRMED, 'num_tokens_and_info', $okProbes, $failedProbes, $firstError);
        }

        // 2. Counts tokens, refuses BOTH info variants (decisively). A
        //    CW-721 with a non-standard/absent collection-info variant.
        if ($numTokensOk && $infoDecisivelyUnsupported) {
            return self::verdict(self::PROBABLE, 'num_tokens_only', $okProbes, $failedProbes, $firstError);
        }

        // 3. Names itself but decisively cannot count tokens. Could be a
        //    minter carrying collection-shaped state — probable at best,
        //    never confirmed.
        if ($infoOk && $numTokensUnsupported) {
            return self::verdict(self::PROBABLE, 'info_only', $okProbes, $failedProbes, $firstError);
        }

        // 3b. Half an answer with the remainder UNKNOWN — do not guess.
        if (($numTokensOk || $infoOk) && $anyIndecisive) {
            return self::verdict(self::UNREACHABLE, 'partial_evidence_node_unreachable', $okProbes, $failedProbes, $firstError);
        }

        // 4. Node/transport noise anywhere → retryable, NEVER terminal.
        if ($anyIndecisive) {
            return self::verdict(self::UNREACHABLE, 'node_unreachable', $okProbes, $failedProbes, $firstError);
        }

        // 5. Every probe decisively refused → this is not a CW-721.
        if ($outcomes !== [] && $failedProbes !== [] && $okProbes === []) {
            $allUnsupported = true;
            foreach ($outcomes as $o) {
                if ($o['ok'] || $o['kind'] !== self::KIND_QUERY_UNSUPPORTED) {
                    $allUnsupported = false;
                    break;
                }
            }
            if ($allUnsupported) {
                return self::verdict(self::NOT_CW721, 'no_cw721_queries', $okProbes, $failedProbes, $firstError);
            }
        }

        // 6. Everything else: missing contract, odd 4xx, unreadable body.
        $reason = 'indeterminate';
        foreach ($outcomes as $o) {
            if (!$o['ok'] && $o['kind'] === self::KIND_NOT_FOUND) {
                $reason = 'contract_not_found';
                break;
            }
        }

        return self::verdict(self::INCONCLUSIVE, $reason, $okProbes, $failedProbes, $firstError);
    }

    /**
     * PURE. Assemble a verdict with its bounded evidence payload.
     *
     * @param  list<string> $okProbes
     * @param  list<string> $failedProbes
     * @return Verdict
     */
    private static function verdict(
        string $classification,
        string $reason,
        array $okProbes,
        array $failedProbes,
        string $firstError
    ): array {
        return [
            'classification'     => $classification,
            'reason'             => self::joinCapped([$reason], 64),
            'probes_ok'          => self::joinCapped($okProbes, 128),
            'probes_failed'      => self::joinCapped($failedProbes, 128),
            'last_error'         => $firstError,
            'classifier_version' => self::VERSION,
        ];
    }

    /**
     * PURE. The verdict for a code family that has NO instantiated
     * contracts at all.
     *
     * ~50% of code families on a busy chain have zero contracts
     * (measured). There is nothing to probe, so there is nothing to
     * confirm OR to rule out — a contract could be instantiated
     * tomorrow. We record `inconclusive` (retryable under the ordinary
     * cap + backoff) rather than `not_cw721`, because settling it
     * negative would be a terminal claim we have no evidence for.
     *
     * @return Verdict
     */
    public static function noContractsVerdict(): array
    {
        return [
            'classification'     => self::INCONCLUSIVE,
            'reason'             => 'no_contracts',
            'probes_ok'          => '',
            'probes_failed'      => '',
            'last_error'         => '',
            'classifier_version' => self::VERSION,
        ];
    }

    /**
     * PURE. The verdict a family inherits from an already-classified
     * SAME-CHECKSUM twin.
     *
     * An identical checksum means an IDENTICAL BINARY, so the twin's
     * answer to "does this code implement the CW-721 query set?" is
     * exactly this family's answer. That is the ONLY thing checksum
     * reuse is allowed to short-circuit.
     *
     * IT MUST NOT, AND STRUCTURALLY CANNOT HERE:
     *   - verify a collection — this returns a FAMILY classification and
     *     touches no collection row, and discovery never sets
     *     is_verified;
     *   - bypass per-contract liveness — contracts under the inheriting
     *     family are still probed individually; family classification
     *     only decides whether the family is worth enumerating;
     *   - bypass spam/deny rules — deny is consulted per CONTRACT on
     *     every path, and no contract address is carried across;
     *   - imply identical collection metadata — no name, symbol, supply
     *     or artwork is copied. `sample_contract` is deliberately NOT
     *     inherited.
     *
     * @param string $twinClassification the twin's settled classification
     * @param int    $twinCodeId         recorded for auditability
     * @return Verdict
     */
    public static function checksumTwinVerdict(string $twinClassification, int $twinCodeId): array
    {
        return [
            'classification'     => $twinClassification,
            'reason'             => 'checksum_twin:' . $twinCodeId,
            'probes_ok'          => '',
            'probes_failed'      => '',
            'last_error'         => '',
            'classifier_version' => self::VERSION,
        ];
    }

    /**
     * PURE. Classifications a checksum twin may propagate.
     *
     * Only SETTLED, binary-determined verdicts. `temporarily_unreachable`
     * and `inconclusive` describe the NODE or the SAMPLE, not the
     * binary, so they must never spread.
     *
     * @return list<string>
     */
    public static function inheritableClassifications(): array
    {
        return [self::CONFIRMED, self::PROBABLE, self::NOT_CW721];
    }

    // ── Retry policy ────────────────────────────────────────────────────

    /**
     * Retry cap for non-terminal classifications. After this many
     * attempts a row stops being swept; an operator can still requeue it
     * explicitly.
     */
    public const MAX_RETRIES = 6;

    /** First backoff step (seconds). */
    private const BACKOFF_BASE_SECONDS = 6 * 3600;

    /** Backoff ceiling (seconds) — 28 days. */
    private const BACKOFF_MAX_SECONDS = 28 * 86400;

    /**
     * PURE. Staged/exponential backoff: 6h, 12h, 1d, 2d, 4d, 8d, … capped
     * at 28 days. Returned in seconds; the caller stores
     * `next_attempt_at = now + backoff`, which keeps retry selection a
     * single bounded indexed range scan instead of a per-row
     * recomputation.
     */
    public static function backoffSeconds(int $retryCount): int
    {
        $retryCount = max(0, min(30, $retryCount));
        $seconds    = self::BACKOFF_BASE_SECONDS * (2 ** $retryCount);

        return (int) min($seconds, self::BACKOFF_MAX_SECONDS);
    }

    /**
     * PURE. Should this row be swept again?
     *
     * Terminal classifications never are — that is the "confirmed
     * `not_cw721` families get NO routine reclassification, ever" rule
     * expressed in one place.
     */
    public static function isRetryable(string $classification, int $retryCount): bool
    {
        if (self::isTerminal($classification)) {
            return false;
        }
        if ($classification === self::CONFIRMED || $classification === self::PROBABLE) {
            return false;
        }

        return $retryCount < self::MAX_RETRIES;
    }

    /**
     * PURE. Join evidence tokens into a column-safe string.
     *
     * @param list<string> $parts
     */
    private static function joinCapped(array $parts, int $max): string
    {
        $joined = implode(',', $parts);

        return function_exists('mb_substr')
            ? mb_substr($joined, 0, $max)
            : substr($joined, 0, $max);
    }
}
