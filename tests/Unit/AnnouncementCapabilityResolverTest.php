<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver as Caps;
use BCC\Trust\Core\Support\Permission;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The announcement capability matrix — api-contract-v1.md §4.32.11.
 *
 * The resolver is pure, so this suite is EXHAUSTIVE rather than
 * representative: every rung of every ladder, every fail-closed default,
 * and the ordering between rungs that can both hold at once.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. A PUBLIC RESPONSE CARRIES CAPABILITIES. The public list and the
 *     rotator are published public data only, for EVERY viewer including
 *     the operator. The resolver has no producer for them, and §6 below
 *     pins the public method inventory so one cannot be added without
 *     this test failing.
 *  2. A CAPABILITY IS GRANTED FROM SOMETHING OTHER THAN THE AUTHORITY.
 *     §4.32.2 forbids `post_author`, mirror claim rows, page metadata,
 *     PeepSo membership and read models as authorization inputs. The
 *     resolver consumes the authority's DECISION ARRAY, so a caller
 *     cannot assert ownership — and §7 proves the source names none of
 *     the forbidden inputs.
 *  3. A DENIAL LEAKS THE CLAIM STATE. All five private reasons the
 *     authority can emit must present as the single generic wire reason.
 *     A distinguishable `claim_ambiguous` maps the contested validators;
 *     a distinguishable `claim_absent` maps the unclaimed ones.
 *  4. THE OWNER SCOPE CARRIES A COMMENT GATE. A page-level "may comment"
 *     authorizes commenting on every announcement, including archived and
 *     unpublished ones.
 *  5. AN ARCHIVED ANNOUNCEMENT BECOMES UNMODERATABLE. §4.32.7 keeps the
 *     discussion visible and read-only WITH operator moderation intact.
 *     The natural implementation — deny everything once archived — would
 *     leave a closed discussion nobody can clean up.
 *  6. A MALFORMED ROW GRANTS ANYTHING. An absent `is_archived`, a string
 *     `"false"`, an unknown status: each must take the restrictive
 *     reading. "We never write those" is a claim about the writer.
 *  7. THE COMMENT GATE REPORTS AN OWNERSHIP REASON. Members comment;
 *     operators are members too. §4.32.11's inventory is CLOSED and the
 *     client closes the composer on anything outside it.
 *  8. A REPORTABILITY CAPABILITY APPEARS. Reporting is an OPEN PROPOSAL
 *     (§4.32.15). Emitting it would make the client render a control with
 *     no server side.
 *
 * ── WHAT THIS DOES NOT PROVE ────────────────────────────────────────────
 * No route exists yet, so nothing here drives a REST request, a
 * serializer or a storage read. What it proves is the decision itself:
 * given an ownership decision, a row state and a viewer standing, which
 * capabilities come back. The wiring is A2's remaining slices.
 */
#[CoversClass(Caps::class)]
#[CoversClass(Permission::class)]
final class AnnouncementCapabilityResolverTest extends TestCase
{
    // ── fixtures ─────────────────────────────────────────────────────────

    /** @return array{decision: string, reason: string|null, operator_user_id: int|null} */
    private static function operator(int $userId = 7): array
    {
        return [
            'decision'         => Authority::DECISION_OPERATOR,
            'reason'           => null,
            'operator_user_id' => $userId,
        ];
    }

    /** @return array{decision: string, reason: string|null, operator_user_id: int|null} */
    private static function denied(string $reason = Authority::REASON_NOT_CLAIMER): array
    {
        return ['decision' => Authority::DECISION_DENIED, 'reason' => $reason, 'operator_user_id' => null];
    }

    /** @return array<string, mixed> */
    private static function publishedRow(bool $commentsEnabled = true): array
    {
        return [
            'status'           => Caps::STATUS_PUBLISHED,
            'is_archived'      => false,
            'comments_enabled' => $commentsEnabled,
        ];
    }

    /** @return array<string, mixed> */
    private static function goodMember(): array
    {
        return [
            'authenticated' => true,
            'suspended'     => false,
            'fraud_locked'  => false,
            'good_standing' => true,
        ];
    }

    // ── 1. The Permission shape ──────────────────────────────────────────

    public function testAllowIsTheExactContractTriple(): void
    {
        self::assertSame(
            ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null],
            Permission::allow()
        );
    }

    public function testDenyCarriesItsReasonAndAnOptionalHint(): void
    {
        self::assertSame(
            ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'nope'],
            Permission::deny('nope')
        );
        self::assertSame(
            ['allowed' => false, 'unlock_hint' => 'Do a thing.', 'reason_code' => 'nope'],
            Permission::deny('nope', 'Do a thing.')
        );
    }

    /**
     * ⚠ Strict, not truthy. A capability map can arrive from a cache, a
     * filter or a decoded payload, and `"no"` is truthy in PHP.
     *
     * @param mixed $capabilities
     */
    #[DataProvider('hostileCapabilityMaps')]
    public function testIsAllowedFailsClosedOnAnythingButLiteralTrue($capabilities, string $why): void
    {
        self::assertFalse(Permission::isAllowed($capabilities, 'can_edit'), $why);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function hostileCapabilityMaps(): iterable
    {
        yield 'null map'            => [null, 'a missing map must not grant'];
        yield 'string map'          => ['can_edit', 'a string is not a map'];
        yield 'empty map'           => [[], 'no key means no permission'];
        yield 'wrong key'           => [['can_pin' => Permission::allow()], 'another key must not leak across'];
        yield 'entry not an array'  => [['can_edit' => true], 'the entry itself must be the triple'];
        yield 'allowed as string'   => [['can_edit' => ['allowed' => 'true']], 'a truthy string is not true'];
        yield 'allowed as one'      => [['can_edit' => ['allowed' => 1]], 'an int is not true'];
        yield 'allowed as "1"'      => [['can_edit' => ['allowed' => '1']], 'a numeric string is not true'];
        yield 'allowed as "false"'  => [['can_edit' => ['allowed' => 'false']], 'the WORST truthy case'];
        yield 'allowed missing'     => [['can_edit' => ['reason_code' => null]], 'an entry without allowed denies'];
        yield 'allowed null'        => [['can_edit' => ['allowed' => null]], 'null is not true'];
    }

    public function testIsAllowedGrantsOnlyOnTheRealTriple(): void
    {
        self::assertTrue(Permission::isAllowed(['can_edit' => Permission::allow()], 'can_edit'));
    }

    // ── 2. Owner scope (b) ───────────────────────────────────────────────

    /**
     * ⚠ `null`, NOT a block with everything denied. §4.32.11: presence of
     * the block is the gate, so an emitted-but-denied block makes the
     * client mount the surface and issue requests that all 404.
     */
    public function testFeatureOffOmitsTheOwnerBlockEntirely(): void
    {
        self::assertNull(Caps::forOwnerScope(self::operator(), false));
        self::assertNull(Caps::forOwnerScope(self::denied(), false));
    }

    public function testAnOperatorMayCreateAndManage(): void
    {
        $block = Caps::forOwnerScope(self::operator(), true);
        self::assertNotNull($block);
        self::assertTrue(Permission::isAllowed($block['capabilities'], 'can_create'));
        self::assertTrue(Permission::isAllowed($block['capabilities'], 'can_manage'));
    }

    /**
     * The block is still EMITTED for a non-operator — the feature is on,
     * so the public bar and tab must render. Only the owner affordances
     * are denied.
     */
    public function testANonOperatorGetsTheBlockWithBothOwnerEntriesDenied(): void
    {
        $block = Caps::forOwnerScope(self::denied(), true);
        self::assertNotNull($block);
        self::assertFalse(Permission::isAllowed($block['capabilities'], 'can_create'));
        self::assertFalse(Permission::isAllowed($block['capabilities'], 'can_manage'));
    }

    /** MUST-NOT-HAPPEN #4. */
    public function testTheOwnerScopeHasExactlyTwoEntriesAndNoCommentGate(): void
    {
        $block = Caps::forOwnerScope(self::operator(), true);
        self::assertNotNull($block);
        self::assertSame(['can_create', 'can_manage'], array_keys($block['capabilities']));
        self::assertArrayNotHasKey('can_comment', $block['capabilities']);
    }

    /**
     * MUST-NOT-HAPPEN #3 — every private reason collapses to the one wire
     * value. Driven off the authority's own constants so a reason added
     * there and forgotten here shows up as an unlisted case rather than
     * passing silently.
     */
    #[DataProvider('everyPrivateDenialReason')]
    public function testEveryOwnershipDenialPresentsTheSameGenericReason(string $privateReason): void
    {
        $block = Caps::forOwnerScope(self::denied($privateReason), true);
        self::assertNotNull($block);

        foreach (['can_create', 'can_manage'] as $key) {
            self::assertSame(
                Authority::PUBLIC_REASON,
                $block['capabilities'][$key]['reason_code'],
                "{$key} leaked the private reason {$privateReason}"
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function everyPrivateDenialReason(): iterable
    {
        yield 'auth_required'   => [Authority::REASON_AUTH_REQUIRED];
        yield 'not a validator' => [Authority::REASON_NOT_A_VALIDATOR];
        yield 'claim absent'    => [Authority::REASON_CLAIM_ABSENT];
        yield 'claim ambiguous' => [Authority::REASON_CLAIM_AMBIGUOUS];
        yield 'not claimer'     => [Authority::REASON_NOT_CLAIMER];
    }

    /**
     * ⚠ The reason must be the authority's constant, not a second literal
     * spelled the same way. Two literals is how a `claim_ambiguous` finds
     * its way back onto the wire.
     */
    public function testTheWireReasonIsReExportedFromTheAuthorityNotRedeclared(): void
    {
        self::assertSame(Authority::PUBLIC_REASON, Caps::REASON_NOT_CLAIMER);

        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Announcements/AnnouncementCapabilityResolver.php'
        );
        self::assertIsString($src);
        self::assertStringContainsString(
            'ValidatorPageOperatorAuthority::PUBLIC_REASON',
            $src,
            'the generic reason must be imported from the authority'
        );
    }

    /** MUST-NOT-HAPPEN #2 — a bare boolean or truthy value is not an operator. */
    #[DataProvider('forgedDecisions')]
    public function testOnlyTheAuthoritysDecisionShapeGrants(array $decision, string $why): void
    {
        $block = Caps::forOwnerScope($decision, true);
        self::assertNotNull($block);
        self::assertFalse(Permission::isAllowed($block['capabilities'], 'can_create'), $why);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function forgedDecisions(): iterable
    {
        yield 'empty'              => [[], 'no decision is not an operator'];
        yield 'bool true'          => [['decision' => true], 'a boolean is not the decision literal'];
        yield 'truthy string'      => [['decision' => 'yes'], 'an arbitrary string is not the literal'];
        yield 'uppercased'         => [['decision' => 'OPERATOR'], 'comparison is case-sensitive'];
        yield 'operator id only'   => [['operator_user_id' => 7], 'an id alone authorizes nothing'];
        yield 'denied with id'     => [
            ['decision' => Authority::DECISION_DENIED, 'operator_user_id' => 7],
            'a denial carrying an id is still a denial',
        ];
    }

    // ── 3. Row scope (c) ─────────────────────────────────────────────────

    public function testTheRowScopeIsExactlyTheSixContractEntries(): void
    {
        self::assertSame(
            ['can_edit', 'can_pin', 'can_archive', 'can_manage_comments', 'can_comment', 'can_report'],
            array_keys(Caps::forRow(self::operator(), self::publishedRow(), self::goodMember(), true))
        );
    }

    public function testAnOperatorOnAPublishedRowGetsEverything(): void
    {
        $caps = Caps::forRow(self::operator(), self::publishedRow(), self::goodMember(), true);
        foreach (array_keys($caps) as $key) {
            self::assertTrue(Permission::isAllowed($caps, $key), "{$key} should be allowed");
        }
    }

    /** MUST-NOT-HAPPEN #5 — moderation is the one capability that survives archival. */
    public function testArchivalClosesEditPinAndCommentButLeavesModerationOpen(): void
    {
        $row  = ['status' => Caps::STATUS_PUBLISHED, 'is_archived' => true, 'comments_enabled' => true];
        $caps = Caps::forRow(self::operator(), $row, self::goodMember(), true);

        foreach (['can_edit', 'can_pin', 'can_archive', 'can_comment'] as $key) {
            self::assertFalse(Permission::isAllowed($caps, $key), "{$key} must close on an archived row");
            self::assertSame(Caps::REASON_ARCHIVED, $caps[$key]['reason_code'], $key);
        }

        self::assertTrue(
            Permission::isAllowed($caps, 'can_manage_comments'),
            'an archived discussion nobody can moderate is worse than a closed one'
        );

        // ⚠⚠ AND the report gate. §4.32.16: an archived announcement keeps
        // its permalink and its public record, so it stays challengeable.
        // `announcement_archived` is deliberately NOT in CAN_REPORT_REASONS,
        // and this is the assertion that proves the two gates diverge.
        self::assertTrue(
            Permission::isAllowed($caps, 'can_report'),
            'archival closes authoring, not challenge'
        );
    }

    /**
     * ⚠ No unlock hint on an archive denial: archive is one-way (§4.32.6),
     * so a hint would promise a path that does not exist.
     */
    public function testAnArchiveDenialOffersNoUnlockHint(): void
    {
        $row  = ['status' => Caps::STATUS_PUBLISHED, 'is_archived' => true, 'comments_enabled' => true];
        $caps = Caps::forRow(self::operator(), $row, self::goodMember(), true);
        self::assertNull($caps['can_edit']['unlock_hint']);
        self::assertNull($caps['can_pin']['unlock_hint']);
    }

    /**
     * Pin is the one row capability with a publication rung: pinning
     * promotes a row on the public page, and a draft has nothing to
     * promote. Edit and archive stay open — a draft is exactly what an
     * operator edits.
     */
    #[DataProvider('unpublishedStates')]
    public function testAnUnpublishedRowCannotBePinnedButCanStillBeEdited(string $status): void
    {
        $row  = ['status' => $status, 'is_archived' => false, 'comments_enabled' => true];
        $caps = Caps::forRow(self::operator(), $row, self::goodMember(), true);

        self::assertFalse(Permission::isAllowed($caps, 'can_pin'), $status);
        self::assertSame(Caps::REASON_NOT_PUBLISHED, $caps['can_pin']['reason_code'], $status);
        self::assertTrue(Permission::isAllowed($caps, 'can_edit'), $status);
        self::assertTrue(Permission::isAllowed($caps, 'can_archive'), $status);
    }

    /** @return iterable<string, array{string}> */
    public static function unpublishedStates(): iterable
    {
        yield 'draft'     => [Caps::STATUS_DRAFT];
        yield 'scheduled' => [Caps::STATUS_SCHEDULED];
        // ⚠ `blocked` is a row forced to publish WITHOUT a valid
        // authorization marker (§4.32.13). Treating it as published would
        // hand capabilities over exactly the row the reconciler flagged.
        yield 'blocked'   => [Caps::STATUS_BLOCKED];
    }

    /**
     * MUST-NOT-HAPPEN #6 — and the ORDER matters. An archived row was
     * published once and is auto-unpinned; reporting it as not-published
     * would be false AND would send the one reason that implies "publish
     * it and try again".
     */
    public function testArchivedBeatsNotPublishedWhenBothWouldApply(): void
    {
        $row  = ['status' => Caps::STATUS_DRAFT, 'is_archived' => true, 'comments_enabled' => true];
        $caps = Caps::forRow(self::operator(), $row, self::goodMember(), true);
        self::assertSame(Caps::REASON_ARCHIVED, $caps['can_pin']['reason_code']);
    }

    /** MUST-NOT-HAPPEN #6. Nothing of ours writes these; a plugin or import can. */
    #[DataProvider('malformedArchivedFlags')]
    public function testAMalformedArchivedFlagReadsAsArchived($value, string $why): void
    {
        $row  = ['status' => Caps::STATUS_PUBLISHED, 'is_archived' => $value, 'comments_enabled' => true];
        $caps = Caps::forRow(self::operator(), $row, self::goodMember(), true);
        self::assertSame(Caps::REASON_ARCHIVED, $caps['can_edit']['reason_code'], $why);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedArchivedFlags(): iterable
    {
        yield 'absent'        => [null, 'an absent flag must not read as "not archived"'];
        yield 'string false'  => ['false', 'a truthy string is not a boolean false'];
        yield 'string zero'   => ['0', 'a DB tinyint read as a string is still not a bool'];
        yield 'int zero'      => [0, 'an int is not a bool'];
        yield 'int one'       => [1, 'an int is not a bool'];
        yield 'empty string'  => ['', 'neither is an empty string'];
    }

    #[DataProvider('unknownStatuses')]
    public function testAnUnrecognisedStatusIsNotPublished($status, string $why): void
    {
        $row  = ['status' => $status, 'is_archived' => false, 'comments_enabled' => true];
        $caps = Caps::forRow(self::operator(), $row, self::goodMember(), true);
        self::assertSame(Caps::REASON_NOT_PUBLISHED, $caps['can_pin']['reason_code'], $why);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unknownStatuses(): iterable
    {
        yield 'absent'            => [null, 'a row with no status cannot be vouched for'];
        yield 'wp publish verb'   => ['publish', 'the WRITE verb is not the READ state (§4.32.13)'];
        yield 'wp schedule verb'  => ['schedule', 'likewise'];
        yield 'wp future'         => ['future', 'the raw WP post_status is not the contract state'];
        yield 'uppercase'         => ['PUBLISHED', 'comparison is case-sensitive'];
        yield 'padded'            => [' published ', 'a padded value is not the literal'];
        yield 'non-string'        => [1, 'an int status is malformed'];
        yield 'array'             => [[], 'so is an array'];
    }

    public function testANonOperatorGetsTheGenericReasonOnEveryOwnerRowCapability(): void
    {
        $caps = Caps::forRow(self::denied(Authority::REASON_CLAIM_AMBIGUOUS), self::publishedRow(), self::goodMember(), true);

        foreach (['can_edit', 'can_pin', 'can_archive', 'can_manage_comments'] as $key) {
            self::assertFalse(Permission::isAllowed($caps, $key), $key);
            self::assertSame(Caps::REASON_NOT_CLAIMER, $caps[$key]['reason_code'], $key);
        }
    }

    /**
     * Feature off still returns all five keys — the row producer has no
     * `null` form, because a row response that omitted capabilities would
     * be indistinguishable from a public one.
     */
    public function testFeatureOffDeniesEveryRowCapabilityWithTheFeatureReason(): void
    {
        $caps = Caps::forRow(self::operator(), self::publishedRow(), self::goodMember(), false);
        self::assertCount(6, $caps);
        foreach (array_keys($caps) as $key) {
            self::assertFalse(Permission::isAllowed($caps, $key), $key);
            self::assertSame(Caps::REASON_FEATURE_DISABLED, $caps[$key]['reason_code'], $key);
        }
    }

    // ── 4. The comment gate and its fixed order ──────────────────────────

    /**
     * Two conditions can hold at once and the client renders distinct copy
     * per reason, so which one wins is contract. Each case below sets up a
     * row/viewer where the EXPECTED reason and at least one later rung
     * both apply.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $viewer
     */
    #[DataProvider('commentGateOrdering')]
    public function testTheCommentGateReportsReasonsInTheContractOrder(
        array $row,
        array $viewer,
        bool $featureEnabled,
        string $expected
    ): void {
        $caps = Caps::forRow(self::operator(), $row, $viewer, $featureEnabled);
        self::assertSame($expected, $caps['can_comment']['reason_code']);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool, string}> */
    public static function commentGateOrdering(): iterable
    {
        $anon = ['authenticated' => false, 'suspended' => true, 'fraud_locked' => true, 'good_standing' => false];

        yield 'feature off beats everything' => [
            ['status' => 'published', 'is_archived' => true, 'comments_enabled' => false],
            $anon, false, Caps::REASON_FEATURE_DISABLED,
        ];
        yield 'unavailable beats archived' => [
            ['status' => Caps::STATUS_DRAFT, 'is_archived' => true, 'comments_enabled' => false],
            $anon, true, Caps::REASON_UNAVAILABLE,
        ];
        yield 'archived beats comments-off' => [
            ['status' => 'published', 'is_archived' => true, 'comments_enabled' => false],
            $anon, true, Caps::REASON_ARCHIVED,
        ];
        yield 'comments-off beats auth' => [
            ['status' => 'published', 'is_archived' => false, 'comments_enabled' => false],
            $anon, true, Caps::REASON_COMMENTS_DISABLED,
        ];
        yield 'auth beats suspension' => [
            ['status' => 'published', 'is_archived' => false, 'comments_enabled' => true],
            $anon, true, Caps::REASON_AUTH_REQUIRED,
        ];
        yield 'suspension beats fraud' => [
            ['status' => 'published', 'is_archived' => false, 'comments_enabled' => true],
            ['authenticated' => true, 'suspended' => true, 'fraud_locked' => true, 'good_standing' => false],
            true, Caps::REASON_SUSPENDED,
        ];
        yield 'fraud beats standing' => [
            ['status' => 'published', 'is_archived' => false, 'comments_enabled' => true],
            ['authenticated' => true, 'suspended' => false, 'fraud_locked' => true, 'good_standing' => false],
            true, Caps::REASON_FRAUD_LOCKED,
        ];
        yield 'standing is reported as suspended' => [
            ['status' => 'published', 'is_archived' => false, 'comments_enabled' => true],
            ['authenticated' => true, 'suspended' => false, 'fraud_locked' => false, 'good_standing' => false],
            true, Caps::REASON_SUSPENDED,
        ];
    }

    /**
     * ⚠ The ONLY denial carrying an unlock hint. Signing in is the one
     * thing a refused viewer can actually do about it.
     */
    public function testOnlyTheAuthDenialTellsTheViewerWhatToDo(): void
    {
        $caps = Caps::forRow(
            self::denied(),
            self::publishedRow(),
            ['authenticated' => false],
            true
        );
        self::assertSame(Caps::REASON_AUTH_REQUIRED, $caps['can_comment']['reason_code']);
        self::assertNotNull($caps['can_comment']['unlock_hint']);
    }

    /** MUST-NOT-HAPPEN #6 — an absent viewer flag takes the restrictive reading. */
    #[DataProvider('partialViewerStandings')]
    public function testAPartialViewerArrayFailsClosed(array $viewer, string $expected): void
    {
        $caps = Caps::forRow(self::denied(), self::publishedRow(), $viewer, true);
        self::assertFalse(Permission::isAllowed($caps, 'can_comment'));
        self::assertSame($expected, $caps['can_comment']['reason_code']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function partialViewerStandings(): iterable
    {
        yield 'entirely empty'       => [[], Caps::REASON_AUTH_REQUIRED];
        yield 'authed, nothing else' => [['authenticated' => true], Caps::REASON_SUSPENDED];
        yield 'no fraud key'         => [
            ['authenticated' => true, 'suspended' => false],
            Caps::REASON_FRAUD_LOCKED,
        ];
        yield 'no standing key'      => [
            ['authenticated' => true, 'suspended' => false, 'fraud_locked' => false],
            Caps::REASON_SUSPENDED,
        ];
    }

    public function testAnAbsentCommentsEnabledFlagClosesTheDiscussion(): void
    {
        $caps = Caps::forRow(
            self::operator(),
            ['status' => Caps::STATUS_PUBLISHED, 'is_archived' => false],
            self::goodMember(),
            true
        );
        self::assertSame(Caps::REASON_COMMENTS_DISABLED, $caps['can_comment']['reason_code']);
    }

    /**
     * MUST-NOT-HAPPEN #7. Checked for BOTH an operator and a non-operator,
     * because the bug would be to add ownership as a rung and then only
     * ever test the operator path.
     */
    public function testTheCommentGateNeverReportsAnOwnershipReason(): void
    {
        foreach (self::commentGateOrdering() as $label => [$row, $viewer, $feature, $_expected]) {
            foreach ([self::operator(), self::denied(Authority::REASON_CLAIM_AMBIGUOUS)] as $decision) {
                $caps = Caps::forRow($decision, $row, $viewer, $feature);
                self::assertNotSame(
                    Caps::REASON_NOT_CLAIMER,
                    $caps['can_comment']['reason_code'],
                    "commenting is not an ownership question ({$label})"
                );
            }
        }
    }

    /**
     * The inventory is CLOSED: the frontend keys distinct copy off each
     * value and closes the composer on anything else. So every reason the
     * resolver can actually emit must be in it — and the constant must not
     * list reasons that never occur, which would make it decorative.
     */
    public function testEveryCommentReasonEmittedIsInTheClosedInventory(): void
    {
        $seen = [];
        foreach (self::commentGateOrdering() as [$row, $viewer, $feature, $_e]) {
            foreach ([self::operator(), self::denied()] as $decision) {
                $code = Caps::forRow($decision, $row, $viewer, $feature)['can_comment']['reason_code'];
                if (is_string($code)) {
                    $seen[$code] = true;
                }
            }
        }

        foreach (array_keys($seen) as $code) {
            self::assertContains($code, Caps::CAN_COMMENT_REASONS, "{$code} is outside the closed inventory");
        }

        // Anti-decoration: every listed reason is reachable. `suspended`
        // covers both suspension and standing, which is why the counts
        // differ from the case count.
        self::assertSame(
            [],
            array_values(array_diff(Caps::CAN_COMMENT_REASONS, array_keys($seen))),
            'the inventory lists a reason nothing can emit'
        );
    }

    // ── 5. The comments response ─────────────────────────────────────────

    /**
     * §4.32.11: the gate travels on the comments response and is returned
     * on EVERY page, so a mid-scroll archive is reflected. One key only —
     * the comments response has no surface for owner affordances.
     */
    public function testTheCommentsResponseCarriesTheGateAndNothingElse(): void
    {
        $only = Caps::forCommentsResponse(self::operator(), self::publishedRow(), self::goodMember(), true);
        self::assertSame(['can_comment'], array_keys($only));

        $full = Caps::forRow(self::operator(), self::publishedRow(), self::goodMember(), true);
        self::assertSame($full['can_comment'], $only['can_comment'], 'the two must not drift');
    }

    public function testTheCommentsResponseReflectsAMidScrollArchive(): void
    {
        $before = Caps::forCommentsResponse(self::operator(), self::publishedRow(), self::goodMember(), true);
        $after  = Caps::forCommentsResponse(
            self::operator(),
            ['status' => Caps::STATUS_PUBLISHED, 'is_archived' => true, 'comments_enabled' => true],
            self::goodMember(),
            true
        );
        self::assertTrue(Permission::isAllowed($before, 'can_comment'));
        self::assertFalse(Permission::isAllowed($after, 'can_comment'));
        self::assertSame(Caps::REASON_ARCHIVED, $after['can_comment']['reason_code']);
    }

    // ── 6. Administrator scope (d), and the missing public producer ───────

    public function testAdministratorRightsAreReadOnlyRecoveryOnly(): void
    {
        $caps = Caps::forAdministrator(true, true);
        self::assertSame(
            ['can_inspect_drafts', 'can_inspect_removed', 'can_restore_comment'],
            array_keys($caps)
        );
        foreach (array_keys($caps) as $key) {
            self::assertTrue(Permission::isAllowed($caps, $key), $key);
        }
    }

    public function testANonAdministratorGetsNoRecoveryRights(): void
    {
        foreach (array_keys(Caps::forAdministrator(false, true)) as $key) {
            self::assertFalse(Permission::isAllowed(Caps::forAdministrator(false, true), $key), $key);
        }
        foreach (Caps::forAdministrator(true, false) as $key => $entry) {
            self::assertFalse($entry['allowed'], $key);
            self::assertSame(Caps::REASON_FEATURE_DISABLED, $entry['reason_code'], $key);
        }
    }

    /**
     * ⚠ The admin object never merges into the other two. An administrator
     * who is independently the resolved operator gets operator rights
     * through the normal path — so the key sets must be disjoint, and
     * `forRow()` must take no administrator flag at all.
     */
    public function testTheAdminScopeIsDisjointAndCannotRaiseARowCapability(): void
    {
        $admin = array_keys(Caps::forAdministrator(true, true));
        $row   = array_keys(Caps::forRow(self::operator(), self::publishedRow(), self::goodMember(), true));
        $owner = ['can_create', 'can_manage'];

        self::assertSame([], array_intersect($admin, $row));
        self::assertSame([], array_intersect($admin, $owner));

        // No "is administrator" parameter exists on the row producer, so
        // there is no code path by which being an administrator raises a
        // row capability.
        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Caps::class, 'forRow'))->getParameters()
        );
        self::assertSame(['decision', 'row', 'viewer', 'featureEnabled'], $params);
    }

    /**
     * MUST-NOT-HAPPEN #1 — the structural guarantee.
     *
     * The public list and the rotator carry no capabilities because there
     * is no producer to call. This pins the public method inventory, so
     * adding a `forPublicList()` fails here rather than shipping a private
     * state onto a cacheable public response.
     */
    public function testThereIsNoProducerAPublicResponseCouldCall(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(Caps::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        self::assertSame(
            ['forAdministrator', 'forCommentsResponse', 'forOwnerScope', 'forRow'],
            $methods,
            'a new public producer needs a contract decision, not just a method'
        );
    }

    /**
     * ⚠ WAS "MUST-NOT-HAPPEN #8 — no reportability capability may appear".
     * §4.32.16 DECIDED the question, so this case is INVERTED rather than
     * deleted: the guard still exists, it now points the other way, and a
     * reader comparing revisions can see the flip was deliberate.
     *
     * What it still protects is the SHAPE of the answer — reporting lives in
     * the row scope and nowhere else.
     */
    public function testReportabilityIsDecidedAndLivesOnlyInTheRowScope(): void
    {
        self::assertFalse(Caps::REPORTABILITY_UNDECIDED, 'settled by §4.32.16');

        $row = array_keys(Caps::forRow(self::operator(), self::publishedRow(), self::goodMember(), true));
        self::assertContains('can_report', $row);

        // 🔒 NOT on the owner scope. A page-level "may report" would be as
        // wrong as a page-level "may comment": reportability depends on the
        // row being published and on whether THIS viewer already reported it.
        $block = Caps::forOwnerScope(self::operator(), true);
        self::assertNotNull($block);
        self::assertArrayNotHasKey('can_report', $block['capabilities']);

        // 🔒 NOT on the administrator scope either — admins resolve reports,
        // they do not file them through this surface.
        self::assertArrayNotHasKey('can_report', Caps::forAdministrator(true, true));

        // 🔒 And still no public producer, so no public response can carry it.
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(Caps::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);
        self::assertSame(['forAdministrator', 'forCommentsResponse', 'forOwnerScope', 'forRow'], $methods);
    }

    /**
     * The report ladder, in its contract order. Each case sets up the
     * expected reason AND a later rung, so a reordering fails here rather
     * than only a missing branch.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $viewer
     */
    #[DataProvider('reportGateOrdering')]
    public function testTheReportGateReportsReasonsInTheContractOrder(
        array $row,
        array $viewer,
        bool $featureEnabled,
        string $expected
    ): void {
        $caps = Caps::forRow(self::operator(), $row, $viewer, $featureEnabled);
        self::assertSame($expected, $caps['can_report']['reason_code']);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool, string}> */
    public static function reportGateOrdering(): iterable
    {
        $worst = [
            'authenticated' => false, 'suspended' => true,
            'fraud_locked'  => true,  'already_reported' => true,
        ];
        $pub = ['status' => Caps::STATUS_PUBLISHED, 'is_archived' => false, 'comments_enabled' => true];

        yield 'feature off beats everything' => [$pub, $worst, false, Caps::REASON_FEATURE_DISABLED];
        yield 'unavailable beats auth' => [
            ['status' => Caps::STATUS_DRAFT, 'is_archived' => false, 'comments_enabled' => true],
            $worst, true, Caps::REASON_UNAVAILABLE,
        ];
        yield 'auth beats suspension' => [$pub, $worst, true, Caps::REASON_AUTH_REQUIRED];
        yield 'suspension beats fraud' => [
            $pub,
            ['authenticated' => true, 'suspended' => true, 'fraud_locked' => true, 'already_reported' => true],
            true, Caps::REASON_SUSPENDED,
        ];
        yield 'fraud beats already-reported' => [
            $pub,
            ['authenticated' => true, 'suspended' => false, 'fraud_locked' => true, 'already_reported' => true],
            true, Caps::REASON_FRAUD_LOCKED,
        ];
        yield 'already reported' => [
            $pub,
            ['authenticated' => true, 'suspended' => false, 'fraud_locked' => false, 'already_reported' => true],
            true, Caps::REASON_ALREADY_REPORTED,
        ];
    }

    /**
     * ⚠⚠ The two differences from the COMMENT gate, asserted together
     * because each is a place the natural implementation would copy the
     * comment ladder and be wrong.
     */
    public function testTheReportGateDivergesFromTheCommentGateInExactlyTwoWays(): void
    {
        // 1. Archived: comments close, reporting does not.
        $archived = Caps::forRow(
            self::denied(),
            ['status' => Caps::STATUS_PUBLISHED, 'is_archived' => true, 'comments_enabled' => true],
            self::goodMember(),
            true
        );
        self::assertFalse(Permission::isAllowed($archived, 'can_comment'));
        self::assertTrue(Permission::isAllowed($archived, 'can_report'));

        // 2. Standing: commenting needs it, reporting does not. Gating
        //    reporting on standing would silence exactly the reporters least
        //    able to be heard another way.
        $poorStanding = Caps::forRow(
            self::denied(),
            self::publishedRow(),
            ['authenticated' => true, 'suspended' => false, 'fraud_locked' => false, 'good_standing' => false],
            true
        );
        self::assertFalse(Permission::isAllowed($poorStanding, 'can_comment'));
        self::assertTrue(Permission::isAllowed($poorStanding, 'can_report'));

        // And NOT a third way: comments_enabled is a comment switch only.
        $commentsOff = Caps::forRow(
            self::denied(),
            ['status' => Caps::STATUS_PUBLISHED, 'is_archived' => false, 'comments_enabled' => false],
            self::goodMember(),
            true
        );
        self::assertFalse(Permission::isAllowed($commentsOff, 'can_comment'));
        self::assertTrue(Permission::isAllowed($commentsOff, 'can_report'));
    }

    /** The report inventory is closed, and every listed reason is reachable. */
    public function testEveryReportReasonEmittedIsInTheClosedInventory(): void
    {
        $seen = [];
        foreach (self::reportGateOrdering() as [$row, $viewer, $feature, $_e]) {
            foreach ([self::operator(), self::denied()] as $decision) {
                $code = Caps::forRow($decision, $row, $viewer, $feature)['can_report']['reason_code'];
                if (is_string($code)) { $seen[$code] = true; }
            }
        }
        foreach (array_keys($seen) as $code) {
            self::assertContains($code, Caps::CAN_REPORT_REASONS, $code);
        }
        self::assertSame(
            [],
            array_values(array_diff(Caps::CAN_REPORT_REASONS, array_keys($seen))),
            'the inventory lists a reason nothing can emit'
        );

        // ⚠ The ownership reason must never be among them, for an operator or
        // a non-operator. Reporting is not an ownership question.
        self::assertNotContains(Caps::REASON_NOT_CLAIMER, Caps::CAN_REPORT_REASONS);
        // ⚠ Nor archived — that absence IS the §4.32.6 guarantee.
        self::assertNotContains(Caps::REASON_ARCHIVED, Caps::CAN_REPORT_REASONS);
    }

    /**
     * An operator may report their own announcement. Strange, not unsafe —
     * and a server that special-cased it would be making a product judgement
     * the contract did not ask for.
     */
    public function testAnOperatorMayReportTheirOwnAnnouncement(): void
    {
        $caps = Caps::forRow(self::operator(), self::publishedRow(), self::goodMember(), true);
        self::assertTrue(Permission::isAllowed($caps, 'can_report'));
    }

    // ── 7. Source-level invariants ───────────────────────────────────────

    /**
     * MUST-NOT-HAPPEN #2 at the source level. §4.32.2 forbids these five
     * as authorization inputs; the resolver must not name any of them,
     * and it must not reach for WordPress state at all — purity is what
     * makes this whole suite possible without a bootstrap.
     */
    #[DataProvider('forbiddenSourceTokens')]
    public function testTheResolverNamesNoForbiddenAuthorizationInput(string $token, string $why): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Announcements/AnnouncementCapabilityResolver.php'
        );
        self::assertIsString($src);

        // Strip comments so the docblocks — which DISCUSS these inputs in
        // order to forbid them — do not trip the executable-code check.
        $code = '';
        foreach (token_get_all($src) as $token_) {
            if (is_array($token_) && in_array($token_[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token_) ? $token_[1] : $token_;
        }

        self::assertStringNotContainsString($token, $code, $why);
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbiddenSourceTokens(): iterable
    {
        yield 'post_author'      => ['post_author', 'the claim flow MOVES page authorship (§4.32.2)'];
        yield 'claim repository' => ['ClaimRepository', 'mirror claim rows are not authority'];
        yield 'peepso'           => ['PeepSo', 'PeepSo membership is not authority'];
        yield 'read model'       => ['ReadModel', 'a denormalized read model is not authority'];
        yield 'current_user_can' => ['current_user_can', 'a WP capability is not the operator check'];
        yield 'get_post'         => ['get_post', 'the resolver reads no post'];
        yield 'get_option'       => ['get_option', 'the feature flag arrives as a parameter'];
        yield 'wpdb'             => ['wpdb', 'the resolver touches no database'];
        yield 'get_current_user' => ['get_current_user_id', 'the viewer arrives as a parameter'];
        yield 'time'             => ['current_time', 'the resolver reads no clock'];
    }

    /**
     * The §11 extraction must be a real delegation, not a copy: the
     * registry names `CardViewService::allow()`/`deny()` as canonical, and
     * a second implementation there would put the two straight back out of
     * sync — which is the condition that produced fourteen copies.
     *
     * ⚠ Behavioural equality is checked AND is not sufficient. A re-copied
     * literal returns the identical array, so an equality assertion alone
     * cannot tell a forwarder from a duplicate. The source assertion below
     * is the one with teeth; the equality assertions catch the forwarder
     * being wired up wrong.
     */
    public function testCardViewServiceDelegatesToTheSharedPermissionShape(): void
    {
        $allow = new \ReflectionMethod(\BCC\Trust\Core\Services\CardViewService::class, 'allow');
        $deny  = new \ReflectionMethod(\BCC\Trust\Core\Services\CardViewService::class, 'deny');
        $allow->setAccessible(true);
        $deny->setAccessible(true);

        self::assertSame(Permission::allow(), $allow->invoke(null));

        // ⚠ The argument order is INVERTED there (hint first), which is
        // exactly the kind of thing a forwarder gets wrong.
        self::assertSame(
            Permission::deny('feature_locked', 'Do a thing.'),
            $deny->invoke(null, 'Do a thing.', 'feature_locked')
        );
        self::assertSame(
            Permission::deny('feature_locked'),
            $deny->invoke(null, null, 'feature_locked')
        );

        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Services/CardViewService.php'
        );
        self::assertIsString($src);
        self::assertStringContainsString(
            'PermissionShape::allow()',
            $src,
            'the canonical producer must FORWARD, not re-implement'
        );
        self::assertStringContainsString('PermissionShape::deny(', $src);
    }
}
