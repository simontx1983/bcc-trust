<?php
/**
 * F-401 — every surface that publishes the home-Hall CHIP answers to the
 * shared hide rule, and none of them resolves the pointer itself.
 *
 * ⚠⚠ WHY THIS FILE EXISTS. `ProfileGroupVisibilityTest` proves
 * `hidesPrimaryHall()` is correct. Nothing in it proves the chip is
 * gated, and the gap between those two claims is how F-323 happened one
 * layer along: the rules existed and the `halls` field simply never
 * asked.
 *
 * The chip is published by FOUR surfaces, and only ONE of them resolves
 * it:
 *
 *   - `UserViewService::getSummary()`  — the directory row. THE GATE.
 *   - `UserViewService::getUser()`     — the full profile. Inherits the
 *     drop from `ProfileGroupVisibility::filter()` because its chip is
 *     derived from the already-filtered `halls` list (F-341 PR 3).
 *   - `CardViewService`                — copies `getSummary()`'s value
 *     into `member_dossier.primary_hall`.
 *   - `MemberProfileComposer`          — copies `getUser()`'s value into
 *     the "Home Hall" identity-meta row.
 *
 * ⭐ That inheritance is exactly what makes one gate sufficient, so the
 * assertions below pin it. The day a surface resolves
 * `bcc_primary_hall_group_id` or `getPrimaryHallForUsers()` for itself it
 * stops inheriting, and a privacy choice silently stops applying to the
 * most prominent place the Hall is shown. These tests are what notices.
 *
 * ── Why a token walk ─────────────────────────────────────────────────
 *
 * {@see GateOrder::code()} strips comments before matching, so a docblock
 * mention cannot satisfy any of these — this repo has been caught by a
 * docblock-reference grep before. `body()` then takes the brace-balanced
 * body of ONE named method, so a call sitting in a different method of
 * the same file does not count either.
 *
 * ⚠ STATIC, and deliberately scoped. It proves the call is present and
 * ORDERED before publication, and that the inheriting surfaces hold no
 * pointer logic. It does NOT prove the gate is reached on every runtime
 * path. No unit harness can: `getSummary()` cannot be built without its
 * twelve collaborators, and the one harness that looks like a candidate
 * — `ProfileHallRoleTest` — stubs `ProfileGroupVisibility::filter()` to a
 * pass-through, so it is structurally blind to this rule. Only an
 * integration test through the REST stack could, and none exists for
 * these surfaces today. Claiming more here would be the false confidence
 * this kind of file exists to remove.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

final class PrimaryHallChipWiringTest extends TestCase
{
    private const USER_VIEW = 'app/Domain/Core/Services/UserViewService.php';
    private const CARD_VIEW = 'app/Domain/Core/Services/CardViewService.php';
    private const COMPOSER  = 'app/Domain/Core/Services/MemberProfileComposer.php';
    private const MENTIONS  = 'app/Domain/Core/Services/Mentions/MentionSearchService.php';

    /** Pointer resolution — the two ways a surface could bypass the gate. */
    private const POINTER_READS = [
        'getPrimaryHallForUsers',
        'bcc_primary_hall_group_id',
    ];

    /**
     * ⭐ THE GATE, and its ORDER. Presence alone is not protection: a
     * refactor that moved the check below the payload would keep the
     * token and publish the chip anyway.
     */
    public function testTheDirectoryRowGatesTheChipBeforePublishingIt(): void
    {
        $body = GateOrder::body(GateOrder::code(self::USER_VIEW), 'getSummary');

        GateOrder::assertOrder(
            $body,
            [
                "array_key_exists('primary_halls'",
                'ProfileGroupVisibility::hidesPrimaryHall(',
                "'primary_hall'",
            ],
            'getSummary(): the chip must be gated after it is resolved and before it is published'
        );
    }

    /**
     * ⚠ The gate must apply to BOTH arms of the resolution. The prefetched
     * arm reads the pointer straight from the batch and the fallback arm
     * goes through `resolveHalls()`; before F-401 only the fallback
     * dropped a hidden Hall, so the same field answered to the rule or
     * not depending on whether the caller passed a prefetch bundle.
     *
     * Pinned by position: the gate sits after the whole if/else, so the
     * fallback's `resolvePrimaryHall()` call comes BEFORE it.
     */
    public function testTheGateCoversTheFallbackArmToo(): void
    {
        $body = GateOrder::body(GateOrder::code(self::USER_VIEW), 'getSummary');

        GateOrder::assertOrder(
            $body,
            [
                'resolvePrimaryHall(',
                'ProfileGroupVisibility::hidesPrimaryHall(',
            ],
            'getSummary(): the gate must sit after both arms, not inside one of them'
        );
    }

    /**
     * ⚠ The gate must be the REAL condition, not merely the token. A
     * token walk cannot see a neutered one: `if (false &&
     * hidesPrimaryHall($userId, 0, $viewerId))` satisfies every needle
     * above and publishes the chip regardless. Three such mutations
     * SURVIVED the first rounds of controls for this change, which is why
     * these assertions exist — pinning the guard expression and the id
     * argument is what closes them.
     *
     * The cost is that reformatting this one condition breaks the test.
     * That is the right trade here: the alternative is a wiring test a
     * one-word edit can hollow out while staying green.
     */
    public function testTheGateIsTheRealConditionOnTheRealId(): void
    {
        $body = GateOrder::body(GateOrder::code(self::USER_VIEW), 'getSummary');

        self::assertStringContainsString(
            '$primaryHall !== null && ProfileGroupVisibility::hidesPrimaryHall(',
            $body,
            'the gate must guard on the resolved chip, not on a constant'
        );
        self::assertStringContainsString(
            "\$primaryHall['id'],",
            $body,
            "the gate must be asked about the member's actual primary Hall id"
        );
    }

    /**
     * ⭐ ONE call site. A second copy is how this class of bug recurs
     * (F-283, F-323), and a copy placed in a surface that already
     * inherits would also pay for a second meta read per row.
     */
    public function testThereIsExactlyOneGateCall(): void
    {
        self::assertSame(
            1,
            substr_count(GateOrder::code(self::USER_VIEW), 'hidesPrimaryHall('),
            'the chip gate belongs at one choke point, not copied per surface'
        );
    }

    /**
     * The full profile inherits instead of gating: its chip is derived
     * from the `halls` list AFTER that list has been through
     * `ProfileGroupVisibility::filter()`, so a hidden Hall is simply not
     * there to be found. Pinned by order — resolve the filtered list,
     * then pick the primary out of it.
     */
    public function testTheFullProfileDerivesTheChipFromTheFilteredList(): void
    {
        $body = GateOrder::body(GateOrder::code(self::USER_VIEW), 'getUser');

        GateOrder::assertOrder(
            $body,
            [
                'resolveHalls(',
                'resolvePrimaryHall(',
            ],
            'getUser(): the chip must be picked out of the filtered list, not resolved beside it'
        );
        self::assertStringContainsString(
            'resolvePrimaryHall($userId, $halls)',
            $body,
            'the filtered list must be what the chip is derived from'
        );
    }

    /**
     * ⚠ The member card copies the gated value. If it ever resolved the
     * pointer itself it would bypass the gate — and `member_dossier`
     * feeds the feed-author strip, the widest audience the chip has.
     */
    public function testTheMemberCardCopiesTheGatedValue(): void
    {
        $body = GateOrder::body(GateOrder::code(self::CARD_VIEW), 'getMemberCardByUserId');

        self::assertStringContainsString(
            "\$summary['primary_hall']",
            $body,
            'the card must copy the summary value, which is already gated'
        );
        self::assertNoPointerReads(self::CARD_VIEW, 'the member card');
    }

    /** The profile identity strip likewise reads the gated view-model. */
    public function testTheIdentityStripReadsTheGatedViewModel(): void
    {
        $body = GateOrder::body(GateOrder::code(self::COMPOSER), 'buildIdentityMeta');

        self::assertStringContainsString(
            "\$base['primary_hall']",
            $body,
            "the Home Hall row must come from getUser()'s gated payload"
        );
        self::assertNoPointerReads(self::COMPOSER, 'the profile composer');
    }

    /**
     * ⚠ The mention picker publishes {id, handle, display_name,
     * avatar_url} and NO group affiliation — which is why F-401 needed no
     * gate there. Pinned so that adding the chip to a keystroke
     * autocomplete endpoint cannot happen without this failing first.
     */
    public function testTheMentionPickerPublishesNoChipAtAll(): void
    {
        self::assertStringNotContainsString(
            'primary_hall',
            GateOrder::code(self::MENTIONS),
            'the mention picker must stay free of group affiliation; add the gate with the field'
        );
    }

    private static function assertNoPointerReads(string $file, string $label): void
    {
        $code = GateOrder::code($file);
        foreach (self::POINTER_READS as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $code,
                "{$label} must not resolve the primary-Hall pointer itself — that bypasses the gate"
            );
        }
    }
}
