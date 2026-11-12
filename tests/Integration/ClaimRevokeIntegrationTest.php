<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
use BCC\Trust\Core\Services\Feed\PageClaimBodyHydrator;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-14 (PR A) + F-89, against real MySQL / MariaDB.
 *
 * ClaimRepository::revoke() flips a verified claim to `revoked` — never a
 * DELETE, so a re-claim restores it — together with the claim's page MIRROR
 * rows, but only for pages bound to that claim's entity and only when no other
 * verified claim of the user still binds the page. Its reads are fail-closed:
 * a failed binding read rolls the whole revoke back.
 *
 * F-89: findManyByIds() returns verified claims only, and the feed drops a
 * `page_claim` item whose claim is no longer verified.
 *
 * The suite uses users 914001/914002, pages 9140xx and deletes only its rows.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ClaimRepository::class)]
#[CoversClass(FeedHydrationPipeline::class)]
final class ClaimRevokeIntegrationTest extends TestCase
{
    private const USER  = 914001;
    private const OTHER = 914002;

    private const PAGE_V  = 914010;
    private const PAGE_PH = 914011;
    private const PAGE_C1 = 914012;
    private const PAGE_C2 = 914013;

    private int $chain = 0;

    /** @var list<int> */
    private array $validatorIds = [];
    /** @var list<int> */
    private array $collectionIds = [];
    /** @var list<int> */
    private array $walletIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $this->chain = (int) $wpdb->get_var("SELECT id FROM `{$wpdb->prefix}bcc_chains` ORDER BY id ASC LIMIT 1");
        self::assertGreaterThan(0, $this->chain, 'the bootstrap must seed a chain');
        $this->cleanUp();
        if (!function_exists('_prime_post_caches')) {
            eval('function _prime_post_caches(array $ids, bool $updateTermCache = true, bool $updateMetaCache = true): void {}');
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $claims = ClaimRepository::table();
        $wpdb->query($wpdb->prepare("DELETE FROM `{$claims}` WHERE user_id IN (%d, %d)", self::USER, self::OTHER));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->postmeta}` WHERE post_id BETWEEN %d AND %d",
            self::PAGE_V,
            self::PAGE_C2 + 50
        ));
        foreach ($this->validatorIds as $id) {
            $wpdb->delete($wpdb->prefix . 'bcc_onchain_validators', ['id' => $id]);
        }
        foreach ($this->collectionIds as $id) {
            $wpdb->delete($wpdb->prefix . 'bcc_onchain_collections', ['id' => $id]);
        }
        foreach ($this->walletIds as $id) {
            $wpdb->delete($wpdb->prefix . 'bcc_wallet_links', ['id' => $id]);
        }
        $this->validatorIds = $this->collectionIds = $this->walletIds = [];
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function linkWallet(int $pageId): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => self::USER,
            'post_id'        => $pageId,
            'wallet_address' => 'f14link' . uniqid(),
            'chain_id'       => $this->chain,
            'wallet_type'    => 'validator',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
        return $this->walletIds[] = (int) $wpdb->insert_id;
    }

    private function validator(?int $walletLinkId): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id'   => $walletLinkId,
            'operator_address' => 'f14valoper' . uniqid(),
            'chain_id'         => $this->chain,
            'moniker'          => 'F-14 fixture',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]), $wpdb->last_error);
        return $this->validatorIds[] = (int) $wpdb->insert_id;
    }

    private function collection(?int $walletLinkId): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_onchain_collections', [
            'wallet_link_id'   => $walletLinkId,
            'contract_address' => '0xf14' . uniqid(),
            'chain_id'         => $this->chain,
            'collection_name'  => 'F-14 fixture',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]), $wpdb->last_error);
        return $this->collectionIds[] = (int) $wpdb->insert_id;
    }

    private function claim(int $userId, string $type, int $entityId, string $role, string $wallet = '0xclaimwallet'): int
    {
        $r = ClaimRepository::upsert($userId, $type, $entityId, $wallet, $this->chain, $role);
        self::assertIsArray($r);
        return $r['id'];
    }

    private function claimStatus(int $claimId): ?string
    {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare('SELECT status FROM `' . ClaimRepository::table() . '` WHERE id = %d', $claimId));
        return $v === null ? null : (string) $v;
    }

    // ── revoke ────────────────────────────────────────────────────────

    public function testAValidatorClaimAndItsPageMirrorAreRevokedTogether(): void
    {
        $v      = $this->validator($this->linkWallet(self::PAGE_V));
        $claim  = $this->claim(self::USER, 'validator', $v, 'operator');
        $mirror = $this->claim(self::USER, 'page', self::PAGE_V, 'operator');

        $r = ClaimRepository::revoke($claim);

        self::assertTrue($r['revoked']);
        self::assertSame(self::USER, $r['user_id']);
        self::assertSame([self::PAGE_V], $r['mirror_page_ids']);
        self::assertSame('revoked', $this->claimStatus($claim), 'kept, not deleted');
        self::assertSame('revoked', $this->claimStatus($mirror));

        self::assertFalse(ClaimRepository::revoke($claim)['revoked'], 'idempotent: a second revoke is a no-op');
    }

    public function testAPlaceholderBoundPageMirrorIsRevoked(): void
    {
        $v = $this->validator(null);
        self::assertTrue(update_post_meta(self::PAGE_PH, '_bcc_onchain_validator_id', (string) $v));
        $claim  = $this->claim(self::USER, 'validator', $v, 'operator');
        $mirror = $this->claim(self::USER, 'page', self::PAGE_PH, 'operator');

        self::assertSame([self::PAGE_PH], ClaimRepository::revoke($claim)['mirror_page_ids']);
        self::assertSame('revoked', $this->claimStatus($mirror));
    }

    public function testOnlyTheRevokedCollectionsOwnPageMirrorFlips(): void
    {
        // ONE wallet created two collections on two pages: the two mirrors
        // share user, wallet, chain and role with BOTH claims.
        $c1 = $this->collection($this->linkWallet(self::PAGE_C1));
        $c2 = $this->collection($this->linkWallet(self::PAGE_C2));
        $claim1  = $this->claim(self::USER, 'collection', $c1, 'creator');
        $claim2  = $this->claim(self::USER, 'collection', $c2, 'creator');
        $mirror1 = $this->claim(self::USER, 'page', self::PAGE_C1, 'creator');
        $mirror2 = $this->claim(self::USER, 'page', self::PAGE_C2, 'creator');

        ClaimRepository::revoke($claim1);

        self::assertSame('revoked', $this->claimStatus($claim1));
        self::assertSame('revoked', $this->claimStatus($mirror1));
        self::assertSame('verified', $this->claimStatus($claim2), 'the other claim is untouched');
        self::assertSame('verified', $this->claimStatus($mirror2), 'the other page keeps its mirror');
    }

    public function testAPageStillHeldThroughAnotherClaimKeepsItsMirror(): void
    {
        // Two collections linked to the SAME page; revoking one leaves the
        // user holding the page through the other.
        $link = $this->linkWallet(self::PAGE_C1);
        $c1 = $this->collection($link);
        $c2 = $this->collection($link);
        $claim1 = $this->claim(self::USER, 'collection', $c1, 'creator');
        $this->claim(self::USER, 'collection', $c2, 'creator');
        $mirror = $this->claim(self::USER, 'page', self::PAGE_C1, 'creator');

        $r = ClaimRepository::revoke($claim1);

        self::assertTrue($r['revoked']);
        self::assertSame([], $r['mirror_page_ids']);
        self::assertSame('verified', $this->claimStatus($mirror));
    }

    public function testAnotherUsersMirrorIsNeverTouched(): void
    {
        $v = $this->validator($this->linkWallet(self::PAGE_V));
        $claim = $this->claim(self::USER, 'validator', $v, 'operator');
        $theirs = $this->claim(self::OTHER, 'page', self::PAGE_V, 'operator');

        ClaimRepository::revoke($claim);

        self::assertSame('verified', $this->claimStatus($theirs));
    }

    public function testAFailedBindingReadRevokesNothing(): void
    {
        $v      = $this->validator($this->linkWallet(self::PAGE_V));
        $claim  = $this->claim(self::USER, 'validator', $v, 'operator');
        $mirror = $this->claim(self::USER, 'page', self::PAGE_V, 'operator');

        $GLOBALS['wpdb']->failQueriesMatching = '/UNION/';
        try {
            ClaimRepository::revoke($claim);
            self::fail('a failed binding read must throw, not read as "no page"');
        } catch (RepositoryReadFailure) {
        } finally {
            $GLOBALS['wpdb']->clearFaultInjection();
        }

        self::assertSame('verified', $this->claimStatus($claim), 'rolled back');
        self::assertSame('verified', $this->claimStatus($mirror));
    }

    public function testRevokingRemovesTheClaimBonusAndAReClaimRestoresIt(): void
    {
        $v = $this->validator($this->linkWallet(self::PAGE_V));
        $claim = $this->claim(self::USER, 'validator', $v, 'operator');
        $before = ClaimRepository::computePageClaimBonus(self::USER);
        self::assertGreaterThan(0.0, $before);

        ClaimRepository::revoke($claim);
        self::assertSame(0.0, ClaimRepository::computePageClaimBonus(self::USER), 'a revoked claim earns no bonus');

        $this->claim(self::USER, 'validator', $v, 'operator');
        self::assertSame('verified', $this->claimStatus($claim), 're-claim restores the same row');
        self::assertSame($before, ClaimRepository::computePageClaimBonus(self::USER));
    }

    public function testAPageMirrorOrUnknownClaimIsNotRevokable(): void
    {
        $mirror = $this->claim(self::USER, 'page', self::PAGE_V, 'operator');

        self::assertFalse(ClaimRepository::revoke($mirror)['revoked'], 'a mirror is revoked through its claim');
        self::assertFalse(ClaimRepository::revoke(999999999)['revoked']);
        self::assertSame('verified', $this->claimStatus($mirror));
    }

    // ── F-89 ──────────────────────────────────────────────────────────

    public function testFindManyByIdsReturnsVerifiedClaimsOnly(): void
    {
        $v1 = $this->validator($this->linkWallet(self::PAGE_V));
        $v2 = $this->validator(null);
        $kept    = $this->claim(self::USER, 'validator', $v1, 'operator');
        $revoked = $this->claim(self::USER, 'validator', $v2, 'operator');
        ClaimRepository::revoke($revoked);

        self::assertSame([$kept], array_keys(ClaimRepository::findManyByIds([$kept, $revoked])));
    }

    public function testTheFeedDropsAPageClaimWhoseClaimWasRevoked(): void
    {
        $v1 = $this->validator($this->linkWallet(self::PAGE_V));
        $v2 = $this->validator(null);
        $kept    = $this->claim(self::USER, 'validator', $v1, 'operator');
        $revoked = $this->claim(self::USER, 'validator', $v2, 'operator');
        ClaimRepository::revoke($revoked);

        // The activity wp_posts point at the claims through sidecar meta.
        update_post_meta(self::PAGE_C2 + 1, '_bcc_activity_sidecar_id', (string) $kept);
        update_post_meta(self::PAGE_C2 + 2, '_bcc_activity_sidecar_id', (string) $revoked);

        $out = $this->hydrateBodies([
            ['post_kind' => 'page_claim', 'external_id' => self::PAGE_C2 + 1, 'body' => []],
            ['post_kind' => 'page_claim', 'external_id' => self::PAGE_C2 + 2, 'body' => []],
            ['post_kind' => 'page_claim', 'external_id' => self::PAGE_C2 + 3, 'body' => []],
        ]);

        $ext = array_map(static fn (array $i): int => (int) $i['external_id'], $out);
        self::assertSame([self::PAGE_C2 + 1, self::PAGE_C2 + 3], $ext, 'the revoked claim\'s item is dropped');
        self::assertSame($kept, $out[0]['body']['claim_id'], 'control: a verified claim is hydrated');
        self::assertSame([], $out[1]['body'], 'an item with no sidecar link is left as before');
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateBodies(array $items): array
    {
        $ref      = new \ReflectionClass(FeedHydrationPipeline::class);
        $pipeline = $ref->newInstanceWithoutConstructor();
        $blank    = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $deps     = [
            'gifRepo'                => $blank(\BCC\Trust\Core\Repositories\GifRepository::class),
            'mentionOverlay'         => $blank(\BCC\Trust\Core\Services\Mentions\MentionOverlayService::class),
            'watchBatchBodyHydrator' => $blank(\BCC\Trust\Core\Services\Feed\WatchBatchBodyHydrator::class),
            'pageClaimBodyHydrator'  => new PageClaimBodyHydrator(),
            'photoBodyHydrator'      => $blank(\BCC\Trust\Core\Services\Feed\PhotoBodyHydrator::class),
            'gifBodyHydrator'        => $blank(\BCC\Trust\Core\Services\Feed\GifBodyHydrator::class),
            'reviewBodyHydrator'     => $blank(\BCC\Trust\Core\Services\Feed\ReviewBodyHydrator::class),
        ];
        foreach ($deps as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($pipeline, $value);
        }
        $m = $ref->getMethod('hydrateBodies');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($pipeline, $items);
        return $out;
    }
}
