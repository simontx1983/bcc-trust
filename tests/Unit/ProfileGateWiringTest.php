<?php
/**
 * Every per-user read route is WIRED to the §F-02 profile gate.
 *
 * ⚠⚠ WHY THIS FILE EXISTS, and it is not belt-and-braces.
 *
 * ProfileAccessTest proves the GATE works. Nothing proved the gate is
 * CALLED. Those are different claims, and the gap between them is the
 * whole vulnerability: F-02 was never a broken gate, it was twelve
 * routes that never asked. Re-open any one of them — delete a guard in a
 * refactor, add a twelfth route and forget it — and that profile is
 * public again while every other test in the suite stays green.
 *
 * The full unit suite passing with ZERO failures after twelve routes
 * started refusing non-owners is the proof that this gap is real: no
 * pre-existing test exercised those routes as a stranger, so nothing
 * would have noticed their gate disappearing either.
 *
 * ── Why a token walk, not a grep ────────────────────────────────────
 *
 * A file-level `strpos($src, 'canViewProfile')` would pass if the gate
 * sat in ONE method of a six-route file, or if the only occurrence were
 * a docblock `{@see ...}` reference. This repo has been caught by both:
 * "a docblock-reference grep is not a caller". So this walks tokens,
 * attributes every call to the method it is lexically inside, and
 * requires the call to be a real `ProfileAccess::canViewProfile(`
 * invocation — `T_DOUBLE_COLON` followed by the name followed by `(`.
 *
 * ⚠ This is a STATIC wiring check, deliberately scoped. It proves the
 * call is present in each route body and (F-96) that, in source order,
 * its refusal is returned before any success response is built. It does
 * NOT prove the gate is REACHED on every path at runtime — only an
 * integration test through the REST stack can, and that is tracked
 * separately. Claiming more than that here would be the false-confidence
 * this file is meant to remove.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

final class ProfileGateWiringTest extends TestCase
{
    private const REST_DIR = __DIR__ . '/../../app/Domain/Core/REST/';

    /**
     * Every REST tree the accounting scan walks, recursively.
     *
     * @var list<string>
     */
    private const REST_ROOTS = [
        __DIR__ . '/../../app/Domain/Core/REST/',
        __DIR__ . '/../../app/Domain/Onchain/REST/',
    ];

    /**
     * The inventory, maintained BY HAND on purpose. A new per-user route
     * must be added here deliberately, which is the moment someone has
     * to think about whether it needs the gate.
     *
     * ⚠ TEN call sites, TWELVE routes. Two handlers are shared, so one
     * guard covers two routes in each case:
     *   - `UsersEndpoint::handleListEndpoint` → `/reviews` + `/disputes`
     *   - `UserFollowsEndpoint::respond`      → `/followers` + `/following`
     * Counting guards and counting routes therefore give different
     * numbers, and conflating them is how "eleven" got repeated through
     * this work before the registrations were actually read.
     *
     * @var array<string, list<string>>
     */
    private const GATED_ROUTES = [
        'UsersEndpoint.php' => [
            'getUser',
            'blog',
            'activity',
            'endorsements',
            'handleListEndpoint',
            'shiftLog',
        ],
        'UserAlbumsEndpoint.php'      => ['getList'],
        'UserAlbumPhotosEndpoint.php' => ['getList'],
        'UserFollowsEndpoint.php'     => ['respond'],
        'UserGroupsEndpoint.php'      => ['getList'],
        // ⚠ `GET /cards/member/{handle}` — a per-user route hiding behind
        // a generic `/cards/{type}/{id}` dispatcher whose other three
        // types are pages, not people. It resolves its handle inside
        // CardViewService rather than through UserSlugResolver, so the
        // sweep that built this inventory did not see it and review
        // caught it instead. Added after the fact, which is the honest
        // record: this inventory is only as complete as the sweep behind
        // it, and that is its standing weakness.
        'CardsEndpoint.php'           => ['handle'],
        // ⚠ F-26: `GET /entities/{kind}/{id}/watchers` lists the page
        // OWNER's followers, so it is a per-user read. It resolves no handle
        // at all (page id → post_author), which is exactly the kind of
        // resolver the accounting scan below states it cannot see, and how
        // it stayed ungated. The gate lives in the service, where the owner
        // is resolved; the path is relative to REST_DIR.
        '../Services/CardWatchersService.php' => ['listWatchers'],
    ];

    /**
     * Method names, per file, that contain a real
     * `ProfileAccess::canViewProfile(` call.
     *
     * @return list<string>
     */
    private function methodsCallingGate(string $file): array
    {
        $src = file_get_contents(self::REST_DIR . $file);
        self::assertIsString($src, "could not read {$file}");

        $tokens = token_get_all($src);
        $method = null;
        $depth  = 0;
        $found  = [];

        foreach ($tokens as $i => $token) {
            // Track the enclosing function name. Good enough for this
            // shape of file (methods are not nested); a closure would
            // report its enclosing method, which is the honest answer
            // for a guard placed inside one.
            if (is_array($token) && $token[0] === T_FUNCTION) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $method = $tokens[$j][1];
                        break;
                    }
                    if (!is_array($tokens[$j]) && $tokens[$j] === '(') {
                        break; // anonymous function
                    }
                }
                continue;
            }

            // `ProfileAccess :: canViewProfile (`  — a CALL, not a
            // docblock mention and not a bare constant reference.
            //
            // ⚠ `::` is T_DOUBLE_COLON, an ARRAY token — NOT a
            // single-character string token. Only punctuation like
            // `(`, `;`, `{` arrives as a bare string. Testing
            // `$token === '::'` matches nothing, which makes the walk
            // silently find zero calls and every wiring assertion
            // vacuous. The control test below is what caught exactly
            // that during development.
            if (is_array($token) && $token[0] === T_DOUBLE_COLON) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === 'canViewProfile') {
                        $k = $j + 1;
                        while (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                            $k++;
                        }
                        // Must be an invocation, not a bare reference.
                        if (isset($tokens[$k]) && !is_array($tokens[$k]) && $tokens[$k] === '(' && $method !== null) {
                            $found[] = $method;
                        }
                    }
                    break;
                }
            }
        }

        unset($depth);

        return array_values(array_unique($found));
    }

    public function testEveryGatedRouteCallsTheProfileGate(): void
    {
        foreach (self::GATED_ROUTES as $file => $methods) {
            $calling = $this->methodsCallingGate($file);

            foreach ($methods as $method) {
                self::assertContains(
                    $method,
                    $calling,
                    "{$file}::{$method}() does NOT call ProfileAccess::canViewProfile(). "
                    . 'A per-user read route without the gate serves a "friends" or '
                    . '"only me" profile to a stranger — which is the entire defect '
                    . 'F-02 exists to close. Either wire the gate or remove the route '
                    . 'from this inventory deliberately.'
                );
            }
        }
    }

    /**
     * F-96: CALLING the gate is not HONOURING it. A route that calls
     * canViewProfile() but ignores the answer, or builds its success
     * response first, still passes the presence check above. Every gated
     * route must return the gate's refusal, and no success response may be
     * built before it. The success response is the work this pins against
     * because it is where the profile actually leaves the server.
     */
    public function testEveryGatedRouteReturnsTheRefusalBeforeAnySuccessResponse(): void
    {
        foreach (self::GATED_ROUTES as $file => $methods) {
            $code = GateOrder::code('app/Domain/Core/REST/' . $file);

            foreach ($methods as $method) {
                $label = "{$file}::{$method}()";
                $body  = GateOrder::body($code, $method);

                self::assertMatchesRegularExpression(
                    '/if \(!ProfileAccess::canViewProfile\([^)]*\)\) \{\s*return ProfileAccess::forbidden\(\);/',
                    $body,
                    "{$label}: the gate's refusal must be returned, not ignored"
                );
                GateOrder::assertNoWorkBefore(
                    $body,
                    'return ProfileAccess::forbidden();',
                    ['ApiResponse::ok(', '->respond('],
                    $label
                );
            }
        }
    }

    /**
     * ⚠⚠ THE GUARD AGAINST HOW THIS INVENTORY FAILS.
     *
     * `GATED_ROUTES` is hand-maintained, so it is only as complete as
     * whoever last swept the codebase — and the first sweep DID miss a
     * route (`/cards/member/{handle}`), because it enumerated callers of
     * `UserSlugResolver::resolve` while that route resolves its handle
     * through CardViewService's own private resolver instead. Review
     * caught it; this test is here so the next one does not need to.
     *
     * The rule: **any REST endpoint file that turns a handle into a user
     * id is a per-user route, and must either be inventoried above or
     * appear in `UNGATED_BY_DESIGN` with a reason.** There is no third
     * option, because a file that resolves a handle and does not gate is
     * exactly the defect F-02 closed.
     *
     * Both forward resolvers are matched. Reverse helpers (user id →
     * handle, as in UserViewService and NotificationDispatcher) are not
     * lookups and are irrelevant here.
     *
     * ⚠⚠ WHAT THIS CANNOT CATCH, stated plainly so nobody mistakes a
     * green run for a proof. It matches TWO LITERAL TOKENS —
     * `UserSlugResolver::resolve` and `resolveMemberCardHandle` — by
     * substring. It therefore does NOT see:
     *   - a THIRD resolver written tomorrow under some other name. That
     *     is exactly how `/cards/member/{handle}` escaped the first
     *     sweep, so the failure mode is proven, not theoretical;
     *   - a call reached through a variable, an alias, or a `::class`
     *     indirection;
     *   - an inline `get_users(['meta_key' => 'bcc_handle', ...])` that
     *     resolves a handle without going through either helper. There
     *     are three such lookups in the plugin today (UserSlugResolver,
     *     CardViewService, and ModerationQueueService which is
     *     capability-gated); a fourth added inline would be invisible
     *     here.
     * Adding a new way to turn a handle into a user id means adding its
     * token to this scan. The scan narrows the gap; it does not close it.
     */
    public function testEveryHandleResolvingEndpointIsAccountedFor(): void
    {
        /**
         * Endpoint files that resolve a handle but deliberately carry no
         * profile gate. Empty today — every such file is inventoried.
         * Adding an entry here is a deliberate, reviewable act.
         *
         * @var list<string>
         */
        $ungatedByDesign = [];

        // ⚠ RECURSIVE, and across EVERY REST tree — not just
        // Domain/Core/REST's top level. `Domain/Core/REST/Auth/` and
        // `Domain/Onchain/REST/` are real directories, and a flat
        // DirectoryIterator over one of them would have reported "all
        // accounted for" while never opening the others. A scan whose
        // blind spot is a subdirectory is the same failure as the sweep
        // that missed the member card in the first place.
        $found = [];

        foreach (self::REST_ROOTS as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $entry) {
                /** @var \SplFileInfo $entry */
                if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($entry->getPathname());
                if (
                    str_contains($src, 'UserSlugResolver::resolve')
                    || str_contains($src, 'resolveMemberCardHandle')
                ) {
                    $found[] = $entry->getFilename();
                }
            }
        }

        $found = array_values(array_unique($found));

        sort($found);

        self::assertNotEmpty(
            $found,
            'no endpoint file appears to resolve a handle at all — the '
            . 'scan is broken, so this assertion would be vacuous'
        );

        foreach ($found as $file) {
            self::assertTrue(
                array_key_exists($file, self::GATED_ROUTES) || in_array($file, $ungatedByDesign, true),
                "{$file} resolves a handle to a user id but is NOT in this test's "
                . 'inventory and NOT in $ungatedByDesign. That means a per-user '
                . 'route may have been added without a profile gate — the exact '
                . 'way `/cards/member/{handle}` was missed. Add the route and its '
                . 'guard, or allowlist the file with a stated reason.'
            );
        }
    }

    /**
     * The control. Without it, a token walk that silently matched
     * NOTHING — a parser change, a renamed helper, an exception
     * swallowed in methodsCallingGate — would leave the test above
     * asserting over an empty expectation set and passing.
     *
     * `members` is a DIRECTORY listing, not a per-user route: it has no
     * single profile owner to gate on, and PeepSo's own user-search
     * already strips 40 and anon-20 users from it. So it is both a
     * genuine non-member of the inventory and a stable negative.
     */
    public function testAMethodWithoutTheGateIsNotReportedAsHavingIt(): void
    {
        $calling = $this->methodsCallingGate('UsersEndpoint.php');

        self::assertNotEmpty(
            $calling,
            'the token walk found NO calls at all — it is broken, and the '
            . 'wiring assertions above are therefore vacuous'
        );
        self::assertNotContains(
            'members',
            $calling,
            'members() is a directory listing with no single profile owner; '
            . 'if the walk reports it as gated, the walk is over-matching '
            . '(e.g. counting docblock mentions) and cannot be trusted'
        );
    }

    /**
     * A docblock `{@see ProfileAccess::canViewProfile()}` must NOT count
     * as a call. Pinned against a synthetic source rather than a real
     * file so it keeps testing the parser after the real files change.
     */
    public function testADocblockMentionDoesNotCountAsACall(): void
    {
        $src = <<<'PHP'
        <?php
        class Fake
        {
            /** {@see ProfileAccess::canViewProfile()} explains the rule. */
            public function mentionsOnly(): void
            {
                // ProfileAccess::canViewProfile($id) in a comment, too.
            }
        }
        PHP;

        // ⚠ This test was itself VACUOUS on first write: it counted with
        // `$t === '::'`, which never matches (T_DOUBLE_COLON is an array
        // token), so it asserted 0 against a counter that could only ever
        // be 0. Hence the positive control below — the same source also
        // contains ONE real call, so the expectation is "exactly 1", and
        // a counter that cannot count fails instead of passing.
        $srcWithRealCall = $src . <<<'PHP'

        class Real
        {
            public function gated(int $id): void
            {
                if (!ProfileAccess::canViewProfile($id)) {
                    return;
                }
            }
        }
        PHP;

        self::assertSame(
            0,
            $this->countGateCalls($src),
            'a docblock/comment mention tokenizes as T_DOC_COMMENT or '
            . 'T_COMMENT, so it must never be counted as a call'
        );
        self::assertSame(
            1,
            $this->countGateCalls($srcWithRealCall),
            'the counter must find a REAL call — otherwise the zero above '
            . 'proves nothing about docblocks and only that the walk is broken'
        );
    }

    /**
     * The same token rule as methodsCallingGate, over a source string:
     * T_DOUBLE_COLON → `canViewProfile` → `(`.
     */
    private function countGateCalls(string $src): int
    {
        $tokens = token_get_all($src);
        $calls  = 0;

        foreach ($tokens as $i => $t) {
            if (!is_array($t) || $t[0] !== T_DOUBLE_COLON) {
                continue;
            }
            $j = $i + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue;
            }
            if ($tokens[$j][1] !== 'canViewProfile') {
                continue;
            }
            $k = $j + 1;
            while (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                $k++;
            }
            if (isset($tokens[$k]) && !is_array($tokens[$k]) && $tokens[$k] === '(') {
                $calls++;
            }
        }

        return $calls;
    }
}
