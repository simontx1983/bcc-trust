<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Services\CollectionDemandService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The demand aggregate against REAL MySQL: a failed read is null, never `[]`.
 *
 * ── WHY THIS NEEDS A DATABASE ───────────────────────────────────────────
 * The unit suites fake `NftHoldingsRepository`, so they can only prove the
 * service reacts correctly to a null. They cannot prove the repository ever
 * PRODUCES one — and the house idiom it replaced, `return $rows ?: [];`,
 * turned a SQL error into exactly the empty list that rendered every row as
 * having no linked holders. Here the query genuinely fails, against the
 * genuine `$wpdb`, and the detector is the genuine `GuardsReadFailures`.
 *
 * ── HOW THE FAILURE IS PRODUCED ─────────────────────────────────────────
 * The table is renamed out from under the query and put back in `finally`,
 * so the SELECT fails the way a missing table or a broken migration would.
 * The restore is asserted, so a failed restore fails the test rather than
 * silently breaking every test after it.
 */
#[Group('integration')]
#[CoversClass(NftHoldingsRepository::class)]
#[CoversClass(CollectionDemandService::class)]
final class NftHoldingsDemandReadIntegrationTest extends TestCase
{
    private const CONTRACT_A = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CONTRACT_B = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const CONTRACT_C = '0xcccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . NftHoldingsRepository::table() . '`');
    }

    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . NftHoldingsRepository::table() . '`');
    }

    private function hold(int $walletLinkId, int $chainId, string $contract, string $tokenId, int $status = NftHoldingsRepository::STATUS_OK): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $ok   = $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . NftHoldingsRepository::table() . '`
                (wallet_link_id, chain_id, contract_address, token_id, metadata_status, last_seen_block, confirmed_at)
             VALUES (%d, %d, %s, %s, %d, 1, UTC_TIMESTAMP())',
            $walletLinkId,
            $chainId,
            $contract,
            $tokenId,
            $status
        ));
        self::assertNotFalse($ok, 'fixture insert must land: ' . (string) $wpdb->last_error);
    }

    public function testDistinctWalletsAreCountedAndSpamIsExcluded(): void
    {
        $this->hold(1, 1, self::CONTRACT_A, '1');
        $this->hold(1, 1, self::CONTRACT_A, '2');   // same wallet, second token: still ONE wallet
        $this->hold(2, 1, self::CONTRACT_A, '3');
        $this->hold(3, 1, self::CONTRACT_A, '4', NftHoldingsRepository::STATUS_PENDING);
        $this->hold(4, 1, self::CONTRACT_B, '1');
        $this->hold(5, 1, self::CONTRACT_B, '2', NftHoldingsRepository::STATUS_SPAM); // not visible

        $rows = NftHoldingsRepository::countDistinctWalletsPerContract(10);

        self::assertNotNull($rows);
        $byContract = [];
        foreach ($rows as $r) {
            $byContract[strtolower((string) $r->contract_address)] = (int) $r->wallets;
        }
        self::assertSame([self::CONTRACT_A => 3, self::CONTRACT_B => 1], $byContract);
    }

    public function testAnEmptyIndexIsAnEmptyListNotNull(): void
    {
        self::assertSame([], NftHoldingsRepository::countDistinctWalletsPerContract(10));
    }

    public function testTheLimitIsHonouredSoTruncationIsDetectable(): void
    {
        $this->hold(1, 1, self::CONTRACT_A, '1');
        $this->hold(2, 1, self::CONTRACT_B, '1');
        $this->hold(3, 1, self::CONTRACT_C, '1');

        $rows = NftHoldingsRepository::countDistinctWalletsPerContract(2);

        self::assertNotNull($rows);
        self::assertCount(2, $rows);
    }

    /**
     * ⚠ THE CONTRACT THIS FILE EXISTS FOR, AGAINST A REAL SQL ERROR — and the
     * demand service on top of it, so "unavailable" is proven end to end
     * rather than only against a fake that was told to return null.
     */
    public function testAFailedReadIsNullAndTheDemandIsUnavailable(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = NftHoldingsRepository::table();
        $moved = $table . '_pr_demand_bak';

        $this->hold(1, 1, self::CONTRACT_A, '1');   // there IS data — the failure must not read as "none"

        self::assertNotFalse($wpdb->query("RENAME TABLE `{$table}` TO `{$moved}`"), 'could not stage the failure');
        try {
            $rows   = NftHoldingsRepository::countDistinctWalletsPerContract(10);
            $demand = CollectionDemandService::linkedHolderCounts();
        } finally {
            $wpdb->query("RENAME TABLE `{$moved}` TO `{$table}`");
        }

        self::assertSame(
            '1',
            (string) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"),
            'the table must be restored with its row — otherwise later tests would fail for the wrong reason'
        );

        self::assertNull($rows, 'a failed read is null, not an empty list');
        self::assertFalse($demand['available']);
        self::assertSame(
            CollectionDemandService::STATE_UNAVAILABLE,
            CollectionDemandService::rowState($demand, 'evm', 1, self::CONTRACT_A)['state'],
            'a row that genuinely has a holder must read UNAVAILABLE, never "none indexed"'
        );
    }
}
