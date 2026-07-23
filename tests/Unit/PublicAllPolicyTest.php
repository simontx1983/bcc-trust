<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\PublicAllPolicy;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Authoritative authorization matrix for who may set visibility='public_all'
 * on a group post (syndicate to the global / anonymous feed).
 *
 * Pure — {@see PublicAllPolicy::canSyndicate} takes (privacy, status,
 * ordinary-members-opt-in) and returns a bool with no WordPress / PeepSo /
 * singleton access, so the full matrix runs with zero stubs. This test is
 * the independent spec: every expected value is written out explicitly, not
 * derived from the implementation.
 *
 * Policy (Phillip, Gate-12 Option B — feed honours public_all, authoring is
 * gated):
 *   - owner / manager / moderator → ALLOW, any privacy, any setting.
 *   - ordinary member:
 *       open           → ALLOW
 *       closed / secret → ALLOW iff the group opted ordinary members in
 *   - readonly / pending / banned / no-row → DENY (fail closed).
 */
final class PublicAllPolicyTest extends TestCase
{
    /**
     * Elevated roles may always syndicate — 3 roles × 3 privacies × 2
     * settings = 18 cells, every one true.
     *
     * @return iterable<string, array{PeepSoPrivacy, string, bool}>
     */
    public static function elevatedProvider(): iterable
    {
        foreach (['member_owner', 'member_manager', 'member_moderator'] as $status) {
            foreach ([PeepSoPrivacy::Open, PeepSoPrivacy::Closed, PeepSoPrivacy::Secret] as $privacy) {
                foreach ([true, false] as $setting) {
                    $label = sprintf('%s / %s / opt-in=%s', $status, $privacy->value, $setting ? 'on' : 'off');
                    yield $label => [$privacy, $status, $setting];
                }
            }
        }
    }

    #[DataProvider('elevatedProvider')]
    public function testElevatedRolesAlwaysSyndicate(PeepSoPrivacy $privacy, string $status, bool $setting): void
    {
        self::assertTrue(PublicAllPolicy::canSyndicate($privacy, $status, $setting));
    }

    /**
     * Ordinary member — open always allows; closed/secret track the opt-in.
     *
     * @return iterable<string, array{PeepSoPrivacy, bool, bool}>
     */
    public static function ordinaryMemberProvider(): iterable
    {
        yield 'open / opt-in off'   => [PeepSoPrivacy::Open,   false, true];
        yield 'open / opt-in on'    => [PeepSoPrivacy::Open,   true,  true];
        yield 'closed / opt-in off' => [PeepSoPrivacy::Closed, false, false];
        yield 'closed / opt-in on'  => [PeepSoPrivacy::Closed, true,  true];
        yield 'secret / opt-in off' => [PeepSoPrivacy::Secret, false, false];
        yield 'secret / opt-in on'  => [PeepSoPrivacy::Secret, true,  true];
    }

    #[DataProvider('ordinaryMemberProvider')]
    public function testOrdinaryMemberTracksPrivacyAndOptIn(PeepSoPrivacy $privacy, bool $setting, bool $expected): void
    {
        self::assertSame($expected, PublicAllPolicy::canSyndicate($privacy, 'member', $setting));
    }

    /**
     * Non-posting statuses fail closed everywhere — 4 statuses × 3
     * privacies × 2 settings = 24 cells, every one false. `null` models
     * "no membership row".
     *
     * @return iterable<string, array{PeepSoPrivacy, string|null, bool}>
     */
    public static function deniedStatusProvider(): iterable
    {
        foreach (['member_readonly', 'pending_admin', 'banned', null] as $status) {
            foreach ([PeepSoPrivacy::Open, PeepSoPrivacy::Closed, PeepSoPrivacy::Secret] as $privacy) {
                foreach ([true, false] as $setting) {
                    $label = sprintf(
                        '%s / %s / opt-in=%s',
                        $status ?? 'no-row',
                        $privacy->value,
                        $setting ? 'on' : 'off'
                    );
                    yield $label => [$privacy, $status, $setting];
                }
            }
        }
    }

    #[DataProvider('deniedStatusProvider')]
    public function testNonPostingStatusesAlwaysDenied(PeepSoPrivacy $privacy, ?string $status, bool $setting): void
    {
        self::assertFalse(PublicAllPolicy::canSyndicate($privacy, $status, $setting));
    }

    /**
     * The opt-in must be a genuine escape-hatch: it flips the decision for
     * an ordinary member of a closed/secret group and NOWHERE else. Guards
     * against a future refactor that lets the flag leak into the elevated
     * or denied paths.
     */
    public function testOptInOnlyMattersForOrdinaryClosedOrSecret(): void
    {
        // Elevated: setting is irrelevant.
        self::assertTrue(PublicAllPolicy::canSyndicate(PeepSoPrivacy::Secret, 'member_owner', false));
        // Denied: setting cannot rescue a readonly member.
        self::assertFalse(PublicAllPolicy::canSyndicate(PeepSoPrivacy::Closed, 'member_readonly', true));
        // Open ordinary member: setting is irrelevant (always allowed).
        self::assertTrue(PublicAllPolicy::canSyndicate(PeepSoPrivacy::Open, 'member', false));
        // The one place it flips:
        self::assertFalse(PublicAllPolicy::canSyndicate(PeepSoPrivacy::Closed, 'member', false));
        self::assertTrue(PublicAllPolicy::canSyndicate(PeepSoPrivacy::Closed, 'member', true));
    }
}
