<?php

declare(strict_types=1);

namespace BCC\Core\Http {
    /**
     * Fake SafeHttpClient, defined at its real FQN before XApiService is
     * loaded so the `use BCC\Core\Http\SafeHttpClient` import resolves here.
     *
     * bcc-trust's composer autoload maps only BCC\Trust\*, so the real
     * bcc-core class is never autoloadable from this suite — this stub is
     * what makes XApiService reachable at all, not merely a convenience.
     */
    final class SafeHttpClient
    {
        /** @var array<string, mixed> */
        public static array $response = [];
        public static string $lastUrl = '';

        /**
         * @param  array<string, mixed> $args
         * @return array<string, mixed>
         */
        public static function get(string $url, array $args = []): array
        {
            unset($args);
            self::$lastUrl = $url;
            return self::$response;
        }
    }
}

namespace BCC\Trust\Core\Services\x {

    // WordPress helpers, faked at their fully-qualified names inside the
    // service's own namespace so PHP's fallback resolves the unqualified
    // calls in XApiService to these.
    if (!function_exists('BCC\\Trust\\Core\\Services\\x\\is_wp_error')) {
        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\x\\wp_remote_retrieve_response_code')) {
        /** @param array<string, mixed> $response */
        function wp_remote_retrieve_response_code(array $response): int
        {
            $code = $response['code'] ?? 0;
            return is_int($code) ? $code : 0;
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\x\\wp_remote_retrieve_body')) {
        /** @param array<string, mixed> $response */
        function wp_remote_retrieve_body(array $response): string
        {
            $body = $response['body'] ?? '';
            return is_string($body) ? $body : '';
        }
    }
    if (!function_exists('BCC\\Trust\\Core\\Services\\x\\wp_parse_url')) {
        /** @return array<string, int|string>|string|int|false|null */
        function wp_parse_url(string $url, int $component = -1)
        {
            return parse_url($url, $component);
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Core\Http\SafeHttpClient;
    use BCC\Trust\Core\Services\x\XApiService;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * Pins share_x verification against X's link-shortening behaviour.
     *
     * ## The bug this pins
     *
     * `QuestValidator::validateShareX()` searches a user's recent tweets for
     * the FRONTEND HOST (e.g. "bluecollarcrypto.io"). It used to search only
     * the tweet's `text` field.
     *
     * X rewrites every link in `text` to a t.co shortlink. A tweet that
     * visibly reads "… bluecollarcrypto.io/u/phillip" comes back from the API
     * as "… https://t.co/aB3xY9" — so the host is simply not in `text`. The
     * share_x quest asks people to post a LINK, which means the check could
     * only ever fail for a correct share. The user was told "No matching
     * tweet found yet" for a tweet that was right there.
     *
     * The original URL survives in `entities.urls[]`, which is now requested
     * and searched.
     */
    #[CoversClass(XApiService::class)]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class XShareVerificationTest extends TestCase
    {
        private const HOST  = 'bluecollarcrypto.io';
        private const TOKEN = 'test-token';
        private const X_ID  = '1234567890';

        protected function setUp(): void
        {
            parent::setUp();
            SafeHttpClient::$response = [];
            SafeHttpClient::$lastUrl  = '';
        }

        /**
         * @param list<array<string, mixed>> $tweets
         * @return array<string, mixed>
         */
        private static function payload(array $tweets): array
        {
            return [
                'code' => 200,
                'body' => json_encode(['data' => $tweets], JSON_THROW_ON_ERROR),
            ];
        }

        /** @param list<array<string, mixed>> $tweets */
        private function findsHostIn(array $tweets): bool
        {
            SafeHttpClient::$response = self::payload($tweets);

            return (new XApiService())
                ->hasRecentTweetContaining(self::TOKEN, self::X_ID, self::HOST);
        }

        // ── The regression this file exists for ──────────────────────────

        /**
         * The exact shape X returns for a shared link: host absent from
         * `text`, present only in the expanded entity.
         */
        public function testSharedLinkIsFoundEvenThoughTextOnlyHasTheShortlink(): void
        {
            $found = $this->findsHostIn([[
                'text'     => 'Building trust on the floor. https://t.co/aB3xY9',
                'entities' => ['urls' => [[
                    'url'          => 'https://t.co/aB3xY9',
                    'expanded_url' => 'https://bluecollarcrypto.io/u/phillip',
                    'display_url'  => 'bluecollarcrypto.io/u/phillip',
                ]]],
            ]]);

            self::assertTrue($found, 'a correctly shared link must verify');
        }

        public function testShortlinkAloneIsNotEnough(): void
        {
            // No entities at all — nothing ties the t.co back to our host, so
            // this must NOT pass. Guards against "match anything with a link".
            $found = $this->findsHostIn([[
                'text' => 'Building trust on the floor. https://t.co/aB3xY9',
            ]]);

            self::assertFalse($found);
        }

        // ── The pre-existing text path must keep working ─────────────────

        /** @return iterable<string, array{array<string, mixed>, bool}> */
        public static function tweets(): iterable
        {
            yield 'plain-text host in body' => [
                ['text' => 'come find me at bluecollarcrypto.io'],
                true,
            ];
            yield 'host only in display_url' => [
                [
                    'text'     => 'my profile https://t.co/zzz',
                    'entities' => ['urls' => [['display_url' => 'bluecollarcrypto.io/u/phillip']]],
                ],
                true,
            ];
            yield 'host only in unwound_url' => [
                [
                    'text'     => 'via a redirect https://t.co/zzz',
                    'entities' => ['urls' => [['unwound_url' => 'https://bluecollarcrypto.io/u/phillip']]],
                ],
                true,
            ];
            yield 'case insensitive' => [
                ['text' => 'BlueCollarCrypto.IO is where I post'],
                true,
            ];
            yield 'unrelated tweet' => [
                [
                    'text'     => 'thoughts on markets https://t.co/qqq',
                    'entities' => ['urls' => [['expanded_url' => 'https://example.com/post']]],
                ],
                false,
            ];
            // Host is parsed, not substring-matched, so a domain that merely
            // CONTAINS ours cannot claim the bonus.
            yield 'attacker domain containing our host is rejected' => [
                [
                    'text'     => 'see https://t.co/qqq',
                    'entities' => ['urls' => [['expanded_url' => 'https://bluecollarcrypto.io.evil.com/x']]],
                ],
                false,
            ];
            yield 'prefixed lookalike is rejected' => [
                [
                    'text'     => 'see https://t.co/qqq',
                    'entities' => ['urls' => [['expanded_url' => 'https://notbluecollarcrypto.io/x']]],
                ],
                false,
            ];
            yield 'real subdomain is accepted' => [
                [
                    'text'     => 'see https://t.co/qqq',
                    'entities' => ['urls' => [['expanded_url' => 'https://www.bluecollarcrypto.io/u/phillip']]],
                ],
                true,
            ];
        }

        /**
         * @param array<string, mixed> $tweet
         */
        #[DataProvider('tweets')]
        public function testMatching(array $tweet, bool $expected): void
        {
            self::assertSame($expected, $this->findsHostIn([$tweet]));
        }

        // ── The request itself ───────────────────────────────────────────

        public function testEntitiesAreRequestedFromTheApi(): void
        {
            $this->findsHostIn([]);

            self::assertStringContainsString(
                'entities',
                urldecode(SafeHttpClient::$lastUrl),
                'without tweet.fields=entities the expanded URL never arrives'
            );
        }

        // ── Malformed payloads must not fatal ────────────────────────────

        /** @return iterable<string, array{mixed}> */
        public static function malformed(): iterable
        {
            yield 'data not an array'   => ['not-an-array'];
            yield 'tweet not an array'  => [['just a string']];
            yield 'urls not an array'   => [[['text' => 'x', 'entities' => ['urls' => 'nope']]]];
            yield 'url entry not array' => [[['text' => 'x', 'entities' => ['urls' => ['nope']]]]];
            yield 'null fields'         => [[['text' => null, 'entities' => null]]];
        }

        #[DataProvider('malformed')]
        public function testMalformedPayloadReturnsFalseWithoutError(mixed $data): void
        {
            SafeHttpClient::$response = [
                'code' => 200,
                'body' => json_encode(['data' => $data], JSON_THROW_ON_ERROR),
            ];

            $found = (new XApiService())
                ->hasRecentTweetContaining(self::TOKEN, self::X_ID, self::HOST);

            self::assertFalse($found);
        }

        public function testNonOkStatusReturnsFalse(): void
        {
            SafeHttpClient::$response = ['code' => 401, 'body' => '{"title":"Unauthorized"}'];

            self::assertFalse(
                (new XApiService())->hasRecentTweetContaining(self::TOKEN, self::X_ID, self::HOST)
            );
        }

        public function testEmptyNeedleNeverMatches(): void
        {
            SafeHttpClient::$response = self::payload([['text' => 'anything at all']]);

            self::assertFalse(
                (new XApiService())->hasRecentTweetContaining(self::TOKEN, self::X_ID, ''),
                'an unset frontend origin must not auto-complete the quest'
            );
        }
    }
}
