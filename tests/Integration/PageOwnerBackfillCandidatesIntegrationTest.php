<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\PeepSoQueryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-299 backfill — which claimed pages get a PeepSo owner row, against a real
 * database.
 *
 * `claimedPagesMissingOwnerRow()` decides what the one-off
 * `wp bcc-trust pages backfill-owners` writes, so every exclusion is a case
 * here: an owner row that already exists, an unowned (author-0) page, a page
 * authored by someone other than the claimer (ownership is never assigned by
 * a repair), a non-exclusive or unverified claim, and an unpublished page.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(PeepSoQueryRepository::class)]
final class PageOwnerBackfillCandidatesIntegrationTest extends TestCase
{
    private const USER_A = 9301;
    private const USER_B = 9302;

    /** @var list<int> */
    private array $pages = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        global $wpdb;
        // PeepSo Pages' membership table (only the columns this query reads).
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

        // The harness's minimal wp_posts has no post_author; real WordPress
        // does (BIGINT UNSIGNED NOT NULL DEFAULT 0). Add it the same way.
        $has = $wpdb->get_var("SHOW COLUMNS FROM `{$wpdb->posts}` LIKE 'post_author'");
        if ($has === null) {
            $ok = $wpdb->query("ALTER TABLE `{$wpdb->posts}` ADD COLUMN post_author BIGINT UNSIGNED NOT NULL DEFAULT 0");
            self::assertNotFalse($ok, (string) $wpdb->last_error);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->pages = [];
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        foreach ($this->pages as $pageId) {
            $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->posts}` WHERE ID = %d", $pageId));
            $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}bcc_onchain_claims` WHERE entity_type = 'page' AND entity_id = %d", $pageId));
            $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}peepso_page_members` WHERE pm_page_id = %d", $pageId));
        }
        parent::tearDown();
    }

    private function page(int $author, string $status = 'publish'): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->posts, [
            'post_title'  => 'F-299 fixture',
            'post_name'   => 'f299-' . uniqid(),
            'post_type'   => 'peepso-page',
            'post_status' => $status,
            'post_author' => $author,
        ]);
        self::assertNotFalse($ok, (string) $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        $this->pages[] = $id;

        return $id;
    }

    private function claim(int $userId, int $pageId, string $role = 'operator', string $status = 'verified'): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
            'user_id'        => $userId,
            'entity_type'    => 'page',
            'entity_id'      => $pageId,
            'wallet_address' => 'cosmos1f299fixture',
            'chain_id'       => 1,
            'claim_role'     => $role,
            'status'         => $status,
        ]);
        self::assertNotFalse($ok, (string) $wpdb->last_error);
    }

    private function member(int $userId, int $pageId, string $status): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'peepso_page_members', [
            'pm_user_id' => $userId, 'pm_page_id' => $pageId, 'pm_user_status' => $status,
        ]);
        self::assertNotFalse($ok, (string) $wpdb->last_error);
    }

    /** @return list<array{page_id: int, user_id: int, current_role: ?string, other_owner: int}> only this test's pages */
    private function candidates(int $after = 0, int $limit = 500): array
    {
        $rows = PeepSoQueryRepository::claimedPagesMissingOwnerRow($after, $limit);
        self::assertNotNull($rows, 'the read must run: ' . (string) $GLOBALS['wpdb']->last_error);

        return array_values(array_filter($rows, fn (array $r): bool => in_array($r['page_id'], $this->pages, true)));
    }

    public function testOnlyAuthoredVerifiedExclusiveClaimsWithoutAnOwnerRowAreCandidates(): void
    {
        $missing = $this->page(self::USER_A);            // ✔ the case F-299 exists for
        $this->claim(self::USER_A, $missing);

        $plainMember = $this->page(self::USER_A);        // ✔ a `member` row is not ownership
        $this->claim(self::USER_A, $plainMember, 'creator');
        $this->member(self::USER_A, $plainMember, 'member');

        $hasOwner = $this->page(self::USER_A);           // ✘ already the owner
        $this->claim(self::USER_A, $hasOwner);
        $this->member(self::USER_A, $hasOwner, 'member_owner');

        $unowned = $this->page(0);                       // ✘ author-0: heals on re-claim, never by a repair
        $this->claim(self::USER_A, $unowned);

        $otherAuthor = $this->page(self::USER_B);        // ✘ ownership is never assigned by a repair
        $this->claim(self::USER_A, $otherAuthor);

        $holder = $this->page(self::USER_A);             // ✘ a non-exclusive role
        $this->claim(self::USER_A, $holder, 'holder');

        $revoked = $this->page(self::USER_A);            // ✘ not verified
        $this->claim(self::USER_A, $revoked, 'operator', 'revoked');

        $draft = $this->page(self::USER_A, 'draft');     // ✘ unpublished
        $this->claim(self::USER_A, $draft);

        // Still CANDIDATES (the command's plan() skips them for manual review),
        // so the query must report the facts that decide it.
        $banned = $this->page(self::USER_A);             // claimer is banned on the page
        $this->claim(self::USER_A, $banned);
        $this->member(self::USER_A, $banned, 'banned');

        $twoOwners = $this->page(self::USER_A);          // someone else already owns it
        $this->claim(self::USER_A, $twoOwners);
        $this->member(self::USER_B, $twoOwners, 'member_owner');

        self::assertSame(
            [
                ['page_id' => $missing,     'user_id' => self::USER_A, 'current_role' => null,     'other_owner' => 0],
                ['page_id' => $plainMember, 'user_id' => self::USER_A, 'current_role' => 'member', 'other_owner' => 0],
                ['page_id' => $banned,      'user_id' => self::USER_A, 'current_role' => 'banned', 'other_owner' => 0],
                ['page_id' => $twoOwners,   'user_id' => self::USER_A, 'current_role' => null,     'other_owner' => self::USER_B],
            ],
            $this->candidates()
        );
    }

    public function testKeysetPagingWalksForwardWithoutRepeats(): void
    {
        $first  = $this->page(self::USER_A);
        $this->claim(self::USER_A, $first);
        $second = $this->page(self::USER_B);
        $this->claim(self::USER_B, $second);

        $page1 = PeepSoQueryRepository::claimedPagesMissingOwnerRow($first - 1, 1);
        self::assertSame([['page_id' => $first, 'user_id' => self::USER_A, 'current_role' => null, 'other_owner' => 0]], $page1);

        self::assertSame([['page_id' => $second, 'user_id' => self::USER_B, 'current_role' => null, 'other_owner' => 0]], $this->candidates($first));
    }

    /** A read that did not run is NULL — never "nothing to repair". */
    public function testAFailedReadIsNullNotEmpty(): void
    {
        global $wpdb;
        $wpdb->failQueriesMatching = '/peepso_page_members/i';

        $rows = PeepSoQueryRepository::claimedPagesMissingOwnerRow(0, 10);

        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        self::assertNull($rows);
    }
}
