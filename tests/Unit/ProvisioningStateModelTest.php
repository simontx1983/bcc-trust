<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The provisioning state machine and its bounded failure vocabulary.
 *
 * ── WHY EXHAUSTIVE AND NOT ILLUSTRATIVE ─────────────────────────────────
 * A transition table tested by a handful of examples asserts that the
 * examples work, not that the table is right. The interesting property is
 * about the moves that must NOT exist — `provisioned -> none` above all,
 * because that is "an operator checkbox deleted a live community" — and you
 * cannot test for an absent edge by listing present ones. So the legality of
 * all 16 ordered pairs is asserted directly.
 */
#[CoversClass(ProvisioningState::class)]
#[CoversClass(ProvisioningFailureCode::class)]
final class ProvisioningStateModelTest extends TestCase
{
    public function testTheStateSetIsExactlyFour(): void
    {
        self::assertSame(
            ['none', 'requested', 'provisioned', 'failed'],
            ProvisioningState::all()
        );
    }

    /**
     * Every ordered pair of states, with the answer written out.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function everyOrderedPair(): array
    {
        $N = ProvisioningState::NONE;
        $R = ProvisioningState::REQUESTED;
        $P = ProvisioningState::PROVISIONED;
        $F = ProvisioningState::FAILED;

        return [
            // from none: only a request may start.
            [$N, $N, false],
            [$N, $R, true],
            [$N, $P, false],   // nothing may become provisioned without intent
            [$N, $F, false],   // a failure with no attempt is not a state

            // from requested: succeed, fail, repeat, or be withdrawn.
            [$R, $N, true],    // verification removed
            [$R, $R, true],    // asking twice is a no-op
            [$R, $P, true],
            [$R, $F, true],

            // from provisioned: NOTHING but itself.
            [$P, $N, false],   // ⚠ the edge that would delete a community
            [$P, $R, false],
            [$P, $P, true],
            [$P, $F, false],

            // from failed: retry via a fresh request, or be withdrawn.
            [$F, $N, true],
            [$F, $R, true],
            [$F, $P, false],   // a retry must go through `requested` first
            [$F, $F, false],
        ];
    }

    #[DataProvider('everyOrderedPair')]
    public function testEveryOrderedPairHasTheDecidedLegality(string $from, string $to, bool $legal): void
    {
        self::assertSame(
            $legal,
            ProvisioningState::canTransition($from, $to),
            sprintf('%s -> %s', $from, $to)
        );
    }

    /**
     * The single most important absent edge, asserted on its own so it can
     * never be lost inside a data provider someone edits in bulk.
     */
    public function testProvisionedCanNeverBeUndone(): void
    {
        foreach (ProvisioningState::all() as $to) {
            if ($to === ProvisioningState::PROVISIONED) {
                continue;
            }
            self::assertFalse(
                ProvisioningState::canTransition(ProvisioningState::PROVISIONED, $to),
                'a provisioned collection must never leave that state: ' . $to
            );
        }
    }

    public function testAnUnknownStateIsNeverATransitionEndpoint(): void
    {
        self::assertFalse(ProvisioningState::canTransition('none', 'archived'));
        self::assertFalse(ProvisioningState::canTransition('archived', 'none'));
        self::assertFalse(ProvisioningState::canTransition('', ''));
        self::assertFalse(ProvisioningState::isValid('PROVISIONED'), 'case matters');
    }

    // ── Field invariants ────────────────────────────────────────────────

    public function testNoneCarriesNothing(): void
    {
        self::assertSame([], ProvisioningState::fieldViolations('none', null, null, null));

        self::assertSame(
            ['none_with_requested_at'],
            ProvisioningState::fieldViolations('none', '2026-09-01 00:00:00', null, null)
        );
        self::assertSame(
            ['none_with_requested_by'],
            ProvisioningState::fieldViolations('none', null, 4, null)
        );
        self::assertSame(
            ['none_with_failure_code'],
            ProvisioningState::fieldViolations('none', null, null, ProvisioningFailureCode::NOT_VERIFIED)
        );
    }

    public function testRequestedMustNameWhoAndWhenAndCarryNoFailure(): void
    {
        self::assertSame(
            [],
            ProvisioningState::fieldViolations('requested', '2026-09-01 00:00:00', 2, null)
        );

        self::assertSame(
            ['requested_without_requested_at'],
            ProvisioningState::fieldViolations('requested', null, 2, null)
        );
        self::assertSame(
            ['requested_without_requested_by'],
            ProvisioningState::fieldViolations('requested', '2026-09-01 00:00:00', null, null)
        );
        self::assertSame(
            ['requested_with_failure_code'],
            ProvisioningState::fieldViolations(
                'requested',
                '2026-09-01 00:00:00',
                2,
                ProvisioningFailureCode::GROUP_CREATE_FAILED
            )
        );
    }

    /**
     * A failure PRESERVES the requester. That is what makes a retry
     * attributable to the person who authorized it rather than to whoever
     * happens to click next.
     */
    public function testFailedPreservesTheRequesterAndRequiresACode(): void
    {
        self::assertSame(
            [],
            ProvisioningState::fieldViolations(
                'failed',
                '2026-09-01 00:00:00',
                2,
                ProvisioningFailureCode::GROUP_CREATE_FAILED
            )
        );

        self::assertSame(
            ['failed_without_failure_code'],
            ProvisioningState::fieldViolations('failed', '2026-09-01 00:00:00', 2, null)
        );
        self::assertContains(
            'failed_without_requested_by',
            ProvisioningState::fieldViolations('failed', '2026-09-01 00:00:00', null, 'group_create_failed')
        );
    }

    /**
     * The ONE exemption, pinned so it cannot quietly widen.
     *
     * The 28 communities that predate PR 6 have no requester, because nobody
     * requested them. Writing a plausible one would fabricate an
     * authorization that never happened.
     */
    public function testProvisionedAcceptsAMissingRequesterOnlyWhenBothAreMissing(): void
    {
        // Migration-backfilled: neither stamp nor requester.
        self::assertSame([], ProvisioningState::fieldViolations('provisioned', null, null, null));

        // Normally provisioned: both present.
        self::assertSame(
            [],
            ProvisioningState::fieldViolations('provisioned', '2026-09-01 00:00:00', 2, null)
        );

        // Half-written is a contradiction in either direction.
        self::assertSame(
            ['provisioned_with_partial_requester'],
            ProvisioningState::fieldViolations('provisioned', '2026-09-01 00:00:00', null, null)
        );
        self::assertSame(
            ['provisioned_with_partial_requester'],
            ProvisioningState::fieldViolations('provisioned', null, 2, null)
        );

        // And a provisioned row never carries a failure code.
        self::assertContains(
            'provisioned_with_failure_code',
            ProvisioningState::fieldViolations('provisioned', null, null, 'group_create_failed')
        );
    }

    public function testARequesterIdOfZeroIsRejectedRatherThanTreatedAsAbsent(): void
    {
        // 0 is a bad write, not "no requester". If it were read as absent it
        // would slip through the `provisioned` exemption.
        self::assertContains(
            'requested_by_not_positive',
            ProvisioningState::fieldViolations('provisioned', '2026-09-01 00:00:00', 0, null)
        );
    }

    // ── The failure vocabulary ──────────────────────────────────────────

    public function testTheFailureVocabularyIsClosed(): void
    {
        self::assertSame(
            [
                'peepso_absent',
                'no_admin_owner',
                'group_create_failed',
                'gate_write_refused',
                'identity_unresolved',
                'awaiting_metadata',
                'not_verified',
                'owner_unresolved',
            ],
            ProvisioningFailureCode::all()
        );

        self::assertFalse(ProvisioningFailureCode::isValid('database is gone'));
        self::assertFalse(ProvisioningFailureCode::isValid(''));
        self::assertFalse(ProvisioningFailureCode::isValid('PEEPSO_ABSENT'));
    }

    /**
     * The column is VARCHAR(32). A code that does not fit would be silently
     * TRUNCATED by MySQL into a value the vocabulary does not contain, and
     * `fieldViolations()` would then report every such row as contradictory.
     */
    public function testEveryCodeFitsTheColumn(): void
    {
        self::assertLessThanOrEqual(
            32,
            ProvisioningFailureCode::maxLength(),
            'provisioning_failure_code is VARCHAR(32)'
        );
    }

    /**
     * No label is derived by munging the token, and an unknown code does not
     * get dressed up as a legitimate one.
     */
    public function testAnUnknownCodeIsNotRenderedAsIfItWereReal(): void
    {
        self::assertSame('Unrecognised failure code', ProvisioningFailureCode::label('sql error: 1064'));

        foreach (ProvisioningFailureCode::all() as $code) {
            $label = ProvisioningFailureCode::label($code);
            self::assertNotSame('Unrecognised failure code', $label, $code . ' has no label');
            self::assertStringNotContainsString('_', $label, $code . ' label is raw token text');
        }
    }
}
