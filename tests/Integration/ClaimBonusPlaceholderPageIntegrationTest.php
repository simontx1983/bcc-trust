<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Services\BonusService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The operator bonus must reach the scoring pipeline for a SYSTEM-MINTED
 * PLACEHOLDER page — the only kind the validator-outreach programme claims.
 *
 * `applyClaimBonus()` resolved a page two ways, and on a placeholder claim
 * BOTH return 0:
 *
 *   1. `WalletRepository::getPostIdForEntity()` JOINs
 *      `validators.wallet_link_id` -> `wallet_links.post_id`. A minted
 *      placeholder validator has `wallet_link_id IS NULL`, so the JOIN
 *      yields nothing.
 *   2. `PageOwnerResolver::getPageForOwner()` reads the scores table (the
 *      very row this call is trying to create) and then `post_author`. But
 *      `bcc_onchain_claim_verified` fires INSIDE `ClaimService::claim()`,
 *      while `post_author` is only transferred later, on `bcc_page_claimed`
 *      — so at this instant the page still has `post_author = 0`.
 *
 * The method then returned. Nothing was written, nothing logged and nothing
 * queued for retry, so `BonusRetryService` never saw it and no later pass
 * repaired it: `bcc_onchain_refresh_page` has no dispatcher anywhere, and
 * the wallet-seed path runs once at signup, before any page is owned.
 *
 * Measured on staging 2026-11-05 with a real signed claim: both claim rows
 * written, `bcc_trust_page_scores` 0, `bcc_onchain_pending_bonus` absent.
 *
 * ── Why the queue is the observable, not the score row
 * This harness has no real ScoreContributor (`apply_filters` is a
 * passthrough, so the NullObject comes back), which means a RESOLVED page
 * lands in `BonusRetryService`'s queue instead of the scores table. That is
 * the honest observable at this layer, and it is the same one measured on
 * staging: resolved => the page id appears in the bonus pipeline;
 * unresolved => nothing exists anywhere. The sum assertion below is what
 * proves a real recompute ran rather than a constant being echoed back.
 *
 * ── ⚠⚠ WHY THIS SUITE RUNS IN SEPARATE PROCESSES
 * `recomputeAndApply()` reaches `get_post()` (via
 * `SignalRepository::get_for_page()` -> `PeepSo::get_page_owner()` ->
 * `NullPageOwnerResolver`, which is NOT a no-op) and `get_posts()` (via the
 * `getPageForOwner()` fallback). The shared bootstrap defines neither, so
 * this suite brings its own stubs — and a global function declared here is
 * declared for every suite loaded after it in the same process.
 *
 * MEASURED: declaring them in-process made all 21 cases of
 * `PageReadModelSyncIntegrationTest` fail (862/28/16 -> 868/28/37). That
 * suite owns its own `get_post` in `page-read-model-sync-stubs.php`, and
 * because this file sorts earlier its `function_exists()` guard found mine
 * already present and silently skipped its own. Process isolation is
 * therefore load-bearing here, not hygiene — same reason
 * `PageClaimBindingIntegrationTest` uses it.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(BonusService::class)]
final class ClaimBonusPlaceholderPageIntegrationTest extends TestCase
{
    private const OPTION     = 'bcc_onchain_pending_bonus';
    private const USER_ID    = 9301;
    private const PAGE_ID    = 9311;
    private const OTHER_PAGE = 9312;

    private int $chainId;

    /** @var list<int> */
    private array $validatorIds = [];

    protected function setUp(): void
    {
        // ⚠ Required HERE, not at file scope. The parent process loads every
        // test file to enumerate cases, so a file-level require would declare
        // these globals in the parent and leak into sibling suites even with
        // process isolation on. setUp() runs only in the child.
        require_once dirname(__DIR__) . '/Stubs/claim-bonus-stubs.php';

        global $wpdb;

        $this->chainId = (int) $wpdb->get_var(
            "SELECT id FROM `{$wpdb->prefix}bcc_chains` WHERE chain_type = 'cosmos' ORDER BY id ASC LIMIT 1"
        );
        self::assertGreaterThan(0, $this->chainId, 'fixture needs a cosmos chain');

        // Own rows only. These tables are shared with sibling suites, so
        // this deliberately never truncates them.
        $this->cleanup();
        delete_option(self::OPTION);

        // ⚠ post_author = 0 on purpose: a minted placeholder page is unowned
        // until `bcc_page_claimed` transfers authorship, and that fires
        // AFTER the bonus listener. This is the state production sees.
        foreach ([self::PAGE_ID, self::OTHER_PAGE] as $pageId) {
            // stdClass, not WP_Post: this harness does not load WP core, and
            // every consumer below reads plain properties.
            $GLOBALS['__bcc_posts'][$pageId] = (object) [
                'ID'          => $pageId,
                'post_type'   => 'peepso-page',
                'post_status' => 'publish',
                'post_author' => 0,
            ];
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        delete_option(self::OPTION);
    }

    private function cleanup(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->prefix}bcc_onchain_claims` WHERE user_id = %d",
            self::USER_ID
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->prefix}bcc_wallet_links` WHERE user_id = %d",
            self::USER_ID
        ));

        foreach ([self::PAGE_ID, self::OTHER_PAGE] as $pageId) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d AND meta_key = '_bcc_onchain_validator_id'",
                $pageId
            ));
        }

        foreach ($this->validatorIds as $id) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$wpdb->prefix}bcc_onchain_validators` WHERE id = %d",
                $id
            ));
        }

        $this->validatorIds = [];
    }

    /** A minted placeholder validator has no wallet link at all. */
    private function insertValidator(?int $walletLinkId = null): int
    {
        global $wpdb;

        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id'   => $walletLinkId,
            'operator_address' => 'cosmosvaloper1bf' . substr(md5(uniqid('bonus', true)), 0, 24),
            'chain_id'         => $this->chainId,
            'moniker'          => 'Bonus Fixture',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        self::assertNotFalse($ok, 'validator fixture: ' . $wpdb->last_error);

        $id = (int) $wpdb->insert_id;
        $this->validatorIds[] = $id;

        return $id;
    }

    private function insertWalletLinkBoundToPage(int $pageId): int
    {
        global $wpdb;

        $ok = $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => self::USER_ID,
            'post_id'        => $pageId,
            'wallet_address' => 'cosmos1bf' . substr(md5(uniqid('wl', true)), 0, 24),
            'chain_id'       => $this->chainId,
            'wallet_type'    => 'user',
        ]);
        self::assertNotFalse($ok, 'wallet_link fixture: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    /** The verified row that `computePageClaimBonus()` sums. */
    private function insertVerifiedOperatorClaim(int $validatorId): void
    {
        global $wpdb;

        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
            'user_id'        => self::USER_ID,
            'entity_type'    => 'validator',
            'entity_id'      => $validatorId,
            'wallet_address' => 'cosmos1bonusfixture',
            'chain_id'       => $this->chainId,
            'claim_role'     => 'operator',
            'status'         => 'verified',
        ]);
        self::assertNotFalse($ok, 'claim fixture: ' . $wpdb->last_error);
    }

    /** @return array<int, array<string, mixed>> */
    private function queue(): array
    {
        $pending = get_option(self::OPTION, []);

        return is_array($pending) ? $pending : [];
    }

    // ── the regression ──────────────────────────────────────────────────

    public function testAPlaceholderBoundValidatorClaimReachesTheBonusPipeline(): void
    {
        $validatorId = $this->insertValidator(null);
        self::assertTrue(update_post_meta(self::PAGE_ID, '_bcc_onchain_validator_id', (string) $validatorId));
        $this->insertVerifiedOperatorClaim($validatorId);

        BonusService::applyClaimBonus(self::USER_ID, 'validator', $validatorId, 'operator');

        $queue = $this->queue();
        self::assertArrayHasKey(
            self::PAGE_ID,
            $queue,
            'the operator bonus must resolve the placeholder page and reach the bonus pipeline'
        );
        self::assertSame(5.0, (float) $queue[self::PAGE_ID]['bonus'], 'operator bonus is 5.0');
    }

    public function testTheResolvedBonusSumsEveryVerifiedClaimTheOperatorHolds(): void
    {
        $first  = $this->insertValidator(null);
        $second = $this->insertValidator(null);
        self::assertTrue(update_post_meta(self::PAGE_ID, '_bcc_onchain_validator_id', (string) $first));
        $this->insertVerifiedOperatorClaim($first);
        $this->insertVerifiedOperatorClaim($second);

        BonusService::applyClaimBonus(self::USER_ID, 'validator', $first, 'operator');

        $queue = $this->queue();
        self::assertArrayHasKey(self::PAGE_ID, $queue);
        self::assertSame(
            10.0,
            (float) $queue[self::PAGE_ID]['bonus'],
            'two verified operator claims => 5.0 + 5.0, which proves the real recompute ran'
        );
    }

    // ── controls ────────────────────────────────────────────────────────

    public function testTheWalletLinkedPathStillResolvesItsOwnPage(): void
    {
        $walletLinkId = $this->insertWalletLinkBoundToPage(self::OTHER_PAGE);
        $validatorId  = $this->insertValidator($walletLinkId);
        $this->insertVerifiedOperatorClaim($validatorId);

        BonusService::applyClaimBonus(self::USER_ID, 'validator', $validatorId, 'operator');

        self::assertArrayHasKey(
            self::OTHER_PAGE,
            $this->queue(),
            'the pre-existing wallet-link leg must keep working, and keep taking priority'
        );
    }

    public function testAValidatorBoundToNoPageAtAllStillResolvesNothing(): void
    {
        $validatorId = $this->insertValidator(null);
        $this->insertVerifiedOperatorClaim($validatorId);

        BonusService::applyClaimBonus(self::USER_ID, 'validator', $validatorId, 'operator');

        self::assertSame(
            [],
            $this->queue(),
            'with no binding of any kind there is no page to credit, and none may be invented'
        );
    }

    /**
     * The leg added by this fix, pinned directly — so the end-to-end
     * assertions above cannot pass for some unrelated reason, and the
     * "resolves nothing" case cannot be a method that always returns 0.
     */
    public function testThePlaceholderResolverIsTwoSided(): void
    {
        $validatorId = $this->insertValidator(null);

        self::assertSame(
            0,
            ValidatorRepository::findPlaceholderPageId($validatorId),
            'no post_meta binding => no page'
        );

        self::assertTrue(update_post_meta(self::PAGE_ID, '_bcc_onchain_validator_id', (string) $validatorId));

        self::assertSame(
            self::PAGE_ID,
            ValidatorRepository::findPlaceholderPageId($validatorId),
            'a bound placeholder resolves to exactly its page'
        );
    }

    public function testARoleWithNoBonusShortCircuitsBeforeAnyPageLookup(): void
    {
        $validatorId = $this->insertValidator(null);
        self::assertTrue(update_post_meta(self::PAGE_ID, '_bcc_onchain_validator_id', (string) $validatorId));

        BonusService::applyClaimBonus(self::USER_ID, 'validator', $validatorId, 'delegator');

        self::assertSame([], $this->queue(), 'an unmapped role must not reach the page lookup at all');
    }
}
