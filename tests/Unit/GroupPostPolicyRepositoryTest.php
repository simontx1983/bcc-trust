<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\GroupPostPolicyRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Storage layer for the ordinary-member public_all opt-in.
 *
 * Runs in its own subprocess; setUp() pulls in the FQN post-meta stubs so
 * the real GroupPostPolicyRepository reads/writes a fixture map instead of
 * $wpdb. Proves the restrictive default (absent meta ⇒ false) and the
 * round-trip (enable stores '1'; disable deletes so the default stays a
 * clean absence).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupPostPolicyRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/public-all-stubs.php';
        $GLOBALS['__bcc_puball_fixture'] = ['groups' => [], 'admins' => []];
    }

    public function testDefaultIsFalseWhenMetaAbsent(): void
    {
        $GLOBALS['__bcc_puball_fixture']['groups'][10] = ['meta' => []];
        self::assertFalse(GroupPostPolicyRepository::publicAllMembersEnabled(10));
    }

    public function testTrueOnlyForCanonicalOne(): void
    {
        $GLOBALS['__bcc_puball_fixture']['groups'][10]['meta']['_bcc_group_public_all_members'] = '1';
        self::assertTrue(GroupPostPolicyRepository::publicAllMembersEnabled(10));
    }

    public function testNonCanonicalValuesAreFalse(): void
    {
        foreach (['0', '', 'true', 'yes'] as $value) {
            $GLOBALS['__bcc_puball_fixture']['groups'][11]['meta']['_bcc_group_public_all_members'] = $value;
            self::assertFalse(
                GroupPostPolicyRepository::publicAllMembersEnabled(11),
                "value '{$value}' must not read as enabled"
            );
        }
    }

    public function testEnableThenDisableRoundTrips(): void
    {
        $GLOBALS['__bcc_puball_fixture']['groups'][12] = ['meta' => []];

        GroupPostPolicyRepository::setPublicAllMembers(12, true);
        self::assertTrue(GroupPostPolicyRepository::publicAllMembersEnabled(12));

        GroupPostPolicyRepository::setPublicAllMembers(12, false);
        self::assertFalse(GroupPostPolicyRepository::publicAllMembersEnabled(12));
        // Disable deletes the key rather than storing '0'.
        self::assertArrayNotHasKey(
            '_bcc_group_public_all_members',
            $GLOBALS['__bcc_puball_fixture']['groups'][12]['meta']
        );
    }

    public function testInvalidGroupIdIsFalseAndNoOp(): void
    {
        self::assertFalse(GroupPostPolicyRepository::publicAllMembersEnabled(0));
        self::assertFalse(GroupPostPolicyRepository::publicAllMembersEnabled(-3));
        // Write with a bad id must not create a phantom fixture row.
        GroupPostPolicyRepository::setPublicAllMembers(0, true);
        self::assertArrayNotHasKey(0, $GLOBALS['__bcc_puball_fixture']['groups']);
    }
}
