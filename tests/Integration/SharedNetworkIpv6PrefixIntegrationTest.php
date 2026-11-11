<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AuditLogRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-108 (user decision): the shared-network signal compares IPv4 by the
 * exact address and IPv6 by its /64.
 *
 * One IPv6 client rotates its address inside its /64 (privacy extensions),
 * so an exact match made one person look like many networks and never
 * matched a household. AuditLogRepository::countDistinctUsersForIp feeds
 * both the young-account IP gate (VoteService) and multi-account detection
 * (FraudDetector::detectMultiAccountVoting).
 */
#[Group('integration')]
#[CoversClass(AuditLogRepository::class)]
final class SharedNetworkIpv6PrefixIntegrationTest extends TestCase
{
    private const SELF = 9801;

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query("DELETE FROM `" . TableRegistry::activity() . "` WHERE action LIKE 'itnet\\_%'");
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->query("DELETE FROM `" . TableRegistry::activity() . "` WHERE action LIKE 'itnet\\_%'");
    }

    private function seen(int $userId, string $ip, int $daysAgo = 0): void
    {
        $wpdb = $GLOBALS['wpdb'];
        self::assertNotFalse($wpdb->insert(TableRegistry::activity(), [
            'user_id'     => $userId,
            'action'      => 'itnet_vote',
            'target_type' => 'page',
            'target_id'   => 1,
            'ip_address'  => inet_pton($ip),
            'created_at'  => gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400 - 60),
        ]), $wpdb->last_error);
    }

    private function usersOnNetwork(string $ip, int $days = 7): int
    {
        return (new AuditLogRepository())->countDistinctUsersForIp((string) inet_pton($ip), self::SELF, $days);
    }

    public function testIpv6MatchesEveryAddressInTheSameSlash64(): void
    {
        $this->seen(1, '2001:db8:aa:1::1');
        $this->seen(2, '2001:db8:aa:1:ffff:ffff:ffff:ffff');   // top of the /64
        $this->seen(3, '2001:db8:aa:1:1234:5678:9abc:def0');
        $this->seen(3, '2001:db8:aa:1::99');                   // same user again: counted once
        $this->seen(4, '2001:db8:aa:2::1');                    // next /64: different network
        $this->seen(5, '2001:db8:aa:0:ffff:ffff:ffff:ffff');   // previous /64
        $this->seen(6, '32.1.13.184');                         // IPv4 = first 4 bytes of the prefix
        $this->seen(self::SELF, '2001:db8:aa:1::5');           // the asking user is excluded
        $this->seen(7, '2001:db8:aa:1::7', 30);                // outside the window

        self::assertSame(3, $this->usersOnNetwork('2001:db8:aa:1:abcd::42'));
    }

    public function testIpv4StaysAnExactMatch(): void
    {
        $this->seen(1, '198.51.100.20');
        $this->seen(2, '198.51.100.20');
        $this->seen(3, '198.51.100.21');                       // neighbour: different
        $this->seen(4, '::ffff:198.51.100.20');                // 16-byte form: different

        self::assertSame(2, $this->usersOnNetwork('198.51.100.20'));
    }
}
