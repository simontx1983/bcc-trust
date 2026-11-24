<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\PageFollowRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Repositories\WatchMetaRepository;
use BCC\Trust\Core\Services\MessagesService;
use BCC\Trust\Core\Services\WatchingService;
use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Structure A row 14: a `q` reaches the reads it must narrow, in BOTH the
 * list and its count, and without one the reads are exactly as before.
 *
 * A capturing $wpdb records every prepared statement and answers with no
 * rows, so each service returns an empty page after its reads. What the
 * search MATCHES runs on a real server in WatchingNameSearchIntegrationTest
 * and InboxNameSearchIntegrationTest; this pins the wiring between them.
 */
#[CoversClass(WatchingService::class)]
#[CoversClass(MessagesService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WatchingInboxSearchWiringTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['src/Repositories/ReadsMetaRows.php', 'src/Repositories/UserMetaRepository.php', 'src/Repositories/PeepSoPagePrivacy.php', 'src/Repositories/PeepSoMessageRepository.php'] as $file) {
            require_once CoreSibling::require($file);
        }
        if (!method_exists(\BCC\Core\Repositories\UserMetaRepository::class, 'publicNameMatchSql')) {
            self::markTestSkipped('Needs bcc-core with the member-name filter (bcc-core #188).');
        }

        $this->wpdb = new class {
            public string $prefix   = 'wp_';
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public string $users    = 'wp_users';
            public string $usermeta = 'wp_usermeta';
            public string $last_error = '';
            /** @var list<object> */
            public array $last_result = [];
            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $prepared = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                $this->prepared[] = [$sql, $args];

                return $sql;
            }

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            public function query(string $sql): int
            {
                // A COUNT answers 0; any other read answers no rows.
                $this->last_result = str_contains($sql, 'COUNT(') ? [(object) ['n' => '0']] : [];

                return count($this->last_result);
            }

            /** @return list<object> */
            public function get_results(string $sql): array
            {
                return [];
            }

            public function get_var(string $sql): string
            {
                // One page follow, so the page-follow LIST is read too.
                return str_contains($sql, 'bcc_page_follows') ? '1' : '0';
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /** @return list<array{0: string, 1: list<mixed>}> */
    private function preparedContaining(string $needle): array
    {
        return array_values(array_filter($this->wpdb->prepared, static fn (array $p): bool => str_contains($p[0], $needle)));
    }

    private function watching(): WatchingService
    {
        return new WatchingService(new WatchingRepository(), new WatchMetaRepository(), new ReputationRepository(), new PageFollowRepository());
    }

    public function testAWatchingSearchNarrowsBothSourcesListAndCount(): void
    {
        $out = $this->watching()->getWatching(7, 1, 20, false, '@ali');
        self::assertIsArray($out);
        self::assertSame(1, $out['pagination']['total'], 'the narrowed counts');

        $members = $this->preparedContaining('bcc_wq_p');
        self::assertCount(2, $members, 'the follow list and its count');
        foreach ($members as [$sql, $args]) {
            self::assertStringContainsString('bcc_nm_u.ID = uf.uf_passive_user_id', $sql, 'the shared name filter, on the followed member');
            self::assertStringContainsString("bcc_wq_p.post_status = 'publish'", $sql);
            self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM wp_postmeta bcc_page_priv', $sql, 'never a secret page title');
            self::assertSame(['%ali%', '%ali%', '%ali%', '%ali%'], array_values(array_filter($args, 'is_string')));
        }

        $pages = $this->preparedContaining('bcc_pq');
        self::assertCount(2, $pages, 'the page-follow list and its count');
        foreach ($pages as [$sql, $args]) {
            self::assertStringContainsString("bcc_pq.post_status = 'publish'", $sql);
            self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM wp_postmeta bcc_page_priv', $sql);
            self::assertSame(['%ali%', '%ali%'], array_values(array_filter($args, 'is_string')));
        }
    }

    public function testAWatchingListWithoutAQueryIsExactlyAsBefore(): void
    {
        $out = $this->watching()->getWatching(7, 1, 20, false, '  ');
        self::assertIsArray($out);

        self::assertSame([], $this->preparedContaining('bcc_wq_p'));
        self::assertSame([], $this->preparedContaining('bcc_pq'));
        self::assertSame([], $this->preparedContaining('bcc_nm_u'));
    }

    public function testAWatchingQueryThatIsNotUtf8ReadsNothing(): void
    {
        $out = $this->watching()->getWatching(7, 1, 20, false, "al\xffi");

        self::assertSame(['items' => [], 'pagination' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 0]], $out);
        self::assertSame([], $this->wpdb->prepared, 'no read at all');
    }

    public function testAnInboxSearchUsesTheMatchingListAndCount(): void
    {
        $out = (new MessagesService(new \BCC\Trust\Core\Repositories\UserMiniRepository()))->listInbox(7, 1, 20, 'ali');
        self::assertSame([], $out['items'] ?? null);

        $reads = $this->preparedContaining('bcc_q_p');
        self::assertCount(2, $reads, 'the inbox list and its count');
        self::assertStringContainsString('ORDER BY mpart.mpart_last_activity DESC', $reads[0][0]);
        self::assertStringContainsString('COUNT(DISTINCT mpart.mpart_msg_id) AS n', $reads[1][0]);
    }

    public function testAnInboxWithoutAQueryIsExactlyAsBefore(): void
    {
        (new MessagesService(new \BCC\Trust\Core\Repositories\UserMiniRepository()))->listInbox(7, 1, 20, '');

        self::assertSame([], $this->preparedContaining('bcc_q_p'));
        self::assertNotSame([], $this->preparedContaining('mpart.mpart_last_activity DESC'), 'control: the inbox was read');
    }
}
