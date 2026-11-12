<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Rank\Repositories\ClusterFindingsRepository;
use BCC\Trust\Rank\Services\IndependenceResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-87 L4: IndependenceResolver's static member→representative memo was never
 * invalidated. A process that recorded or reversed a confirmed finding kept
 * answering from the map it built before, and a long-running process (WP-CLI,
 * a cron batch) never saw another process's findings at all.
 *
 * Against the real repository on a real server.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(IndependenceResolver::class)]
#[CoversClass(ClusterFindingsRepository::class)]
final class IndependenceResolverMemoIntegrationTest extends TestCase
{
    private const REP    = 987201;
    private const MEMBER = 987202;

    private string $table = '';

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $this->table = $wpdb->prefix . 'bcc_trust_cluster_findings';
        $wpdb->query("DROP TABLE IF EXISTS `{$this->table}`");
        // The columns create() writes and the active-set read touches.
        $wpdb->query(
            "CREATE TABLE `{$this->table}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                level VARCHAR(12) NOT NULL,
                member_user_ids TEXT NOT NULL,
                representative_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                selection_method VARCHAR(24) NOT NULL DEFAULT '',
                evidence_refs TEXT NULL,
                confidence DECIMAL(5,4) NOT NULL DEFAULT 0,
                decided_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                decided_at DATETIME NULL,
                effective_at DATETIME NULL,
                reversed_at DATETIME DEFAULT NULL,
                reversal_reason VARCHAR(160) NULL
             ) ENGINE=InnoDB"
        );
        self::assertSame('', $wpdb->last_error, 'fixture table must be creatable');
        $this->resetMemo();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->query("DROP TABLE IF EXISTS `{$this->table}`");
        $this->resetMemo();
    }

    private function resetMemo(): void
    {
        (new \ReflectionProperty(IndependenceResolver::class, 'memberToRepresentative'))->setValue(null, null);
    }

    private function resolver(): IndependenceResolver
    {
        return new IndependenceResolver(new ClusterFindingsRepository());
    }

    private function confirm(): int
    {
        // An explicit representative, so create() needs no wp_users lookup.
        $id = (new ClusterFindingsRepository())->create('confirmed', [self::REP, self::MEMBER], self::REP, [], 0.9, 1);
        self::assertGreaterThan(0, $id, (string) $GLOBALS['wpdb']->last_error);

        return $id;
    }

    public function testRecordingAFindingInThisProcessIsSeenImmediately(): void
    {
        $resolver = $this->resolver();
        self::assertFalse($resolver->isZeroCredit(self::MEMBER), 'precondition: memo built with no findings');

        $this->confirm();

        self::assertTrue($resolver->isZeroCredit(self::MEMBER), 'the memo still answered from before the finding');
        self::assertSame(self::REP, $resolver->representativeOf(self::MEMBER));
    }

    public function testReversingAFindingInThisProcessIsSeenImmediately(): void
    {
        $id       = $this->confirm();
        $resolver = $this->resolver();
        self::assertTrue($resolver->isZeroCredit(self::MEMBER), 'precondition');

        self::assertTrue((new ClusterFindingsRepository())->reverse($id, 'appeal upheld'));

        self::assertFalse($resolver->isZeroCredit(self::MEMBER), 'a reversed finding still zero-credited the member');
    }

    public function testAnotherProcessesFindingIsSeenOnceTheMemoAgesOut(): void
    {
        $resolver = $this->resolver();
        self::assertFalse($resolver->isZeroCredit(self::MEMBER), 'precondition');

        // Written behind the repository's back — another process's write,
        // which this process's write counter cannot see.
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$this->table}` (level, member_user_ids, representative_user_id) VALUES ('confirmed', %s, %d)",
            '[' . self::REP . ',' . self::MEMBER . ']',
            self::REP
        ));
        self::assertSame('', $wpdb->last_error);

        self::assertFalse($resolver->isZeroCredit(self::MEMBER), 'within MEMO_TTL the memo is still trusted');

        (new \ReflectionProperty(IndependenceResolver::class, 'memoBuiltAt'))
            ->setValue(null, time() - IndependenceResolver::MEMO_TTL);

        self::assertTrue($resolver->isZeroCredit(self::MEMBER), 'a long-running process never rebuilt the memo');
    }
}
