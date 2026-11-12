<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-157: the dead-validator sweep marks only rows on the chains passed in
 * (the chains the index run actually indexed). Real table, real SQL.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversMethod(ValidatorRepository::class, 'markDeadValidators')]
final class DeadValidatorSweepChainScopeIntegrationTest extends TestCase
{
    private const MAX_ATTEMPTS = 10;

    private static function table(): string
    {
        return ValidatorRepository::table();
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE `' . self::table() . '`');
    }

    /** A row old enough, with attempts exhausted: eligible to be marked dead. */
    private static function staleRow(int $chainId, string $address): void
    {
        global $wpdb;
        $ok = $wpdb->insert(self::table(), [
            'operator_address'    => $address,
            'chain_id'            => $chainId,
            'status'              => 'active',
            'fetched_at'          => gmdate('Y-m-d H:i:s', time() - 40 * 86400),
            'expires_at'          => gmdate('Y-m-d H:i:s', time() - 39 * 86400),
            'enrichment_attempts' => self::MAX_ATTEMPTS,
        ]);
        self::assertNotFalse($ok, 'fixture insert: ' . $wpdb->last_error);
    }

    /** @return array<string, string> address => status */
    private static function statuses(): array
    {
        global $wpdb;
        $out = [];
        foreach ($wpdb->get_results('SELECT operator_address, status FROM `' . self::table() . '`') ?? [] as $r) {
            $out[(string) $r->operator_address] = (string) $r->status;
        }
        ksort($out);
        return $out;
    }

    public function testOnlyTheIndexedChainsRowsAreMarked(): void
    {
        self::staleRow(9, 'valoper-a');
        self::staleRow(10, 'valoper-b');   // chain skipped this run

        $n = ValidatorRepository::markDeadValidators(self::MAX_ATTEMPTS, [9]);

        self::assertSame(1, $n);
        self::assertSame(['valoper-a' => 'inactive', 'valoper-b' => 'active'], self::statuses());
    }

    public function testSeveralChainsAndDuplicatesBind(): void
    {
        self::staleRow(9, 'valoper-a');
        self::staleRow(10, 'valoper-b');
        self::staleRow(11, 'valoper-c');

        $n = ValidatorRepository::markDeadValidators(self::MAX_ATTEMPTS, [9, 11, 9, 0]);

        self::assertSame(2, $n);
        self::assertSame(['valoper-a' => 'inactive', 'valoper-b' => 'active', 'valoper-c' => 'inactive'], self::statuses());
    }

    public function testAnEmptyListMarksNothing(): void
    {
        self::staleRow(9, 'valoper-a');

        self::assertSame(0, ValidatorRepository::markDeadValidators(self::MAX_ATTEMPTS, []));
        self::assertSame(['valoper-a' => 'active'], self::statuses());
    }
}
