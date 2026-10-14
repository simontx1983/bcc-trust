<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The A3 write guarantees, asserted over the source.
 *
 * ── ⚠⚠ WHY SOURCE-LEVEL AND NOT BEHAVIOURAL ─────────────────────────────────
 * Each claim here is an ABSENCE — no engagement write, no scoring write, no
 * auto-hide call, no cached authorization. An absence cannot be driven by a
 * test: there is no input that makes a missing call happen. The read side
 * learned this the hard way in the other direction, where a test counting
 * `catch` blocks and `unavailable()` calls stayed green while an early return
 * made the 503 unreachable — so the behavioural claims in this slice live in
 * `AnnouncementWritePathIntegrationTest` and the real-dispatch probe, against
 * real SQL and a real WP_REST_Server, and only the absences live here.
 *
 * ⚠ Every assertion reads the file from disk and asserts the file was FOUND
 * first. A source sweep whose path is wrong reports zero matches, which looks
 * exactly like a satisfied absence.
 */
#[Group('unit')]
final class AnnouncementWriteGuaranteesTest extends TestCase
{
    private const SERVICE  = 'app/Domain/Core/Services/AnnouncementWriteService.php';
    private const ENDPOINT = 'app/Domain/Core/REST/AnnouncementWriteEndpoint.php';
    private const POLICY   = 'app/Domain/Core/Announcements/AnnouncementTransition.php';

    private static function src(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $s    = file_get_contents($path);

        // ⚠ Anti-vacuity: a wrong path yields '' and every "must not contain"
        // assertion below would pass for the wrong reason.
        self::assertIsString($s, 'could not read ' . $rel);
        self::assertGreaterThan(2000, strlen($s), $rel . ' is implausibly short — wrong path?');

        return $s;
    }

    /** Comment-stripped, so a docblock mentioning a forbidden call is not a hit. */
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

    // ── the three absences the slice is required to hold ────────────────────

    /**
     * ⚠⚠ NO ENGAGEMENT. An announcement takes no stokes and no helpful marks,
     * and the guarantee was landed BEFORE any announcement could exist so there
     * is never a window in which one accumulates them. A write here that
     * "records activity" would reopen that window, and the helpful-mark path is
     * the dangerous one: it mints rank evidence crediting the content author,
     * so an open door moves a validator operator's own standing.
     */
    #[DataProvider('engagementSymbols')]
    public function testTheWriteLayerPerformsNoEngagementWrite(string $symbol): void
    {
        foreach ([self::SERVICE, self::ENDPOINT, self::POLICY] as $file) {
            self::assertStringNotContainsString(
                $symbol,
                self::code($file),
                $file . ' must not reach ' . $symbol
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function engagementSymbols(): iterable
    {
        yield 'activity stream writer' => ['ActivityStreamWriter'];
        yield 'peepso activity writer' => ['PeepSoActivityWriter'];
        yield 'stokes'                 => ['Stoke'];
        yield 'helpful marks'          => ['HelpfulMark'];
        yield 'reactions'              => ['ReactionGrammar'];
        yield 'feed cold start'        => ['FeedColdStart'];
        yield 'feed hydration'         => ['FeedHydration'];
    }

    /**
     * ⚠⚠ NO SCORING. A write must not move rank, score or tier state. These are
     * the tables that decide a member's standing, and an announcement is an
     * information billboard, not a contribution.
     */
    #[DataProvider('scoringSymbols')]
    public function testTheWriteLayerPerformsNoScoringWrite(string $symbol): void
    {
        foreach ([self::SERVICE, self::ENDPOINT, self::POLICY] as $file) {
            self::assertStringNotContainsString(
                $symbol,
                self::code($file),
                $file . ' must not reach ' . $symbol
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function scoringSymbols(): iterable
    {
        yield 'rank events'      => ['RankEvent'];
        yield 'rank service'     => ['RankService'];
        yield 'score events'     => ['ScoreEvent'];
        yield 'attestation'      => ['AttestationRepository'];
        yield 'tier days'        => ['tier_days'];
        yield 'page scores'      => ['PageScore'];
    }

    /**
     * ⚠⚠ NO AUTOMATIC REPORT-BASED HIDING. §4.32.16 accepted reportability
     * WITHOUT automatic hiding, and module 205's exclusion from
     * `AutoHideService` is the whole safety property. The write layer must not
     * call it in either direction — not to hide, and not to un-hide.
     */
    public function testTheWriteLayerNeverTouchesAutoHide(): void
    {
        foreach ([self::SERVICE, self::ENDPOINT, self::POLICY] as $file) {
            $code = self::code($file);
            self::assertStringNotContainsString('AutoHide', $code, $file);
            self::assertStringNotContainsString('mayAutoHide', $code, $file);
            self::assertStringNotContainsString('hidden_activities', $code, $file);
        }
    }

    // ── authorization freshness ─────────────────────────────────────────────

    /**
     * ⚠⚠ A FRESH DECISION PER ACTION. A claim can be revoked, or become
     * ambiguous, between two requests. A cached decision would let the second
     * action run on the first one's authority — and a static cache is exactly
     * the "optimisation" a later reader would add to a function called once per
     * request.
     */
    public function testAuthorizationIsResolvedFreshWithNoCache(): void
    {
        $code = self::code(self::ENDPOINT);

        self::assertStringContainsString(
            'Authority::decide(',
            $code,
            'the endpoint must resolve authority through the accepted producer'
        );
        // No static state of any kind in the endpoint: no memo array, no
        // property, no transient.
        self::assertDoesNotMatchRegularExpression(
            '/\bstatic\s+\$/',
            $code,
            'a static variable here would cache an authorization decision'
        );
        self::assertStringNotContainsString('wp_cache_set', $code);
        self::assertStringNotContainsString('set_transient', $code);
        self::assertStringNotContainsString('update_option', $code);
    }

    /**
     * ⚠ Every handler calls authorize() itself. A shared decision computed once
     * and passed between handlers would be the same defect as a cache.
     */
    public function testEveryHandlerResolvesItsOwnAuthority(): void
    {
        $code = self::code(self::ENDPOINT);

        $handlers = ['create', 'edit', 'publish', 'archive', 'pin'];
        foreach ($handlers as $h) {
            self::assertMatchesRegularExpression(
                '/function\s+' . $h . '\s*\(/',
                $code,
                'handler ' . $h . ' must exist'
            );
        }

        // One authorize() call per handler, and the count must match.
        self::assertSame(
            count($handlers),
            substr_count($code, 'self::authorize('),
            'each of the five handlers must resolve authority exactly once'
        );
    }

    /**
     * ⚠⚠ THE SWITCH IS FIRST, BEFORE AUTHORITY AND BEFORE ANY READ. A withdrawn
     * surface must not resolve a claim and must not write. Asserted by POSITION,
     * not merely by presence: the read side found that a feature check present
     * but placed after the authority call still leaks that the page is claimed.
     */
    #[DataProvider('handlerNames')]
    public function testTheFeatureCheckPrecedesAuthorityInEveryHandler(string $handler): void
    {
        $code = self::code(self::ENDPOINT);

        $start = strpos($code, 'function ' . $handler . '(');
        self::assertIsInt($start, 'handler not found: ' . $handler);

        // Bound the search to this handler's body by taking the slice up to the
        // next function declaration.
        $rest = substr($code, $start + 1);
        $next = strpos($rest, 'function ');
        $body = $next === false ? $rest : substr($rest, 0, $next);

        $feature = strpos($body, 'Feature::isEnabled()');
        $auth    = strpos($body, 'self::authorize(');

        self::assertIsInt($feature, $handler . ' must check the feature switch');
        self::assertIsInt($auth, $handler . ' must resolve authority');
        self::assertLessThan(
            $auth,
            $feature,
            $handler . ': the feature switch must be checked BEFORE authority is resolved'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function handlerNames(): iterable
    {
        yield 'create'  => ['create'];
        yield 'edit'    => ['edit'];
        yield 'publish' => ['publish'];
        yield 'archive' => ['archive'];
        yield 'pin'     => ['pin'];
    }

    // ── privacy ─────────────────────────────────────────────────────────────

    /**
     * ⚠⚠ 404, NEVER 403, AND NEVER THE PRIVATE REASON. A non-operator must not
     * learn that a page has an announcements surface at all. The service's
     * refusal for a non-operator is the house not-found code, and the private
     * denial reasons (`claim_absent`, `claim_ambiguous`, `not_claimer`) must
     * never appear in the write layer's own source — they are the authority's
     * internal vocabulary.
     */
    /**
     * ⚠⚠ RECONCILED TO §4.32.2 — THIS CASE PREVIOUSLY ASSERTED THE OPPOSITE.
     *
     * It required 404 and forbade 403, on my reasoning that a 403 would tell a
     * prober which pages have an announcements surface. §4.32.2 is the
     * canonical rule and settles it the other way, with the reason stated:
     * *"Every ownership denial — no claim, a claim held by somebody else, or
     * competing claims — presents on the wire as `bcc_forbidden` with
     * `reason_code: not_claimer` and nothing else."*
     *
     * The disclosure concern is answered by the reason being GENERIC, not by
     * the status code — and the surface is already public anyway: the read list
     * is unauthenticated and the routes appear in `/wp-json/`. The owner read
     * route has always answered 403 this way, so the write routes were the
     * outlier, not the standard.
     */
    public function testANonOperatorRefusalIsAGenericForbidden(): void
    {
        $service = self::code(self::SERVICE);

        self::assertStringContainsString("'bcc_forbidden'", $service);
        self::assertStringContainsString(
            'Authority::PUBLIC_REASON',
            $service,
            'the serialised reason must be the authority s own generic constant, not a literal'
        );

        // ⚠ The half of the old case that was RIGHT, kept verbatim: the finer
        // internal reasons must still never be serialised. A distinguishable
        // claim_ambiguous would reveal which validators are contested, and a
        // distinguishable claim_absent which are unclaimed.
        foreach (['claim_absent', 'claim_ambiguous', 'auth_required'] as $private) {
            self::assertStringNotContainsString(
                "'" . $private . "'",
                $service,
                'the private denial reason ' . $private . ' must not be emitted by the write layer'
            );
        }

        // Feature-off and a genuinely missing row both stay 404, so the three
        // outcomes remain distinguishable to a legitimate operator.
        self::assertStringContainsString("'bcc_not_found'", $service);
    }

    /**
     * ⚠ The permission_callback is deliberately __return_true, because a
     * callback denial emits WordPress's OWN 401/403 envelope — which would
     * disclose the surface. The real gate is in the handler. This is pinned so
     * a reader "hardening" it does not silently reintroduce the disclosure.
     */
    public function testThePermissionCallbackIsDeliberatelyPermissiveAndTheGateIsInTheHandler(): void
    {
        $code = self::code(self::ENDPOINT);

        self::assertSame(
            5,
            substr_count($code, "'permission_callback' => '__return_true'"),
            'all five routes gate in the handler, not in the permission callback'
        );
        self::assertSame(
            5,
            substr_count($code, 'Feature::isEnabled()')
                - substr_count($code, 'Caps::forOwnerScope($decision, Feature::isEnabled())'),
            'each handler checks the switch once, plus the render-time capability call'
        );
    }

    // ── bounded arguments actually bind ─────────────────────────────────────

    /**
     * ⚠⚠ EVERY BOUNDED OR ENUMERATED ARG DECLARES validate_callback EXPLICITLY.
     * WordPress substitutes the schema-enforcing `rest_parse_request_arg` only
     * for an argument that declares NO `sanitize_callback`, so `minimum`,
     * `maximum` and `enum` sitting beside a sanitizer are DECORATIVE. That exact
     * defect shipped on `/bcc/v1/members`, which declared `maximum: 50` and
     * served a real `LIMIT 100000`.
     */
    public function testEveryArgBlockWithASanitizerAlsoDeclaresAValidator(): void
    {
        $src = self::src(self::ENDPOINT);

        $blocks = [];
        if (preg_match_all('/\[[^\[\]]*?(?:minimum|maximum|enum)[^\[\]]*?\]/s', $src, $m)) {
            $blocks = $m[0];
        }
        self::assertNotSame([], $blocks, 'no bounded arg blocks found — wrong pattern?');

        foreach ($blocks as $block) {
            if (strpos($block, 'sanitize_callback') !== false) {
                self::assertStringContainsString(
                    'validate_callback',
                    $block,
                    'a bounded arg with a sanitizer but no validator does NOT bind: ' . $block
                );
            }
        }
    }

    /**
     * The status enum is the three write verbs and nothing else — in particular
     * not `blocked`, which §4.32.13 defines as a state produced by reconciler
     * arm C. Rejecting it in the schema is what stops a refusal looking like a
     * successful write.
     */
    public function testTheStatusEnumIsTheVerbSetAndRejectsBlocked(): void
    {
        $code = self::code(self::ENDPOINT);

        self::assertStringContainsString("'enum'              => Verb::verbs()", $code);
        self::assertStringNotContainsString("'blocked'", $code);
    }

    // ── fail-closed ─────────────────────────────────────────────────────────

    /**
     * ⚠⚠ THE FAIL-CLOSED CORE, pinned structurally. On a marker-write failure
     * the service must remove any half-written marker, take the row out of a
     * public status, and then MEASURE non-visibility through the repository's
     * own public finder before reporting. The measurement is the part that
     * matters: without it the claim is a belief about predicate 3.
     */
    public function testAFailedMarkerWriteRevertsAndThenMeasuresNonVisibility(): void
    {
        $code = self::code(self::SERVICE);

        self::assertStringContainsString('failClosed(', $code);
        self::assertStringContainsString('delete_post_meta(', $code);
        self::assertStringContainsString('findPublic(', $code, 'non-visibility must be MEASURED, not assumed');

        $start = strpos($code, 'function failClosed(');
        self::assertIsInt($start);
        $body = substr($code, $start, 1400);

        self::assertStringContainsString('STATUS_DRAFT', $body, 'the row must be taken out of a public status');
        self::assertStringContainsString('findPublic(', $body, 'the finder must be consulted inside failClosed');
        self::assertStringContainsString('Logger::error', $body, 'a possible leak must be logged at error');
    }

    /**
     * ⚠ An unreadable confirmation is NOT a confirmation. If `findPublic()`
     * throws, the service must treat the row as possibly leaked rather than
     * report a clean failure it cannot substantiate.
     */
    public function testAnUnreadableConfirmationIsTreatedAsAPossibleLeak(): void
    {
        $code  = self::code(self::SERVICE);
        $start = strpos($code, 'function failClosed(');
        self::assertIsInt($start);
        $body = substr($code, $start, 1400);

        self::assertMatchesRegularExpression(
            '/catch\s*\(\s*RepositoryException[^)]*\)\s*\{\s*\$leaked\s*=\s*true/s',
            $body,
            'a RepositoryException during confirmation must set $leaked = true'
        );
    }

    /**
     * ⚠ A write is not confirmed until a read says so. The precedent is the
     * Cosmos endpoint switch: `updateRestUrl()` reported only "no DB error",
     * which is also true for zero rows matched.
     */
    public function testEverySuccessfulPublicationPathEndsInAReadBack(): void
    {
        $code = self::code(self::SERVICE);

        self::assertStringContainsString('readBackOrFail(', $code);
        self::assertGreaterThanOrEqual(
            2,
            substr_count($code, 'readBackOrFail('),
            'the read-back must be reached from more than one write path'
        );
        self::assertStringContainsString('findForOwner(', $code);
    }

    /**
     * ⚠ The actor is the post author, because predicate 3 compares the marker's
     * user id against `post_author` NUMERICALLY. Any other author makes the row
     * permanently unpublishable — a failure that would look like a storage bug.
     */
    public function testThePostAuthorIsTheActorSoPredicateThreeCanEverBeSatisfied(): void
    {
        $code = self::code(self::SERVICE);

        self::assertMatchesRegularExpression(
            "/'post_author'\s*=>\s*\\\$actorId/",
            $code,
            'post_author must be the acting operator'
        );
        self::assertMatchesRegularExpression(
            '/META_AUTHORIZED_USER_ID,\s*\(string\)\s*\$actorId/',
            $code,
            'the marker user id must be the same actor'
        );
    }

    /**
     * ⚠ post_password is never set. §4.32.1 records that
     * `post_password_required()` short-circuits to `edit_post` BEFORE
     * `check_read_permission()` runs, so a password is a REST bypass surface
     * rather than a privacy control.
     */
    public function testNoWritePathEverSetsAPostPassword(): void
    {
        $code = self::code(self::SERVICE);

        // ⚠ Asserted as "exactly one occurrence, and it is the empty one",
        // rather than with a negative lookahead. The first version of this case
        // used `assertDoesNotMatchRegularExpression` with `(?!'')` and failed
        // against correct code: a lookahead that must not match is two
        // negations stacked, and it is easier to get the test wrong than the
        // code. Counting is unambiguous.
        self::assertSame(
            1,
            substr_count($code, "'post_password'"),
            'post_password should be mentioned exactly once, in the create payload'
        );
        self::assertMatchesRegularExpression(
            "/'post_password'\s*=>\s*''/",
            $code,
            'the single post_password assignment must be the empty string'
        );
    }
}
