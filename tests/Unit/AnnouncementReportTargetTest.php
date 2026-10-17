<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\REST\MyReportsEndpoint;
use BCC\Trust\Core\Services\AutoHideService;
use BCC\Trust\Core\Services\ContentReportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reporting an announcement — api-contract-v1.md §4.32.4 / §4.32.11.
 *
 * ── ⚠⚠ THE DEFECT THIS CLOSES ───────────────────────────────────────────
 * §4.32.11 advertises `can_report` on a notice, and it answered
 * `allowed: true` on a published one — measured on staging. But
 * `POST /bcc/v1/me/reports` declared `target_kind` with an enum of
 * `feed_item` ONLY, and the announcement view-model carries no activity,
 * feed or module id (measured: zero such fields). So the server said
 * "you may report this" and offered neither a route nor an id to do it
 * with. The reporting journey could not be completed from the notice at
 * all — a capability promising something no request could express.
 *
 * ── ⚠⚠ WHAT MUST NOT HAPPEN, each stated because the obvious fix does it ─
 *  1. THE AUTHOR/ACTIVITY RELATIONSHIP COMES FROM THE BROWSER. The
 *     alternative fix was to publish the feed activity id on the notice
 *     row and let the client report that. It was rejected twice over: it
 *     widens what a public notice discloses, and it makes "whose content
 *     is this" a client-supplied claim. The client sends the NOTICE's own
 *     id — the only id it legitimately holds — and the server resolves the
 *     page, the readability predicate and the author itself.
 *  2. REPORTABILITY IS DECIDED BY `post_status`. A `publish` row missing
 *     its publication-authorization marker reads as `published` on the
 *     owner list and is NOT publicly readable. Reportability is public
 *     READABILITY, decided by the same `findPublic()` the detail route
 *     uses.
 *  3. AN ARCHIVED NOTICE STOPS BEING REPORTABLE. §4.32.6 keeps an archived
 *     notice at its permalink precisely so it stays challengeable. A fix
 *     that dropped archived rows would remove the challenge exactly when
 *     the operator had closed the discussion.
 *  4. "NOT REPORTABLE" DISCLOSES WHICH. A draft, a scheduled row, a
 *     blocked row, an unauthorised `publish` row and a row that never
 *     existed must answer IDENTICALLY — the non-disclosure §4.32.1 buys
 *     is lost the moment one of them answers differently.
 *  5. A NEW TARGET KIND REACHES THE AUTO-HIDE COUNTER. §4.32.16 forbids
 *     an announcement being hidden by reporters, and the structural reason
 *     it cannot is pinned here rather than assumed.
 *
 * ── WHAT THIS DOES NOT PROVE ────────────────────────────────────────────
 * No WordPress, so no `get_post`, no SQL and no real dispatch. The
 * behavioural half — 404 parity across all five unreportable shapes, the
 * archived 200, the duplicate `status: existing`, the self-report refusal
 * and 25 announcement-kind events leaving the row published — is
 * `scripts/tests/announcement-reporting.php`, which needs a real install.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ContentReportService::class)]
final class AnnouncementReportTargetTest extends TestCase
{
    // ── 1. The accepted kinds ────────────────────────────────────────────

    /**
     * ⚠⚠ AN EXACT SET, and that is the point. `target_kind`'s enum on the
     * route is built from this constant, so the constant IS the contract's
     * §4.32.4 enum. A kind added here without a contract entry ships a
     * route the documentation does not describe; a kind removed breaks
     * every client that sends it. Growing this list is the moment to check
     * that both exist.
     */
    public function testTheAcceptedTargetKindsAreExactlyFeedItemAndAnnouncement(): void
    {
        self::assertSame(
            ['feed_item', 'announcement'],
            ContentReportService::TARGET_KINDS,
            'the §4.32.4 target_kind enum'
        );

        // The two literals are also exported as named constants, so a
        // caller never spells a kind by hand.
        self::assertSame('feed_item', ContentReportService::KIND_FEED_ITEM);
        self::assertSame('announcement', ContentReportService::KIND_ANNOUNCEMENT);
    }

    /**
     * ⚠ The report KIND is not the post TYPE, and conflating them is the
     * mistake that would make a client send `bcc_announcement`. The kind is
     * a wire vocabulary word; the post type is storage.
     */
    public function testTheReportKindIsNotThePostType(): void
    {
        self::assertNotSame(
            AnnouncementPostType::POST_TYPE,
            ContentReportService::KIND_ANNOUNCEMENT,
            'the wire kind and the storage type are different vocabularies'
        );
        self::assertSame('bcc_announcement', AnnouncementPostType::POST_TYPE);
    }

    // ── 2. The server resolves everything that matters ───────────────────

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #1 — nothing about the target comes from the
     * request. The resolver takes ONE argument, the announcement id, and
     * derives the page from `post_parent`. There is no page id, author id
     * or activity id in its signature or its body to be supplied.
     */
    public function testTheAnnouncementResolverTakesOnlyTheNoticeIdAndDerivesTheRest(): void
    {
        $method = new ReflectionMethod(ContentReportService::class, 'resolveAnnouncementTarget');

        self::assertTrue($method->isPrivate(), 'an internal resolution step, not a surface');
        self::assertSame(1, $method->getNumberOfParameters());

        $params = $method->getParameters();
        self::assertSame('announcementId', $params[0]->getName());
        self::assertSame('int', (string) $params[0]->getType());

        $body = self::methodBody('resolveAnnouncementTarget');

        // The page comes from the post's own parent, server-side.
        self::assertStringContainsString('post_parent', $body, 'the page is derived, not received');

        // ⚠ And nothing request-shaped appears at all. Each needle is a
        // real way the wrong version of this would be written.
        foreach (['$request', 'get_param', 'page_id', '$_POST', '$_GET', 'post_author_id'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $body,
                "the resolver must not read {$needle} — the browser supplies only the notice id"
            );
        }
    }

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #2 — reportability is public READABILITY.
     *
     * Asserted two-sidedly: the predicate the detail route uses must be
     * present, and `post_status` must be absent. A `post_status === 'publish'`
     * check would pass every ordinary test while making an unauthorised
     * `publish` row reportable, which is the one row this must refuse.
     */
    public function testReportabilityIsDecidedByThePublicReadabilityPredicateNotByStatus(): void
    {
        $body = self::methodBody('resolveAnnouncementTarget');

        self::assertStringContainsString(
            'findPublic(',
            $body,
            'the same single-row predicate the detail route applies'
        );
        self::assertStringNotContainsString(
            'post_status',
            $body,
            'a status check is not the readability predicate — §4.32.1 has three'
        );

        // ⚠ ANTI-VACUITY. `findPublic` must actually be the repository's,
        // and it must still apply the predicate — otherwise this test
        // passes against a method that merely shares the name.
        self::assertStringContainsString(
            'publicPredicateSql',
            file_get_contents(self::path('app/Domain/Core/Repositories/AnnouncementRepository.php')),
            'findPublic composes the §4.32.1 predicate'
        );
    }

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #4 — one answer for every unreportable shape, and
     * a DIFFERENT one for a read that failed.
     *
     * `bcc_not_found` twice: the wrong post type, and `findPublic()`
     * returning null (which covers draft, scheduled, blocked, the
     * unauthorised publish and the wrong page in one branch).
     * `bcc_unavailable` once, for a `RepositoryException` — failing closed
     * on an unreadable database must never look like a row that is absent,
     * or a provider outage reads to the client as deleted content.
     */
    public function testTheResolverAnswersNotFoundTwiceAndUnavailableOnce(): void
    {
        $body = self::methodBody('resolveAnnouncementTarget');

        self::assertSame(2, substr_count($body, "'bcc_not_found'"), 'wrong type, and not publicly readable');
        self::assertSame(1, substr_count($body, "'bcc_unavailable'"), 'the read failed');
        self::assertSame(1, substr_count($body, 'RepositoryException'), 'and it is caught, not propagated');

        // Both not-found arms carry the SAME message, so the bodies are
        // indistinguishable and not merely the same code.
        self::assertSame(2, substr_count($body, "'Not found.'"));
    }

    /**
     * The author arm is DISPATCHED, not appended — the extension this
     * method's own comment invited. A second `resolveFeedItemAuthor()` call
     * for the new kind would resolve a feed activity by an announcement id.
     */
    public function testTheAuthorResolutionIsDispatchedPerKind(): void
    {
        $body = self::methodBody('fileReport');

        self::assertStringContainsString('KIND_ANNOUNCEMENT', $body, 'the announcement arm exists');
        self::assertStringContainsString('resolveAnnouncementTarget(', $body);
        self::assertStringContainsString('resolveFeedItemAuthor(', $body, 'and the feed arm survives');

        // ⚠ The announcement arm returns the resolver's error envelope
        // BEFORE any author comparison — so an unreportable target answers
        // 404 rather than falling through to a self-report check with a
        // zero author.
        $arm = strpos($body, 'KIND_ANNOUNCEMENT');
        $cmp = strpos($body, '=== $reporterUserId');
        self::assertIsInt($arm);
        self::assertIsInt($cmp);
        self::assertLessThan($cmp, $arm, 'target resolution precedes the self-report comparison');
    }

    // ── 3. The status mapping ────────────────────────────────────────────

    /**
     * ⚠ 404 exists so an unreportable notice answers exactly as its detail
     * route does. Without this arm the `match`'s `default` turns "not
     * found" into a 400, which tells a prober the id was well-formed but
     * refused — the disclosure §4.32.1 avoids by making a draft and a
     * missing row indistinguishable.
     */
    #[DataProvider('errorCodeStatuses')]
    public function testTheEndpointMapsEveryServiceErrorCodeToItsContractStatus(
        string $code,
        int $expected
    ): void {
        $method = new ReflectionMethod(MyReportsEndpoint::class, 'statusForCode');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invoke(null, $code), $code);
    }

    /** @return iterable<string, array{string, int}> */
    public static function errorCodeStatuses(): iterable
    {
        yield 'not found — NEW, and the reason this test exists' => ['bcc_not_found', 404];

        // ⚠ The pre-existing mappings, asserted so the new arm cannot have
        // been added by reordering or replacing one of them.
        yield 'unauthorized'    => ['bcc_unauthorized', 401];
        yield 'forbidden'       => ['bcc_forbidden', 403];
        yield 'invalid request' => ['bcc_invalid_request', 400];
        yield 'rate limited'    => ['bcc_rate_limited', 429];
        yield 'unavailable'     => ['bcc_unavailable', 503];

        // An unknown code still falls back to 400 rather than leaking a 500.
        yield 'unknown falls back' => ['bcc_something_new', 400];
    }

    // ── 4. The new kind cannot reach the auto-hide counter ───────────────

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #5, proved STRUCTURALLY and two-sidedly.
     *
     * `AutoHideService::onContentReported()` returns before anything else
     * when the kind is not `feed_item`, so an announcement report cannot
     * reach the distinct-reporter count or the hide write. Adding a target
     * kind is exactly what makes that gate load-bearing, so it is pinned
     * here instead of assumed.
     *
     * ⚠ The control is what makes this non-vacuous. The service is built
     * WITHOUT its constructor, so its repositories are uninitialised: any
     * execution that gets past the gate must fail. `announcement`
     * completes silently; `feed_item` throws. The specific throwable is
     * immaterial and deliberately not asserted — what is asserted is that
     * execution continued past the gate in one case and not the other. A
     * test that only checked the announcement case would pass just as well
     * against a method that had been emptied.
     */
    public function testAnAnnouncementReportNeverReachesTheAutoHideCounter(): void
    {
        $service = (new ReflectionClass(AutoHideService::class))->newInstanceWithoutConstructor();

        $service->onContentReported(7, ContentReportService::KIND_ANNOUNCEMENT, 4471, 9, 'spam');
        $this->addToAssertionCount(1); // reached: the gate returned

        $threw = false;
        try {
            $service->onContentReported(7, ContentReportService::KIND_FEED_ITEM, 4471, 9, 'spam');
        } catch (\Throwable) {
            $threw = true;
        }

        self::assertTrue(
            $threw,
            'POSITIVE CONTROL: a feed_item report must proceed past the gate — '
            . 'if this does not throw on an unconstructed service, the gate is not what '
            . 'stopped the announcement call and the case above proves nothing'
        );
    }

    /**
     * And the gate is the FIRST thing in the method — before the threshold
     * read, before the already-hidden probe, before the count. A gate
     * placed after a write would satisfy the test above while still having
     * touched the row.
     */
    public function testTheTargetKindGateIsTheFirstStatementOfTheHandler(): void
    {
        $body = self::methodBody('onContentReported', AutoHideService::class);

        $gate = strpos($body, "!== 'feed_item'");
        self::assertIsInt($gate, 'the gate is keyed on the literal kind');

        // ⚠ ANTI-VACUITY: every needle must be FOUND, not merely
        // "found after the gate". A loop that skipped missing needles would
        // pass against a method whose body had been deleted.
        $found = 0;
        foreach (['resolveThreshold(', 'hiddenRepo', 'reportRepo'] as $later) {
            $at = strpos($body, $later);
            self::assertIsInt($at, "{$later} must still be in the handler");
            self::assertGreaterThan($gate, $at, "{$later} must come after the kind gate");
            $found++;
        }
        self::assertSame(3, $found);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private static function path(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path, 'the file this suite reads must exist');

        return $path;
    }

    /**
     * The source text of one method, located by reflection rather than by
     * grepping the whole file — a file-wide search would let a needle match
     * a docblock somewhere else and report the opposite of the truth.
     */
    private static function methodBody(string $name, string $class = ContentReportService::class): string
    {
        $method = new ReflectionMethod($class, $name);
        $file   = $method->getFileName();
        self::assertIsString($file);

        $start = $method->getStartLine();
        $end   = $method->getEndLine();
        self::assertGreaterThan($start, $end, 'a method with no body would make every needle vacuous');

        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
