<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * Counts reads. Page rows come back for the ids the fixture declares
     * published; bcc-core's page-privacy reads are answered by the shared
     * \BccSecretPageListWpdb from `$GLOBALS['__bcc_spl']`; every other read
     * returns nothing.
     */
    final class PageContextCountingWpdb
    {
        public string $posts    = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $prefix   = 'wp_';

        /**
         * ⚠ DECLARED, NOT LEFT TO PHP. Real `wpdb` has this property, and
         * `PageDiscoveryRepository::findPublishedByIds()` now clears it
         * before its read (F-216). Without the declaration PHP 8.2 creates
         * it dynamically and emits
         * "Creation of dynamic property … ::$last_error is deprecated" —
         * which is a FATAL in PHP 9, so the double has to model the real
         * object rather than rely on a behaviour that is going away.
         */
        public string $last_error = '';

        public int $reads = 0;

        /** @var list<string> */
        public array $sql = [];

        /** @var list<object> */
        public array $pages = [];

        /** Real `wpdb::$last_result`, which `trustedRows()` reads (F-230). */
        public array $last_result = [];

        /**
         * Fills the values in, so the page-privacy reads can be routed by the
         * page ids they carry.
         *
         * @param mixed ...$args
         */
        public function prepare(string $query, ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        /** @return list<object> */
        public function get_results(string $sql): array
        {
            ++$this->reads;
            $this->sql[] = $sql;

            return str_contains($sql, 'post_title, post_name') ? $this->pages : [];
        }

        /**
         * ⚠ ADDED FOR F-230. `PageDiscoveryRepository` now reads through
         * `trustedRows()`, which calls `query()` explicitly and takes its
         * rows from `last_result` — because `wpdb::query()` has failure
         * paths that return false WITHOUT setting `last_error`, and
         * `get_results()` discards that return. So the double has to model
         * the surface the code actually uses.
         *
         * Bookkeeping is identical to `get_results()` above — one read per
         * call — so `$reads` keeps the meaning
         * {@see testReadCountDoesNotGrowWithTheNumberOfItems} relies on.
         */
        public function query(string $sql): int
        {
            ++$this->reads;
            $this->sql[]       = $sql;
            $this->last_error  = '';

            if (str_contains($sql, 'bcc_page_priv') || str_contains($sql, 'peepso_page_members')) {
                $this->privacy ??= new \BccSecretPageListWpdb();
                $this->privacy->query($sql);
                $this->last_result = $this->privacy->last_result;

                return count($this->last_result);
            }

            $this->last_result = str_contains($sql, 'post_title, post_name') ? $this->pages : [];

            return count($this->last_result);
        }

        private ?\BccSecretPageListWpdb $privacy = null;

        /** PeepSoPagePrivacy reads its answers back from `last_result`. */
        public function get_var(?string $sql = null): ?string
        {
            $row = $this->last_result[0] ?? null;
            if (!is_object($row)) {
                return null;
            }
            $values = array_values(get_object_vars($row));

            return isset($values[0]) ? (string) $values[0] : null;
        }

        /** @return list<string> */
        public function get_col(?string $sql = null): array
        {
            $out = [];
            foreach ($this->last_result as $row) {
                $values = array_values(get_object_vars($row));
                $out[]  = (string) ($values[0] ?? '');
            }

            return $out;
        }
    }

    /**
     * `page_context` on the seam the integration suite cannot measure: its
     * meta double reads the database per call, so it says nothing about query
     * counts. Here the meta functions are doubled, and reads are counted.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class FeedPageContextHydrationTest extends TestCase
    {
        /** @var mixed */
        private $priorWpdb;

        private PageContextCountingWpdb $wpdb;

        private const VIEWER = 5;

        protected function setUp(): void
        {
            require_once dirname(__DIR__) . '/Stubs/page-context-stubs.php';
            require_once dirname(__DIR__) . '/Stubs/secret-page-list-stubs.php';
            \bcc_spl_load_core();
            $GLOBALS['__bcc_spl'] = ['posts' => [700 => \bcc_spl_post(700, 70)]];

            $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
            $this->wpdb      = new PageContextCountingWpdb();
            $this->wpdb->pages = [
                (object) ['ID' => '700', 'post_title' => 'Page Seven', 'post_name' => 'page-seven'],
            ];
            $GLOBALS['wpdb']           = $this->wpdb;
            $GLOBALS['__bcc_page_ctx'] = ['meta_primes' => [], 'meta_keys' => [], 'markers' => []];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['__bcc_page_ctx'], $GLOBALS['__bcc_spl']);
            if ($this->priorWpdb === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $this->priorWpdb;
            }
        }

        /**
         * @param list<array<string, mixed>> $items
         * @return list<array<string, mixed>>
         */
        private function hydrate(array $items, int $viewerId = self::VIEWER): array
        {
            $m = new ReflectionMethod(FeedHydrationPipeline::class, 'hydratePageContexts');
            $m->setAccessible(true);
            /** @var list<array<string, mixed>> $out */
            $out = $m->invoke(null, $items, $viewerId);

            return $out;
        }

        /** @return list<array<string, mixed>> */
        private function markedItems(int $count): array
        {
            $items = [];
            for ($i = 1; $i <= $count; ++$i) {
                $GLOBALS['__bcc_page_ctx']['markers'][$i] = '700';
                $items[] = ['external_id' => $i];
            }

            return $items;
        }

        public function testEmptyPageStaysEmpty(): void
        {
            self::assertSame([], $this->hydrate([]));
            self::assertSame(0, $this->wpdb->reads);
        }

        public function testEveryItemGetsTheKeyEvenWithNoBackingPost(): void
        {
            $out = $this->hydrate([['id' => 'a'], ['id' => 'b', 'external_id' => 0]]);

            self::assertArrayHasKey('page_context', $out[0]);
            self::assertNull($out[0]['page_context']);
            self::assertArrayHasKey('page_context', $out[1]);
            self::assertNull($out[1]['page_context']);
            self::assertSame([], $GLOBALS['__bcc_page_ctx']['meta_primes']);
        }

        public function testUnmarkedPostsCostOneMetaPrimeAndNoQuery(): void
        {
            $out = $this->hydrate([['external_id' => 1], ['external_id' => 2]]);

            self::assertNull($out[0]['page_context']);
            self::assertNull($out[1]['page_context']);
            self::assertSame([[1, 2]], $GLOBALS['__bcc_page_ctx']['meta_primes']);
            self::assertSame(0, $this->wpdb->reads);
        }

        public function testTheMarkerIsTheKeyTheWriterStamps(): void
        {
            $this->hydrate([['external_id' => 1]]);

            self::assertSame(['_bcc_page_id'], array_keys($GLOBALS['__bcc_page_ctx']['meta_keys']));
        }

        public function testShapeIsExactAndServerRendered(): void
        {
            $out = $this->hydrate($this->markedItems(1));

            self::assertSame(
                [
                    'id'     => 700,
                    'name'   => 'Page Seven',
                    'handle' => 'page-seven',
                    'url'    => '/v/page-seven',
                    'label'  => 'Operator of Page Seven',
                ],
                $out[0]['page_context']
            );
        }

        public function testAPageThatDoesNotResolveReadsAsNull(): void
        {
            $this->wpdb->pages = [];

            $out = $this->hydrate($this->markedItems(2));

            self::assertNull($out[0]['page_context']);
            self::assertNull($out[1]['page_context']);
        }

        public function testReadCountDoesNotGrowWithTheNumberOfItems(): void
        {
            $this->hydrate($this->markedItems(2));
            $forTwo = $this->wpdb->reads;
            self::assertGreaterThan(0, $forTwo, 'marked items must actually reach the page read');

            $this->wpdb->reads = 0;
            $GLOBALS['__bcc_page_ctx']['meta_primes'] = [];
            $out = $this->hydrate($this->markedItems(50));

            self::assertSame($forTwo, $this->wpdb->reads, 'reads must not scale with items');
            self::assertCount(1, $GLOBALS['__bcc_page_ctx']['meta_primes']);
            self::assertCount(50, $GLOBALS['__bcc_page_ctx']['meta_primes'][0]);
            self::assertNotNull($out[49]['page_context']);
        }

        // ── SECRET pages (PageAccess) ───────────────────────────────────

        public function testASecretPagesLabelReadsAsNullLikeAnUnpublishedPage(): void
        {
            $GLOBALS['__bcc_spl']['secret'] = [700 => true];
            $hidden = $this->hydrate($this->markedItems(2));

            $GLOBALS['__bcc_spl']['secret'] = [];
            $this->wpdb->pages = [];
            $unpublished = $this->hydrate($this->markedItems(2));

            self::assertNull($hidden[0]['page_context']);
            self::assertSame($unpublished, $hidden, 'the same answer as a page that no longer resolves');
        }

        public function testAnAnonymousViewerGetsNoSecretPageLabel(): void
        {
            $GLOBALS['__bcc_spl']['secret'] = [700 => true];

            self::assertNull($this->hydrate($this->markedItems(1), 0)[0]['page_context']);
        }

        /** @return iterable<string, array{0: list<string>, 1: bool}> */
        public static function membership(): iterable
        {
            yield 'a member' => [['member'], true];
            yield 'invited'  => [['pending_user'], true];
            yield 'banned'   => [['member', 'banned'], false];
        }

        /** @param list<string> $statuses */
        #[\PHPUnit\Framework\Attributes\DataProvider('membership')]
        public function testTheSecretPageDecidesWhoGetsItsLabel(array $statuses, bool $labelled): void
        {
            $GLOBALS['__bcc_spl']['secret'] = [700 => true];
            $GLOBALS['__bcc_spl']['statuses'][700][self::VIEWER] = $statuses;

            $out = $this->hydrate($this->markedItems(1));

            self::assertSame($labelled, $out[0]['page_context'] !== null);
        }

        public function testTheSecretPagesAuthorGetsItsLabel(): void
        {
            $GLOBALS['__bcc_spl']['secret'] = [700 => true];
            $GLOBALS['__bcc_spl']['posts'][700] = \bcc_spl_post(700, self::VIEWER);

            self::assertSame('page-seven', $this->hydrate($this->markedItems(1))[0]['page_context']['handle'] ?? null);
        }

        public function testThePrivacyCheckIsOneBatchedReadPerFeedPage(): void
        {
            $GLOBALS['__bcc_spl']['secret'] = [700 => true];
            $GLOBALS['__bcc_spl']['statuses'][700][self::VIEWER] = ['member'];

            $this->hydrate($this->markedItems(2));
            $forTwo = $this->wpdb->reads;

            $this->wpdb->reads = 0;
            $out = $this->hydrate($this->markedItems(50));

            self::assertSame($forTwo, $this->wpdb->reads, 'the privacy reads are per distinct page, never per item');
            self::assertNotNull($out[49]['page_context']);
            $batches = array_filter($this->wpdb->sql, static fn (string $q): bool => \BccSecretPageListWpdb::isBatch($q));
            self::assertCount(2, $batches, 'one batched privacy read per hydration');
        }
    }
}
