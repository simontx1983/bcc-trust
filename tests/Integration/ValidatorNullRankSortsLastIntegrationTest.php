<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-146: a validator with no `voting_power_rank` (NULL) sorted FIRST in the
 * rank-ordered lists, ahead of rank #1. MySQL and MariaDB both sort NULL
 * first under ASC, so this runs on both engines (the `mariadb` group is the
 * CI job against the production engine).
 */
#[Group('integration')]
#[Group('mariadb')]
final class ValidatorNullRankSortsLastIntegrationTest extends TestCase
{
    private const POST = 770146;

    private int $chainId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $chainId = ChainRepository::resolveIdAnyState('cosmos');
        self::assertIsInt($chainId, 'the chain registry must be seeded');
        $this->chainId = $chainId;
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare('DELETE FROM `' . WalletRepository::table() . '` WHERE post_id = %d', self::POST));
        $wpdb->query("DELETE FROM `" . ValidatorRepository::table() . "` WHERE operator_address LIKE 'f146val%'");
    }

    private function linkId(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->insert(WalletRepository::table(), [
            'user_id' => 770146, 'post_id' => self::POST, 'wallet_address' => 'cosmos1f146wallet', 'chain_id' => $this->chainId,
        ]);
        self::assertSame('', $wpdb->last_error, 'fixture: wallet link');

        return (int) $wpdb->insert_id;
    }

    /** Inserts one validator; null rank/commission are stored as SQL NULL. */
    private function seed(?int $linkId, string $suffix, ?int $rank, ?string $commission = '0.05'): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . ValidatorRepository::table() . '`'
            . ' (wallet_link_id, operator_address, chain_id, total_stake, voting_power_rank, commission_rate, expires_at)'
            . ' VALUES (' . ($linkId === null ? 'NULL' : '%d') . ', %s, %d, 1000, '
            . ($rank === null ? 'NULL' : '%d') . ', ' . ($commission === null ? 'NULL' : '%s') . ', UTC_TIMESTAMP())',
            ...array_values(array_filter(
                [$linkId, 'f146val' . $suffix, $this->chainId, $rank, $commission],
                static fn ($v): bool => $v !== null
            ))
        ));
        self::assertSame('', $wpdb->last_error, 'fixture: validator ' . $suffix);

        return (int) $wpdb->insert_id;
    }

    /** @return list<int> */
    private static function ids(array $items): array
    {
        return array_map(static fn (object $r): int => (int) $r->id, $items);
    }

    public function testTheRankSortPutsUnrankedValidatorsLast(): void
    {
        $link    = $this->linkId();
        $unrank  = $this->seed($link, 'null', null);
        $second  = $this->seed($link, 'r2', 2);
        $first   = $this->seed($link, 'r1', 1);

        $got = self::ids(ValidatorRepository::getForProject(self::POST, 1, 8, 'voting_power_rank')['items']);

        self::assertSame('', (string) $GLOBALS['wpdb']->last_error);
        self::assertSame([$first, $second, $unrank], $got, 'rank 1, rank 2, then the unranked one (it used to lead)');
    }

    /** Same NULL-first defect on the other ASC sort. */
    public function testTheCommissionSortPutsUnknownCommissionLast(): void
    {
        $link    = $this->linkId();
        $unknown = $this->seed($link, 'cnull', 1, null);
        $high    = $this->seed($link, 'chigh', 2, '0.10');
        $low     = $this->seed($link, 'clow', 3, '0.01');

        $got = self::ids(ValidatorRepository::getForProject(self::POST, 1, 8, 'commission_rate')['items']);

        self::assertSame([$low, $high, $unknown], $got);
    }

    /** Anti-vacuity: a DESC sort is unchanged (NULLs were already last there). */
    public function testTheDefaultStakeSortIsUnchanged(): void
    {
        $link = $this->linkId();
        $a    = $this->seed($link, 'sa', null);
        $b    = $this->seed($link, 'sb', 1);

        $got = self::ids(ValidatorRepository::getForProject(self::POST, 1, 8)['items']);

        self::assertSame([$b, $a], $got, 'tied stakes keep the id DESC tiebreak');
    }

    public function testThePlaceholderBacklogPutsUnrankedValidatorsLastWithinAChain(): void
    {
        $unrank = $this->seed(null, 'pnull', null);
        $second = $this->seed(null, 'p2', 2);
        $first  = $this->seed(null, 'p1', 1);

        $got = self::ids(ValidatorRepository::findMissingPlaceholderPage(
            $this->chainId,
            10,
            0,
            [$unrank, $second, $first]
        ));

        self::assertSame('', (string) $GLOBALS['wpdb']->last_error);
        self::assertSame([$first, $second, $unrank], $got);
    }
}
