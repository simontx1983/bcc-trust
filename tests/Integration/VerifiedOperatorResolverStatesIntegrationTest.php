<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the THREE STATES of
 * ClaimRepository::resolveVerifiedOperatorForPage(s) against real MySQL /
 * MariaDB.
 *
 * ⚠ THIS METHOD HAD NO TEST COVERAGE OF ANY KIND before this file. Verified
 * with a positive control: `userHasVerifiedClaimOnPage` is found in `tests/`,
 * `resolveVerifiedOperatorForPage` and `OPERATOR_RESOLVED` were found in zero
 * test files. It is nonetheless:
 *
 *   - the routing authority for validator direct messages
 *     (`MessagesService`, `ValidatorMsgQueueWorker`, `CardViewService`'s
 *     `can_message`), and
 *   - named by api-contract-v1.md §4.32.2 as the SOLE authority for
 *     operator-authored validator announcements.
 *
 * The states it must distinguish:
 *
 *   UNCLAIMED — absent from the batch map; the point variant defaults it
 *   RESOLVED  — exactly ONE DISTINCT operator user, id returned
 *   AMBIGUOUS — more than one distinct operator user, id NULL, never resolved
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. A CONTESTED PAGE RESOLVES. The method must never return a user id when
 *     two distinct operators resolve to one page. Nothing in the DATABASE
 *     prevents that state: the only key is
 *     `uq_user_entity (user_id, entity_type, entity_id)`, which stops one user
 *     holding two rows and says nothing about two users holding one entity.
 *     Exclusivity is advisory-lock-and-check inside `createExclusiveClaim()`,
 *     which the repository documents as deliberate because "MySQL does not
 *     support partial unique indexes". So the state is reachable by manual
 *     SQL, by a migration, and by any row predating that code.
 *  2. ONE OPERATOR ACROSS A MULTI-CHAIN PAGE READS AS CONTESTED. A validator
 *     page can carry several validator rows (one per chain). The resolver
 *     aggregates by DISTINCT USER, not by row, so the same operator holding
 *     both chains is RESOLVED. Counting rows would lock that operator out of
 *     their own page — and the only visible symptom would be a generic 403.
 *  3. THE COLLECTION LEG OR THE CREATOR ROLE LEAKS IN. The live page-image
 *     gate (`userHasVerifiedClaimOnPage`) accepts both. This resolver must
 *     not, or a verified NFT creator could speak as a validator.
 *  4. A DEAD CLAIM STILL RESOLVES. A pending/revoked status, a deleted
 *     validator row, or an entity whose `wallet_link_id` is NULL must all
 *     drop out. Claim revocation is a row DELETE, so "absent" is the normal
 *     revoked representation and must read as UNCLAIMED.
 *
 * The AMBIGUOUS fixtures are inserted directly rather than through
 * `createExclusiveClaim()`, because that method exists precisely to refuse
 * them. Going through it would assert that the guard works (already covered)
 * instead of asserting what the resolver does when the guard has been
 * bypassed, which is the only reason the AMBIGUOUS branch exists.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ClaimRepository::class)]
final class VerifiedOperatorResolverStatesIntegrationTest extends TestCase
{
    private const USER_A   = 401;
    private const USER_B   = 402;
    private const PAGE_ID  = 5501;
    private const OTHER_PAGE = 5502;
    private const CHAIN_ID = 8;

    protected function setUp(): void
    {
        global $wpdb;
        foreach (['bcc_onchain_claims', 'bcc_onchain_validators', 'bcc_onchain_collections', 'bcc_wallet_links'] as $t) {
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . $t . '`');
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function walletLink(int $userId, int $postId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => $userId,
            'post_id'        => $postId,
            'wallet_address' => 'cosmos1resolver' . $userId . 'p' . $postId . uniqid(),
            'chain_id'       => self::CHAIN_ID,
            'wallet_type'    => 'validator',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function validator(?int $walletLinkId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id'   => $walletLinkId,
            'operator_address' => 'cosmosvaloper1resolver' . uniqid(),
            'chain_id'         => self::CHAIN_ID,
            'moniker'          => 'Resolver Fixture',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function collection(?int $walletLinkId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_collections', [
            'wallet_link_id'   => $walletLinkId,
            'contract_address' => '0xresolver' . uniqid(),
            'chain_id'         => self::CHAIN_ID,
            'collection_name'  => 'Resolver Fixture Collection',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * Direct insert, deliberately. See the class docblock: the exclusive
     * write path refuses the contested fixtures this suite is here to
     * exercise, and routing the clean fixtures through a different path from
     * the contested ones would make the two incomparable.
     */
    private function claim(
        int $userId,
        string $entityType,
        int $entityId,
        string $role = 'operator',
        string $status = 'verified'
    ): void {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
            'user_id'        => $userId,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'wallet_address' => 'cosmos1resolver' . $userId,
            'chain_id'       => self::CHAIN_ID,
            'claim_role'     => $role,
            'status'         => $status,
            'verified_at'    => $status === 'verified' ? gmdate('Y-m-d H:i:s') : null,
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        // ⚠ $wpdb->insert returns false SILENTLY on a column mismatch, which
        // would make every assertion below vacuous against an empty table.
        self::assertNotFalse($ok, 'fixture claim insert must succeed');
    }

    /** A validator on $pageId claimed by $userId as a verified operator. */
    private function claimedValidatorOn(int $pageId, int $userId): int
    {
        $vid = $this->validator($this->walletLink($userId, $pageId));
        $this->claim($userId, 'validator', $vid);
        return $vid;
    }

    // ── RESOLVED ──────────────────────────────────────────────────────────

    public function testOneVerifiedOperatorResolvesToThatUser(): void
    {
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_A);

        $r = ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID);

        self::assertSame(ClaimRepository::OPERATOR_RESOLVED, $r['state']);
        self::assertSame(self::USER_A, $r['user_id']);
    }

    /**
     * MUST-NOT-HAPPEN #2. One operator, two chains, one page. Two claim rows,
     * ONE distinct user — RESOLVED, not AMBIGUOUS. A row-counting
     * implementation locks a multi-chain operator out of their own page.
     */
    public function testOneOperatorHoldingTwoValidatorsOnOnePageStillResolves(): void
    {
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_A);
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_A);

        $r = ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID);

        self::assertSame(ClaimRepository::OPERATOR_RESOLVED, $r['state'], 'two rows, one user');
        self::assertSame(self::USER_A, $r['user_id']);
    }

    // ── AMBIGUOUS — MUST-NOT-HAPPEN #1 ────────────────────────────────────

    public function testTwoDistinctOperatorsOnOneValidatorAreAmbiguousAndNeverResolved(): void
    {
        $vid = $this->validator($this->walletLink(self::USER_A, self::PAGE_ID));
        $this->claim(self::USER_A, 'validator', $vid);
        $this->claim(self::USER_B, 'validator', $vid);

        $r = ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID);

        self::assertSame(ClaimRepository::OPERATOR_AMBIGUOUS, $r['state']);
        self::assertNull($r['user_id'], 'an ambiguous page must never name a winner');
    }

    /**
     * The multi-chain shape of the same hazard: two DIFFERENT validators on
     * one page, each claimed by a different user. Per-validator exclusivity
     * is satisfied — `createExclusiveClaim` would allow BOTH, because it locks
     * on `entity_id`, not on the page. So this contested state is reachable
     * through the ORDINARY write path, with no manual SQL at all.
     */
    public function testTwoOperatorsOnDifferentValidatorsOfOnePageAreAmbiguous(): void
    {
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_A);
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_B);

        $r = ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID);

        self::assertSame(ClaimRepository::OPERATOR_AMBIGUOUS, $r['state']);
        self::assertNull($r['user_id']);
    }

    /** Ambiguity on one page must not contaminate a clean neighbour. */
    public function testAmbiguityIsScopedToItsOwnPage(): void
    {
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_A);
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_B);
        $this->claimedValidatorOn(self::OTHER_PAGE, self::USER_A);

        $map = ClaimRepository::resolveVerifiedOperatorForPages([self::PAGE_ID, self::OTHER_PAGE]);

        self::assertSame(ClaimRepository::OPERATOR_AMBIGUOUS, $map[self::PAGE_ID]['state']);
        self::assertSame(ClaimRepository::OPERATOR_RESOLVED, $map[self::OTHER_PAGE]['state']);
        self::assertSame(self::USER_A, $map[self::OTHER_PAGE]['user_id']);
    }

    // ── UNCLAIMED, and absence as revocation ──────────────────────────────

    public function testAPageWithNoClaimsIsAbsentFromTheMapAndUnclaimedAtThePoint(): void
    {
        $this->validator($this->walletLink(self::USER_A, self::PAGE_ID));

        self::assertArrayNotHasKey(
            self::PAGE_ID,
            ClaimRepository::resolveVerifiedOperatorForPages([self::PAGE_ID]),
            'the batch map omits unclaimed pages — callers rely on the ?? default'
        );

        $r = ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID);
        self::assertSame(ClaimRepository::OPERATOR_UNCLAIMED, $r['state']);
        self::assertNull($r['user_id']);
    }

    /**
     * MUST-NOT-HAPPEN #4. Revocation is a row DELETE — `ClaimStatus::REVOKED`
     * is declared but assigned by no code path, and the only mutators are
     * `upsert`, `deleteByUserAndWallet` (wallet unlink) and `deleteForUser`.
     * So a revoked operator presents as UNCLAIMED, and anything rechecking
     * ownership must read absence as revocation.
     */
    public function testDeletingTheClaimRowReturnsThePageToUnclaimed(): void
    {
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_A);
        self::assertSame(
            ClaimRepository::OPERATOR_RESOLVED,
            ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID)['state']
        );

        global $wpdb;
        $wpdb->query('DELETE FROM `' . $wpdb->prefix . 'bcc_onchain_claims` WHERE user_id = ' . self::USER_A);

        self::assertSame(
            ClaimRepository::OPERATOR_UNCLAIMED,
            ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID)['state']
        );
    }

    public function testANonPositivePageIdIsUnclaimedAndTheEmptyBatchIsEmpty(): void
    {
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_A);

        foreach ([0, -1] as $bad) {
            self::assertSame(
                ClaimRepository::OPERATOR_UNCLAIMED,
                ClaimRepository::resolveVerifiedOperatorForPage($bad)['state'],
                "pageId {$bad}"
            );
        }
        self::assertSame([], ClaimRepository::resolveVerifiedOperatorForPages([]));
        self::assertSame([], ClaimRepository::resolveVerifiedOperatorForPages([0, -3]));
    }

    // ── Exclusions — MUST-NOT-HAPPEN #3 and #4 ────────────────────────────

    /**
     * The creator role and the collection leg are both accepted by the live
     * page-image gate and must both be invisible here.
     */
    public function testCreatorRoleAndCollectionLegAreBothExcluded(): void
    {
        $link = $this->walletLink(self::USER_A, self::PAGE_ID);

        // Creator role on the validator leg.
        $this->claim(self::USER_A, 'validator', $this->validator($link), 'creator');
        // Operator role on the collection leg.
        $this->claim(self::USER_B, 'collection', $this->collection($link));

        self::assertSame(
            ClaimRepository::OPERATOR_UNCLAIMED,
            ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID)['state']
        );
    }

    public function testHolderRoleIsExcluded(): void
    {
        $this->claim(self::USER_A, 'validator', $this->validator($this->walletLink(self::USER_A, self::PAGE_ID)), 'holder');

        self::assertSame(
            ClaimRepository::OPERATOR_UNCLAIMED,
            ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID)['state']
        );
    }

    public function testNonVerifiedStatusesAreExcluded(): void
    {
        foreach (['pending', 'revoked'] as $status) {
            $this->setUp();
            $this->claim(
                self::USER_A,
                'validator',
                $this->validator($this->walletLink(self::USER_A, self::PAGE_ID)),
                'operator',
                $status
            );

            self::assertSame(
                ClaimRepository::OPERATOR_UNCLAIMED,
                ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID)['state'],
                "status {$status}"
            );
        }
    }

    /**
     * A claim whose validator row is gone, and a validator whose
     * `wallet_link_id` is NULL, both drop out — the resolver's INNER JOINs
     * are what bind a claim to a page at all, so neither can be reached.
     */
    public function testAClaimWithNoLiveJoinPathIsExcluded(): void
    {
        // Validator row deleted after the claim was made.
        $vid = $this->validator($this->walletLink(self::USER_A, self::PAGE_ID));
        $this->claim(self::USER_A, 'validator', $vid);
        global $wpdb;
        $wpdb->query('DELETE FROM `' . $wpdb->prefix . 'bcc_onchain_validators` WHERE id = ' . $vid);

        // A second validator with no wallet link at all.
        $this->claim(self::USER_B, 'validator', $this->validator(null));

        self::assertSame(
            ClaimRepository::OPERATOR_UNCLAIMED,
            ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE_ID)['state']
        );
    }

    // ── Bounds ────────────────────────────────────────────────────────────

    /**
     * The IN-list is capped at 100. Pages beyond the cap are not answered —
     * and because the map omits unanswered pages, a caller applying the
     * documented `?? UNCLAIMED` default reads them as unclaimed rather than
     * as an error. Pinned here so the cap is a known, deliberate bound and
     * not a silent truncation someone discovers in production.
     */
    public function testTheBatchIsCappedAtOneHundredPages(): void
    {
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_A);

        $ids = range(9000, 9000 + 104);   // 105 ids, none of them PAGE_ID
        $ids[] = self::PAGE_ID;           // the 106th — beyond the cap

        $map = ClaimRepository::resolveVerifiedOperatorForPages($ids);

        self::assertArrayNotHasKey(
            self::PAGE_ID,
            $map,
            'a page past the 100-id cap is simply not answered'
        );

        // Positive control: the same page inside the cap IS answered, so the
        // assertion above is about the cap and not about a broken fixture.
        $withinCap = ClaimRepository::resolveVerifiedOperatorForPages([self::PAGE_ID]);
        self::assertSame(ClaimRepository::OPERATOR_RESOLVED, $withinCap[self::PAGE_ID]['state']);
    }

    public function testDuplicatePageIdsAreDedupedRatherThanMultiplyingRows(): void
    {
        $this->claimedValidatorOn(self::PAGE_ID, self::USER_A);

        $map = ClaimRepository::resolveVerifiedOperatorForPages(
            [self::PAGE_ID, self::PAGE_ID, self::PAGE_ID]
        );

        self::assertCount(1, $map);
        self::assertSame(ClaimRepository::OPERATOR_RESOLVED, $map[self::PAGE_ID]['state']);
    }
}
