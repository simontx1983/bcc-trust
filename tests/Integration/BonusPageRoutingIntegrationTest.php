<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Application\ScoreContributorService;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\PageOwnerResolver;
use BCC\Trust\Onchain\Services\BonusRetryService;
use BCC\Trust\Onchain\Services\BonusService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Where a person's on-chain bonus lives, end to end on a real database
 * (user decisions 2026-11-17 / 2026-11-18):
 *
 *   - the BONUS PAGE is the owner's oldest owned entity page, else their
 *     member self-page (ScoreRepository::getPageIdForOwner). It used to be
 *     `LIMIT 1` with no ORDER, so the owner's lowest-scoring row won and a
 *     bonus write could flip the target;
 *   - the bonus counts ONCE: a self-page carries it only while its owner has
 *     no entity page, and a write to an entity page takes it off the
 *     self-page — in the live recompute AND in the retry queue;
 *   - owner-0: a released page's score rows leave the old owner, the page is
 *     cleared to an explicit 0 (its existing ownerless row accepts the
 *     write), and the owner's bonus is recomputed onto a page they own;
 *   - a released page draws no vouch edge.
 *
 * Real ScoreRepository, ScoreContributorService, BonusService,
 * BonusRetryService, AdvisoryLock and claims read. The owner's signals are
 * seeded into SignalRepository's cache (4.0); the claims table holds none of
 * this owner's rows, so the person bonus is exactly 4.0.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(BonusService::class)]
#[CoversClass(ScoreRepository::class)]
final class BonusPageRoutingIntegrationTest extends TestCase
{
    private const OWNER    = 986501;
    private const OTHER    = 986502;
    private const ATTESTOR = 986503;
    private const OLDER    = 986511; // entity pages: the lower id is the older page
    private const NEWER    = 986512;
    private const SIGNALS  = 4.0;
    private const OPTION   = 'bcc_onchain_pending_bonus';

    private int $self;
    private ScoreRepository $scores;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/claim-bonus-stubs.php';
        if (!function_exists('do_action')) {
            eval('function do_action(string $hook, ...$args): void {}');
        }
        $GLOBALS['__bcc_test_object_cache'] = [];

        $this->self   = MemberSelfPageService::ID_BASE + self::OWNER;
        $this->scores = new ScoreRepository();

        // The real writer and resolver, where the harness's pass-through
        // apply_filters would otherwise resolve bcc-core's null services.
        $cache    = new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache');
        $resolved = (array) $cache->getValue();
        $resolved['bcc.resolve.score_contributor']   = new ScoreContributorService($this->scores);
        $resolved['bcc.resolve.page_owner_resolver'] = new PageOwnerResolver();
        $cache->setValue(null, $resolved);

        // The entity pages' owner, as bcc-core's PeepSo page-owner cache holds it.
        $peepso = new \ReflectionProperty(\BCC\Core\PeepSo\PeepSo::class, 'cache');
        $peepso->setValue(null, [self::OLDER => self::OWNER, self::NEWER => self::OWNER]);

        // The owner's wallet signals, as SignalRepository::getByUser() caches them.
        wp_cache_set('signals_user_' . self::OWNER, [['score_contribution' => self::SIGNALS]], 'bcc_onchain_signals');

        $this->purge();
        delete_option(self::OPTION);
    }

    protected function tearDown(): void
    {
        $this->purge();
        delete_option(self::OPTION);
    }

    private function purge(): void
    {
        global $wpdb;
        foreach ([self::OLDER, self::NEWER, $this->self] as $pageId) {
            $wpdb->delete($wpdb->prefix . 'bcc_trust_page_scores', ['page_id' => $pageId]);
            $wpdb->delete($wpdb->prefix . 'bcc_trust_page_scores_velocity', ['page_id' => $pageId]);
        }
        $att = \BCC\Trust\Core\Database\TableRegistry::trustAttestations();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $att)) === $att) {
            $wpdb->delete($att, ['attestor_user_id' => self::ATTESTOR]);
        }
    }

    private function row(int $pageId, int $owner, float $total, float $bonus = 0.0): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
            'page_id'       => $pageId,
            'category_id'   => 0,
            'page_owner_id' => $owner,
            'total_score'   => $total,
            'onchain_bonus' => $bonus,
        ]), $wpdb->last_error);
    }

    private function bonus(int $pageId): float
    {
        global $wpdb;
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT onchain_bonus FROM `{$wpdb->prefix}bcc_trust_page_scores` WHERE page_id = %d AND category_id = 0",
            $pageId
        ));
    }

    private function owner(int $pageId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT page_owner_id FROM `{$wpdb->prefix}bcc_trust_page_scores` WHERE page_id = %d AND category_id = 0",
            $pageId
        ));
    }

    // ── the bonus page ──────────────────────────────────────────────────

    public function testTheBonusPageIsTheOldestOwnedEntityPageNotTheLowestScoringRow(): void
    {
        self::assertSame(0, $this->scores->getPageIdForOwner(self::OWNER), 'no row: no page');

        $this->row($this->self, self::OWNER, 30.0);
        self::assertSame($this->self, $this->scores->getPageIdForOwner(self::OWNER), 'no entity page: the self-page');

        $this->row(self::NEWER, self::OWNER, 10.0); // the lowest score, which LIMIT 1 used to return
        $this->row(self::OLDER, self::OWNER, 60.0);

        self::assertSame(self::OLDER, $this->scores->getPageIdForOwner(self::OWNER));
        self::assertSame([self::OLDER, self::NEWER, $this->self], $this->scores->getOwnedPageIds(self::OWNER, 10));
        self::assertSame([self::OLDER], $this->scores->getOwnedPageIds(self::OWNER, 1), 'bounded');
    }

    // ── option (a): the bonus counts once ───────────────────────────────

    public function testAnEntityPageWriteTakesTheBonusOffTheSelfPage(): void
    {
        $this->row(self::OLDER, self::OWNER, 50.0);
        $this->row($this->self, self::OWNER, 57.0, 7.0); // stale: written before they ran a page

        BonusService::recomputeAndApply(self::OLDER, self::OWNER);

        self::assertEqualsWithDelta(self::SIGNALS, $this->bonus(self::OLDER), 0.0001);
        self::assertEqualsWithDelta(0.0, $this->bonus($this->self), 0.0001, 'counted once');
    }

    public function testASelfPageCarriesTheBonusOnlyWhileItsOwnerHasNoEntityPage(): void
    {
        $this->row($this->self, self::OWNER, 50.0);

        BonusService::recomputeAndApply($this->self, self::OWNER);
        self::assertEqualsWithDelta(self::SIGNALS, $this->bonus($this->self), 0.0001, 'a member with no page keeps their bonus');

        $this->row(self::OLDER, self::OWNER, 50.0);
        BonusService::recomputeAndApply($this->self, self::OWNER);
        self::assertEqualsWithDelta(0.0, $this->bonus($this->self), 0.0001, 'once they run a page, it lives there');
    }

    public function testARetryNeverPutsTheBonusBackOnASelfPageAndSettlesIt(): void
    {
        $this->row(self::OLDER, self::OWNER, 50.0);
        $this->row($this->self, self::OWNER, 57.0, 7.0);

        BonusRetryService::queue($this->self, 0.0);
        BonusRetryService::processAll();
        self::assertEqualsWithDelta(0.0, $this->bonus($this->self), 0.0001, 'the retry applies the same rule');

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'bcc_trust_page_scores', ['onchain_bonus' => 7.0, 'total_score' => 57.0], ['page_id' => $this->self]);
        $GLOBALS['__bcc_test_object_cache'] = []; // a raw write: drop the cached row, as a repository write would
        wp_cache_set('signals_user_' . self::OWNER, [['score_contribution' => self::SIGNALS]], 'bcc_onchain_signals');
        // A later day: the first clear spent the page's daily velocity budget.
        $wpdb->delete($wpdb->prefix . 'bcc_trust_page_scores_velocity', ['page_id' => $this->self]);
        BonusRetryService::queue(self::OLDER, 0.0);
        BonusRetryService::processAll();
        self::assertEqualsWithDelta(self::SIGNALS, $this->bonus(self::OLDER), 0.0001);
        self::assertEqualsWithDelta(0.0, $this->bonus($this->self), 0.0001, 'an entity-page retry settles the self-page');
    }

    // ── owner-0: the whole release ──────────────────────────────────────

    public function testAReleasedPageLeavesItsOwnerAndTheirBonusMovesToAPageTheyStillOwn(): void
    {
        $this->row(self::OLDER, self::OWNER, 54.0, self::SIGNALS);
        $this->row($this->self, self::OWNER, 50.0);
        // After the release nobody owns the page (post_author 0, no PeepSo owner).
        $GLOBALS['__bcc_posts'][self::OLDER] = (object) ['ID' => self::OLDER, 'post_type' => 'peepso-page', 'post_status' => 'publish', 'post_author' => 0];
        $resolverCache = new \ReflectionProperty(PageOwnerResolver::class, 'ownerCache');
        $resolverCache->setValue(null, [self::OLDER => 0]);

        self::assertSame(0, $this->scores->releaseOwner(self::OLDER, self::OTHER, 0), 'guarded: rows of another owner never move');
        self::assertSame(1, $this->scores->releaseOwner(self::OLDER, self::OWNER, 0));
        self::assertSame(0, $this->owner(self::OLDER));
        self::assertSame($this->self, $this->scores->getPageIdForOwner(self::OWNER), 'they no longer own it');

        BonusService::clearForUnownedPage(self::OLDER);
        self::assertEqualsWithDelta(0.0, $this->bonus(self::OLDER), 0.0001, 'the ownerless row accepts the explicit 0');
        $pending = get_option(self::OPTION, []);
        self::assertArrayNotHasKey(self::OLDER, is_array($pending) ? $pending : [], 'written, not queued');

        BonusService::recomputeAndApply($this->scores->getPageIdForOwner(self::OWNER), self::OWNER);
        self::assertEqualsWithDelta(self::SIGNALS, $this->bonus($this->self), 0.0001, 'their bonus lives on their self-page again');
    }

    public function testAnOwnerlessPageStillNeverGetsANewRow(): void
    {
        $GLOBALS['__bcc_posts'][self::NEWER] = (object) ['ID' => self::NEWER, 'post_type' => 'peepso-page', 'post_status' => 'publish', 'post_author' => 0];
        $resolverCache = new \ReflectionProperty(PageOwnerResolver::class, 'ownerCache');
        $resolverCache->setValue(null, [self::NEWER => 0]);

        self::assertFalse((new ScoreContributorService($this->scores))->applyBonus(self::NEWER, 'onchain', 0.0));
        self::assertFalse($this->scores->existsForPage(self::NEWER));
    }

    // ── owner-0: no vouch edge to nobody ────────────────────────────────

    public function testAVouchForAReleasedPageDrawsNoEdgeWhileAProfileVouchStill(): void
    {
        global $wpdb;
        $att = \BCC\Trust\Core\Database\TableRegistry::trustAttestations();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $att)) !== $att) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';
            \bcc_trust_create_trust_attestations_table();
        }
        $this->row(self::OLDER, 0, 50.0);           // released
        $this->row(self::NEWER, self::OTHER, 50.0); // owned
        foreach ([['validator_card', self::OLDER], ['validator_card', self::NEWER], ['user_profile', self::OWNER]] as [$kind, $target]) {
            self::assertNotFalse($wpdb->insert($att, [
                'attestor_user_id' => self::ATTESTOR,
                'target_kind'      => $kind,
                'target_id'        => $target,
                'kind'             => 'vouch',
            ]), $wpdb->last_error);
        }

        $mine = array_values(array_filter(
            (new AttestationRepository())->getVouchEdges(),
            static fn (object $e): bool => (int) $e->endorser_user_id === self::ATTESTOR
        ));
        $targets = array_map(static fn (object $e): int => (int) $e->page_owner_id, $mine);
        sort($targets);

        self::assertSame([self::OWNER, self::OTHER], $targets, 'the profile and the owned page; nobody for the released one');
    }
}
