<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The six sibling routes in `UsersEndpoint` that declared a cap and enforced
 * nothing — api-contract-v1.md §4.
 *
 * ── ⚠⚠ WHAT WAS WRONG ───────────────────────────────────────────────────
 * Every one of these declared `minimum`/`maximum` beside a
 * `sanitize_callback`, and WordPress enforces a schema ONLY for an argument
 * that declares no sanitizer — so none of the caps bound. Each handler then
 * clamped the LOWER bound only, leaving nothing bounded above:
 *
 *   /users/:handle/blog          limit     documented max 50   unbounded
 *   /users/:handle/activity      limit     documented max 50   unbounded
 *   /users/:handle/endorsements  limit     documented max 50   unbounded
 *   /users/:handle/reviews       per_page  documented max 50   unbounded
 *   /users/:handle/disputes      per_page  documented max 50   unbounded
 *   /users/:handle/shift-log     weeks     documented max 104  unbounded
 *
 * `absint` made each worse than an absent check by rewriting invalid input
 * into valid input — `absint('-5') === 5`.
 *
 * ── ⚠ EACH CAP IS ITS OWN NUMBER ────────────────────────────────────────
 * `weeks` is 104, not 50. Enforcing one shared number would silently halve a
 * documented two-year window, so the tests below assert each route against
 * the constant the contract documents for THAT route.
 *
 * ── ⚠ WHY SOURCE TESTS ──────────────────────────────────────────────────
 * These handlers reach `Plugin::instance()`, `UserSlugResolver`, `Throttle`
 * and four services. Standing that up with no WordPress would prove things
 * about doubles. The behaviour is proved end to end by
 * `scripts/tests/users-sibling-bounds-real-dispatch.php`; what these cases
 * pin is the part that rots silently — ORDER, and the absence of `absint`.
 */
final class UsersEndpointSiblingBoundsTest extends TestCase
{
    private static function source(): string
    {
        $path = dirname(__DIR__, 2) . '/app/Domain/Core/REST/UsersEndpoint.php';
        $src  = file_get_contents($path);
        self::assertIsString($src);

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        return $code;
    }

    private static function body(string $function): string
    {
        $code  = self::source();
        $start = strpos($code, 'function ' . $function . '(');
        self::assertIsInt($start, $function . '() not found');
        $next = strpos($code, ' function ', $start + 10);

        return $next === false ? substr($code, $start) : substr($code, $start, $next - $start);
    }

    /**
     * ⚠⚠ THE ASSERTION THAT MATTERS: the bound is parsed and refused BEFORE
     * the handle lookup, because `UserSlugResolver::resolve()` is a database
     * read and a refused request must not pay for one.
     *
     * ⚠ This is a deliberate change of precedence. A request carrying BOTH
     * an unknown handle and an out-of-range bound now answers 400 where it
     * used to answer 404.
     */
    #[DataProvider('handlersThatMustRefuseFirst')]
    public function testTheBoundIsRefusedBeforeTheHandleLookup(string $function): void
    {
        $body = self::body($function);

        $parse  = strpos($body, 'BoundedIntParam::parse');
        $refuse = strpos($body, 'return self::bad');
        $read   = strpos($body, 'UserSlugResolver::resolve');

        self::assertIsInt($parse, $function . ' does not parse its bound');
        self::assertIsInt($refuse, $function . ' does not refuse an out-of-range bound');
        self::assertIsInt($read, $function . ' no longer resolves a handle — update this test');

        self::assertLessThan($read, $parse, $function . ': the parse must precede the handle lookup');
        self::assertLessThan($read, $refuse, $function . ': the refusal must precede the handle lookup');
    }

    /** @return iterable<string, array{string}> */
    public static function handlersThatMustRefuseFirst(): iterable
    {
        yield 'blog'                => ['blog'];
        yield 'activity'            => ['activity'];
        yield 'endorsements'        => ['endorsements'];
        yield 'shiftLog'            => ['shiftLog'];
        yield 'reviews + disputes'  => ['handleListEndpoint'];
    }

    /**
     * ⚠ `endorsements()` is the ONE route where the refusal is not literally
     * zero reads: the throttle stays first on purpose, because it is the
     * guard against a hot profile starving the namespace and a malformed
     * request should not get a free pass around it.
     */
    public function testEndorsementsKeepsItsThrottleAheadOfTheBound(): void
    {
        $body = self::body('endorsements');

        $throttle = strpos($body, 'Throttle::allow');
        $parse    = strpos($body, 'BoundedIntParam::parse');

        self::assertIsInt($throttle, 'the rate-limit guard has gone');
        self::assertIsInt($parse);
        self::assertLessThan($parse, $throttle, 'the throttle must stay ahead of the bound check');
    }

    /**
     * Each route enforces the cap the contract documents for IT — not a
     * shared number. `weeks` is the one that would be silently halved.
     *
     * @param string $function
     * @param string $constant
     */
    #[DataProvider('capPerRoute')]
    public function testEachRouteEnforcesItsOwnDocumentedCap(string $function, string $constant): void
    {
        $body = self::body($function);

        self::assertStringContainsString(
            $constant,
            $body,
            $function . ' must enforce ' . $constant . ', not a borrowed number'
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function capPerRoute(): iterable
    {
        yield 'blog -> 50'          => ['blog', 'self::BLOG_MAX_LIMIT'];
        yield 'activity -> 50'      => ['activity', 'self::ACTIVITY_MAX_LIMIT'];
        yield 'endorsements -> 50'  => ['endorsements', 'self::ENDORSEMENTS_MAX_LIMIT'];
        yield 'lists -> 50'         => ['handleListEndpoint', 'self::LIST_MAX_PER_PAGE'];
        yield 'shift-log -> 104'    => ['shiftLog', 'self::MAX_WEEKS'];
    }

    /** The documented numbers themselves, so a drift in either direction fails. */
    public function testTheDocumentedCapsAreTheConstants(): void
    {
        $code = self::source();

        foreach ([
            'BLOG_MAX_LIMIT'         => 50,
            'ACTIVITY_MAX_LIMIT'     => 50,
            'ENDORSEMENTS_MAX_LIMIT' => 50,
            'LIST_MAX_PER_PAGE'      => 50,
            'MAX_WEEKS'              => 104,
        ] as $name => $value) {
            self::assertMatchesRegularExpression(
                '/const\s+' . $name . '\s*=\s*' . $value . ';/',
                $code,
                $name . ' must be ' . $value . ' per §4'
            );
        }
    }

    /**
     * ⚠⚠ `absint` must be gone from every bounded argument, because it
     * rewrites invalid input into valid input. The ONE survivor is
     * `/users/mention-search`, and that is deliberate — see the next case.
     */
    public function testAbsintSurvivesOnlyWhereTheHandlerAlreadyClampsBothEnds(): void
    {
        $code = self::source();

        self::assertSame(
            1,
            substr_count($code, "'absint'"),
            'absint should remain on exactly one argument: mention-search'
        );

        // And it is harmless there precisely because that handler bounds
        // both ends itself — the only route in this file that already did.
        $mention = self::body('mentionSearch');
        self::assertStringContainsString('MentionSearchService::MAX_LIMIT', $mention);
        self::assertMatchesRegularExpression(
            '/\$limit\s*>\s*MentionSearchService::MAX_LIMIT/',
            $mention,
            'mention-search keeps its own upper clamp — that is why it needed no change'
        );
    }

    /**
     * ⚠ mention-search is left CLAMPING, not converted to a rejection.
     * It has no defect, and turning a working clamp into a 400 would be an
     * undocumented behaviour change for no bound-safety gain.
     */
    public function testMentionSearchWasDeliberatelyNotConverted(): void
    {
        $mention = self::body('mentionSearch');

        self::assertStringNotContainsString(
            'BoundedIntParam::parse',
            $mention,
            'mention-search was correct already; converting it changes behaviour for nothing'
        );
    }

    /** The lower-bound-only clamps that hid the missing upper bound are gone. */
    #[DataProvider('handlersThatMustRefuseFirst')]
    public function testTheLowerBoundOnlyClampIsGone(string $function): void
    {
        $body = self::body($function);

        foreach (['$limit < 1', '$weeks < 1', '$page < 1', '$perPage < 1'] as $clamp) {
            self::assertStringNotContainsString(
                $clamp,
                $body,
                $function . ': the clamp this change replaced has returned — ' . $clamp
            );
        }
    }

    /**
     * One refusal vocabulary. `bcc_invalid_request` is the house validation
     * code; a second code here would mean clients branch two ways on the
     * same class of mistake.
     */
    public function testEveryRefusalUsesTheHouseValidationCode(): void
    {
        $code = self::source();

        // ⚠ Asserted per PRODUCER, not as a whole-file count. The first
        // version of this case asserted `substr_count(...) === 4` and failed
        // at 6, because the file already carried two `bcc_invalid_request`
        // refusals of its own that have nothing to do with bounds. A count
        // over a whole file measures its history as much as this change, so
        // it would have had to be re-tuned by whoever added the next
        // unrelated refusal — for no gain.
        foreach (['badLimit', 'badBound', 'badPagination'] as $producer) {
            $body = self::body($producer);
            self::assertStringContainsString(
                "'bcc_invalid_request'",
                $body,
                $producer . '() must emit the house validation code'
            );
            self::assertStringContainsString(
                '400',
                $body,
                $producer . '() must refuse with a 400'
            );
        }

        self::assertStringNotContainsString('bcc_validation', $code, 'that code has no producer anywhere');
    }

    /**
     * ⚠ Scope guard: this change is `UsersEndpoint` only. The same
     * non-binding shape exists on other endpoint files and is knowingly
     * untouched, so this suite must not be read as covering them.
     */
    public function testTheChangeIsScopedToThisFile(): void
    {
        $dir   = dirname(__DIR__, 2) . '/app/Domain/Core/REST';
        $other = 0;

        foreach (glob($dir . '/*.php') ?: [] as $path) {
            if (basename($path) === 'UsersEndpoint.php') {
                continue;
            }
            $s = file_get_contents($path);
            if (!is_string($s)) {
                continue;
            }
            if (preg_match_all("/\[[^\[\]]*?(?:minimum|maximum)[^\[\]]*?\]/s", $s, $m)) {
                foreach ($m[0] as $blk) {
                    if (strpos($blk, 'sanitize_callback') !== false
                        && strpos($blk, 'validate_callback') === false
                    ) {
                        $other++;
                    }
                }
            }
        }

        // Recorded, not asserted to zero: these are OUT of scope by
        // instruction. If someone fixes them, this number drops and the
        // message says so — which is the point.
        self::assertGreaterThan(
            0,
            $other,
            'if this is 0, the other endpoint files were fixed too — update or delete this case'
        );
    }
}
