<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Card must carry §4.32's `announcements` block, because its PRESENCE is
 * the frontend's only feature gate.
 *
 * ── ⚠⚠ WHY A CI-RUNNABLE TEST AND NOT JUST THE PROBE ──────────────────────
 * The behavioural evidence is `scripts/tests/announcement-card-block.php`
 * (30 assertions, real WordPress, 9/19 on an unfixed tree). But `scripts/tests/*`
 * is NOT CI-wired — it runs by hand. So without this file, deleting the caller
 * would leave CI entirely green while making the feature unreachable again,
 * which is exactly the state this change fixes.
 *
 * That is not hypothetical: the caller was missing for the whole of A2–A5 and
 * every route test passed throughout, because the gate is on a different
 * surface from the routes.
 */
#[Group('unit')]
final class CardAnnouncementsBlockTest extends TestCase
{
    private const CARD = 'app/Domain/Core/Services/CardViewService.php';

    private static function code(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $src  = file_get_contents($path);
        self::assertIsString($src, 'could not read ' . $rel);

        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    public function testTheCardEmitsTheAnnouncementsKey(): void
    {
        self::assertStringContainsString(
            "\$card['announcements']",
            self::code(self::CARD),
            'the frontend gates both announcement surfaces on this key; without it the feature is unreachable'
        );
    }

    public function testTheBlockComesFromTheOwnerScopeProducer(): void
    {
        // One producer for the page scope. A hand-built block here would be a
        // second source of truth for the same two capabilities.
        self::assertStringContainsString(
            'AnnouncementCapabilityResolver::forOwnerScope(',
            self::code(self::CARD)
        );
    }

    /**
     * The inserted region, isolated so an assertion cannot pass on unrelated
     * code elsewhere in a 900-line file.
     *
     * ⚠ Anchored on `forOwnerScope(`, which exists ONLY with the fix. An
     * earlier version of the two cases below asserted `!== null` and
     * `$kind === 'validator'` against the WHOLE file: `!== null` appears 41
     * times on an unfixed tree, so that case passed vacuously, and the other
     * was written in a DOUBLE-quoted string where `$kind` interpolated away to
     * nothing and raised an undefined-variable warning. Both passed on main.
     */
    private static function callSite(): string
    {
        $code = self::code(self::CARD);
        $at   = strpos($code, 'AnnouncementCapabilityResolver::forOwnerScope(');
        self::assertNotFalse($at, 'the owner-scope producer is never called');

        return substr($code, max(0, $at - 260), 700);
    }

    public function testTheKeyIsOmittedRatherThanEmittedAsNull(): void
    {
        // forOwnerScope() returns null when the switch is off, and its own
        // docblock is explicit: an emitted-but-denied block would make the
        // client mount the surface and fire requests that all 404. So the
        // assignment sits behind a non-null check at the call site.
        $site = self::callSite();

        self::assertStringContainsString('!== null', $site);
        self::assertStringContainsString("card['announcements']", $site);
        self::assertSame(
            0,
            substr_count(self::code(self::CARD), "'announcements' =>"),
            'the key must be added conditionally, never declared in the array literal'
        );
    }

    public function testAuthorityIsResolvedRatherThanAssumed(): void
    {
        // Operator standing is decided by the one authority, at the call site,
        // not by a capability check or a post_author comparison.
        $site = self::callSite();

        self::assertStringContainsString('ValidatorPageOperatorAuthority::decide(', $site);
        self::assertStringContainsString('viewerId', $site);
        self::assertStringNotContainsString('current_user_can(', $site);
    }

    public function testTheBlockIsScopedToValidatorCards(): void
    {
        // decide() answers `not_a_validator_page` for any other kind, so an
        // unscoped call would do work to reach null on every builder and nft
        // card. The check also documents that announcements are validator-only.
        self::assertStringContainsString(
            "kind === 'validator'",
            self::callSite(),
            'the block must be scoped to validator cards at the call site'
        );
    }
}
