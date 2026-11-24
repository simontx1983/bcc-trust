<?php

declare(strict_types=1);

/**
 * Doubles for the memo claim's Cosmos transaction proof
 * ({@see \BCC\Trust\Onchain\Services\CosmosTxInclusionFetcher}).
 *
 * Built on the REAL transport stack (enumeration-real-transport-stubs:
 * ApiRetry, OnchainCircuitBreaker, the option/transient/cache stores are
 * production code). Only the wire is scripted, by URL ({@see \BccWire::$routes}),
 * so each node's answer is stated once whatever the request order.
 *
 * Adds a recording DegradationMetrics (bcc-core is not loaded in the unit
 * context) and builders for the CometBFT and LCD bodies the fetcher reads,
 * shaped as the live nodes answered on 2026-11-24.
 */

namespace BCC\Core\Observability {
    if (!class_exists(__NAMESPACE__ . '\\DegradationMetrics', false)) {
        final class DegradationMetrics
        {
            /** @var list<array{0: string, 1: string}> */
            public static array $records = [];

            public static function record(string $subsystem, string $event): void
            {
                self::$records[] = [$subsystem, $event];
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/enumeration-real-transport-stubs.php';

    /** Scripted node answers and the chain row, for one scenario. */
    final class BccTxProof
    {
        public const CHAIN_ID = 3;

        /** Seed the active-chains cache with the chains a scenario needs. */
        public static function seedChains(): void
        {
            \BccBreakerStore::$cache['bcc_chains:active_all'] = [
                (object) ['id' => (string) self::CHAIN_ID, 'slug' => 'cosmos', 'chain_type' => 'cosmos', 'bech32_prefix' => 'cosmos'],
                (object) ['id' => '9', 'slug' => 'injective', 'chain_type' => 'cosmos', 'bech32_prefix' => 'inj'],
                (object) ['id' => '11', 'slug' => 'kujira', 'chain_type' => 'cosmos', 'bech32_prefix' => 'kujira'],
            ];
        }

        /** Answer every URL containing $needle with $response. */
        public static function route(string $needle, $response): void
        {
            \BccWire::$routes[] = [$needle, $response];
        }

        /** @return array{code: int, body: string} */
        public static function ok(array $json): array
        {
            return ['code' => 200, 'body' => (string) json_encode($json)];
        }

        /** @return array{code: int, body: string} */
        public static function status(string $network): array
        {
            return self::ok(['jsonrpc' => '2.0', 'id' => -1, 'result' => [
                'node_info' => ['network' => $network, 'other' => ['tx_index' => 'on']],
                'sync_info' => ['latest_block_height' => '200'],
            ]]);
        }

        /**
         * @param array<string, mixed>|null $txResult null = no tx_result at all
         * @return array{code: int, body: string}
         */
        public static function tx(string $hash, string $bytes, int $height = 123, ?array $txResult = ['gas_used' => '70000']): array
        {
            $result = ['hash' => strtoupper($hash), 'height' => (string) $height, 'index' => 0, 'tx' => base64_encode($bytes)];
            if ($txResult !== null) {
                $result['tx_result'] = $txResult;
            }

            return self::ok(['jsonrpc' => '2.0', 'id' => -1, 'result' => $result]);
        }

        /** CometBFT's answer for a hash it has not indexed (HTTP 500). @return array{code: int, body: string} */
        public static function notFound(string $hash): array
        {
            return ['code' => 500, 'body' => (string) json_encode(['jsonrpc' => '2.0', 'id' => -1, 'error' => [
                'code' => -32603, 'message' => 'Internal error', 'data' => 'tx (' . strtoupper($hash) . ') not found',
            ]])];
        }

        /** @return array{code: int, body: string} */
        public static function commit(int $height, string $chainId, string $time): array
        {
            return self::ok(['jsonrpc' => '2.0', 'id' => -1, 'result' => [
                'signed_header' => ['header' => ['chain_id' => $chainId, 'height' => (string) $height, 'time' => $time]],
                'canonical'     => true,
            ]]);
        }

        /** @param array<string, mixed> $account @return array{code: int, body: string} */
        public static function account(array $account): array
        {
            return self::ok(['account' => $account]);
        }

        /** @return array{code: int, body: string} */
        public static function validator(?string $contact): array
        {
            $description = ['moniker' => 'Validator One'];
            if ($contact !== null) {
                $description['security_contact'] = $contact;
            }

            return self::ok(['validator' => ['operator_address' => 'x', 'description' => $description]]);
        }

        /** Events recorded under the proof's metric subsystem. @return list<string> */
        public static function metrics(): array
        {
            return array_values(array_map(
                static fn (array $r): string => $r[1],
                array_filter(
                    \BCC\Core\Observability\DegradationMetrics::$records,
                    static fn (array $r): bool => $r[0] === 'cosmos_tx_proof'
                )
            ));
        }
    }
}
