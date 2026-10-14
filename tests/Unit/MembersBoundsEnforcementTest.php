<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `GET /bcc/v1/members` — the bound binds, and it binds BEFORE the work.
 *
 * ── ⚠ WHY THIS IS A SOURCE TEST AND NOT A DISPATCH TEST ─────────────────
 * `UsersEndpoint::members()` reaches `Plugin::instance()`, `WP_User_Query`,
 * two PeepSo repositories and an eleven-map prefetcher. Standing all of that
 * up in a harness with no WordPress would mean doubling so much that the
 * test would prove things about the doubles. The behaviour is instead proved
 * end to end by `scripts/tests/members-bounds-real-dispatch.php` against a
 * real install — including that a refused request issues zero queries.
 *
 * What a source test CAN pin, and what matters most here, is ORDER: a
 * refusal that lands after the prefetch is not a fix. These assertions fail
 * if the refusal moves, if `absint` returns, or if a clamp creeps back.
 *
 * ⚠⚠ This file is also the record of a trap. The declared
 * `'maximum' => 50` on `per_page` did NOT bind, because
 * `WP_REST_Request::sanitize_params()` substitutes the schema-enforcing
 * `rest_parse_request_arg` only for an argument that declares no
 * `sanitize_callback`. Measured on WordPress 6.7.2: `?per_page=100000`
 * answered **200** with a real `LIMIT 100000` on a `__return_true` route.
 */
final class MembersBoundsEnforcementTest extends TestCase
{
    private static function source(): string
    {
        $path = dirname(__DIR__, 2) . '/app/Domain/Core/REST/UsersEndpoint.php';
        $src  = file_get_contents($path);
        self::assertIsString($src, 'cannot read UsersEndpoint.php');

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        return $code;
    }

    /** Just the body of members(), so neighbouring routes cannot satisfy a check. */
    private static function membersBody(): string
    {
        $code  = self::source();
        $start = strpos($code, 'function members(');
        self::assertIsInt($start, 'members() not found');

        // To the start of the next method declaration.
        $next = strpos($code, ' function ', $start + 10);

        return $next === false ? substr($code, $start) : substr($code, $start, $next - $start);
    }

    /**
     * ⚠⚠ THE ASSERTION THAT MATTERS. Both the parse and the refusal must
     * precede every repository and prefetch reference in the method.
     */
    #[DataProvider('workThatMustComeLater')]
    public function testTheRefusalPrecedesEveryPieceOfWork(string $token): void
    {
        $body = self::membersBody();

        $parse  = strpos($body, 'BoundedIntParam::parse');
        $refuse = strpos($body, "'bcc_invalid_request'");
        $work   = strpos($body, $token);

        self::assertIsInt($parse, 'members() does not parse its bounds');
        self::assertIsInt($refuse, 'members() does not refuse an invalid bound');

        if ($work === false) {
            self::markTestSkipped($token . ' is no longer referenced in members()');
        }

        self::assertLessThan($work, $parse, 'the parse must precede ' . $token);
        self::assertLessThan($work, $refuse, 'the refusal must precede ' . $token);
    }

    /** @return iterable<string, array{string}> */
    public static function workThatMustComeLater(): iterable
    {
        yield 'the global type-count query' => ['getGlobalOwnedPageUserCountsByType'];
        yield 'the member prefetcher'       => ['Prefetcher'];
        yield 'the user query'              => ['WP_User_Query'];
        yield 'the service locator'         => ['Plugin::instance'];
    }

    /**
     * The route must not reintroduce the sanitizer that rewrote invalid
     * input into valid input — `absint('-5') === 5` is why a negative page
     * size was served rather than refused.
     */
    public function testTheMembersArgsDoNotUseAbsint(): void
    {
        $code  = self::source();
        $start = strpos($code, "'/members'");
        self::assertIsInt($start);

        // The registration block: from the path literal to the end of its args.
        $block = substr($code, $start, 2000);

        self::assertStringNotContainsString(
            "'absint'",
            $block,
            'absint on a bounded arg destroys the information the refusal needs'
        );
        self::assertStringContainsString('BoundedIntParam::class', $block);
    }

    /**
     * ⚠ The lower-bound-only clamp is what made the upper bound unreachable.
     * Its exact shape must not come back in this method.
     */
    public function testTheLowerBoundOnlyClampIsGone(): void
    {
        $body = self::membersBody();

        foreach (['$page < 1', '$perPage < 1'] as $clamp) {
            self::assertStringNotContainsString(
                $clamp,
                $body,
                'the clamp this fix replaced has returned: ' . $clamp
            );
        }
    }

    /**
     * The declared bound stays in the schema as documentation, and
     * `LIST_MAX_PER_PAGE` must remain the single source of the number the
     * handler enforces — not a second literal that can drift from it.
     */
    public function testTheEnforcedMaximumComesFromTheDeclaredConstant(): void
    {
        $body = self::membersBody();

        self::assertStringContainsString('self::LIST_MAX_PER_PAGE', $body);
        self::assertSame(
            1,
            preg_match_all('/BoundedIntParam::parse/', $body) > 0 ? 1 : 0,
            'members() parses its bounds through the shared parser'
        );

        $code = self::source();
        self::assertStringContainsString(
            'LIST_MAX_PER_PAGE     = 50',
            $code,
            'the contract documents max 50'
        );
    }

    /**
     * ⚠ Scope note, asserted so it cannot drift silently: this change is
     * `/members` only. The sibling routes in this file carry the SAME
     * non-binding-bound shape and are reviewed but deliberately untouched,
     * so a reader is not misled into thinking the file is now clean.
     *
     * `/users/mention-search` is the one that was already correct — it
     * enforces its own maximum inside the handler.
     */
    public function testTheSiblingRoutesAreKnowinglyUnchanged(): void
    {
        $code = self::source();

        // Still present, still clamping only the lower bound. If someone
        // fixes them, this case should be deleted in the same change.
        self::assertStringContainsString('$limit < 1', $code, 'blog()/activity()/endorsements() still clamp low only');

        // And mention-search still enforces its own upper bound.
        self::assertStringContainsString(
            'MentionSearchService::MAX_LIMIT',
            $code,
            'mention-search was already correct and must stay that way'
        );
    }
}
