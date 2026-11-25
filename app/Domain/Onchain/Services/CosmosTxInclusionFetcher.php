<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Crypto\Bech32;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\CosmosEndpointVerifier;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;
use BCC\Trust\Onchain\ValueObjects\CosmosSecurityContact;
use BCC\Trust\Onchain\ValueObjects\CosmosTxInclusion;
use BCC\Trust\Onchain\ValueObjects\CosmosTxInclusionResult;
use BCC\Trust\Onchain\ValueObjects\CosmosTxProofEndpoints;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE VALIDATOR MEMO CLAIM'S VIEW OF A COSMOS TRANSACTION: was it included,
 * and what are its exact bytes?
 *
 * It establishes inclusion and nothing more. Key control (sha256(bytes) ==
 * hash, the signature, the self-send shape) is proven by bcc-core
 * `CosmosTxVerifier::verifySelfSend()` over the bytes handed back here;
 * the claim decision belongs to the relationship service. Two endpoints
 * agreeing is never treated as proof of who signed.
 *
 * ── WHAT "INCLUDED" MEANS ───────────────────────────────────────────────
 * Every proof endpoint ({@see CosmosTxProofEndpoints}: two operators) must:
 *   1. be on the pinned network (RPC `/status`, {@see CosmosEndpointVerifier::verifyProofRpc()});
 *   2. serve `/tx?hash=` with the same raw bytes, the same height and the
 *      same result code;
 *   3. serve `/commit?height=` with a header naming the pinned chain id and
 *      the same block time.
 * Code 0 on all → included. The same non-zero code on all → failed on chain.
 * Anything else is "not yet": the member submits again, and nothing is
 * decided against them. The raw bytes come from the RPC because the LCD
 * never returns TxRaw bytes.
 *
 * ── THE BREAKER ─────────────────────────────────────────────────────────
 * Requests go through {@see ApiRetry} against the chain's breaker, charged
 * to the endpoint's pinned fingerprint. ⚠ CometBFT answers `/tx` for a hash
 * it has not indexed with HTTP 500 `tx (<HASH>) not found`. That is an
 * ANSWER, so it is passed as `application_error`: not retried and never
 * charged. Otherwise a member polling for their own transaction would charge
 * the CHAIN-WIDE breaker and could stop that chain's indexing. Any other 5xx
 * still retries and charges as usual.
 *
 * ── NOTHING FROM AN ENDPOINT IS TRUSTED THAT COULD FORGE A PASS ─────────
 *   - the chain id is BCC's pin, never the node's word (the node must
 *     match it);
 *   - the account number comes from one operator's LCD: a wrong one only
 *     makes the signature check fail;
 *   - `security_contact` could redirect a warning, so it needs every
 *     operator to agree ({@see securityContact()}).
 * Endpoint URLs are never logged; operators are named instead.
 */
final class CosmosTxInclusionFetcher
{
    /** The DegradationMetrics subsystem (registered in bcc-core's health map). */
    public const METRIC_SUBSYSTEM = 'cosmos_tx_proof';

    /**
     * Bytes. A bank self-send with its memo is a few hundred. The same cap as
     * bcc-core's CosmosTxVerifier (16 KiB), which refuses anything larger as
     * malformed, so a larger transaction is never fetched into memory for it.
     */
    public const MAX_TX_BYTES = 16384;

    /** The SDK caps security_contact at 140 characters; anything longer is not the field. */
    public const MAX_CONTACT_LENGTH = 256;

    private const TIMEOUT = 10;

    /** `tx (<64 hex>) not found`: CometBFT's answer for a hash it has not indexed. */
    private const TX_NOT_FOUND = '/^tx \([0-9A-Fa-f]{64}\) not found$/';

    /** RFC 3339 in UTC, as CometBFT writes header times (fraction optional). */
    private const HEADER_TIME = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(\.\d{1,9})?Z$/';

    /**
     * Was $txHash included on $slug, per every proof endpoint?
     *
     * @param string $operatorAddress the claimed validator's valoper (bech32)
     * @param int|null $deadlineAt     absolute unix time a waiting member gives up (ApiRetry `deadline_at`)
     */
    public static function fetch(
        string $slug,
        string $txHash,
        string $operatorAddress,
        ProviderRequestBudget $budget,
        ?int $deadlineAt = null
    ): CosmosTxInclusionResult {
        $hash = self::normalizeHash($txHash);
        if ($hash === null) {
            return CosmosTxInclusionResult::invalidHash();
        }

        $endpoints = CosmosTxProofEndpoints::forSlug($slug);
        $network   = CosmosEndpointPolicy::pinnedNetwork($slug);
        $prefix    = CosmosEndpointPolicy::pinnedAccountPrefix($slug);
        $chain     = ChainRepository::getBySlug($slug); // only for its id: the breaker's key
        if ($endpoints === [] || $network === null || $prefix === null || $chain === null) {
            return CosmosTxInclusionResult::unsupported();
        }

        // The prefix is BCC's pin, never the chains row's bech32_prefix.
        $account = self::operatorAccount($operatorAddress, $prefix);
        if ($account === null) {
            return CosmosTxInclusionResult::invalidValidator();
        }
        $chainId = (int) $chain->id;

        if (!self::everyNodeOnNetwork($slug, $endpoints, $budget)) {
            return CosmosTxInclusionResult::notYet();
        }

        // ── The transaction, on every node ─────────────────────────────
        $seen = [];
        foreach ($endpoints as $endpoint) {
            $tx = self::readTx($slug, $chainId, $endpoint, $hash, $budget, $deadlineAt);
            if ($tx === null) {
                return CosmosTxInclusionResult::notYet(); // not indexed there yet, or unavailable
            }
            $seen[] = [$endpoint['operator'], $tx];
        }
        [, $first] = $seen[0];
        foreach ($seen as [$operator, $tx]) {
            if (!hash_equals($first['bytes'], $tx['bytes']) || $tx['height'] !== $first['height'] || $tx['code'] !== $first['code']) {
                self::disagreement($slug, 'transaction', $seen[0][0], $operator);

                return CosmosTxInclusionResult::notYet();
            }
        }
        if ($first['code'] !== 0) {
            return CosmosTxInclusionResult::failedOnChain($first['code']);
        }

        // ── The block header at that height, on every node ─────────────
        $time = null;
        foreach ($endpoints as $endpoint) {
            $header = self::readHeader($slug, $chainId, $endpoint, $first['height'], $budget, $deadlineAt);
            if ($header === null) {
                return CosmosTxInclusionResult::notYet();
            }
            if ($header['chain_id'] !== $network) {
                self::count('network_mismatch');
                \BCC\Core\Log\Logger::warning('[bcc-trust] cosmos tx proof: a block header names another chain', [
                    'chain' => $slug, 'operator' => $endpoint['operator'],
                ]);

                return CosmosTxInclusionResult::notYet();
            }
            if ($time !== null && $header['time'] !== $time) {
                self::disagreement($slug, 'block time', $endpoints[0]['operator'], $endpoint['operator']);

                return CosmosTxInclusionResult::notYet();
            }
            $time = $header['time'];
        }

        // ── The operator account's number (one operator suffices) ──────
        $accountNumber = null;
        foreach ($endpoints as $endpoint) {
            $accountNumber = self::readAccountNumber($slug, $chainId, $endpoint, $account, $budget, $deadlineAt);
            if ($accountNumber !== null) {
                break;
            }
        }
        if ($accountNumber === null || $time === null) {
            return CosmosTxInclusionResult::notYet();
        }

        return CosmosTxInclusionResult::included(new CosmosTxInclusion(
            $slug,
            $first['bytes'],
            $hash,
            $network,
            $accountNumber,
            $operatorAddress,
            $first['height'],
            $time,
            $prefix
        ));
    }

    /**
     * The validator's `description.security_contact`, counted only when
     * every proof endpoint returns the same value.
     */
    public static function securityContact(
        string $slug,
        string $operatorAddress,
        ProviderRequestBudget $budget,
        ?int $deadlineAt = null
    ): CosmosSecurityContact {
        $endpoints = CosmosTxProofEndpoints::forSlug($slug);
        $chain     = ChainRepository::getBySlug($slug);
        if ($endpoints === [] || $chain === null
            || self::operatorAccount($operatorAddress, (string) CosmosEndpointPolicy::pinnedAccountPrefix($slug)) === null
        ) {
            return CosmosSecurityContact::unavailable();
        }
        if (!self::everyNodeOnNetwork($slug, $endpoints, $budget)) {
            return CosmosSecurityContact::unavailable();
        }

        $values = [];
        foreach ($endpoints as $endpoint) {
            $data = self::lcdGet($slug, (int) $chain->id, $endpoint, '/cosmos/staking/v1beta1/validators/' . $operatorAddress, $budget, $deadlineAt);
            $raw  = is_array($data) ? ($data['validator']['description']['security_contact'] ?? null) : null;
            if (!is_string($raw) || strlen($raw) > self::MAX_CONTACT_LENGTH) {
                return CosmosSecurityContact::unavailable();
            }
            $values[] = [$endpoint['operator'], trim($raw)];
        }

        foreach ($values as [$operator, $value]) {
            if ($value !== $values[0][1]) {
                self::count('contact_disagreement');
                \BCC\Core\Log\Logger::warning('[bcc-trust] cosmos tx proof: operators disagree on a validator security contact', [
                    'chain' => $slug, 'operators' => [$values[0][0], $operator],
                ]);

                return CosmosSecurityContact::disagreed();
            }
        }

        return $values[0][1] === '' ? CosmosSecurityContact::notSet() : CosmosSecurityContact::agreed($values[0][1]);
    }

    /**
     * PURE. Is this response CometBFT's "tx … not found" answer (HTTP 500,
     * JSON-RPC error data `tx (<HASH>) not found`)? Exactly that, and
     * nothing else, so no other failure can hide behind it.
     */
    public static function isTxNotFound(string $body, int $code): bool
    {
        if ($code !== 500) {
            return false;
        }
        $decoded = json_decode($body, true);
        $data    = is_array($decoded) && is_array($decoded['error'] ?? null) ? ($decoded['error']['data'] ?? null) : null;

        return is_string($data) && preg_match(self::TX_NOT_FOUND, $data) === 1;
    }

    /** PURE. 64 hex characters (an optional `0x` dropped), uppercased; null otherwise. */
    public static function normalizeHash(string $hash): ?string
    {
        $hash = trim($hash);
        if (str_starts_with($hash, '0x') || str_starts_with($hash, '0X')) {
            $hash = substr($hash, 2);
        }

        return preg_match('/^[0-9A-Fa-f]{64}$/', $hash) === 1 ? strtoupper($hash) : null;
    }

    /**
     * PURE. The operator ACCOUNT address of a valoper on a chain whose account
     * prefix is $prefix: the valoper's 20 bytes re-encoded. Null unless the
     * valoper is a checksum-valid `<prefix>valoper` address of 20 bytes.
     */
    public static function operatorAccount(string $valoper, string $prefix): ?string
    {
        if ($prefix === '' || $valoper !== strtolower($valoper)) {
            return null;
        }
        // bcc-core's strict decoder: checksum, case, length and padding, so only
        // the one canonical spelling of an address decodes.
        $decoded = Bech32::decode($valoper);
        if ($decoded === null || $decoded['hrp'] !== $prefix . 'valoper' || strlen($decoded['bytes']) !== 20) {
            return null;
        }

        return Bech32::encode($prefix, $decoded['bytes']);
    }

    /**
     * PURE. The header time as unix seconds, from CometBFT's RFC 3339 UTC.
     * Null on anything else.
     */
    public static function headerTime(string $time): ?int
    {
        if (preg_match(self::HEADER_TIME, $time, $m) !== 1) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $m[1], new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->getTimestamp();
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * @param list<array{operator: string, rpc: string, rest: string}> $endpoints
     */
    private static function everyNodeOnNetwork(string $slug, array $endpoints, ProviderRequestBudget $budget): bool
    {
        foreach ($endpoints as $endpoint) {
            if ($budget->timedOut()) {
                return false;
            }
            $gate = CosmosEndpointVerifier::verifyProofRpc($slug, $endpoint['rpc']);
            if (!$gate['ok']) {
                if ($gate['reason'] === CosmosEndpointVerifier::NETWORK_MISMATCH) {
                    self::count('network_mismatch');
                    \BCC\Core\Log\Logger::warning('[bcc-trust] cosmos tx proof: a node reports another network', [
                        'chain' => $slug, 'operator' => $endpoint['operator'],
                    ]);
                }

                return false;
            }
        }

        return true;
    }

    /**
     * The transaction as one node serves it, or null when that node has not
     * indexed it (an answer) or could not be read (a failure).
     *
     * @param array{operator: string, rpc: string, rest: string} $endpoint
     * @return array{bytes: string, height: int, code: int}|null
     */
    private static function readTx(string $slug, int $chainId, array $endpoint, string $hash, ProviderRequestBudget $budget, ?int $deadlineAt): ?array
    {
        $response = self::request($slug, $chainId, $endpoint['rpc'], $endpoint['rpc'] . '/tx?hash=0x' . $hash, 'Cosmos tx proof /tx', $budget, $deadlineAt, true);
        if ($response === null) {
            return null;
        }
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        $txB64    = $result['tx'] ?? null;
        $height   = $result['height'] ?? null;
        $txResult = $result['tx_result'] ?? null;
        if (!is_array($txResult)) {
            return null; // no execution result: never read as a success
        }
        // CometBFT's ABCI JSON is `omitempty`: a successful tx usually has no
        // `code` at all. Only an ABSENT code reads as 0.
        $code = array_key_exists('code', $txResult) ? $txResult['code'] : 0;
        if (!is_string($txB64) || strlen($txB64) > (int) ceil(self::MAX_TX_BYTES / 3) * 4
            || strtoupper((string) ($result['hash'] ?? '')) !== $hash
            || !is_numeric($height) || (int) $height < 1
            || !is_int($code) || $code < 0
        ) {
            return null;
        }
        $bytes = base64_decode($txB64, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_TX_BYTES) {
            return null;
        }

        return ['bytes' => $bytes, 'height' => (int) $height, 'code' => $code];
    }

    /**
     * @param array{operator: string, rpc: string, rest: string} $endpoint
     * @return array{chain_id: string, time: int}|null
     */
    private static function readHeader(string $slug, int $chainId, array $endpoint, int $height, ProviderRequestBudget $budget, ?int $deadlineAt): ?array
    {
        $response = self::request($slug, $chainId, $endpoint['rpc'], $endpoint['rpc'] . '/commit?height=' . $height, 'Cosmos tx proof /commit', $budget, $deadlineAt, false);
        $result   = is_array($response) ? ($response['result'] ?? $response) : null;
        $header   = is_array($result) ? ($result['signed_header']['header'] ?? null) : null;
        if (!is_array($header) || !is_string($header['chain_id'] ?? null) || !is_string($header['time'] ?? null)
            || (string) ($header['height'] ?? '') !== (string) $height
        ) {
            return null;
        }
        $time = self::headerTime($header['time']);

        return $time === null ? null : ['chain_id' => $header['chain_id'], 'time' => $time];
    }

    /**
     * @param array{operator: string, rpc: string, rest: string} $endpoint
     */
    private static function readAccountNumber(string $slug, int $chainId, array $endpoint, string $account, ProviderRequestBudget $budget, ?int $deadlineAt): ?int
    {
        $data    = self::lcdGet($slug, $chainId, $endpoint, '/cosmos/auth/v1beta1/accounts/' . $account, $budget, $deadlineAt);
        $acct    = is_array($data) ? ($data['account'] ?? null) : null;
        $numbers = is_array($acct) ? [
            $acct['account_number'] ?? null,                                     // BaseAccount
            $acct['base_account']['account_number'] ?? null,                     // EthAccount (Injective)
            $acct['base_vesting_account']['base_account']['account_number'] ?? null, // vesting accounts
        ] : [];
        foreach ($numbers as $number) {
            if (is_string($number) && preg_match('/^\d{1,18}$/', $number) === 1) {
                return (int) $number;
            }
        }

        return null;
    }

    /**
     * @param array{operator: string, rpc: string, rest: string} $endpoint
     * @return array<string, mixed>|null
     */
    private static function lcdGet(string $slug, int $chainId, array $endpoint, string $path, ProviderRequestBudget $budget, ?int $deadlineAt): ?array
    {
        return self::request($slug, $chainId, $endpoint['rest'], $endpoint['rest'] . $path, 'Cosmos tx proof LCD', $budget, $deadlineAt, false);
    }

    /**
     * One budgeted ApiRetry GET, charged to the chain's breaker under this
     * endpoint's pinned fingerprint. The decoded JSON on a 200, else null.
     *
     * @return array<string, mixed>|null
     */
    private static function request(
        string $slug,
        int $chainId,
        string $base,
        string $url,
        string $label,
        ProviderRequestBudget $budget,
        ?int $deadlineAt,
        bool $txNotFoundIsAnswer
    ): ?array {
        if (!$budget->canSpend()) {
            return null;
        }
        $budget->spend();

        $options = [
            'label'       => $label,
            'chain_id'    => $chainId,
            'endpoint_fp' => CosmosEndpointPolicy::pinnedFingerprint($slug, $base),
        ];
        if ($deadlineAt !== null) {
            $options['deadline_at'] = $deadlineAt;
        }
        if ($txNotFoundIsAnswer) {
            $options['application_error'] = static fn (string $body, int $code): bool => self::isTxNotFound($body, $code);
        }

        $response = ApiRetry::get($url, [
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'headers'     => ['Accept' => 'application/json'],
        ], $options);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function disagreement(string $slug, string $what, string $operatorA, string $operatorB): void
    {
        self::count('endpoint_disagreement');
        \BCC\Core\Log\Logger::warning('[bcc-trust] cosmos tx proof: operators disagree', [
            'chain' => $slug, 'on' => $what, 'operators' => [$operatorA, $operatorB],
        ]);
    }

    private static function count(string $event): void
    {
        if (class_exists('\\BCC\\Core\\Observability\\DegradationMetrics')) {
            \BCC\Core\Observability\DegradationMetrics::record(self::METRIC_SUBSYSTEM, $event);
        }
    }
}
