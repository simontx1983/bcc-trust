<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Core\Plugin;
    use BCC\Trust\Core\REST\OnboardingEndpoint;
    use BCC\Trust\Core\Services\PageDiscoveryService;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-439 — GET /onboarding/suggestions?chain= biases the VALIDATORS bucket
     * toward a home chain, and says honestly whether it did.
     *
     * Before this, the onboarding chips ("Bias suggestions toward") changed
     * nothing: the endpoint took no chain. Now:
     *   - validators: chain-filtered pages first (PageDiscoveryService's
     *     `chain_slug`), then the unbiased trust ranking (the plain path's
     *     own query and cache key), deduped, never more than 4 and never
     *     fewer cards than without a chain, counted on the cards built;
     *   - projects and creators: unchanged (no chain data to bias by);
     *   - data.bias = {chain, applied_to}: applied_to is ["validators"] only
     *     when the chain filter really ran (not the legacy fallback, which
     *     ignores chain_slug) AND at least one chain page built a card in
     *     the bucket (a page can build no card for the viewer);
     *   - an unknown chain is a 400, never a silent unbiased answer.
     *
     * The discovery service is a scripted subclass; everything else is the
     * real endpoint.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class OnboardingSuggestionsChainBiasTest extends TestCase
    {
        private ScriptedDiscovery $discovery;

        protected function setUp(): void
        {
            parent::setUp();
            if (!defined('ABSPATH')) {
                define('ABSPATH', sys_get_temp_dir() . '/');
            }
            require_once dirname(__DIR__) . '/Stubs/onboarding-suggestions-stubs.php';
            $GLOBALS['__bcc_current_user'] = 7;
            Plugin::$cardCalls = [];
            Plugin::$noCard    = [];
            \BCC\Trust\Core\Support\PageCardPrefetcher::$primed = [];
            $this->discovery   = new ScriptedDiscovery([
                // The unbiased trust ranking per type.
                'validator|' => [21, 1, 2, 3, 4, 5, 6, 7],
                'builder|'   => [31, 32, 33, 34],
                'nft|'       => [41, 42, 43, 44],
                // Two Cosmos validators.
                'validator|cosmos' => ['ids' => [21, 22], 'applied' => true],
                // Osmosis has plenty.
                'validator|osmosis' => ['ids' => [51, 52, 53, 54, 55], 'applied' => true],
                // The filter ran and Ethereum has no validators.
                'validator|ethereum' => ['ids' => [], 'applied' => true],
                // Legacy fallback: chain_slug ignored, rows are a plain ranking.
                'validator|injective' => ['ids' => [9, 8, 7, 6], 'applied' => false],
            ]);
            Plugin::$discovery = $this->discovery;
        }

        /** @param array<string, mixed> $params */
        private static function call(array $params = []): \WP_REST_Response
        {
            $endpoint = (new \ReflectionClass(OnboardingEndpoint::class))->newInstanceWithoutConstructor();
            return $endpoint->suggestions(new \WP_REST_Request($params));
        }

        /** @return array<string, mixed> */
        private static function data(\WP_REST_Response $resp): array
        {
            $body = $resp->get_data();
            self::assertIsArray($body);
            self::assertIsArray($body['data'] ?? null);
            return $body['data'];
        }

        /** @return list<int> */
        private static function ids(array $data, string $bucket): array
        {
            return array_map(static fn (array $c): int => $c['page_id'], $data[$bucket]);
        }

        public function testWithoutAChainNothingChangesAndBiasIsNull(): void
        {
            $data = self::data(self::call());

            self::assertSame([21, 1, 2, 3], self::ids($data, 'validators'));
            self::assertSame([31, 32, 33, 34], self::ids($data, 'projects'));
            self::assertSame([41, 42, 43, 44], self::ids($data, 'creators'));
            self::assertNull($data['bias']);
            self::assertCount(3, $this->discovery->calls);
            foreach ($this->discovery->calls as $args) {
                self::assertArrayNotHasKey('chain_slug', $args);
                self::assertSame(4, $args['limit']);
            }
        }

        public function testChainValidatorsComeFirstThenTheUnbiasedRankingDeduped(): void
        {
            $data = self::data(self::call(['chain' => 'cosmos']));

            // 21 and 22 are on Cosmos; 21 is also top of the unbiased list,
            // so the top-up skips it.
            self::assertSame([21, 22, 1, 2], self::ids($data, 'validators'));
            self::assertSame(['chain' => 'cosmos', 'applied_to' => ['validators']], $data['bias']);
        }

        public function testTheTopUpIsThePlainPathsOwnQuery(): void
        {
            self::call();
            $plain = $this->discovery->calls;
            $this->discovery->calls = [];

            self::call(['chain' => 'cosmos']);

            $validatorCalls = array_values(array_filter(
                $this->discovery->calls,
                static fn (array $a): bool => $a['types'] === ['validator']
            ));
            self::assertCount(2, $validatorCalls);
            $chainCalls = array_values(array_filter($validatorCalls, static fn (array $a): bool => isset($a['chain_slug'])));
            $plainCalls = array_values(array_filter($validatorCalls, static fn (array $a): bool => !isset($a['chain_slug'])));
            self::assertCount(1, $chainCalls);
            self::assertSame('cosmos', $chainCalls[0]['chain_slug']);
            self::assertSame(4, $chainCalls[0]['limit']);
            // Identical args to the unbiased path's validators query, so it
            // reuses that cache entry instead of a widened one.
            $plainValidator = array_values(array_filter($plain, static fn (array $a): bool => $a['types'] === ['validator']));
            self::assertSame($plainValidator, $plainCalls);
        }

        public function testAFullChainBucketBuildsNoPlainCards(): void
        {
            $data = self::data(self::call(['chain' => 'osmosis']));

            self::assertSame([51, 52, 53, 54], self::ids($data, 'validators'));
            self::assertSame(['validators'], $data['bias']['applied_to']);
            $validatorCards = array_values(array_filter(Plugin::$cardCalls, static fn (array $c): bool => $c[0] === 'validator'));
            self::assertSame([51, 52, 53, 54], array_map(static fn (array $c): int => $c[1], $validatorCards));
        }

        public function testAChainPageThatBuildsNoCardIsReplacedFromThePlainRanking(): void
        {
            Plugin::$noCard = [22];

            $data = self::data(self::call(['chain' => 'cosmos']));

            // 22 builds no card; 21 is still a chain card, then the plain list.
            self::assertSame([21, 1, 2, 3], self::ids($data, 'validators'));
            self::assertSame(['validators'], $data['bias']['applied_to']);
        }

        public function testNoChainCardBuiltMeansNoBiasClaimed(): void
        {
            // The filter ran and found Osmosis pages, but none builds a card
            // for this viewer: the bucket is the plain one, and says so.
            Plugin::$noCard = [51, 52, 53, 54];

            $data = self::data(self::call(['chain' => 'osmosis']));

            self::assertSame([21, 1, 2, 3], self::ids($data, 'validators'));
            self::assertSame(['chain' => 'osmosis', 'applied_to' => []], $data['bias']);
        }

        public function testNeverFewerCardsThanWithoutAChain(): void
        {
            // Plain pages 1 and 2 build no card: the unbiased bucket holds 2.
            Plugin::$noCard = [1, 2];
            $plain = self::ids(self::data(self::call()), 'validators');
            self::assertSame([21, 3], $plain);

            foreach (['cosmos', 'osmosis', 'ethereum', 'injective'] as $chain) {
                $biased = self::ids(self::data(self::call(['chain' => $chain])), 'validators');
                self::assertGreaterThanOrEqual(count($plain), count($biased), $chain);
                self::assertLessThanOrEqual(4, count($biased), $chain);
                self::assertSame(array_values(array_unique($biased)), $biased, $chain);
            }
            self::assertSame([21, 22, 3], self::ids(self::data(self::call(['chain' => 'cosmos'])), 'validators'));
        }

        public function testOnePrimeCoversTheChainAndPlainCandidatesOnce(): void
        {
            self::call(['chain' => 'cosmos']);

            $primed = \BCC\Trust\Core\Support\PageCardPrefetcher::$primed;
            self::assertCount(1, $primed);
            self::assertSame(array_values(array_unique($primed[0])), $primed[0]);
            foreach ([21, 22, 1, 2, 3, 31, 41] as $id) {
                self::assertContains($id, $primed[0]);
            }
        }

        public function testOnlyTheValidatorsBucketIsEverBiased(): void
        {
            $data = self::data(self::call(['chain' => 'cosmos']));

            self::assertSame([31, 32, 33, 34], self::ids($data, 'projects'));
            self::assertSame([41, 42, 43, 44], self::ids($data, 'creators'));
            foreach ($this->discovery->calls as $args) {
                if ($args['types'] !== ['validator']) {
                    self::assertArrayNotHasKey('chain_slug', $args);
                }
            }
        }

        public function testAChainWithNoValidatorsFallsBackAndClaimsNoBias(): void
        {
            $data = self::data(self::call(['chain' => 'ethereum']));

            self::assertSame([21, 1, 2, 3], self::ids($data, 'validators'));
            self::assertSame(['chain' => 'ethereum', 'applied_to' => []], $data['bias']);
        }

        public function testTheLegacyFallbackIsNotCountedAsABias(): void
        {
            // chain_applied false: these rows were never filtered by chain.
            $data = self::data(self::call(['chain' => 'injective']));

            self::assertSame([21, 1, 2, 3], self::ids($data, 'validators'));
            self::assertSame(['chain' => 'injective', 'applied_to' => []], $data['bias']);
        }

        public function testAnUnknownChainIsA400AndQueriesNothing(): void
        {
            $resp = self::call(['chain' => 'dogecoin']);

            self::assertSame(400, $resp->get_status());
            self::assertSame('bcc_invalid_request', $resp->get_data()['error']['code'] ?? null);
            self::assertSame([], $this->discovery->calls);
        }

        public function testTheChainIsNormalisedLikeHomeChain(): void
        {
            $data = self::data(self::call(['chain' => '  Cosmos ']));

            self::assertSame('cosmos', $data['bias']['chain']);
            self::assertSame([21, 22, 1, 2], self::ids($data, 'validators'));
        }

        public function testAnEmptyChainIsNoChain(): void
        {
            $data = self::data(self::call(['chain' => '']));

            self::assertNull($data['bias']);
            self::assertSame([21, 1, 2, 3], self::ids($data, 'validators'));
        }

        public function testSignedOutIsStill401BeforeTheChainIsRead(): void
        {
            $GLOBALS['__bcc_current_user'] = 0;
            $resp = self::call(['chain' => 'dogecoin']);

            self::assertSame(401, $resp->get_status());
            self::assertSame([], $this->discovery->calls);
        }

        public function testASmallPoolIsNotPadded(): void
        {
            $this->discovery->script['validator|cosmos'] = ['ids' => [21], 'applied' => true];
            $this->discovery->script['validator|']       = [21];

            $data = self::data(self::call(['chain' => 'cosmos']));

            self::assertSame([21], self::ids($data, 'validators'));
        }

        public function testTheBrowserCacheHeaderIsUnchanged(): void
        {
            $resp = self::call(['chain' => 'cosmos']);

            self::assertSame('private, max-age=60', $resp->get_headers()['Cache-Control'] ?? null);
        }
    }

    /**
     * PageDiscoveryService::query() scripted per "type|chain": a plain list is
     * an unbiased ranking; ['ids' => …, 'applied' => bool] is a chain query
     * and whether the chain filter really ran (`chain_applied`).
     */
    final class ScriptedDiscovery extends PageDiscoveryService
    {
        /** @var list<array<string, mixed>> */
        public array $calls = [];

        /** @param array<string, list<int>|array{ids: list<int>, applied: bool}> $script */
        public function __construct(public array $script)
        {
        }

        /**
         * @param array<string, mixed> $args
         * @return array<string, mixed>
         */
        public function query(array $args): array
        {
            $this->calls[] = $args;
            $types = $args['types'] ?? [];
            $type  = is_array($types) ? (string) ($types[0] ?? '') : '';
            $chain = (string) ($args['chain_slug'] ?? '');
            $entry = $this->script[$type . '|' . $chain] ?? [];
            $ids   = isset($entry['ids']) ? $entry['ids'] : $entry;
            $ids   = array_slice($ids, 0, (int) ($args['limit'] ?? 12));

            return [
                'results'       => array_map(static fn (int $id): array => ['page_id' => $id], $ids),
                'total'         => count($ids),
                'pages'         => 1,
                'page'          => 1,
                'chain_applied' => $chain !== '' && (bool) ($entry['applied'] ?? false),
            ];
        }
    }
}
