<?php
/**
 * Both profile surfaces are WIRED to the shared group-privacy filter.
 *
 * ⚠⚠ WHY THIS FILE EXISTS, and it is not belt-and-braces — it is the same
 * gap `ProfileGateWiringTest` was written for, one layer along.
 *
 * `ProfileGroupVisibilityTest` proves the FILTER is correct. Nothing in it
 * proves the filter is CALLED. Those are different claims, and the gap
 * between them is exactly how F-323 happened: the rules existed in
 * `UserGroupsEndpoint` and the user view-model's `halls` field simply
 * never asked, so it published secret-group membership to any profile
 * reader while every test in the suite stayed green.
 *
 * Delete the call from either surface and every assertion in the filter's
 * own suite still passes. These assertions are what notices.
 *
 * ── Why the token walk ──────────────────────────────────────────────
 *
 * {@see GateOrder::code()} strips comments before matching, so a docblock
 * `{@see ProfileGroupVisibility}` reference cannot satisfy these — this
 * repo has been caught by a docblock-reference grep before. `body()` then
 * takes the brace-balanced body of one named method, so a call sitting in
 * some OTHER method of the same file does not count either.
 *
 * ⚠ STATIC wiring check, deliberately scoped. It proves the call is
 * present in each method body and that both call sites pass a viewer. It
 * does NOT prove the filter is reached on every runtime path; only an
 * integration test through the REST stack could, and none exists for
 * these two surfaces today. Claiming more here would be the
 * false-confidence this kind of file is meant to remove.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

final class ProfileGroupVisibilityWiringTest extends TestCase
{
    private const GROUPS_TAB = 'app/Domain/Core/REST/UserGroupsEndpoint.php';
    private const USER_VIEW  = 'app/Domain/Core/Services/UserViewService.php';

    /** The profile Groups tab delegates instead of deciding. */
    public function testTheGroupsTabCallsTheSharedFilter(): void
    {
        $body = GateOrder::body(GateOrder::code(self::GROUPS_TAB), 'filterByPrivacy');

        self::assertStringContainsString(
            'ProfileGroupVisibility::filter(',
            $body,
            'the Groups tab must defer to the one owner of the rules'
        );
    }

    /**
     * ⚠⚠ THE F-323 FIX, pinned. This is the surface that had no filter at
     * all; if the call goes missing again the leak returns silently.
     */
    public function testTheUserViewModelCallsTheSharedFilter(): void
    {
        $body = GateOrder::body(GateOrder::code(self::USER_VIEW), 'resolveHalls');

        self::assertStringContainsString(
            'ProfileGroupVisibility::filter(',
            $body,
            'the `halls` field leaked secret groups precisely because this call was absent'
        );
    }

    /**
     * ⚠ A filter that is called with no viewer cannot filter. Both
     * profile paths — the full profile (`getUser`) and the directory
     * summary (`getSummary`) — must hand the viewer down.
     */
    public function testBothProfilePathsPassTheViewerToResolveHalls(): void
    {
        $code = GateOrder::code(self::USER_VIEW);

        self::assertSame(
            2,
            substr_count($code, 'resolveHalls($userId, $viewerId)'),
            'getUser() and getSummary() must both pass the viewer'
        );
    }

    /**
     * ⭐ THE SINGLE-OWNER INVARIANT, and the one that stops the drift
     * rather than just the deletion. The privacy rules must not creep
     * back into the Groups tab beside the shared filter: two copies that
     * later disagreed is the whole shape of F-323.
     *
     * Asserted on the method BODY, so the file's other legitimate uses of
     * these symbols (the per-group enrichment, the join copy) are not
     * caught by it.
     */
    public function testTheGroupsTabNoLongerDecidesPrivacyItself(): void
    {
        $body = GateOrder::body(GateOrder::code(self::GROUPS_TAB), 'filterByPrivacy');

        self::assertStringNotContainsString(
            'PeepSoPrivacy::Secret',
            $body,
            'secret-group handling belongs to ProfileGroupVisibility now, not here'
        );
        self::assertStringNotContainsString(
            'findUserMemberships',
            $body,
            'the membership read moved with the rule it serves'
        );
    }
}
