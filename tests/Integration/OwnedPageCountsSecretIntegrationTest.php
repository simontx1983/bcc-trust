<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoPageRepository;
use BCC\Trust\Core\Repositories\UserSyncRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Page-ownership counts, against a real database: only published,
 * non-secret pages count (bcc-core PeepSoPageRepository::listablePageSql).
 * Rationale: F-427 (private tracker).
 *
 * Covers every member-facing reader: bcc-core's per-type counts, its
 * owners-of-a-type list and its global per-type counts, and bcc-trust's
 * UserSyncRepository::getOwnedPageCountsForUsers (the card's total).
 */
#[Group('integration')]
#[Group('mariadb')]
final class OwnedPageCountsSecretIntegrationTest extends TestCase
{
    private const CAT_VALIDATORS = 994001;   // post_name 'vaildators' → validator
    private const CAT_BUILDERS   = 994002;   // post_name 'builders'   → project

    private const OPEN            = 994101;
    private const CLOSED          = 994102;
    private const NO_META         = 994103;
    private const SECRET          = 994104;
    private const TWO_ROWS_SECRET = 994105;   // privacy rows '0' and '2'
    private const TRASHED         = 994106;
    private const DRAFT           = 994107;
    private const MISSING         = 994108;   // membership rows, no post at all

    /** Owns OPEN + SECRET (validators) and CLOSED + TRASHED (builders). */
    private const OWNER = 994201;
    /** Owns only pages that may not be counted. */
    private const UNCOUNTED_OWNER = 994202;
    /** Owns NO_META (validators): no privacy row reads as open. */
    private const NO_META_OWNER = 994203;

    private const PAGE_IDS = [
        self::OPEN, self::CLOSED, self::NO_META, self::SECRET,
        self::TWO_ROWS_SECRET, self::TRASHED, self::DRAFT, self::MISSING,
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        global $wpdb;
        // PeepSo Pages' membership and category tables (only the columns the
        // reads use). No unique keys, as in PeepSo.
        $ok = $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_page_members` (
                pm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                pm_user_id BIGINT UNSIGNED NOT NULL,
                pm_page_id BIGINT UNSIGNED NOT NULL,
                pm_user_status VARCHAR(32) NOT NULL DEFAULT 'member',
                PRIMARY KEY (pm_id)
            )"
        );
        self::assertNotFalse($ok, (string) $wpdb->last_error);
        $ok = $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}peepso_page_categories` (
                pm_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                pm_page_id BIGINT UNSIGNED NOT NULL,
                pm_cat_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (pm_id)
            )"
        );
        self::assertNotFalse($ok, (string) $wpdb->last_error);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        parent::tearDown();
    }

    public function testTheCardTotalCountsOnlyPublishedNonSecretPages(): void
    {
        $this->seed();

        self::assertSame(
            [self::OWNER => 2, self::NO_META_OWNER => 1],
            self::sortedByKey(UserSyncRepository::getOwnedPageCountsForUsers([self::OWNER, self::UNCOUNTED_OWNER, self::NO_META_OWNER]))
        );
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error);
    }

    public function testTheCardBadgesCountOnlyPublishedNonSecretPages(): void
    {
        $this->seed();

        self::assertSame(
            [
                self::OWNER           => ['validator' => 1, 'project' => 1, 'nft' => 0, 'dao' => 0],
                self::UNCOUNTED_OWNER => ['validator' => 0, 'project' => 0, 'nft' => 0, 'dao' => 0],
                self::NO_META_OWNER   => ['validator' => 1, 'project' => 0, 'nft' => 0, 'dao' => 0],
            ],
            PeepSoPageRepository::getOwnedPageTypeCountsForUsers([self::OWNER, self::UNCOUNTED_OWNER, self::NO_META_OWNER])
        );
    }

    public function testTheTypeFilterListsOnlyOwnersOfACountablePage(): void
    {
        $this->seed();

        $validators = self::fixtureUsers(PeepSoPageRepository::getUserIdsOwningPagesOfType('validator'));
        $projects   = self::fixtureUsers(PeepSoPageRepository::getUserIdsOwningPagesOfType('project'));

        self::assertSame([self::OWNER, self::NO_META_OWNER], $validators);
        self::assertSame([self::OWNER], $projects, 'owning only a trashed builders page does not list a member');
    }

    public function testTheGlobalChipCountsAddOnlyOwnersOfACountablePage(): void
    {
        // Global over the whole table, so measure what the fixture adds.
        $before = PeepSoPageRepository::getGlobalOwnedPageUserCountsByType();
        $this->seed();
        $after = PeepSoPageRepository::getGlobalOwnedPageUserCountsByType();

        self::assertSame(2, $after['validator'] - $before['validator'], 'OWNER and NO_META_OWNER');
        self::assertSame(1, $after['project'] - $before['project'], 'OWNER only');
        self::assertSame(0, $after['nft'] - $before['nft']);
        self::assertSame(0, $after['dao'] - $before['dao']);
    }

    // ── fixture ─────────────────────────────────────────────────────────

    private function seed(): void
    {
        global $wpdb;

        $this->post(self::CAT_VALIDATORS, 'peepso-page-cat', 'publish', 'vaildators');
        $this->post(self::CAT_BUILDERS, 'peepso-page-cat', 'publish', 'builders');

        $pages = [
            // id => [status, privacy rows, category, owner]
            self::OPEN            => ['publish', ['0'], self::CAT_VALIDATORS, self::OWNER],
            self::CLOSED          => ['publish', ['1'], self::CAT_BUILDERS, self::OWNER],
            self::SECRET          => ['publish', ['2'], self::CAT_VALIDATORS, self::OWNER],
            self::TRASHED         => ['trash', ['0'], self::CAT_BUILDERS, self::OWNER],
            self::TWO_ROWS_SECRET => ['publish', ['0', '2'], self::CAT_VALIDATORS, self::UNCOUNTED_OWNER],
            self::DRAFT           => ['draft', ['0'], self::CAT_VALIDATORS, self::UNCOUNTED_OWNER],
            self::MISSING         => [null, [], self::CAT_BUILDERS, self::UNCOUNTED_OWNER],
            self::NO_META         => ['publish', [], self::CAT_VALIDATORS, self::NO_META_OWNER],
        ];
        foreach ($pages as $pageId => [$status, $privacy, $category, $owner]) {
            if ($status !== null) {
                $this->post($pageId, 'peepso-page', $status, 'owned-count-' . $pageId);
            }
            foreach ($privacy as $value) {
                $ok = $wpdb->insert($wpdb->postmeta, ['post_id' => $pageId, 'meta_key' => 'peepso_page_privacy', 'meta_value' => $value]);
                self::assertNotFalse($ok, (string) $wpdb->last_error);
            }
            $ok = $wpdb->insert($wpdb->prefix . 'peepso_page_categories', ['pm_page_id' => $pageId, 'pm_cat_id' => $category]);
            self::assertNotFalse($ok, (string) $wpdb->last_error);
            $ok = $wpdb->insert($wpdb->prefix . 'peepso_page_members', ['pm_user_id' => $owner, 'pm_page_id' => $pageId, 'pm_user_status' => 'member_owner']);
            self::assertNotFalse($ok, (string) $wpdb->last_error);
        }
        // A plain member of a countable page is not its owner.
        $ok = $wpdb->insert($wpdb->prefix . 'peepso_page_members', ['pm_user_id' => self::UNCOUNTED_OWNER, 'pm_page_id' => self::OPEN, 'pm_user_status' => 'member']);
        self::assertNotFalse($ok, (string) $wpdb->last_error);
    }

    private function post(int $id, string $type, string $status, string $name): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->posts, ['ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_name' => $name, 'post_title' => $name]);
        self::assertNotFalse($ok, (string) $wpdb->last_error);
    }

    private function purge(): void
    {
        global $wpdb;
        $ids = implode(',', array_merge(self::PAGE_IDS, [self::CAT_VALIDATORS, self::CAT_BUILDERS]));
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$ids})");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids})");
        $wpdb->query("DELETE FROM `{$wpdb->prefix}peepso_page_members` WHERE pm_page_id IN ({$ids})");
        $wpdb->query("DELETE FROM `{$wpdb->prefix}peepso_page_categories` WHERE pm_page_id IN ({$ids})");
    }

    /**
     * @param list<int> $ids
     * @return list<int> the fixture's users among $ids, ascending
     */
    private static function fixtureUsers(array $ids): array
    {
        $mine = array_values(array_intersect($ids, [self::OWNER, self::UNCOUNTED_OWNER, self::NO_META_OWNER]));
        sort($mine);
        return $mine;
    }

    /**
     * @param array<int, int> $map
     * @return array<int, int>
     */
    private static function sortedByKey(array $map): array
    {
        ksort($map);
        return $map;
    }
}
