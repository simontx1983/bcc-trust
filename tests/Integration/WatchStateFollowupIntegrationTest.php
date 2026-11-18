<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\PageFollowRepository;
use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Services\SuggestionService;
use BCC\Trust\Core\Services\ViewerWatchResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-255 follow-up, on real rows:
 *
 *  1. Suggestions never offer someone the viewer already follows, however
 *     many they follow. The "already following" list suggestions had was
 *     capped at 200; a viewer following 250 was offered the other 50 with
 *     a "Watch" button that answered `already_watching`.
 *
 *  2. A FAILED watch-state read is UNKNOWN (`viewer_is_watching: null`),
 *     never "not watching", on each source independently (user decision:
 *     a neutral state, not a wrong "Watch"; the card page never fails).
 *     The failure is injected with the harness's failQueriesMatching, and
 *     each case asserts the fault actually fired.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(SuggestionService::class)]
#[CoversClass(ViewerWatchResolver::class)]
#[CoversClass(WatchingRepository::class)]
#[CoversClass(PageFollowRepository::class)]
final class WatchStateFollowupIntegrationTest extends TestCase
{
    private const VIEWER      = 9256000;
    private const MEMBER_BASE = 9256000; // followed members 9256001 … 9256250
    private const FOLLOWED    = 250;
    private const STRANGERS   = [9256901, 9256902, 9256903];

    private const OWNED_PAGE       = 925700001; // authored by member #1
    private const PLACEHOLDER_PAGE = 925700002; // no author, page-followed

    /** @var list<string> */
    private array $createdTables = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/watch-scale-stubs.php';
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->ensureTables();
        $this->purge();

        global $wpdb;
        $p = $wpdb->prefix;
        for ($i = 1; $i <= self::FOLLOWED; $i++) {
            self::assertNotFalse($wpdb->insert($p . 'peepso_user_followers', [
                'uf_active_user_id' => self::VIEWER, 'uf_passive_user_id' => self::MEMBER_BASE + $i, 'uf_follow' => 1,
            ]), $wpdb->last_error);
        }
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID' => self::OWNED_PAGE, 'post_type' => 'peepso-page', 'post_status' => 'publish',
            'post_author' => self::MEMBER_BASE + 1, 'post_title' => 'F255b owned', 'post_name' => 'f255b-owned',
        ]), $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID' => self::PLACEHOLDER_PAGE, 'post_type' => 'peepso-page', 'post_status' => 'publish',
            'post_author' => 0, 'post_title' => 'F255b placeholder', 'post_name' => 'f255b-placeholder',
        ]), $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($p . 'bcc_page_follows', [
            'user_id' => self::VIEWER, 'page_id' => self::PLACEHOLDER_PAGE, 'card_kind' => 'validator',
        ]), $wpdb->last_error);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        global $wpdb;
        foreach ($this->createdTables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    // ── 1. suggestions, exact ───────────────────────────────────────────

    public function testAViewerFollowing250IsNeverSuggestedOneOfThem(): void
    {
        $followed   = range(self::MEMBER_BASE + 1, self::MEMBER_BASE + self::FOLLOWED);
        $candidates = array_merge($followed, self::STRANGERS);
        shuffle($candidates);

        $kept = $this->dropAlreadyFollowing($candidates);

        self::assertSame([], array_values(array_intersect($kept, $followed)), 'no followed member survives, past the old 200 cap too');
        $strangers = self::STRANGERS;
        sort($kept);
        sort($strangers);
        self::assertSame($strangers, $kept, 'and nobody else is dropped');
    }

    public function testAFailedExactCheckKeepsTheCandidatesRatherThanFailing(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/peepso_user_followers/';

        $candidates = [self::MEMBER_BASE + 1, self::STRANGERS[0]];
        self::assertSame($candidates, $this->dropAlreadyFollowing($candidates));
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault must actually have fired');
    }

    // ── 2. unknown, not "not watching" ──────────────────────────────────

    public function testHealthyReadsAnswerWatchingAndNotWatching(): void
    {
        $resolver = $this->resolver();

        $members = $resolver->forMembers(self::VIEWER, [self::MEMBER_BASE + 250, self::STRANGERS[0]]);
        self::assertTrue(ViewerWatchResolver::fieldsFor($members, self::MEMBER_BASE + 250)['viewer_is_watching']);
        self::assertFalse(ViewerWatchResolver::fieldsFor($members, self::STRANGERS[0])['viewer_is_watching']);

        $pages = $resolver->forPages(self::VIEWER, [self::OWNED_PAGE, self::PLACEHOLDER_PAGE]);
        self::assertSame('peepso', ViewerWatchResolver::fieldsFor($pages, self::OWNED_PAGE)['viewer_watch']['source'] ?? null);
        self::assertSame('page', ViewerWatchResolver::fieldsFor($pages, self::PLACEHOLDER_PAGE)['viewer_watch']['source'] ?? null);
    }

    public function testAFailedFollowReadMakesMemberAndOwnedPageCardsUnknown(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/peepso_user_followers/';
        $resolver = $this->resolver();

        $members = $resolver->forMembers(self::VIEWER, [self::MEMBER_BASE + 1, self::STRANGERS[0]]);
        foreach ([self::MEMBER_BASE + 1, self::STRANGERS[0]] as $id) {
            self::assertSame(
                ['viewer_is_watching' => null, 'viewer_watch' => null],
                ViewerWatchResolver::fieldsFor($members, $id),
                "member {$id}: unknown, never false"
            );
        }

        $pages = $resolver->forPages(self::VIEWER, [self::OWNED_PAGE, self::PLACEHOLDER_PAGE]);
        self::assertNull(ViewerWatchResolver::fieldsFor($pages, self::OWNED_PAGE)['viewer_is_watching'], 'owned page: unknown');
        self::assertTrue(
            ViewerWatchResolver::fieldsFor($pages, self::PLACEHOLDER_PAGE)['viewer_is_watching'],
            'the healthy page-follow source still answers'
        );
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault must actually have fired');
    }

    public function testAFailedPageFollowReadMakesPlaceholderCardsUnknown(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/bcc_page_follows/';

        $pages = $this->resolver()->forPages(self::VIEWER, [self::OWNED_PAGE, self::PLACEHOLDER_PAGE]);

        self::assertNull(ViewerWatchResolver::fieldsFor($pages, self::PLACEHOLDER_PAGE)['viewer_is_watching'], 'placeholder: unknown');
        self::assertTrue(
            ViewerWatchResolver::fieldsFor($pages, self::OWNED_PAGE)['viewer_is_watching'],
            'the healthy follow source still answers'
        );
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault must actually have fired');
    }

    // ── fixture ─────────────────────────────────────────────────────────

    private function resolver(): ViewerWatchResolver
    {
        return new ViewerWatchResolver(new WatchingRepository(), new PageFollowRepository());
    }

    /**
     * @param list<int> $candidates
     * @return list<int>
     */
    private function dropAlreadyFollowing(array $candidates): array
    {
        $r       = new ReflectionClass(SuggestionService::class);
        $service = $r->newInstanceWithoutConstructor();
        $r->getProperty('watchingRepository')->setValue($service, new WatchingRepository());
        $m = $r->getMethod('dropAlreadyFollowing');

        /** @var list<int> $out */
        $out = $m->invoke($service, self::VIEWER, $candidates);

        return $out;
    }

    private function purge(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}peepso_user_followers WHERE uf_active_user_id = %d", self::VIEWER));
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}bcc_page_follows WHERE user_id = %d", self::VIEWER));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE ID IN (%d, %d)",
            self::OWNED_PAGE,
            self::PLACEHOLDER_PAGE
        ));
    }

    /**
     * Created only when absent (and dropped again), widened only with the
     * columns read here: other suites leave stunted copies of
     * peepso_user_followers behind.
     */
    private function ensureTables(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach (['peepso_user_followers', 'bcc_page_follows'] as $suffix) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $suffix)) !== $p . $suffix) {
                $this->createdTables[] = $p . $suffix;
            }
        }
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}peepso_user_followers` (
                uf_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                uf_passive_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uf_active_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uf_follow TINYINT(1) NOT NULL DEFAULT 1
            )"
        );
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . 'bcc_page_follows')) !== $p . 'bcc_page_follows') {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-page-follows.php';
            \bcc_trust_create_page_follows_table();
        }

        foreach ([
            $p . 'peepso_user_followers' => [
                'uf_active_user_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'uf_follow'         => 'TINYINT(1) NOT NULL DEFAULT 1',
            ],
            $wpdb->posts => ['post_author' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0'],
        ] as $table => $wanted) {
            $existing = (array) $wpdb->get_col($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            foreach ($wanted as $column => $definition) {
                if (!in_array($column, $existing, true)) {
                    self::assertNotFalse($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}"), $wpdb->last_error);
                }
            }
        }
    }
}
