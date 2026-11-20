<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Support\MemberSearchCandidates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-512 on a real engine: which display-name matches keep their name
 * searchable, read from real wp_usermeta rows through bcc-core's
 * UserMetaRepository.
 *
 * A member is searchable by name only when NONE of their
 * bcc_privacy_real_name_hidden rows is truthy, whatever order the rows were
 * written in. (get_users() itself is WordPress and is not hosted by this
 * harness; the decision under test is the post-filter, which is.)
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(MemberSearchCandidates::class)]
final class RealNameSearchFlagRowsIntegrationTest extends TestCase
{
    private const KEY = 'bcc_privacy_real_name_hidden';

    private const ONE_THEN_ZERO = 980101;
    private const ZERO_THEN_ONE = 980102;
    private const ZERO_ONLY     = 980103;
    private const NO_ROW        = 980104;
    private const ONE_ONLY      = 980105;
    private const TRUE_THEN_ZERO = 980106;

    private bool $tableWasOurs = false;

    protected function setUp(): void
    {
        global $wpdb;
        $table = $wpdb->usermeta;
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE IF NOT EXISTS `{$table}` (
                    umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    meta_key VARCHAR(255) NULL DEFAULT NULL,
                    meta_value LONGTEXT NULL,
                    PRIMARY KEY (umeta_id),
                    KEY user_id (user_id),
                    KEY meta_key (meta_key(191))
                ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            ), $wpdb->last_error);
            $this->tableWasOurs = true;
        }
        $this->deleteOurRows();

        // Inserted one at a time, so umeta_id order IS the order listed.
        foreach ([
            [self::ONE_THEN_ZERO, '1'], [self::ONE_THEN_ZERO, '0'],
            [self::ZERO_THEN_ONE, '0'], [self::ZERO_THEN_ONE, '1'],
            [self::ZERO_ONLY, '0'],
            [self::ONE_ONLY, '1'],
            [self::TRUE_THEN_ZERO, 'true'], [self::TRUE_THEN_ZERO, '0'],
        ] as [$userId, $value]) {
            self::assertNotFalse(
                $wpdb->insert($table, ['user_id' => $userId, 'meta_key' => self::KEY, 'meta_value' => $value]),
                $wpdb->last_error
            );
        }
        // An unrelated key on the "no row" member, so absence of THIS key is what is tested.
        self::assertNotFalse(
            $wpdb->insert($table, ['user_id' => self::NO_ROW, 'meta_key' => 'bcc_handle', 'meta_value' => 'norow']),
            $wpdb->last_error
        );
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $this->deleteOurRows();
        if ($this->tableWasOurs) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->usermeta}`");
        }
    }

    private function deleteOurRows(): void
    {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM `{$wpdb->usermeta}` WHERE user_id IN ("
            . implode(',', [self::ONE_THEN_ZERO, self::ZERO_THEN_ONE, self::ZERO_ONLY, self::NO_ROW, self::ONE_ONLY, self::TRUE_THEN_ZERO])
            . ')'
        );
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function searchable(array $ids, bool $throw = false): array
    {
        $m = new \ReflectionMethod(MemberSearchCandidates::class, 'withoutHiddenRealNames');
        /** @var list<int> $out */
        $out = $m->invoke(null, $ids, $throw);
        sort($out);
        return $out;
    }

    public function testOnlyMembersWithNoTruthyRowKeepTheirNameSearchable(): void
    {
        global $wpdb;

        self::assertSame(
            [self::ZERO_ONLY, self::NO_ROW],
            self::searchable([
                self::ONE_THEN_ZERO, self::ZERO_THEN_ONE, self::ZERO_ONLY,
                self::NO_ROW, self::ONE_ONLY, self::TRUE_THEN_ZERO,
            ])
        );
        self::assertSame('', (string) $wpdb->last_error, 'the read ran without a driver error');
    }

    /** Each duplicate order on its own, so neither result rides on the other. */
    public function testBothRowOrdersHideTheName(): void
    {
        self::assertSame([], self::searchable([self::ONE_THEN_ZERO]), "'1' written before '0'");
        self::assertSame([], self::searchable([self::ZERO_THEN_ONE]), "'0' written before '1'");
        self::assertSame([self::ZERO_ONLY], self::searchable([self::ZERO_ONLY]), 'control');
    }
}
