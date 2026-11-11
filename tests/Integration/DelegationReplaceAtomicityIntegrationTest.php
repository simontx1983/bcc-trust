<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\DelegationRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-145 — DelegationRepository::replaceForWalletLink must be all-or-nothing.
 *
 * It ran DELETE-then-INSERT in a transaction but checked none of the writes:
 * a failed insert was counted as inserted and the transaction COMMITTED after
 * the DELETE, so the wallet's previous delegation set was lost behind a
 * "success" count. That set feeds the shared-validator recommender and the
 * delegator-group gate's write-through.
 *
 * Runs the REAL repository against the REAL delegations schema; failures are
 * reached with server fault injection and each case asserts the fault fired.
 */
#[Group('integration')]
#[Group('mariadb')]
final class DelegationReplaceAtomicityIntegrationTest extends TestCase
{
    private const LINK  = 880001;
    private const CHAIN = 880001;

    private static bool $installed = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$installed) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-delegations.php';
            \bcc_onchain_create_delegations_table();
            self::$installed = true;
        }
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        parent::tearDown();
    }

    public function testAReplaceSwapsTheSet(): void
    {
        DelegationRepository::replaceForWalletLink(self::LINK, self::CHAIN, [['validator_address' => 'valA']]);

        $count = DelegationRepository::replaceForWalletLink(self::LINK, self::CHAIN, [
            ['validator_address' => 'valB'],
            ['validator_address' => 'valC'],
        ]);

        self::assertSame(2, $count);
        self::assertSame(['valB', 'valC'], $this->validators());
    }

    /** A failed insert throws and ROLLS BACK — the previous set survives. */
    public function testAFailedInsertKeepsThePreviousSet(): void
    {
        global $wpdb;
        DelegationRepository::replaceForWalletLink(self::LINK, self::CHAIN, [
            ['validator_address' => 'valA'],
            ['validator_address' => 'valB'],
        ]);

        $wpdb->failQueriesMatching = "/^\s*INSERT INTO `?\S*onchain_delegations.*'valD'/is";
        $threw = false;
        try {
            DelegationRepository::replaceForWalletLink(self::LINK, self::CHAIN, [
                ['validator_address' => 'valC'],
                ['validator_address' => 'valD'],
            ]);
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        $wpdb->clearFaultInjection();

        self::assertTrue($threw, 'a failed write must not report success');
        self::assertSame(['valA', 'valB'], $this->validators(), 'the DELETE was rolled back with the failed insert');
    }

    public function testAFailedDeleteKeepsThePreviousSet(): void
    {
        global $wpdb;
        DelegationRepository::replaceForWalletLink(self::LINK, self::CHAIN, [['validator_address' => 'valA']]);

        $wpdb->failQueriesMatching = '/^\s*DELETE FROM `?\S*onchain_delegations/i';
        $threw = false;
        try {
            DelegationRepository::replaceForWalletLink(self::LINK, self::CHAIN, [['validator_address' => 'valZ']]);
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        self::assertGreaterThan(0, $wpdb->injectedFailures);
        $wpdb->clearFaultInjection();

        self::assertTrue($threw);
        self::assertSame(['valA'], $this->validators());
    }

    /**
     * A provider list naming the same validator twice must not become a
     * unique-key failure that rolls back an otherwise good set.
     */
    public function testADuplicateValidatorInTheListIsDeduped(): void
    {
        $count = DelegationRepository::replaceForWalletLink(self::LINK, self::CHAIN, [
            ['validator_address' => 'valA', 'amount' => 1.0],
            ['validator_address' => 'valA', 'amount' => 2.0],
            ['validator_address' => 'valB'],
        ]);

        self::assertSame(2, $count);
        self::assertSame(['valA', 'valB'], $this->validators());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return list<string> */
    private function validators(): array
    {
        global $wpdb;
        $table = DelegationRepository::table();
        $rows  = $wpdb->get_col($wpdb->prepare(
            "SELECT validator_address FROM `{$table}` WHERE wallet_link_id = %d ORDER BY validator_address",
            self::LINK
        ));
        return array_values(array_map('strval', $rows));
    }

    private function purge(): void
    {
        global $wpdb;
        $table = DelegationRepository::table();
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE wallet_link_id = %d", self::LINK));
    }
}
