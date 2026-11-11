<?php
/**
 * ProfileAccess — the §F-02 profile-visibility gate.
 *
 * What this suite is actually for: proving the gate's OWN guards, and
 * proving it can answer BOTH ways. A gate that always denied would be a
 * catastrophic regression (every profile 403s) and would pass every
 * deny-path assertion, so the allow-path test below is an anti-vacuity
 * control, not a nicety.
 *
 * PeepSo's ladder itself (owner-exempt, admin-exempt, 10/20/40, and the
 * `peepso_user_is_accessible` filter where peepso-friends grants 30) is
 * deliberately NOT reimplemented or re-tested here. It is PeepSo's, and
 * restating it in a stub would only assert that the stub matches the
 * stub. What IS tested is that ProfileAccess delegates to it with the
 * right arguments, and refuses before reaching it when it must.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\ProfileAccess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProfileAccessTest extends TestCase
{
    private const OWNER = 42;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/profile-access-stubs.php';
        $GLOBALS['__bcc_profile_access'] = [
            'levels'   => [],
            'verdicts' => [],
            'calls'    => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_profile_access']);
        parent::tearDown();
    }

    /** @return list<array{acc: mixed, user_id: int}> */
    private function calls(): array
    {
        $c = $GLOBALS['__bcc_profile_access']['calls'] ?? [];
        return is_array($c) ? $c : [];
    }

    // ── the allow path: the anti-vacuity control ────────────────────

    public function testAllowsWhenPeepSoReportsTheProfileAccessible(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 10];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [10 => true];

        self::assertTrue(
            ProfileAccess::canViewProfile(self::OWNER),
            'the gate must be able to ALLOW — without this every other '
            . 'assertion here would also pass against a hardcoded deny-all'
        );
    }

    public function testDeniesWhenPeepSoReportsTheProfileInaccessible(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 40];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [40 => false];

        self::assertFalse(ProfileAccess::canViewProfile(self::OWNER));
    }

    public function testPassesTheStoredLevelAndOwnerThroughToPeepSo(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 30];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [30 => true];

        ProfileAccess::canViewProfile(self::OWNER);

        // Delegation is the whole design: if the arguments were wrong,
        // the gate would consult PeepSo about the wrong user or the
        // wrong level and still return a plausible boolean.
        self::assertSame(
            [['acc' => 30, 'user_id' => self::OWNER]],
            $this->calls(),
            'the stored level and the profile owner must reach PeepSo verbatim'
        );
    }

    // ── guards that must refuse BEFORE consulting PeepSo ────────────

    public function testZeroOwnerIdDeniesWithoutConsultingPeepSo(): void
    {
        $GLOBALS['__bcc_profile_access']['verdicts'] = [10 => true];

        self::assertFalse(ProfileAccess::canViewProfile(0));
        self::assertSame(
            [],
            $this->calls(),
            'a non-resolving user must not reach PeepSo at all'
        );
    }

    public function testNegativeOwnerIdDeniesWithoutConsultingPeepSo(): void
    {
        $GLOBALS['__bcc_profile_access']['verdicts'] = [10 => true];

        self::assertFalse(ProfileAccess::canViewProfile(-7));
        self::assertSame([], $this->calls());
    }

    /**
     * The stored column is read through PeepSo and comes back as
     * whatever is in the row. A non-numeric value means we do not know
     * the level, and not knowing must DENY — never fall through to a
     * default that happens to be public.
     */
    public function testNonNumericStoredLevelDeniesWithoutConsultingPeepSo(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => 'nonsense'];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [0 => true, 10 => true];

        self::assertFalse(
            ProfileAccess::canViewProfile(self::OWNER),
            'an unreadable level must fail CLOSED'
        );
        self::assertSame(
            [],
            $this->calls(),
            'the numeric guard must run before PeepSo is consulted — '
            . 'otherwise "nonsense" would be cast to 0 and 0 is PUBLIC '
            . 'on PeepSo\'s ladder, turning a broken row into an open profile'
        );
    }

    public function testNullStoredLevelDeniesWithoutConsultingPeepSo(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => null];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [0 => true];

        self::assertFalse(ProfileAccess::canViewProfile(self::OWNER));
        self::assertSame([], $this->calls());
    }

    /**
     * A numeric STRING is how a DB column legitimately arrives, so it
     * must be honoured rather than swept into the fail-closed branch.
     * Paired with the test above, this pins the boundary: numeric-ish
     * passes through, non-numeric denies.
     */
    public function testNumericStringLevelIsHonouredAndForwardedAsInt(): void
    {
        $GLOBALS['__bcc_profile_access']['levels']   = [self::OWNER => '20'];
        $GLOBALS['__bcc_profile_access']['verdicts'] = [20 => true];

        self::assertTrue(ProfileAccess::canViewProfile(self::OWNER));
        self::assertSame([['acc' => 20, 'user_id' => self::OWNER]], $this->calls());
    }

    // ── the refusal envelope is part of the §9 contract ─────────────

    public function testForbiddenCarriesTheContractedCodeStatusAndReason(): void
    {
        $response = ProfileAccess::forbidden();

        self::assertSame(403, $response->get_status());

        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
        self::assertIsArray($data['error']);
        self::assertSame('bcc_forbidden', $data['error']['code']);
        self::assertSame(403, $data['error']['status']);

        // The machine-readable reason is what lets the frontend render
        // "this profile is private" instead of a generic permission
        // error, so it is contract surface, not a log detail.
        self::assertArrayHasKey('data', $data['error']);
        self::assertIsArray($data['error']['data']);
        self::assertSame('profile_private', $data['error']['data']['reason']);
        self::assertSame(ProfileAccess::REASON_PRIVATE, $data['error']['data']['reason']);
    }
}
