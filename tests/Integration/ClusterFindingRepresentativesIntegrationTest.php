<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Rank\Repositories\ClusterFindingsRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ClusterFindingsRepository::representativesByIds() — the read behind
 * PollService::closureExclusionReasons() (F-39 follow-up).
 *
 * A closed poll's audit is explained against the finding it closed under,
 * so REVERSED findings must still resolve; the read is bounded by the ids
 * asked for, and fail-closed (a DB error throws, never answers "none").
 */
#[Group('integration')]
#[Group('mariadb')]
final class ClusterFindingRepresentativesIntegrationTest extends TestCase
{
    private string $table = '';

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->clearFaultInjection();
        $this->table = $wpdb->prefix . 'bcc_trust_cluster_findings';

        $wpdb->query("DROP TABLE IF EXISTS `{$this->table}`");
        // Only the columns the read touches, plus reversed_at.
        $wpdb->query(
            "CREATE TABLE `{$this->table}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                level VARCHAR(12) NOT NULL,
                representative_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                reversed_at DATETIME DEFAULT NULL
             ) ENGINE=InnoDB"
        );
        self::assertSame('', $wpdb->last_error, 'fixture table must be creatable');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        $wpdb->query("DROP TABLE IF EXISTS `{$this->table}`");
        parent::tearDown();
    }

    private function finding(int $representative, ?string $reversedAt = null): int
    {
        global $wpdb;
        if ($reversedAt === null) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$this->table}` (level, representative_user_id, reversed_at) VALUES ('confirmed', %d, NULL)",
                $representative
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$this->table}` (level, representative_user_id, reversed_at) VALUES ('confirmed', %d, %s)",
                $representative,
                $reversedAt
            ));
        }
        self::assertSame('', $wpdb->last_error, 'fixture insert must succeed');
        // The harness wpdb does not refresh insert_id after a raw query().
        return (int) $wpdb->get_var("SELECT MAX(id) FROM `{$this->table}`");
    }

    public function testResolvesActiveAndReversedFindingsOnlyForTheIdsAsked(): void
    {
        $active   = $this->finding(104);
        $reversed = $this->finding(205, '2026-11-01 00:00:00');
        $other    = $this->finding(306);

        $map = (new ClusterFindingsRepository())->representativesByIds([$active, $reversed, $active, 0, -3, 999999]);

        self::assertSame([$active => 104, $reversed => 205], $map);
        self::assertArrayNotHasKey($other, $map, 'only the ids asked for are read');
    }

    public function testNoIdsIsNoQuery(): void
    {
        global $wpdb;
        $wpdb->resetQueryCount();

        self::assertSame([], (new ClusterFindingsRepository())->representativesByIds([0, -1]));
        self::assertSame(0, $wpdb->queryCount);
    }

    public function testAFailedReadThrowsInsteadOfAnsweringNone(): void
    {
        global $wpdb;
        $id = $this->finding(104);
        $wpdb->failQueriesMatching = '/FROM `?' . preg_quote($this->table, '/') . '`?\s+WHERE id IN/';

        try {
            (new ClusterFindingsRepository())->representativesByIds([$id]);
            self::fail('a failed read must not answer "no representatives"');
        } catch (RepositoryReadFailure $e) {
            self::assertSame('representativesByIds', $e->repositoryMethod());
        }
        self::assertGreaterThan(0, $wpdb->injectedFailures);
    }
}
