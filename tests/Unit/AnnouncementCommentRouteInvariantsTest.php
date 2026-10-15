<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementViewerStanding as Standing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A5b — the five comment routes, the standing resolver, and the tombstone boundary.
 *
 * ── ⚠⚠ THE DEFECT THIS FILE EXISTS TO MAKE IMPOSSIBLE ──────────────────────
 * `AnnouncementCapabilityResolver::forRow()` reads the viewer's standing out of
 * an array its CALLER builds, and it fails closed on an absent key. The only
 * caller shipped before A5b returned `['authenticated' => $viewerId > 0]` —
 * three of the four keys were simply missing, so `suspended` read as TRUE and
 * `good_standing` as FALSE for every viewer alive. `can_comment` could not be
 * `allowed` through any live route, for anyone.
 *
 * Nothing caught it, and nothing could have: the resolver was right about
 * failing closed, the endpoint was right about the one key it supplied, and no
 * test compared the two files. {@see testEveryDenyOnAbsenceKeyIsSupplied} is
 * that comparison, and it is DERIVED FROM THE RESOLVER'S OWN SOURCE rather
 * than from a list restated here — so a fifth deny-on-absence key added to the
 * resolver tomorrow fails this suite until a producer supplies it.
 *
 * ── WHAT CAN AND CANNOT BE EXECUTED HERE ──────────────────────────────────
 * The anonymous branch of `forViewer()` returns before touching WordPress, so
 * it is EXECUTED. The authenticated branch reaches `Permissions`, PeepSo and
 * the reputation repository, none of which exist in the unit harness — it is
 * pinned structurally, and the live evidence is
 * `scripts/tests/announcement-comment-routes.php` (real dispatch, five-state
 * control). This file holds what source can prove.
 *
 * ⚠ Every needle below is matched against COMMENT-STRIPPED code. The docblocks
 * in these files quote the very constructs the tests forbid — the word
 * `unset()`, `AuthSupport`, `in_array` — so a raw `substr_count` would report
 * their own documentation as a violation.
 */
#[Group('unit')]
final class AnnouncementCommentRouteInvariantsTest extends TestCase
{
    private const STANDING = 'app/Domain/Core/Announcements/AnnouncementViewerStanding.php';
    private const RESOLVER = 'app/Domain/Core/Announcements/AnnouncementCapabilityResolver.php';
    private const SERVICE  = 'app/Domain/Core/Services/AnnouncementCommentService.php';
    private const ENDPOINT = 'app/Domain/Core/REST/AnnouncementCommentsEndpoint.php';
    private const READS    = 'app/Domain/Core/REST/AnnouncementsEndpoint.php';

    /** The four keys a complete standing array carries, sorted. */
    private const KEYS = ['authenticated', 'fraud_locked', 'good_standing', 'suspended'];

    private static function src(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $s    = file_get_contents($path);

        self::assertIsString($s, 'could not read ' . $rel);
        self::assertGreaterThan(1500, strlen($s), $rel . ' is implausibly short — wrong path?');

        return $s;
    }

    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(self::src($rel)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /** The comment-stripped body of one method, brace-balanced. */
    private static function body(string $rel, string $method): string
    {
        $code  = self::code($rel);
        $start = strpos($code, 'function ' . $method . '(');
        self::assertNotFalse($start, $method . '() not found in ' . $rel);

        $open = strpos($code, '{', $start);
        self::assertNotFalse($open, $method . '() has no body in ' . $rel);

        $depth = 0;
        for ($i = $open, $n = strlen($code); $i < $n; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        self::fail($method . '() body is unbalanced in ' . $rel);
    }

    // ── 1. the anonymous branch, EXECUTED ────────────────────────────────

    public function testAnAnonymousViewerGetsAllFourKeys(): void
    {
        $keys = array_keys(Standing::forViewer(0));
        sort($keys);

        self::assertSame(self::KEYS, $keys, 'a partial array is the defect this class replaces');
    }

    public function testAnAnonymousViewerIsNotAuthenticatedAndOtherwiseNeutral(): void
    {
        // ⚠ Neutral, NOT suspended. The resolver checks `authenticated` first,
        // so a visitor is told `auth_required` — "sign in" — rather than an
        // alarming `suspended` about an account that does not exist.
        self::assertSame(
            [
                'authenticated' => false,
                'suspended'     => false,
                'fraud_locked'  => false,
                'good_standing' => false,
            ],
            Standing::forViewer(0)
        );
    }

    #[DataProvider('nonPositiveIds')]
    public function testEveryNonPositiveIdTakesTheAnonymousBranch(int $id): void
    {
        self::assertSame(Standing::forViewer(0), Standing::forViewer($id));
    }

    /** @return array<string, array{int}> */
    public static function nonPositiveIds(): array
    {
        return ['zero' => [0], 'minus one' => [-1], 'far negative' => [-999999]];
    }

    public function testEveryValueIsARealBooleanNotATruthyScalar(): void
    {
        // A `'0'` or `''` would satisfy the resolver's `flag()` by accident
        // today and stop satisfying it the moment that helper tightens.
        foreach (Standing::forViewer(0) as $key => $value) {
            self::assertIsBool($value, $key . ' is not a bool');
        }
    }

    // ── 2. the cross-file comparison that would have caught the gap ──────

    public function testEveryDenyOnAbsenceKeyIsSupplied(): void
    {
        $needed = self::denyOnAbsenceKeys();

        self::assertNotEmpty($needed, 'parsed no $viewer reads — the regex has drifted');

        $supplied = array_keys(Standing::forViewer(0));
        sort($supplied);
        sort($needed);

        self::assertSame(
            $needed,
            $supplied,
            'the resolver denies when these keys are absent, so the producer must supply exactly them'
        );
    }

    public function testAKeyWhoseAbsenceAllowsIsDeliberatelyNotSupplied(): void
    {
        // `already_reported` is read from the same array with a FALSE default
        // and used unnegated, so absence means "not yet reported" and ALLOWS.
        // It is a dedupe convenience owned by the reporting slice, not a
        // standing fact, and this producer does not invent one.
        $all = self::viewerReads();

        self::assertArrayHasKey('already_reported', $all, 'the reporting slice no longer reads it');
        self::assertFalse(
            $all['already_reported'],
            'already_reported now denies on absence, so it must be supplied'
        );
        self::assertArrayNotHasKey('already_reported', Standing::forViewer(0));
    }

    /**
     * Every `$viewer` key the resolver reads, mapped to whether ABSENCE denies.
     *
     * Absence yields the declared default; a negated read denies on `false`,
     * an unnegated read denies on `true`.
     *
     * @return array<string, bool>
     */
    private static function viewerReads(): array
    {
        preg_match_all(
            '~(!?)self::flag\(\$viewer, \'([a-z_]+)\', (true|false)\)~',
            self::code(self::RESOLVER),
            $m,
            PREG_SET_ORDER
        );

        $out = [];
        foreach ($m as $hit) {
            $negated = $hit[1] === '!';
            $default = $hit[3] === 'true';

            $out[$hit[2]] = $negated ? !$default : $default;
        }

        return $out;
    }

    /** @return list<string> */
    private static function denyOnAbsenceKeys(): array
    {
        return array_values(array_keys(array_filter(self::viewerReads())));
    }

    public function testTheResolverStillFailsClosedOnAllFourKeys(): void
    {
        // The producer's completeness is only worth something while the
        // resolver's defaults still deny. If a default flips to permissive,
        // this suite's central guarantee quietly stops meaning anything.
        $reads = self::viewerReads();

        foreach (self::KEYS as $key) {
            self::assertArrayHasKey($key, $reads, $key . ' is no longer read by the resolver');
            self::assertTrue($reads[$key], $key . ' no longer denies when absent');
        }
    }

    public function testTheCommentGateItselfConsultsEveryStandingFlag(): void
    {
        // ⚠ M5 SURVIVED the first mutation run, and the reason is worth
        // keeping: {@see testTheResolverStillFailsClosedOnAllFourKeys} asks
        // whether a key is read ANYWHERE in the resolver, and `report()` reads
        // three of the same four. So the COMMENT gate could stop checking
        // suspension altogether while that case stayed green.
        //
        // This one is scoped to the comment producer, which is the only
        // producer A5b's routes consult. `report()` deliberately reads a
        // different set — it has no `good_standing` rung — so the two cannot
        // share an assertion.
        $gate = self::body(self::RESOLVER, 'comment');

        foreach (self::KEYS as $key) {
            self::assertSame(
                1,
                substr_count($gate, "'" . $key . "'"),
                'the comment gate must consult ' . $key . ' exactly once'
            );
        }

        // And the operator's own switch, which is a row fact rather than a
        // viewer fact but belongs to the same refusal ladder.
        self::assertStringContainsString("'comments_enabled'", $gate);
    }

    public function testTheCommentGateAppliesItsRungsInTheDocumentedOrder(): void
    {
        // §4.32.7's ladder: feature, then availability, then archival, then
        // the operator's switch, then the viewer. An authenticated check
        // sitting above `comments_disabled` would tell a signed-out visitor
        // to sign in for a thread nobody may comment on.
        $gate  = self::body(self::RESOLVER, 'comment');
        $order = [];

        foreach ([
            'REASON_FEATURE_DISABLED',
            'REASON_UNAVAILABLE',
            'REASON_ARCHIVED',
            'REASON_COMMENTS_DISABLED',
            'REASON_AUTH_REQUIRED',
        ] as $reason) {
            $at = strpos($gate, $reason);
            self::assertNotFalse($at, $reason . ' is not in the comment gate');
            $order[] = $at;
        }

        $sorted = $order;
        sort($sorted);

        self::assertSame($sorted, $order, 'the refusal ladder is out of documented order');
    }

    // ── 3. the authenticated branch, structurally ───────────────────────

    public function testBothReturnSitesCarryAllFourKeys(): void
    {
        $body = self::body(self::STANDING, 'forViewer');

        self::assertSame(2, substr_count($body, 'return ['), 'expected exactly two return sites');

        foreach (self::KEYS as $key) {
            self::assertSame(
                2,
                substr_count($body, "'" . $key . "'"),
                $key . ' is not named on both return sites'
            );
        }
    }

    #[DataProvider('standingSources')]
    public function testEachFlagComesFromItsEstablishedSource(string $needle, string $why): void
    {
        self::assertStringContainsString($needle, self::code(self::STANDING), $why);
    }

    /** @return array<string, array{string, string}> */
    public static function standingSources(): array
    {
        return [
            // ⚠ `, false` is the admin bypass DISABLED — the same call
            // CommentService::create() and MessagesService make. A suspended
            // administrator is still suspended for the purpose of writing.
            'suspension through Permissions with the admin bypass off' => [
                'is_not_suspended($viewerId, false)',
                'suspension must route through the cross-plugin seam with the bypass off',
            ],
            'fraud lock is PeepSo ban and nothing else' => [
                "get_user_role() === 'ban'",
                'fraud lock must mirror MessagesService::senderRestricted()',
            ],
            'good standing through the canonical mapping' => [
                'UserViewService::isInGoodStanding(',
                'the tier-to-standing mapping has exactly one owner',
            ],
            'the tier itself from the reputation repository' => [
                'reputationRepository()->getTier(',
                'the tier has exactly one source',
            ],
        ];
    }

    #[DataProvider('forbiddenInStanding')]
    public function testTheStandingResolverRestatesNothing(string $needle, string $why): void
    {
        self::assertSame(0, substr_count(self::code(self::STANDING), $needle), $why);
    }

    /** @return array<string, array{string, string}> */
    public static function forbiddenInStanding(): array
    {
        return [
            // UserViewService::isInGoodStanding() owns the list and says so in
            // its own docblock. Duplicating it here is the §11 violation that
            // docblock exists to prevent.
            'the tier list is not restated' => [
                'in_array',
                'the tier list belongs to UserViewService alone',
            ],
            'the tier constant is not re-read' => [
                'GOOD_STANDING_TIERS',
                'reading the constant directly re-implements the mapping',
            ],
            'no tier literal appears at all' => [
                "'trusted'",
                'a tier literal here is a second source of truth',
            ],
            // ⚠ AuthSupport::resolveInGoodStanding() wraps the same lookup and
            // fails OPEN, for a stated reason that does not transfer: it
            // decorates a login response, whereas this gates a WRITE.
            'the fail-open helper is not reused' => [
                'AuthSupport',
                'AuthSupport fails open; a write gate must not',
            ],
            'standing is never read off the request' => [
                'WP_REST_Request',
                'standing is a server fact, never a parameter',
            ],
        ];
    }

    #[DataProvider('failClosedDirections')]
    public function testEachLookupFailsClosedInItsOwnDirection(string $method, string $onError): void
    {
        $body = self::body(self::STANDING, $method);

        self::assertStringContainsString('catch (', $body, $method . ' has no catch arm');
        self::assertStringContainsString(
            'return ' . $onError . ';',
            $body,
            $method . ' must return ' . $onError . ' on an unreadable lookup'
        );
    }

    /**
     * ⚠ "Fail closed" is a DIFFERENT literal per flag, which is exactly why
     * this is a provider and not one assertion: `suspended` and `fraud_locked`
     * deny by being TRUE, `good_standing` denies by being FALSE. A single
     * copy-pasted `return false` would silently un-suspend everyone whose
     * suspension lookup happened to fail.
     *
     * @return array<string, array{string, string}>
     */
    public static function failClosedDirections(): array
    {
        return [
            'an unreadable suspension state counts as suspended'  => ['isSuspended', 'true'],
            'an unreadable PeepSo role counts as locked'          => ['isFraudLocked', 'true'],
            'an unreadable tier is not evidence of good standing' => ['isInGoodStanding', 'false'],
        ];
    }

    public function testTheReadEndpointDelegatesRatherThanRebuilding(): void
    {
        $body = self::body(self::READS, 'viewerStanding');

        self::assertStringContainsString('AnnouncementViewerStanding::forViewer(', $body);
        self::assertStringNotContainsString(
            "'authenticated'",
            $body,
            'the read endpoint must not hand-build a standing array again'
        );
    }

    // ── 4. the tombstone privacy boundary ───────────────────────────────

    public function testTheTombstoneBranchBuildsAFreshArrayRatherThanRemovingFields(): void
    {
        // An `unset()`-based tombstone leaks every field a later change adds
        // and forgets to remove. There is no unset anywhere in the service.
        self::assertSame(0, substr_count(self::code(self::SERVICE), 'unset('));
    }

    public function testTheTombstoneBranchCarriesExactlyFourKeys(): void
    {
        $returns = self::returnLiterals(self::body(self::SERVICE, 'shapeRow'));

        self::assertCount(2, $returns, 'shapeRow should have exactly two array returns');

        $tombstone = $returns[0];
        self::assertStringContainsString(
            "'is_removed' => true",
            $tombstone,
            'the first return is not the tombstone — has the branch order changed?'
        );

        foreach (['id', 'is_removed', 'removed_at', 'label'] as $key) {
            self::assertStringContainsString("'" . $key . "'", $tombstone, $key . ' missing');
        }

        self::assertSame(4, substr_count($tombstone, '=>'), 'a tombstone carries exactly four keys');
    }

    #[DataProvider('fieldsAbsentFromATombstone')]
    public function testTheTombstoneNamesNoneOfTheVisibleFields(string $field): void
    {
        $tombstone = self::returnLiterals(self::body(self::SERVICE, 'shapeRow'))[0];

        self::assertStringNotContainsString(
            "'" . $field . "'",
            $tombstone,
            $field . ' must not appear in a tombstone'
        );
    }

    /** @return array<string, array{string}> */
    public static function fieldsAbsentFromATombstone(): array
    {
        return [
            // The removed body is the obvious one — but the author block, the
            // timestamp and the per-viewer permissions are equally gone,
            // because a tombstone is a POSITION MARKER and not a comment.
            'the removed body'   => ['body'],
            'the author block'   => ['author'],
            'the posting time'   => ['posted_at'],
            'viewer permissions' => ['permissions'],
            'the removal actor'  => ['removed_actor'],
        ];
    }

    public function testTheTombstoneLabelComesFromTheClosedSetNotTheDatabase(): void
    {
        $body = self::body(self::SERVICE, 'shapeRow');

        self::assertStringContainsString('Policy::labelFor(', $body, 'the label must come from the policy');
        self::assertStringContainsString(
            'Policy::LABEL_AUTHOR',
            $body,
            'an unrecognised stored actor needs a constant fallback, not the stored value'
        );

        $tombstone = self::returnLiterals($body)[0];
        self::assertStringNotContainsString(
            '$row->removed_actor',
            $tombstone,
            'a caption must never be echoed out of the database'
        );
    }

    /**
     * Every top-level `return [ ... ];` literal in a method body, in order.
     *
     * @return list<string>
     */
    private static function returnLiterals(string $body): array
    {
        $out    = [];
        $offset = 0;

        while (($start = strpos($body, 'return [', $offset)) !== false) {
            $open  = strpos($body, '[', $start);
            $depth = 0;
            $found = false;

            for ($i = $open, $n = strlen($body); $i < $n; $i++) {
                if ($body[$i] === '[') {
                    $depth++;
                } elseif ($body[$i] === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $out[]  = substr($body, $open, $i - $open + 1);
                        $offset = $i + 1;
                        $found  = true;
                        break;
                    }
                }
            }

            self::assertTrue($found, 'unbalanced return literal');
        }

        return $out;
    }

    // ── 5. administrator power is RECOVERY, not moderation ──────────────

    public function testAnAdministratorFlagReachesOnlyTheRestorePath(): void
    {
        // §4.32.7 gives administrators "— (restore only)". An admin who is
        // neither the author nor the operator gets the same 403 as anyone.
        //
        // ⚠ Checked as a SPAN over the whole file, not as an absence from
        // `remove()`'s body. An earlier version asked `body('remove')` not to
        // mention the flag — and `body()` begins at the opening brace, so a
        // mutation that added `bool $actorIsAdministrator` to remove()'s
        // SIGNATURE survived. A parameter is the flag reaching the removal
        // path just as surely as a statement is.
        $code = self::code(self::SERVICE);
        $span = self::methodSpan($code, 'adminRestore');

        $offset = 0;
        $seen   = 0;

        while (($at = strpos($code, 'actorIsAdministrator', $offset)) !== false) {
            $seen++;
            self::assertGreaterThanOrEqual(
                $span[0],
                $at,
                'an administrator flag appears before adminRestore() — removal must not consult one'
            );
            self::assertLessThan(
                $span[1],
                $at,
                'an administrator flag appears after adminRestore() — removal must not consult one'
            );
            $offset = $at + 1;
        }

        self::assertGreaterThan(0, $seen, 'the flag has vanished entirely — restore cannot be gated');
    }

    /**
     * The byte span of a method's declaration AND body, so a check cannot miss
     * a parameter the way a body-only check does.
     *
     * @return array{int, int}
     */
    private static function methodSpan(string $code, string $method): array
    {
        $start = strpos($code, 'function ' . $method . '(');
        self::assertNotFalse($start, $method . ' not found');

        $open  = strpos($code, '{', $start);
        $depth = 0;

        for ($i = $open, $n = strlen($code); $i < $n; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$start, $i + 1];
                }
            }
        }

        self::fail($method . ' body is unbalanced');
    }

    public function testTheServiceNeverReadsACapabilityItself(): void
    {
        // The administrator fact arrives as a parameter the endpoint resolved,
        // so the service stays testable without a WordPress user.
        $code = self::code(self::SERVICE);

        self::assertSame(0, substr_count($code, 'manage_options'));
        self::assertSame(0, substr_count($code, 'current_user_can('));
        self::assertSame(0, substr_count($code, 'get_current_user_id('));
    }

    public function testRestoreIsRefusedByThePolicyAndNotOnlyByThePermissionCallback(): void
    {
        // Belt and braces on purpose: the route already requires an admin, but
        // the rule "authors cannot restore" is then testable without a request.
        self::assertStringContainsString(
            'Policy::mayRestore(',
            self::body(self::SERVICE, 'adminRestore')
        );
    }

    public function testEveryRemovalDenialUsesTheSameGenericReason(): void
    {
        $body = self::body(self::SERVICE, 'remove');

        self::assertStringContainsString('REASON_NOT_COMMENT_AUTHOR', $body);
        // A stranger, a signed-out visitor and a member who simply did not
        // write the comment must be indistinguishable in the response.
        self::assertSame(
            0,
            substr_count($body, 'not_operator') + substr_count($body, 'auth_required'),
            'a removal denial must not reveal WHY the viewer is not entitled'
        );
    }

    public function testTheServiceOwnsNoSql(): void
    {
        self::assertSame(0, substr_count(self::code(self::SERVICE), 'wpdb'), '§1 — SQL lives in the repository');
    }

    // ── 6. route and permission postures ───────────────────────────────

    public function testAllFiveRoutesAreRegistered(): void
    {
        self::assertSame(5, substr_count(self::code(self::ENDPOINT), 'register_rest_route('));
    }

    public function testThreeMemberRoutesGateInTheHandlerAndTwoAdminRoutesGateInTheCallback(): void
    {
        $code = self::code(self::ENDPOINT);

        // ⚠ The asymmetry is deliberate and documented: a permission-callback
        // denial emits WordPress's OWN 401/403 envelope, which would disclose
        // the surface and bypass §4.32.2's generic-reason rule. That argument
        // does not apply to an explicitly administrative path.
        self::assertSame(3, substr_count($code, '__return_true'), 'three member routes');
        self::assertSame(2, substr_count($code, 'requireAdmin'), 'two administrator routes');
        self::assertSame(5, substr_count($code, "'permission_callback'"), 'every route declares one');
    }

    #[DataProvider('everyHandler')]
    public function testTheFeatureGateIsTheFirstStatementOfEveryHandler(string $handler): void
    {
        $body = self::body(self::ENDPOINT, $handler);

        // §4.32.10 — 404 while off, before any authority resolution, so a
        // disabled feature cannot be told apart from a missing route.
        $firstStatement = substr($body, 0, (int) strpos($body, ')') + 1);

        self::assertStringContainsString(
            '!Feature::isEnabled()',
            $firstStatement,
            $handler . ' resolves something before checking the switch'
        );
        self::assertStringContainsString('Feature::notFound()', $body, $handler . ' must answer 404');
    }

    /** @return array<string, array{string}> */
    public static function everyHandler(): array
    {
        return [
            'the thread'           => ['index'],
            'creating a comment'   => ['create'],
            'removing a comment'   => ['remove'],
            'the private original' => ['adminShow'],
            'restoring a comment'  => ['adminRestore'],
        ];
    }

    public function testTheBodyFieldIsDeliberatelyNotSchemaRequired(): void
    {
        // ⚠ A schema-`required` field is validated BEFORE the handler runs, so
        // omitting it would answer 400 while the feature is OFF — the §4.32.10
        // divergence `status` and `title` already carry on the create route.
        // This route does not add a third: the handler refuses the body, after
        // the feature gate.
        $norm = (string) preg_replace('~\s+~', ' ', self::code(self::ENDPOINT));
        $at   = strpos($norm, "'body' => [");

        self::assertNotFalse($at, "the 'body' argument is not declared");
        self::assertStringContainsString(
            "'required' => false",
            substr($norm, $at, 80),
            "'body' must not be schema-required"
        );
    }

    public function testAnyPresentParentIdIsRefusedByTheHandler(): void
    {
        $code = self::code(self::ENDPOINT);

        // Accepted in the schema so the handler can answer the contract's own
        // `replies_not_supported` instead of `rest_invalid_param`...
        self::assertStringContainsString("'parent_id'", $code);
        // ...and tested by PRESENCE, not value, so `0` and `''` are refused too.
        self::assertStringContainsString("has_param('parent_id')", $code);
    }

    public function testEveryPathIdentifierIsBareNumeric(): void
    {
        $code = self::code(self::ENDPOINT);

        preg_match_all("~'(/[^']*)'~", $code, $m);
        self::assertNotEmpty($m[1], 'parsed no route patterns');

        foreach ($m[1] as $pattern) {
            // `acmt_` and `ann_` are VIEW-MODEL prefixes; a client holding one
            // strips it before building a URL. §4.32.4 states the rule
            // normatively after a worked example drifted the other way.
            self::assertStringNotContainsString('acmt_', $pattern, 'a view-model prefix is not a path segment');
            self::assertStringNotContainsString('ann_', $pattern, 'a view-model prefix is not a path segment');

            // ⚠ Checked by REMOVING the two permitted spellings and then
            // demanding nothing captured is left, rather than by a regex.
            // A regex needing an escaped backslash is exactly the needle
            // that silently matches the wrong thing — the first version of
            // this case asserted `(?P<id>` followed by a DIGIT, and passed
            // review. Two literal needles cannot drift that way, and they
            // pin the two permitted ids BY NAME rather than by shape.
            $stripped = str_replace(
                ['(?P<id>\d+)', '(?P<commentId>\d+)'],
                '',
                $pattern
            );

            self::assertStringNotContainsString(
                '(?P<',
                $stripped,
                'a captured id must be exactly \d+, found: ' . $pattern
            );
        }
    }

    public function testEveryResponseIsPrivateAndVariesOnTheCredential(): void
    {
        $code = self::code(self::ENDPOINT);

        // The thread is mostly public but viewer-DEPENDENT: `can_comment` and
        // each row's `can_remove` differ per viewer, so a shared cache entry
        // would hand one member another member's permissions.
        self::assertSame(5, substr_count($code, 'private, no-store'));
        self::assertSame(5, substr_count($code, 'Authorization, Cookie'));
        self::assertSame(0, substr_count($code, 'max-age'), 'no comment surface may be shared-cached');
    }

    public function testEveryIntegerArgumentDeclaresItsOwnValidator(): void
    {
        // ⚠ The `/members` defect: `sanitize_params()` substitutes the
        // schema-enforcing `rest_parse_request_arg` only for an argument that
        // declares NO `sanitize_callback`, so bounds sitting beside a
        // sanitizer are decorative. Every integer arg declares an explicit
        // validator, so `minimum`/`maximum` actually bind.
        $norm = (string) preg_replace('~\s+~', ' ', self::code(self::ENDPOINT));

        $bounded = substr_count($norm, "'maximum'") + substr_count($norm, "'minimum'");
        self::assertGreaterThan(0, $bounded, 'no bounded arguments found — has the schema changed?');

        // ⚠ Per ARGUMENT, not per integer argument. An earlier version of
        // this case compared the integer-typed count against the validator
        // count and failed — correctly, because `cursor` and `body` are
        // strings that declare validators too. The invariant is that EVERY
        // declared argument carries one.
        $declared = substr_count($norm, "'type' =>");
        self::assertSame(5, $declared, 'expected five declared arguments');

        self::assertSame(
            $declared,
            substr_count($norm, "'validate_callback' => 'rest_validate_request_arg'"),
            'every declared argument must carry an explicit validator'
        );
    }

    public function testTheEndpointOwnsNoSqlAndNoPolicy(): void
    {
        $code = self::code(self::ENDPOINT);

        self::assertSame(0, substr_count($code, 'wpdb'));
        // Removal entitlement, captions and audit actions are the policy's.
        self::assertSame(0, substr_count($code, 'LABEL_'));
        self::assertSame(0, substr_count($code, 'AUDIT_'));
    }

    // ── 7. the capability call PHPStan caught ──────────────────────────

    public function testTheCapabilityResolverIsCalledWithEveryParameterItDeclares(): void
    {
        // ⚠ `forRow()` was invoked with THREE arguments against a
        // four-parameter signature. PHP raises ArgumentCountError only when
        // the line actually runs, and for a capability block that means on a
        // real request — PHPStan caught it, no test did. This is that test.
        $declared = self::parameterCount(self::code(self::RESOLVER), 'forRow');
        $passed   = self::argumentCount(self::body(self::SERVICE, 'canComment'), 'Caps::forRow(');

        self::assertSame(4, $declared, 'forRow() no longer takes four parameters');
        self::assertSame($declared, $passed, 'the call site must pass every declared parameter');
    }

    public function testTheFeatureFlagIsPassedRatherThanReadInsideTheResolver(): void
    {
        // A capability answer must be a pure function of its inputs, so a
        // caller cannot get a different answer than the handler that gated it.
        $call = self::body(self::SERVICE, 'canComment');

        self::assertStringContainsString('AnnouncementFeature::isEnabled()', $call);
        self::assertSame(
            0,
            substr_count(self::body(self::RESOLVER, 'forRow'), 'isEnabled('),
            'the resolver must not read the switch itself'
        );
    }

    private static function parameterCount(string $code, string $method): int
    {
        $start = strpos($code, 'function ' . $method . '(');
        self::assertNotFalse($start, $method . ' not found');

        $open  = strpos($code, '(', $start);
        $depth = 0;

        for ($i = $open, $n = strlen($code); $i < $n; $i++) {
            if ($code[$i] === '(') {
                $depth++;
            } elseif ($code[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $inner = trim(substr($code, $open + 1, $i - $open - 1));

                    return $inner === '' ? 0 : substr_count($inner, '$');
                }
            }
        }

        self::fail($method . ' signature is unbalanced');
    }

    private static function argumentCount(string $body, string $call): int
    {
        $start = strpos($body, $call);
        self::assertNotFalse($start, $call . ' not found');

        $open     = $start + strlen($call) - 1;
        $depth    = 0;
        $topLevel = 0;

        for ($i = $open, $n = strlen($body); $i < $n; $i++) {
            $ch = $body[$i];

            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
                if ($depth === 0) {
                    $inner = trim(substr($body, $open + 1, $i - $open - 1));

                    return $inner === '' ? 0 : $topLevel + 1;
                }
            } elseif ($ch === ',' && $depth === 1) {
                $topLevel++;
            }
        }

        self::fail($call . ' is unbalanced');
    }
}
