<?php

declare(strict_types=1);

/**
 * The endpoint-proof seam, shared by every suite that touches discovery.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * PR 7.11 made "has an administrator proven this chain's endpoint?" a
 * condition of scanning. Two consequences land on the harness:
 *
 *   1. surfaces that must NOT verify (renders, cron, the maintenance sweep,
 *      worker chunks) need a way for a test to PROVE they made no request —
 *      hence the recording {@see \BCC\Core\Http\SafeHttpClient} fake, and
 *      `assertSame([], SafeHttpClient::$calls)` as the assertion;
 *   2. surfaces that legitimately verify need a scripted answer, or every
 *      pre-existing admin test starts failing for a reason unrelated to what
 *      it tests.
 *
 * Both are here, in ONE file, required by the stub sets rather than copied
 * into them: three divergent SafeHttpClient fakes is how a suite ends up
 * asserting against a recorder that was never the one the code called.
 *
 * ⚠ NOTHING HERE WEAKENS THE RULE. `approve()` writes the same option the
 * production recorder writes, through the production class, after computing
 * the fingerprint with the production policy. A test cannot use it to
 * authorize an endpoint the policy does not approve — `isAuthorized()`
 * re-checks approval on every read, so an unapproved URL stays unauthorized
 * no matter what is recorded.
 */

namespace {
    /**
     * ⚠ SELF-SUFFICIENT ON PURPOSE. Two of the three stub trees that need the
     * endpoint proof never defined an option store, and the proof is stored in
     * an option. Relying on some earlier suite in the same PHP process having
     * defined `get_option` would make these tests pass or fatal depending on
     * alphabetical order — the exact class of false green this harness has
     * been bitten by before. Every declaration below is guarded, so a tree
     * that already has its own store keeps it.
     */
    /**
     * ⚠ Guarded like everything else here. A tree that never needed to script
     * a transport failure never declared it, and `scriptUnreachable()` is the
     * first thing that asks for one.
     */
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            /** @var array<string, list<string>> */
            private array $errors = [];

            /** @param mixed $data */
            public function __construct(string $code = '', string $message = '', $data = null)
            {
                if ($code !== '') {
                    $this->errors[$code][] = $message;
                }
            }

            public function get_error_code(): string
            {
                $keys = array_keys($this->errors);

                return $keys === [] ? '' : (string) $keys[0];
            }

            public function get_error_message(): string
            {
                foreach ($this->errors as $messages) {
                    return (string) ($messages[0] ?? '');
                }

                return '';
            }
        }
    }

    if (!class_exists('BccTestOptionStore', false)) {
        final class BccTestOptionStore
        {
            /** @var array<string, mixed> */
            public static array $options = [];

            /** @var array<string, mixed> */
            public static array $transients = [];

            public static function reset(): void
            {
                self::$options    = [];
                self::$transients = [];
            }
        }
    }

    if (!function_exists('get_option')) {
        /**
         * @param  mixed $default
         * @return mixed
         */
        function get_option(string $option, $default = false)
        {
            return \BccTestOptionStore::$options[$option] ?? $default;
        }
    }

    if (!function_exists('update_option')) {
        /** @param mixed $value */
        function update_option(string $option, $value, ?bool $autoload = null): bool
        {
            \BccTestOptionStore::$options[$option] = $value;

            return true;
        }
    }

    if (!function_exists('delete_option')) {
        function delete_option(string $option): bool
        {
            if (!array_key_exists($option, \BccTestOptionStore::$options)) {
                return false;
            }
            unset(\BccTestOptionStore::$options[$option]);

            return true;
        }
    }

    /**
     * The three HTTP shims the verifier reads its answer through.
     *
     * ⚠ Same reason as the option store: the admin stub tree defines none of
     * them, so before this file existed a verification there resolved — or
     * did not — according to which OTHER suite had already run in the same
     * process. A test that passes because of alphabetical ordering is not a
     * test. The response shape (`['code' => int, 'body' => string]`) matches
     * every other stub set in the harness.
     */
    if (!function_exists('is_wp_error')) {
        /** @param mixed $thing */
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (!function_exists('wp_remote_retrieve_response_code')) {
        /** @param mixed $response */
        function wp_remote_retrieve_response_code($response): int
        {
            return is_array($response) ? (int) ($response['code'] ?? 200) : 0;
        }
    }

    if (!function_exists('wp_remote_retrieve_body')) {
        /** @param mixed $response */
        function wp_remote_retrieve_body($response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }

    /**
     * Transients, for the verifier's SHORT POSITIVE CACHE.
     *
     * ⚠ NOT an implementation detail a test should reach into. They are here
     * because the verifier writes a successful proof to a transient and reads
     * it back, so a tree without them throws from inside the production code
     * — which surfaces as the admin route's generic `error` result and looks
     * like a defect in the route rather than a missing shim.
     */
    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $transient)
        {
            return \BccTestOptionStore::$transients[$transient] ?? false;
        }
    }

    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $transient, $value, int $expiration = 0): bool
        {
            \BccTestOptionStore::$transients[$transient] = $value;

            return true;
        }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient(string $transient): bool
        {
            if (!array_key_exists($transient, \BccTestOptionStore::$transients)) {
                return false;
            }
            unset(\BccTestOptionStore::$transients[$transient]);

            return true;
        }
    }

    if (!class_exists('BccTestEndpointProof', false)) {
        /**
         * Test-side control of the endpoint proof.
         *
         * Deliberately tiny: script a node_info answer, or record a proof
         * directly. Anything more would be a second implementation of the
         * thing under test.
         */
        final class BccTestEndpointProof
        {
            /** The approved primary for the one governed chain. */
            public const APPROVED_PRIMARY = 'https://cosmos-api.polkachu.com';

            /** The approved incumbent — production's endpoint. */
            public const APPROVED_INCUMBENT = 'https://rest.cosmos.directory/cosmoshub';

            /** An unapproved host that is otherwise perfectly well-formed. */
            public const UNAPPROVED = 'https://lcd.example';

            /**
             * Script the next identity request as a valid node_info answer.
             *
             * The default network is the one the policy expects, so the
             * common case is a one-word call. Pass a different network to
             * exercise the mismatch arm.
             */
            public static function scriptNodeInfo(string $network = 'cosmoshub-4'): void
            {
                \BCC\Core\Http\SafeHttpClient::$next = [
                    'code' => 200,
                    'body' => (string) json_encode([
                        'default_node_info' => ['network' => $network],
                    ]),
                ];
            }

            /** Script the next identity request as unreachable. */
            public static function scriptUnreachable(): void
            {
                \BCC\Core\Http\SafeHttpClient::$next = new \WP_Error('http_request_failed', 'scripted');
            }

            /**
             * Record a proof for a chain, exactly as the production recorder
             * would — same class, same option, same fingerprint algorithm.
             *
             * Returns false when the chain is ungoverned or its endpoint is
             * not approved, because in those cases there is nothing a proof
             * could say. A test that gets false and expected true has a
             * fixture pointed somewhere the policy does not allow.
             */
            public static function approve(int $chainId, string $slug, string $url): bool
            {
                $fingerprint = \BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy::fingerprint($slug, $url);
                if ($fingerprint === null
                    || !\BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy::isApproved($slug, $url)
                ) {
                    return false;
                }

                return \BCC\Trust\Onchain\Support\CosmosEndpointAuthorization::record(
                    $chainId,
                    $fingerprint,
                    (string) \BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy::expectedNetwork($slug),
                    1
                );
            }

            /** Forget every recorded proof and every scripted answer. */
            public static function reset(): void
            {
                \BCC\Core\Http\SafeHttpClient::$calls = [];
                \BCC\Core\Http\SafeHttpClient::$next  = null;

                foreach (array_keys(\BccTestOptionStore::$options) as $key) {
                    if (str_starts_with((string) $key, 'bcc_cosmos_endpoint_authz_')) {
                        unset(\BccTestOptionStore::$options[$key]);
                    }
                }
            }
        }
    }
}

namespace BCC\Core\Http {
    /**
     * The SSRF-hardened client, faked at its production FQN.
     *
     * ⚠ RECORDS ITS ARGS, so a test can assert both that a request was NOT
     * made and that a request that WAS made did not enable redirects.
     *
     * ⚠ GUARDED, like every other declaration of this FQN in the harness:
     * PHPUnit loads the whole suite into one process, so whichever stub set
     * loads first wins. All of them are the same shape on purpose.
     */
    if (!class_exists(SafeHttpClient::class, false)) {
        final class SafeHttpClient
        {
            /** @var list<array{url: string, args: array<string, mixed>}> */
            public static array $calls = [];

            /** @var array<string, mixed>|\WP_Error|null */
            public static $next = null;

            public static function reset(): void
            {
                self::$calls = [];
                self::$next  = null;
            }

            /**
             * @param  array<string, mixed> $args
             * @return array<string, mixed>|\WP_Error
             */
            public static function get(string $url, array $args = [])
            {
                self::$calls[] = ['url' => $url, 'args' => $args];

                return self::$next ?? new \WP_Error('http_request_failed', 'no scripted response');
            }

            /** @param array<string, mixed> $args */
            public static function prepareArgs(string $url, array $args = []): array
            {
                return $args;
            }
        }
    }
}
