<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use PHPUnit\Framework\TestCase;

/**
 * F-550 prerequisite, against the REAL database: `vote_account` on
 * wp_bcc_onchain_validators.
 *   - dbDelta adds the column to a table that predates it (the deploy path).
 *   - ValidatorRepository::bulkUpsert() stores, backfills and clears it for a
 *     fetcher that sends it, and never touches it for one that doesn't.
 */
final class ValidatorVoteAccountIntegrationTest extends TestCase
{
    private const VOTE_A = 'Vote111111111111111111111111111111111111111';
    private const VOTE_B = 'Stake11111111111111111111111111111111111111';

    private int $chainId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb']->clearFaultInjection();
        $chainId = ChainRepository::resolveIdAnyState('solana');
        self::assertIsInt($chainId, 'the chain registry must be seeded');
        $this->chainId = $chainId;
        $this->purge();
        unset($GLOBALS['__bcc_test_object_cache']['bcc_onchain_validators']['counts_by_chain']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        $GLOBALS['wpdb']->query("DELETE FROM `" . ValidatorRepository::table() . "` WHERE operator_address LIKE 'f550node%'");
    }

    /** @return array<string, mixed> */
    private function incoming(string $addr, mixed $vote = 'absent', string $moniker = 'F550'): array
    {
        $row = [
            'operator_address'  => $addr,
            'chain_id'          => $this->chainId,
            'moniker'           => $moniker,
            'status'            => 'active',
            'commission_rate'   => 5.0,
            'total_stake'       => 100.0,
            'jailed_count'      => 0,
            'voting_power_rank' => 1,
        ];
        if ($vote !== 'absent') {
            $row['vote_account'] = $vote;
        }
        return $row;
    }

    private function storedVote(string $addr): ?string
    {
        $wpdb = $GLOBALS['wpdb'];
        $vote = $wpdb->get_var($wpdb->prepare(
            'SELECT vote_account FROM `' . ValidatorRepository::table() . '` WHERE chain_id = %d AND operator_address = %s LIMIT 1',
            $this->chainId,
            $addr
        ));
        self::assertSame('', (string) $wpdb->last_error);
        return $vote === null ? null : (string) $vote;
    }

    public function testDbDeltaAddsTheColumnToATableThatPredatesIt(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ValidatorRepository::table();
        $has   = static fn (): bool => $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'vote_account')) === 'vote_account';

        self::assertTrue($has(), 'the bootstrap built the table with the column');
        $wpdb->query("ALTER TABLE `{$table}` DROP COLUMN vote_account");
        try {
            self::assertFalse($has());
            bcc_onchain_create_validators_table();
        } finally {
            if (!$has()) {
                // Never leave the shared table without its column.
                $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN vote_account VARCHAR(64) DEFAULT NULL");
            }
        }
        self::assertTrue($has(), 'dbDelta added the column on the existing table');
    }

    public function testASolanaRowIsStoredBackfilledAndCleared(): void
    {
        ValidatorRepository::bulkUpsert([$this->incoming('f550nodeA', self::VOTE_A)], 3600);
        self::assertSame(self::VOTE_A, $this->storedVote('f550nodeA'), 'inserted with it');

        $GLOBALS['wpdb']->query("UPDATE `" . ValidatorRepository::table() . "` SET vote_account = NULL WHERE operator_address = 'f550nodeA'");
        $stats = ValidatorRepository::bulkUpsert([$this->incoming('f550nodeA', self::VOTE_A)], 3600);
        self::assertSame(1, $stats['updated']);
        self::assertSame(self::VOTE_A, $this->storedVote('f550nodeA'), 'a row from before the column is backfilled');

        $stats = ValidatorRepository::bulkUpsert([$this->incoming('f550nodeA', self::VOTE_A)], 3600);
        self::assertSame(1, $stats['unchanged'], 'the same value is no write');

        ValidatorRepository::bulkUpsert([$this->incoming('f550nodeA', self::VOTE_B)], 3600);
        self::assertSame(self::VOTE_B, $this->storedVote('f550nodeA'), 'a changed value is written');

        ValidatorRepository::bulkUpsert([$this->incoming('f550nodeA', null)], 3600);
        self::assertNull($this->storedVote('f550nodeA'), 'an identity the fetcher cannot trust clears it');
    }

    public function testAFetcherThatDoesNotSendItNeverTouchesIt(): void
    {
        ValidatorRepository::bulkUpsert([$this->incoming('f550nodeB', self::VOTE_A)], 3600);

        $stats = ValidatorRepository::bulkUpsert([$this->incoming('f550nodeB', 'absent', 'Renamed')], 3600);

        self::assertSame(1, $stats['updated'], 'the moniker changed, so the row is rewritten');
        self::assertSame(self::VOTE_A, $this->storedVote('f550nodeB'), 'but the vote account is kept');
    }
}
