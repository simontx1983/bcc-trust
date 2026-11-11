<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The authoritative validator-page operator check — isolated permission
 * tests for every rung of the ladder.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. A CONTESTED PAGE PICKS A WINNER. Two verified operator claims on one
 *     page is AMBIGUOUS. Resolving it hands authorship of a validator's
 *     public voice to whichever row the query planner returned first. It must
 *     DENY, and it must deny for the ambiguity reason — not fall through to
 *     "you are not the claimer", which would read as a settled page.
 *  2. THE CALLER'S OWN CLAIM IS NEVER CHECKED. Every existing caller of the
 *     resolver uses it to find out WHERE a message goes. An authorization
 *     check must additionally bind the resolution to the ACTING VIEWER. A
 *     resolver that returns RESOLVED for operator #7 must deny viewer #9.
 *  3. THE DENIAL REASON LEAKS THE CLAIM STATE. `claim_absent`,
 *     `claim_ambiguous` and `not_claimer` are three different facts about a
 *     page's ownership. On the wire they must be ONE value, or an
 *     unauthenticated prober learns which validators are unclaimed and which
 *     are contested.
 *  4. A NON-VALIDATOR PAGE IS ACCEPTED. The resolver reads the validator leg
 *     only. A collection-backed page would come back UNCLAIMED and deny with
 *     `claim_absent` — "nobody owns this" — when the truth is "out of scope".
 *     Worse, widening to the collection leg (as the live page-image gate
 *     does) would let a verified CREATOR speak as a validator.
 *  5. A HALF-RESOLUTION IS TRUSTED. RESOLVED with a null or non-positive
 *     user id is a contradiction. Comparing it to the viewer id with loose
 *     typing is how `0 === 0` grants an anonymous viewer operator rights.
 *
 * ⚠ AMBIGUOUS IS NOT PREVENTED BY THE DATABASE. `ClaimRepository::upsert()`
 * carries only `UNIQUE KEY uq_user_entity (user_id, entity_type, entity_id)`,
 * which stops ONE user holding two rows on one entity and says nothing about
 * two DIFFERENT users both holding a verified operator claim on the same
 * validator. Exclusivity is application-level only — an advisory lock plus a
 * check-then-upsert inside `createExclusiveClaim()`, which the repository
 * documents as a deliberate choice because "MySQL does not support partial
 * unique indexes". So the contested state remains reachable through manual
 * SQL, a migration, or any row predating that code, and the branch that
 * handles it is load-bearing rather than defensive.
 *
 * These are unit tests with a doubled resolver because that is the only way to
 * place the fixture at the rung under test without writing a state the
 * application refuses to write. The resolver's own SQL is pinned separately in
 * {@see \BCC\Trust\Tests\Integration\VerifiedOperatorResolverStatesIntegrationTest}.
 *
 * Collaborators are doubled at their real FQCNs, so every case runs in its
 * own process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorPageOperatorAuthorityTest extends TestCase
{
    private const PAGE  = 4471;
    private const OWNER = 7;
    private const OTHER = 9;

    /** @var class-string */
    private const AUTH = \BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority::class;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/operator-authority-stubs.php';

        $GLOBALS['__bcc_a0_resolve_calls']  = 0;
        $GLOBALS['__bcc_a0_resolution']     = null;
        $GLOBALS['__bcc_a0_posts']          = [self::PAGE => self::publishedPage(self::PAGE)];
        $GLOBALS['__bcc_a0_validator_rows'] = [self::PAGE => (object) ['validator_id' => '12', 'chain_slug' => 'cosmoshub']];
    }

    private static function publishedPage(int $id, string $type = 'peepso-page', string $status = 'publish'): object
    {
        $p              = new \WP_Post();
        $p->ID          = $id;
        $p->post_type   = $type;
        $p->post_status = $status;
        return $p;
    }

    /** @param int|null $userId */
    private static function resolution(string $state, $userId): void
    {
        $GLOBALS['__bcc_a0_resolution'] = ['state' => $state, 'user_id' => $userId];
    }

    // ── 1. The one case that grants ────────────────────────────────────────

    public function testTheResolvedOperatorActingAsThemselvesIsAuthorized(): void
    {
        self::resolution('RESOLVED', self::OWNER);

        $d = (self::AUTH)::decide(self::PAGE, self::OWNER);

        self::assertSame('operator', $d['decision']);
        self::assertNull($d['reason']);
        self::assertSame(self::OWNER, $d['operator_user_id']);
        self::assertTrue((self::AUTH)::isOperator(self::PAGE, self::OWNER));
    }

    /**
     * The id is returned so the §4.32.1 publication path can stamp an
     * authorization marker without re-resolving. If it ever came back as
     * anything other than the acting viewer, the marker would be bound to
     * the wrong author and the three-predicate gate would be meaningless.
     */
    public function testTheReturnedOperatorIdIsTheActingViewerAndNothingElse(): void
    {
        self::resolution('RESOLVED', self::OWNER);

        $d = (self::AUTH)::decide(self::PAGE, self::OWNER);

        self::assertSame(self::OWNER, $d['operator_user_id']);
    }

    // ── 2. Viewer binding — MUST-NOT-HAPPEN #2 ────────────────────────────

    public function testAResolvedPageDeniesEveryViewerWhoIsNotThatOperator(): void
    {
        self::resolution('RESOLVED', self::OWNER);

        $d = (self::AUTH)::decide(self::PAGE, self::OTHER);

        self::assertSame('denied', $d['decision']);
        self::assertSame('not_claimer', $d['reason']);
        self::assertNull($d['operator_user_id']);
        self::assertFalse((self::AUTH)::isOperator(self::PAGE, self::OTHER));
    }

    // ── 3. Ambiguity — MUST-NOT-HAPPEN #1 ─────────────────────────────────

    public function testAContestedPageDeniesTheRivalWhoWouldHaveWonOnRowOrder(): void
    {
        // The resolver reports AMBIGUOUS with user_id null — it refuses to
        // name a winner. The authority must not invent one.
        self::resolution('AMBIGUOUS', null);

        foreach ([self::OWNER, self::OTHER] as $rival) {
            $d = (self::AUTH)::decide(self::PAGE, $rival);
            self::assertSame('denied', $d['decision'], "rival {$rival}");
            self::assertSame('claim_ambiguous', $d['reason'], "rival {$rival}");
            self::assertNull($d['operator_user_id'], "rival {$rival}");
        }
    }

    /**
     * Ambiguity is checked BEFORE the id comparison. Were the order
     * reversed, `user_id === null` would fail the comparison and the
     * decision would come back `not_claimer` — the same answer a settled
     * page gives, losing the fact that the page is contested and silently
     * dropping the audit distinction.
     */
    public function testAmbiguityIsReportedAsAmbiguityRatherThanAsNotTheClaimer(): void
    {
        self::resolution('AMBIGUOUS', null);

        self::assertSame('claim_ambiguous', (self::AUTH)::decide(self::PAGE, self::OWNER)['reason']);
    }

    // ── 4. Absence, and absence as revocation ─────────────────────────────

    public function testAnUnclaimedPageDenies(): void
    {
        self::resolution('UNCLAIMED', null);

        $d = (self::AUTH)::decide(self::PAGE, self::OWNER);

        self::assertSame('denied', $d['decision']);
        self::assertSame('claim_absent', $d['reason']);
    }

    /**
     * Claim revocation is a row DELETE — `ClaimStatus::REVOKED` is declared
     * but never assigned by any code path, and the only mutators are
     * `upsert`, `deleteByUserAndWallet` (wallet unlink) and
     * `deleteForUser`. So "the claim is gone" arrives as UNCLAIMED, and a
     * recheck MUST read absence as revocation rather than as "unchanged".
     */
    public function testAClaimThatDisappearsBetweenChecksRevokesAuthority(): void
    {
        self::resolution('RESOLVED', self::OWNER);
        self::assertTrue((self::AUTH)::isOperator(self::PAGE, self::OWNER));

        // The wallet is unlinked; the row is deleted, not marked revoked.
        self::resolution('UNCLAIMED', null);

        self::assertFalse((self::AUTH)::isOperator(self::PAGE, self::OWNER));
    }

    /**
     * An unknown state is a denial, not a pass. If the resolver ever gains
     * a fourth state, this class must not treat it as a grant by omission.
     */
    public function testAnUnrecognisedResolverStateDenies(): void
    {
        self::resolution('SOMETHING_NEW', self::OWNER);

        $d = (self::AUTH)::decide(self::PAGE, self::OWNER);

        self::assertSame('denied', $d['decision']);
        self::assertSame('claim_absent', $d['reason']);
    }

    // ── 5. Half-resolutions — MUST-NOT-HAPPEN #5 ──────────────────────────

    public function testResolvedWithoutAUsableOperatorIdDenies(): void
    {
        foreach ([null, 0, -1] as $bad) {
            self::resolution('RESOLVED', $bad);
            $d = (self::AUTH)::decide(self::PAGE, self::OWNER);
            self::assertSame('denied', $d['decision'], 'user_id=' . var_export($bad, true));
            self::assertSame('claim_absent', $d['reason'], 'user_id=' . var_export($bad, true));
        }
    }

    /**
     * The 0 === 0 trap: an anonymous viewer is rejected on the auth rung
     * BEFORE any resolution happens, so a RESOLVED-with-0 fixture can never
     * meet it in the middle.
     */
    public function testAnAnonymousViewerIsRefusedBeforeAnyClaimIsResolved(): void
    {
        self::resolution('RESOLVED', 0);

        foreach ([0, -1] as $anon) {
            $d = (self::AUTH)::decide(self::PAGE, $anon);
            self::assertSame('denied', $d['decision'], "viewer {$anon}");
            self::assertSame('auth_required', $d['reason'], "viewer {$anon}");
        }

        self::assertSame(0, $GLOBALS['__bcc_a0_resolve_calls'], 'anon must cost no claim query');
    }

    // ── 6. Page scope — MUST-NOT-HAPPEN #4 ────────────────────────────────

    public function testAPageWithNoValidatorLegIsOutOfScopeRatherThanUnclaimed(): void
    {
        self::resolution('RESOLVED', self::OWNER);
        $GLOBALS['__bcc_a0_validator_rows'] = [];

        $d = (self::AUTH)::decide(self::PAGE, self::OWNER);

        self::assertSame('denied', $d['decision']);
        self::assertSame('not_a_validator_page', $d['reason']);
        self::assertSame(0, $GLOBALS['__bcc_a0_resolve_calls'], 'scope is settled before resolving');
    }

    public function testAMissingOrWrongTypedOrUnpublishedPostIsRefused(): void
    {
        self::resolution('RESOLVED', self::OWNER);

        $cases = [
            'missing'     => [],
            'wrong type'  => [self::PAGE => self::publishedPage(self::PAGE, 'post')],
            'draft'       => [self::PAGE => self::publishedPage(self::PAGE, 'peepso-page', 'draft')],
            'not a post'  => [self::PAGE => (object) ['ID' => self::PAGE]],
        ];

        foreach ($cases as $label => $posts) {
            $GLOBALS['__bcc_a0_posts'] = $posts;
            $d = (self::AUTH)::decide(self::PAGE, self::OWNER);
            self::assertSame('denied', $d['decision'], $label);
            self::assertSame('not_a_validator_page', $d['reason'], $label);
        }
    }

    public function testANonPositivePageIdIsRefused(): void
    {
        self::resolution('RESOLVED', self::OWNER);

        foreach ([0, -5] as $bad) {
            self::assertFalse((self::AUTH)::isOperator($bad, self::OWNER), "pageId {$bad}");
        }
        self::assertSame(0, $GLOBALS['__bcc_a0_resolve_calls']);
    }

    // ── 7. Identity non-exposure — MUST-NOT-HAPPEN #3 ─────────────────────

    /**
     * Four distinct private reasons, ONE wire value. This is the assertion
     * that keeps claim existence, ownership and contention indistinguishable
     * from outside — §4.32.2's "leaks neither operator identity nor claim
     * count".
     */
    public function testEveryDenialPresentsTheSameReasonOnTheWire(): void
    {
        $privateReasons = [];

        // auth_required
        self::resolution('RESOLVED', self::OWNER);
        $privateReasons[] = $this->wireAndRecord(self::PAGE, 0);

        // not_a_validator_page
        $GLOBALS['__bcc_a0_validator_rows'] = [];
        $privateReasons[] = $this->wireAndRecord(self::PAGE, self::OWNER);
        $GLOBALS['__bcc_a0_validator_rows'] = [self::PAGE => (object) ['validator_id' => '12', 'chain_slug' => 'x']];

        // claim_absent
        self::resolution('UNCLAIMED', null);
        $privateReasons[] = $this->wireAndRecord(self::PAGE, self::OWNER);

        // claim_ambiguous
        self::resolution('AMBIGUOUS', null);
        $privateReasons[] = $this->wireAndRecord(self::PAGE, self::OWNER);

        // not_claimer
        self::resolution('RESOLVED', self::OWNER);
        $privateReasons[] = $this->wireAndRecord(self::PAGE, self::OTHER);

        // Anti-vacuity: the five cases really did reach five DIFFERENT
        // internal reasons, so collapsing them is a property of the
        // serializer and not an artefact of the fixture.
        self::assertCount(5, array_unique($privateReasons), 'fixture must reach 5 distinct reasons');
    }

    /** Collects the private reason while asserting the public one. */
    private function wireAndRecord(int $pageId, int $viewerId): string
    {
        $d = (self::AUTH)::decide($pageId, $viewerId);
        self::assertSame('denied', $d['decision']);
        self::assertSame('not_claimer', (self::AUTH)::publicReason($d), 'wire reason must be constant');
        return (string) $d['reason'];
    }

    public function testAGrantHasNoWireReasonAtAll(): void
    {
        self::resolution('RESOLVED', self::OWNER);

        self::assertNull((self::AUTH)::publicReason((self::AUTH)::decide(self::PAGE, self::OWNER)));
    }

    // ── 8. Cost and freshness ─────────────────────────────────────────────

    /**
     * One resolution per decision — no caching. A cached authorization is a
     * stale authorization, and because revocation is a DELETE there is no
     * write that any cache would be invalidated by.
     */
    public function testEachDecisionResolvesExactlyOnceAndNothingIsMemoized(): void
    {
        self::resolution('RESOLVED', self::OWNER);

        (self::AUTH)::decide(self::PAGE, self::OWNER);
        self::assertSame(1, $GLOBALS['__bcc_a0_resolve_calls']);

        (self::AUTH)::decide(self::PAGE, self::OWNER);
        (self::AUTH)::isOperator(self::PAGE, self::OWNER);
        self::assertSame(3, $GLOBALS['__bcc_a0_resolve_calls']);
    }

    // ── 9. The forbidden inputs ───────────────────────────────────────────

    /**
     * §4.32.2 forbids `wp_posts.post_author`, mirror claim rows, page meta,
     * PeepSo membership and read models as authorization inputs. The
     * post_author case is the dangerous one: the claim flow MOVES page
     * authorship, so accepting it would make authorship its own proof.
     *
     * Asserted structurally — the fixture post carries no author at all, and
     * the authority still reaches a correct grant and a correct denial. A
     * source sweep backs this up in
     * {@see testTheAuthorityNamesNoForbiddenAuthorizationInput}.
     */
    public function testAuthorshipOfThePagePlaysNoPartInTheDecision(): void
    {
        $page              = self::publishedPage(self::PAGE);
        $page->post_author = self::OTHER;          // the WRONG user owns the post
        $GLOBALS['__bcc_a0_posts'] = [self::PAGE => $page];

        self::resolution('RESOLVED', self::OWNER); // the claim says otherwise

        self::assertTrue((self::AUTH)::isOperator(self::PAGE, self::OWNER), 'the claim decides');
        self::assertFalse((self::AUTH)::isOperator(self::PAGE, self::OTHER), 'authorship does not');
    }

    /**
     * Source-level guard. A future edit that reaches for the mirror row or
     * `owns_page()` would pass every behavioural test above, because those
     * sources agree with the resolver in the happy path — they only diverge
     * on the unrepaired and contested rows that have no fixture here.
     */
    public function testTheAuthorityNamesNoForbiddenAuthorizationInput(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ValidatorPageOperatorAuthority.php'
        );
        self::assertNotSame('', $src, 'source must be readable for this guard to mean anything');

        // Strip the docblocks: they DISCUSS the forbidden inputs by name,
        // which is the point of them. Only executable code is scanned.
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        self::assertStringContainsString('resolveVerifiedOperatorForPage', $code, 'positive control');

        foreach ([
            'owns_page',
            'get_post_meta',
            'userHasVerifiedClaimOnPage',
            'getUserClaim',
            'viewerHasClaim',
            'peepso_page_members',
            'page_read_model',
            "'page'",            // the entity_type='page' MIRROR leg
            'post_author',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                "executable code must not reach for {$forbidden}"
            );
        }
    }
}
