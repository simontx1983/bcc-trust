<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\AttestationService;
use BCC\Trust\Core\Services\Capability\CapabilityResolver;
use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\ValueObjects\CapabilityDecision;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;

/**
 * An UNCLAIMED page card (post_author 0) offers no vouch, back or report —
 * to anyone, anon included — and says why: `reason_code: "unclaimed"`.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────
 * The cast write already refuses the target: checkCastEligibility() resolves
 * the owner from the same post_author and 404s on 0 ("Target not found."),
 * which the frontend rendered as "Attestation not found." (2026-11-11 live
 * report). The card used to offer the buttons anyway. Product decision: on
 * an unclaimed page, Message replaces Vouch; vouch/back/report are hidden.
 *
 * ── WHERE, AND WHY NOT IN AttestationService ────────────────────────────
 * getViewerActionPermissions(viewer, 0) means "viewer gates only" to the
 * endorse gate (EndorsementService::resolveViewerVouchGate). Denying target 0
 * THERE would lock every viewer out of endorsing. The page-card resolver is
 * the one place that knows target 0 means "unclaimed page".
 *
 * Isolation: CardViewService and AttestationService are built without their
 * constructors; the attestation resolver's per-request memo is pre-seeded so
 * it never reaches its repositories.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UnclaimedPageAttestationPermissionTest extends TestCase
{
    private const VIEWER = 5;
    private const OWNER  = 7;
    private const PAGE   = 2015;

    private const ALLOWED = ['allowed' => true, 'unlock_hint' => null];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/rank-unit-stubs.php';
    }

    private function service(): CardViewService
    {
        $attestation = (new \ReflectionClass(AttestationService::class))->newInstanceWithoutConstructor();
        (new ReflectionObject($attestation))->getProperty('viewerActionPermsCache')->setValue($attestation, [
            // Authed + eligible, against either target: the card layer alone
            // must turn the unclaimed case into a hidden slot.
            self::VIEWER . ':0'           => ['can_vouch' => self::ALLOWED, 'can_stand_behind' => self::ALLOWED, 'can_report' => self::ALLOWED],
            self::VIEWER . ':' . self::OWNER => ['can_vouch' => self::ALLOWED, 'can_stand_behind' => self::ALLOWED, 'can_report' => self::ALLOWED],
        ]);

        $capability = new class extends CapabilityResolver {
            public function __construct() {}

            public function can(int $userId, string $key, array $context = []): CapabilityDecision
            {
                return CapabilityDecision::allowed('test');
            }
        };

        $card = (new \ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();
        $o = new ReflectionObject($card);
        $o->getProperty('attestationService')->setValue($card, $attestation);
        $o->getProperty('capabilityResolver')->setValue($card, $capability);

        return $card;
    }

    /** @return array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}> */
    private function perms(int $viewerId, int $ownerId): array
    {
        $m = new ReflectionMethod(CardViewService::class, 'resolvePagePermissions');
        $canMessage = ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];

        /** @var array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}> $out */
        $out = $m->invoke($this->service(), $viewerId, false, ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null], $ownerId, 'validator_card', self::PAGE, $canMessage);
        return $out;
    }

    private static function assertUnclaimed(array $perm, string $key): void
    {
        self::assertSame(
            ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'unclaimed'],
            $perm,
            "{$key}: hidden (no hint, §N7 structural) and says why"
        );
    }

    public function testAnUnclaimedPageHidesVouchBackAndReportForASignedInViewer(): void
    {
        $p = $this->perms(self::VIEWER, 0);

        foreach (['can_vouch', 'can_stand_behind', 'can_report'] as $key) {
            self::assertUnclaimed($p[$key], $key);
        }
        self::assertTrue($p['can_message']['allowed'], 'Message stays — it is what replaces Vouch here');
        self::assertTrue($p['can_watch']['allowed'], 'everyone can still watch');
    }

    public function testAnUnclaimedPageHidesThemForAnAnonViewerToo(): void
    {
        // Not "Sign in to vouch": signing in would not unlock it.
        $p = $this->perms(0, 0);

        foreach (['can_vouch', 'can_stand_behind', 'can_report'] as $key) {
            self::assertUnclaimed($p[$key], $key);
        }
    }

    public function testAClaimedPageIsUnchangedForASignedInViewer(): void
    {
        $p = $this->perms(self::VIEWER, self::OWNER);

        foreach (['can_vouch', 'can_stand_behind', 'can_report'] as $key) {
            self::assertSame(['allowed' => true, 'unlock_hint' => null, 'reason_code' => null], $p[$key], $key);
        }
    }

    public function testAClaimedPageStillInvitesAnAnonViewerToSignIn(): void
    {
        $p = $this->perms(0, self::OWNER);

        self::assertFalse($p['can_vouch']['allowed']);
        self::assertSame('Sign in to vouch for operators.', $p['can_vouch']['unlock_hint']);
        self::assertNull($p['can_vouch']['reason_code']);
    }
}
