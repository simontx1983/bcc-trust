<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-334 — "does ANY verified operator/creator claim still bind this page?",
 * against a real database. The page-release step trusts this answer before
 * resetting post_author, so it must follow the SAME binding rule revoke()
 * uses (the wallet link OR the placeholder `_bcc_onchain_validator_id`
 * meta), ignore holders and revoked claims, and fail CLOSED.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ClaimRepository::class)]
final class AnyPrimaryClaimBoundToPageIntegrationTest extends TestCase
{
    private const PAGE = 3401;
    private const USER = 9401;

    private int $chain = 0;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->clearFaultInjection();
        $chain = $wpdb->get_var("SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE chain_type = 'cosmos' ORDER BY id ASC LIMIT 1");
        self::assertNotNull($chain);
        $this->chain = (int) $chain;
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->prefix}bcc_onchain_claims` WHERE user_id = %d", self::USER));
        $wpdb->query("DELETE FROM `{$wpdb->prefix}bcc_onchain_validators` WHERE moniker LIKE 'F334 %'");
        $wpdb->query("DELETE FROM `{$wpdb->prefix}bcc_wallet_links` WHERE wallet_address LIKE 'f334%'");
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d", self::PAGE));
    }

    private function validator(?int $walletLinkId): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id'   => $walletLinkId,
            'operator_address' => 'f334valoper' . uniqid(),
            'chain_id'         => $this->chain,
            'moniker'          => 'F334 ' . uniqid(),
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        self::assertNotFalse($ok, (string) $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    private function pageLink(): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id' => 1, 'post_id' => self::PAGE, 'wallet_address' => 'f334link' . uniqid(),
            'chain_id' => $this->chain, 'wallet_type' => 'validator', 'verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, (string) $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    private function claim(int $validatorId, string $role = 'operator', string $status = 'verified'): void
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
            'user_id' => self::USER, 'entity_type' => 'validator', 'entity_id' => $validatorId,
            'wallet_address' => 'f334wallet', 'chain_id' => $this->chain,
            'claim_role' => $role, 'status' => $status,
        ]);
        self::assertNotFalse($ok, (string) $wpdb->last_error);
    }

    public function testNoClaimMeansNotHeld(): void
    {
        self::assertFalse(ClaimRepository::anyPrimaryClaimBoundToPage(self::PAGE));
    }

    public function testAVerifiedOperatorThroughTheWalletLinkHoldsThePage(): void
    {
        $this->claim($this->validator($this->pageLink()));

        self::assertTrue(ClaimRepository::anyPrimaryClaimBoundToPage(self::PAGE));
    }

    /** Placeholder pages bind validators through post meta, not a wallet link. */
    public function testAVerifiedOperatorThroughThePlaceholderBindingHoldsThePage(): void
    {
        $validatorId = $this->validator(null);
        self::assertTrue((bool) update_post_meta(self::PAGE, '_bcc_onchain_validator_id', (string) $validatorId));
        $this->claim($validatorId);

        self::assertTrue(ClaimRepository::anyPrimaryClaimBoundToPage(self::PAGE));
    }

    public function testHolderAndRevokedClaimsDoNotHoldThePage(): void
    {
        $this->claim($this->validator($this->pageLink()), 'holder');
        $this->claim($this->validator($this->pageLink()), 'operator', 'revoked');

        self::assertFalse(ClaimRepository::anyPrimaryClaimBoundToPage(self::PAGE));
    }

    /** Unknown is never "free": a failed read throws instead of answering false. */
    public function testAFailedReadThrows(): void
    {
        global $wpdb;
        $wpdb->failQueriesMatching = '/bcc_onchain_claims/i';

        try {
            ClaimRepository::anyPrimaryClaimBoundToPage(self::PAGE);
            self::fail('a failed read must not answer');
        } catch (RepositoryReadFailure $e) {
            self::assertGreaterThan(0, $wpdb->injectedFailures);
        }
    }
}
