<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins ClaimRepository::userHasVerifiedClaimOnPage() — the canonical
 * claim→page resolution behind the page-image permission gate
 * (PagesEndpoint::requireClaimer) — against a real MySQL, exercising the
 * genuine two-leg validator+collection UNION.
 *
 * The load-bearing assertions:
 *   1. A verified operator claim on a validator whose wallet link binds to
 *      the page resolves TRUE (ditto creator claim on a collection).
 *   2. The legacy entity_type='page' mirror row alone resolves FALSE — the
 *      gate must read the underlying claim, not the best-effort mirror
 *      (regression pin for the smoke-found 403: no live flow guarantees a
 *      mirror row exists).
 *   3. Pending/revoked status, holder role, an unlinked entity
 *      (wallet_link_id NULL), a different page, and a different user all
 *      resolve FALSE.
 */
#[Group('integration')]
#[CoversClass(ClaimRepository::class)]
final class UserVerifiedClaimOnPageIntegrationTest extends TestCase
{
    private const USER_ID  = 140;
    private const PAGE_ID  = 2105;
    private const CHAIN_ID = 8;

    protected function setUp(): void
    {
        global $wpdb;
        foreach (['bcc_onchain_claims', 'bcc_onchain_validators', 'bcc_onchain_collections', 'bcc_wallet_links'] as $t) {
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . $t . '`');
        }
    }

    private function insertWalletLink(int $userId = self::USER_ID, int $postId = self::PAGE_ID): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => $userId,
            'post_id'        => $postId,
            'wallet_address' => 'cosmos1integrationfixture' . $userId . 'p' . $postId,
            'chain_id'       => self::CHAIN_ID,
            'wallet_type'    => 'validator',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function insertValidator(?int $walletLinkId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id'   => $walletLinkId,
            'operator_address' => 'cosmosvaloper1integration' . uniqid(),
            'chain_id'         => self::CHAIN_ID,
            'moniker'          => 'Coinbase01',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function insertCollection(?int $walletLinkId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_collections', [
            'wallet_link_id'   => $walletLinkId,
            'contract_address' => '0xintegration' . uniqid(),
            'chain_id'         => self::CHAIN_ID,
            'collection_name'  => 'Fixture Collection',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function insertClaim(
        string $entityType,
        int $entityId,
        string $role = 'operator',
        string $status = 'verified',
        int $userId = self::USER_ID
    ): void {
        if ($status === 'verified') {
            // Verified fixtures go through the REAL write path — the same
            // upsert the live claim flow uses.
            $result = ClaimRepository::upsert(
                $userId,
                $entityType,
                $entityId,
                'cosmos1integrationfixture' . $userId,
                self::CHAIN_ID,
                $role,
                $status
            );
            self::assertNotFalse($result, 'fixture claim upsert must succeed');
            return;
        }

        // Non-verified fixtures are inserted directly: upsert()'s WP-style
        // prepare() renders the NULL verified_at as '' (both here and in real
        // wpdb), which strict-mode MySQL rejects for DATETIME. No live flow
        // writes non-verified rows via upsert, so the direct insert models
        // the row shape without depending on that dormant path.
        global $wpdb;
        $result = $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
            'user_id'        => $userId,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'wallet_address' => 'cosmos1integrationfixture' . $userId,
            'chain_id'       => self::CHAIN_ID,
            'claim_role'     => $role,
            'status'         => $status,
            'verified_at'    => null,
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($result, 'fixture claim insert must succeed');
    }

    public function testVerifiedOperatorValidatorClaimResolvesToPage(): void
    {
        $walletLinkId = $this->insertWalletLink();
        $validatorId  = $this->insertValidator($walletLinkId);
        $this->insertClaim('validator', $validatorId, 'operator', 'verified');

        self::assertTrue(ClaimRepository::userHasVerifiedClaimOnPage(self::USER_ID, self::PAGE_ID));
    }

    public function testVerifiedCreatorCollectionClaimResolvesToPage(): void
    {
        $walletLinkId = $this->insertWalletLink();
        $collectionId = $this->insertCollection($walletLinkId);
        $this->insertClaim('collection', $collectionId, 'creator', 'verified');

        self::assertTrue(ClaimRepository::userHasVerifiedClaimOnPage(self::USER_ID, self::PAGE_ID));
    }

    public function testPageMirrorRowAloneDoesNotResolve(): void
    {
        // The legacy gate accepted this shape; the canonical gate must not —
        // a bare mirror row proves nothing about the underlying claim.
        $this->insertClaim('page', self::PAGE_ID, 'operator', 'verified');

        self::assertFalse(ClaimRepository::userHasVerifiedClaimOnPage(self::USER_ID, self::PAGE_ID));
    }

    public function testPendingClaimDoesNotResolve(): void
    {
        $walletLinkId = $this->insertWalletLink();
        $validatorId  = $this->insertValidator($walletLinkId);
        $this->insertClaim('validator', $validatorId, 'operator', 'pending');

        self::assertFalse(ClaimRepository::userHasVerifiedClaimOnPage(self::USER_ID, self::PAGE_ID));
    }

    public function testHolderRoleDoesNotResolve(): void
    {
        $walletLinkId = $this->insertWalletLink();
        $collectionId = $this->insertCollection($walletLinkId);
        $this->insertClaim('collection', $collectionId, 'holder', 'verified');

        self::assertFalse(ClaimRepository::userHasVerifiedClaimOnPage(self::USER_ID, self::PAGE_ID));
    }

    public function testUnlinkedEntityDoesNotResolve(): void
    {
        // Bulk-indexed validator: wallet_link_id NULL → no page binding.
        $validatorId = $this->insertValidator(null);
        $this->insertClaim('validator', $validatorId, 'operator', 'verified');

        self::assertFalse(ClaimRepository::userHasVerifiedClaimOnPage(self::USER_ID, self::PAGE_ID));
    }

    public function testClaimOnAnotherPageDoesNotResolve(): void
    {
        $walletLinkId = $this->insertWalletLink(self::USER_ID, 9999);
        $validatorId  = $this->insertValidator($walletLinkId);
        $this->insertClaim('validator', $validatorId, 'operator', 'verified');

        self::assertFalse(ClaimRepository::userHasVerifiedClaimOnPage(self::USER_ID, self::PAGE_ID));
        self::assertTrue(ClaimRepository::userHasVerifiedClaimOnPage(self::USER_ID, 9999));
    }

    public function testAnotherUsersClaimDoesNotResolveForThisUser(): void
    {
        $walletLinkId = $this->insertWalletLink();
        $validatorId  = $this->insertValidator($walletLinkId);
        $this->insertClaim('validator', $validatorId, 'operator', 'verified', 999);

        self::assertFalse(ClaimRepository::userHasVerifiedClaimOnPage(self::USER_ID, self::PAGE_ID));
    }

    public function testNonPositiveIdsShortCircuitFalse(): void
    {
        self::assertFalse(ClaimRepository::userHasVerifiedClaimOnPage(0, self::PAGE_ID));
        self::assertFalse(ClaimRepository::userHasVerifiedClaimOnPage(self::USER_ID, 0));
        self::assertFalse(ClaimRepository::userHasVerifiedClaimOnPage(-1, -1));
    }
}
