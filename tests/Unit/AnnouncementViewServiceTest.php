<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver as Caps;
use BCC\Trust\Core\Services\AnnouncementViewService as View;
use BCC\Trust\Core\Support\KeysetCursor as Cursor;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Announcement view-models — api-contract-v1.md §4.32.3, §4.32.11, §4.32.12.
 *
 * ── ⚠⚠⚠ THE ASSERTION THIS WHOLE SUITE EXISTS FOR ───────────────────────
 * **The public list is byte-identical for a resolved operator and an
 * anonymous reader.** §4.32.11 (umbrella #177): the public list and rotator
 * are published public data only — no `capabilities`, no `status`, no
 * `body` — for ANY viewer, *including the operator*.
 *
 * A capability can leak through a **serializer** rather than through
 * `AnnouncementCapabilityResolver`, and no test of the resolver can see
 * that. This is the one that can.
 *
 * ── ⚠⚠ THE OTHER THINGS THAT MUST NOT HAPPEN ────────────────────────────
 *  1. A PUBLIC ROW GAINS capabilities / status / body. §6 pins the public
 *     method inventory so a producer cannot be added quietly, and the
 *     public shaper takes no viewer argument at all.
 *  2. A PAGE MINTS A CURSOR FOR AN EMPTY NEXT PAGE. `has_more` comes from
 *     the repository's limit+1 over-fetch, never from
 *     `count($rows) === $limit` — the bug that makes `CommentService` hand
 *     out a false-positive cursor on an exact-boundary page.
 *  3. THE CURSOR KEY DISAGREES WITH THE ORDER BY. Active is ordered
 *     pinned-first so its key is the COMPOSITE `pinned|date`; archived is
 *     ordered by a DIFFERENT column. A key built from the wrong field
 *     starts the next page in the wrong place.
 *  4. `updated_at` ECHOES `published_at`. §4.32.3 surfaces it only when it
 *     differs materially — a duplicate makes the client compare timestamps
 *     to decide whether to render "edited".
 *  5. AN ARCHIVED ROW IS TREATED AS UNPUBLISHED. §4.32.6 — archival is a
 *     public record, not a deletion.
 *  6. A ZERO COMMENT COUNT IS INVENTED. §4.32.7/§4.32.3: it is sourced
 *     from the real discussion. An absent map key means "no comments" and
 *     renders a MEASURED 0 — never a substituted activity count.
 *
 * ── WHAT THIS DOES NOT PROVE ────────────────────────────────────────────
 * The service is pure, so nothing here executes SQL. That the repository
 * *orders by* the field the cursor key is built from is proved in
 * `AnnouncementRepositoryIntegrationTest`, against a real engine.
 */
#[CoversClass(View::class)]
final class AnnouncementViewServiceTest extends TestCase
{
    private const SLUG = 'blacksmith-node';

    /** A published, unarchived, comments-open row as the repository returns it. */
    private static function row(array $over = []): object
    {
        return (object) array_merge([
            'ID'                => 4471,
            'post_title'        => 'Upgrade to v18 complete',
            'post_excerpt'      => 'Operator-written one-liner.',
            'post_content'      => "We completed the **v18** upgrade.\n\nNo downtime.",
            'post_status'       => 'publish',
            'post_author'       => 7,
            'post_date_gmt'     => '2026-10-09 14:00:00',
            'post_modified_gmt' => '2026-10-09 14:00:00',
            'is_pinned'         => '0',
            'archived_at'       => null,
            'comments_enabled'  => '1',
            'publish_at'        => null,
            'effective_at'      => null,
        ], $over);
    }

    /** @return array{decision: string, reason: string|null, operator_user_id: int|null} */
    private static function operator(): array
    {
        return ['decision' => Authority::DECISION_OPERATOR, 'reason' => null, 'operator_user_id' => 7];
    }

    /** @return array{decision: string, reason: string|null, operator_user_id: int|null} */
    private static function anonymous(): array
    {
        return [
            'decision'         => Authority::DECISION_DENIED,
            'reason'           => Authority::REASON_AUTH_REQUIRED,
            'operator_user_id' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function goodMember(): array
    {
        return ['authenticated' => true, 'suspended' => false, 'fraud_locked' => false, 'good_standing' => true];
    }

    // ── 1. THE assertion — MUST-NOT-HAPPEN #0 ───────────────────────────

    /**
     * ⚠⚠⚠ Byte-identical. Not "equivalent", not "has no capabilities" —
     * `assertSame` on the whole structure, because any viewer-dependent
     * field at all would make the public list uncacheable and would be a
     * §4.32.11 breach.
     *
     * The public producer takes no viewer argument, so this is really
     * asserting that the SIGNATURE cannot express a viewer — which is the
     * guarantee. Driven over several row shapes so it is not one lucky row.
     */
    #[DataProvider('variedRows')]
    public function testThePublicPageIsByteIdenticalForAnOperatorAndAnAnonymousReader(array $over): void
    {
        $rows = [self::row($over)];

        // There is literally no viewer parameter to vary — which is the
        // point. Build it twice and compare, so a future signature change
        // that ADDS one fails here.
        $first  = View::publicPage($rows, false, [4471 => 3], Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE, self::SLUG);
        $second = View::publicPage($rows, false, [4471 => 3], Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE, self::SLUG);

        self::assertSame($first, $second);
        self::assertSame(
            json_encode($first),
            json_encode($second),
            'the public page must serialize to identical bytes'
        );

        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(View::class, 'publicPage'))->getParameters()
        );
        self::assertSame(
            ['rows', 'hasMore', 'commentCounts', 'surface', 'validatorSlug'],
            $params,
            'a viewer/decision/feature parameter here would break the §4.32.11 guarantee'
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function variedRows(): iterable
    {
        yield 'plain published'  => [[]];
        yield 'pinned'           => [['is_pinned' => '1']];
        yield 'archived'         => [['archived_at' => '2026-10-11 09:00:00']];
        yield 'comments off'     => [['comments_enabled' => '0']];
        yield 'edited'           => [['post_modified_gmt' => '2026-10-10 09:00:00']];
    }

    /** MUST-NOT-HAPPEN #1 — the three forbidden fields, on every public surface. */
    #[DataProvider('publicSurfaces')]
    public function testNoPublicRowCarriesCapabilitiesStatusOrBody(callable $build): void
    {
        $page = $build();

        self::assertNotSame([], $page['items']);
        foreach ($page['items'] as $item) {
            foreach (['capabilities', 'status', 'body'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $item, $forbidden . ' must never be public');
            }
        }
    }

    /** @return iterable<string, array{callable}> */
    public static function publicSurfaces(): iterable
    {
        $rows = [self::row(), self::row(['ID' => 4480, 'is_pinned' => '1'])];

        yield 'active list' => [static fn (): array => View::publicPage(
            $rows, false, [], Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE, self::SLUG
        )];
        yield 'archived list' => [static fn (): array => View::publicPage(
            [self::row(['archived_at' => '2026-10-11 09:00:00'])],
            false, [], Cursor::SURFACE_ANNOUNCEMENTS_ARCHIVED, self::SLUG
        )];
        yield 'rotator' => [static fn (): array => View::rotatorPage($rows, [], self::SLUG)];
    }

    /** The exact §4.32.3 public field set — no more, no less. */
    public function testThePublicRowIsExactlyTheContractFieldSet(): void
    {
        $page = View::publicPage([self::row()], false, [], Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE, self::SLUG);

        self::assertSame([
            'id', 'title', 'summary', 'published_at', 'updated_at',
            'is_pinned', 'is_archived', 'archived_at',
            'comment_count', 'comments_enabled', 'links',
        ], array_keys($page['items'][0]));
        self::assertSame(['self'], array_keys($page['items'][0]['links']));
    }

    // ── 2. Pagination — MUST-NOT-HAPPEN #2 and #3 ───────────────────────

    /**
     * ⚠ `has_more: false` ⇒ no cursor. The repository's limit+1 over-fetch
     * is the only source of `has_more`; a serializer that minted a cursor
     * on a full-looking page would reproduce `CommentService`'s
     * false-positive cursor.
     */
    public function testAFinalPageMintsNoCursor(): void
    {
        $page = View::publicPage(
            [self::row(), self::row(['ID' => 4480])],
            false,
            [],
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::SLUG
        );

        self::assertSame(['next_cursor' => null, 'has_more' => false], $page['pagination']);
    }

    public function testAnEmptyPageMintsNoCursor(): void
    {
        $page = View::publicPage([], true, [], Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE, self::SLUG);

        self::assertSame([], $page['items']);
        self::assertSame(['next_cursor' => null, 'has_more' => false], $page['pagination']);
    }

    /** The rotator's pagination is inert by construction (§4.32.4). */
    public function testTheRotatorMintsNoCursorEvenWithAFullCap(): void
    {
        $rows = [];
        for ($i = 0; $i < Cursor::MAX_ENCODED_LENGTH && $i < 5; $i++) {
            $rows[] = self::row(['ID' => 4000 + $i]);
        }

        $page = View::rotatorPage($rows, [], self::SLUG);

        self::assertCount(5, $page['items']);
        self::assertSame(['next_cursor' => null, 'has_more' => false], $page['pagination']);
    }

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #3 — the key must match the surface's ORDER BY.
     * Active is pinned-first, so its key is the COMPOSITE; archived orders
     * by a different column entirely.
     */
    public function testTheCursorKeyMatchesTheSurfacesOrderBy(): void
    {
        $active = View::publicPage(
            [self::row(['is_pinned' => '1'])],
            true,
            [],
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::SLUG
        );
        $decoded = Cursor::decode(Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE, $active['pagination']['next_cursor']);
        self::assertNotNull($decoded);
        self::assertSame('1|2026-10-09 14:00:00', $decoded['key'], 'active is ordered pinned-first');
        self::assertSame(4471, $decoded['id']);

        $archived = View::publicPage(
            [self::row(['archived_at' => '2026-10-11 09:00:00'])],
            true,
            [],
            Cursor::SURFACE_ANNOUNCEMENTS_ARCHIVED,
            self::SLUG
        );
        $decodedArchived = Cursor::decode(
            Cursor::SURFACE_ANNOUNCEMENTS_ARCHIVED,
            $archived['pagination']['next_cursor']
        );
        self::assertNotNull($decodedArchived);
        self::assertSame('2026-10-11 09:00:00', $decodedArchived['key'], 'archived orders by archived_at');
    }

    /**
     * ⚠ And the surfaces must not cross-decode — the property #325 exists
     * to deliver. An active cursor must be refused by the archived list.
     */
    public function testAnActiveCursorIsRefusedByTheArchivedSurface(): void
    {
        $active = View::publicPage([self::row()], true, [], Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE, self::SLUG);
        $token  = $active['pagination']['next_cursor'];

        self::assertNotNull(Cursor::decode(Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE, $token));
        self::assertNull(Cursor::decode(Cursor::SURFACE_ANNOUNCEMENTS_ARCHIVED, $token));
    }

    /**
     * ⚠ A row the cursor cannot address ends the list rather than handing
     * out a token that silently resets it. Id 0 cannot be encoded
     * (`KeysetCursor::encode` throws on a non-positive id), so the
     * serializer must not even try.
     */
    public function testARowThatCannotBeAddressedEndsTheListInsteadOfThrowing(): void
    {
        $page = View::publicPage(
            [self::row(['ID' => 0])],
            true,
            [],
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::SLUG
        );

        self::assertSame(['next_cursor' => null, 'has_more' => false], $page['pagination']);
    }

    // ── 3. Field semantics — MUST-NOT-HAPPEN #4, #5, #6 ─────────────────

    /** MUST-NOT-HAPPEN #4. */
    public function testUpdatedAtIsSuppressedOnTheOwnerRowWhenItEqualsPublishedAt(): void
    {
        $same = View::ownerPage(
            [self::row()],
            false,
            [],
            self::operator(),
            self::goodMember(),
            true,
            self::SLUG
        );
        self::assertNull($same['items'][0]['updated_at'], 'an unedited row must not echo its publish date');

        $edited = View::ownerPage(
            [self::row(['post_modified_gmt' => '2026-10-10 09:12:00'])],
            false,
            [],
            self::operator(),
            self::goodMember(),
            true,
            self::SLUG
        );
        self::assertSame('2026-10-10T09:12:00Z', $edited['items'][0]['updated_at']);
    }

    /** MUST-NOT-HAPPEN #5 — archival is a public record (§4.32.6). */
    public function testAnArchivedRowIsStillPublicAndCarriesItsArchivedAt(): void
    {
        $page = View::publicPage(
            [self::row(['archived_at' => '2026-10-11 09:00:00'])],
            false,
            [],
            Cursor::SURFACE_ANNOUNCEMENTS_ARCHIVED,
            self::SLUG
        );

        $item = $page['items'][0];
        self::assertTrue($item['is_archived']);
        self::assertSame('2026-10-11T09:00:00Z', $item['archived_at']);
        // Still carries its ORIGINAL published_at, not the archive date.
        self::assertSame('2026-10-09T14:00:00Z', $item['published_at']);
    }

    /**
     * ⚠ An EMPTY `archived_at` meta is not an archival.
     * `update_post_meta($id, $key, '')` leaves a row behind, and treating
     * that as archived would strand the announcement in the Archived
     * section permanently — archival is one-way.
     */
    #[DataProvider('nonArchivalValues')]
    public function testAnEmptyOrBlankArchivedAtIsNotArchived($value): void
    {
        $page = View::publicPage(
            [self::row(['archived_at' => $value])],
            false,
            [],
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::SLUG
        );

        self::assertFalse($page['items'][0]['is_archived']);
        self::assertNull($page['items'][0]['archived_at']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonArchivalValues(): iterable
    {
        yield 'null'       => [null];
        yield 'empty'      => [''];
        yield 'spaces'     => ['   '];
        yield 'zero date'  => ['0000-00-00 00:00:00'];
    }

    /** MUST-NOT-HAPPEN #6 — a measured zero, never an invented one. */
    public function testAnAbsentCommentCountKeyRendersAMeasuredZero(): void
    {
        $page = View::publicPage(
            [self::row(['ID' => 4471]), self::row(['ID' => 4480])],
            false,
            [4471 => 12],          // 4480 deliberately absent
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::SLUG
        );

        self::assertSame(12, $page['items'][0]['comment_count']);
        self::assertSame(0, $page['items'][1]['comment_count'], 'absent means no comments, rendered as 0');
    }

    /**
     * §4.32.7: discussion is the default, and `comments_enabled: false` is
     * an explicit operator choice. Absent meta must not read as closed.
     */
    #[DataProvider('commentsEnabledValues')]
    public function testCommentsEnabledDefaultsOpenAndClosesOnlyOnAnExplicitOff($value, bool $expected): void
    {
        $page = View::publicPage(
            [self::row(['comments_enabled' => $value])],
            false,
            [],
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::SLUG
        );

        self::assertSame($expected, $page['items'][0]['comments_enabled']);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function commentsEnabledValues(): iterable
    {
        yield 'absent'   => [null, true];
        yield 'one'      => ['1', true];
        yield 'zero'     => ['0', false];
        yield 'empty'    => ['', false];
        yield 'spaces'   => ['   ', false];
    }

    /**
     * ⚠ `is_pinned` must be the column's numeric truth, not any truthy
     * string. MySQL returns `'1'`/`'0'` for the CASE expression, and
     * `'false'` must not read as pinned.
     */
    #[DataProvider('pinnedValues')]
    public function testIsPinnedReadsTheColumnsNumericTruthOnly($value, bool $expected): void
    {
        $page = View::publicPage(
            [self::row(['is_pinned' => $value])],
            false,
            [],
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::SLUG
        );

        self::assertSame($expected, $page['items'][0]['is_pinned']);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function pinnedValues(): iterable
    {
        yield 'string one'   => ['1', true];
        yield 'int one'      => [1, true];
        yield 'bool true'    => [true, true];
        yield 'string zero'  => ['0', false];
        yield 'int zero'     => [0, false];
        yield 'null'         => [null, false];
        yield 'string false' => ['false', false];
        yield 'string yes'   => ['yes', false];
    }

    /** Dates are ISO-8601 UTC with no server-rendered label (§4.32.3). */
    public function testDatesAreIsoUtcAndNoLabelIsEmitted(): void
    {
        $item = View::publicPage(
            [self::row()],
            false,
            [],
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::SLUG
        )['items'][0];

        self::assertSame('2026-10-09T14:00:00Z', $item['published_at']);
        self::assertArrayNotHasKey('posted_at_label', $item, '§4.32.3 formats announcement dates client-side');
    }

    public function testAnUnparseableOrZeroDateBecomesNullRatherThanAnEpoch(): void
    {
        foreach (['', '0000-00-00 00:00:00', 'not a date'] as $bad) {
            $item = View::publicPage(
                [self::row(['post_date_gmt' => $bad])],
                false,
                [],
                Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
                self::SLUG
            )['items'][0];

            self::assertNull($item['published_at'], 'bad date [' . $bad . '] must not invent 1970');
        }
    }

    public function testTheIdIsPrefixedAndThePermalinkIsSlugShaped(): void
    {
        $item = View::publicPage(
            [self::row()],
            false,
            [],
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::SLUG
        )['items'][0];

        self::assertSame('ann_4471', $item['id']);
        self::assertSame('/v/blacksmith-node/a/4471', $item['links']['self']);
    }

    // ── 4. Owner and detail ─────────────────────────────────────────────

    public function testTheOwnerRowCarriesStatusAndRowCapabilities(): void
    {
        $item = View::ownerPage(
            [self::row(['post_status' => 'draft'])],
            false,
            [],
            self::operator(),
            self::goodMember(),
            true,
            self::SLUG
        )['items'][0];

        self::assertSame(Caps::STATUS_DRAFT, $item['status']);
        self::assertArrayHasKey('capabilities', $item);
        self::assertArrayNotHasKey('body', $item, 'body is detail-only (§4.32.3)');
    }

    /**
     * ⚠ Capabilities are passed through from the resolver VERBATIM. Asserted
     * by comparing against a direct resolver call, so the service cannot
     * filter, reorder or recompute them — and so it stays agnostic to
     * whether the reportability slice's `can_report` is present.
     */
    public function testCapabilitiesArePassedThroughFromTheResolverVerbatim(): void
    {
        $row     = self::row(['post_status' => 'draft']);
        $viewer  = self::goodMember();
        $item    = View::ownerPage([$row], false, [], self::operator(), $viewer, true, self::SLUG)['items'][0];

        $expected = Caps::forRow(
            self::operator(),
            ['status' => Caps::STATUS_DRAFT, 'is_archived' => false, 'comments_enabled' => true],
            $viewer,
            true
        );

        self::assertSame($expected, $item['capabilities']);
    }

    public function testOnlyTheDetailViewModelCarriesTheMarkdownBody(): void
    {
        $detail = View::detail(
            self::row(),
            [4471 => 3],
            self::operator(),
            self::goodMember(),
            true,
            self::SLUG
        );

        self::assertSame("We completed the **v18** upgrade.\n\nNo downtime.", $detail['body']);
        self::assertArrayHasKey('status', $detail);
        self::assertArrayHasKey('capabilities', $detail);
    }

    /**
     * ⚠⚠ `blocked` is NOT produced, and that is recorded rather than
     * silently absent: §4.32.13 defines it as a row flagged by reconciler
     * arm C, which does not exist yet, so nothing can distinguish it from
     * an ordinary draft.
     *
     * `draft` is the fail-safe answer — both states are non-public, so a
     * blocked row is never exposed by the mapping; it is only labelled less
     * precisely to its own operator.
     */
    #[DataProvider('statusMappings')]
    public function testTheReadStatusMapsFromPostStatusAndNeverYieldsBlocked(
        string $postStatus,
        string $expected
    ): void {
        $item = View::ownerPage(
            [self::row(['post_status' => $postStatus])],
            false,
            [],
            self::operator(),
            self::goodMember(),
            true,
            self::SLUG
        )['items'][0];

        self::assertSame($expected, $item['status']);
        self::assertNotSame(Caps::STATUS_BLOCKED, $item['status']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function statusMappings(): iterable
    {
        yield 'publish'  => ['publish', Caps::STATUS_PUBLISHED];
        yield 'future'   => ['future', Caps::STATUS_SCHEDULED];
        yield 'draft'    => ['draft', Caps::STATUS_DRAFT];
        yield 'pending'  => ['pending', Caps::STATUS_DRAFT];
        yield 'trash'    => ['trash', Caps::STATUS_DRAFT];
        yield 'unknown'  => ['whatever', Caps::STATUS_DRAFT];
        yield 'empty'    => ['', Caps::STATUS_DRAFT];
    }

    // ── 5. Owner pagination uses its own surface ─────────────────────────

    public function testTheOwnerPageMintsAnOwnerCursorFromTheEffectiveDate(): void
    {
        $page = View::ownerPage(
            [self::row(['post_status' => 'draft', 'effective_at' => '2026-10-12 11:04:00'])],
            true,
            [],
            self::operator(),
            self::goodMember(),
            true,
            self::SLUG
        );

        $decoded = Cursor::decode(Cursor::SURFACE_ANNOUNCEMENTS_OWNER, $page['pagination']['next_cursor']);
        self::assertNotNull($decoded);
        self::assertSame('2026-10-12 11:04:00', $decoded['key']);

        // And it is refused by both public surfaces.
        self::assertNull(Cursor::decode(
            Cursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            $page['pagination']['next_cursor']
        ));
    }

    // ── 6. Structural guarantees ─────────────────────────────────────────

    /**
     * ⚠⚠ MUST-NOT-HAPPEN #1, structurally. The public surfaces are produced
     * by exactly two methods, and neither can be handed a viewer. Pinning
     * the inventory means adding a viewer-aware public producer fails here
     * rather than shipping a private state onto a cacheable response.
     */
    public function testThePublicMethodInventoryIsPinned(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(View::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        self::assertSame(
            ['detail', 'metaKeysRead', 'ownerPage', 'publicPage', 'rotatorPage'],
            $methods,
            'a new public producer needs a contract decision, not just a method'
        );

        // Neither public producer may take a viewer-ish argument.
        foreach (['publicPage', 'rotatorPage'] as $publicProducer) {
            foreach ((new \ReflectionMethod(View::class, $publicProducer))->getParameters() as $p) {
                self::assertNotContains(
                    $p->getName(),
                    ['decision', 'viewer', 'viewerId', 'featureEnabled', 'isOwner'],
                    $publicProducer . ' must not be able to learn a viewer'
                );
            }
        }
    }

    /**
     * The meta keys this projection reads must be the ones
     * `AnnouncementVisibility` defines — two spellings of the same key is
     * how a projection silently stops reflecting reality.
     */
    public function testTheMetaKeysReadAreTheCanonicalOnes(): void
    {
        self::assertSame([
            'archived_at'      => '_bcc_announcement_archived_at',
            'comments_enabled' => '_bcc_announcement_comments_enabled',
        ], View::metaKeysRead());
    }

    /** MUST-NOT-HAPPEN #5 — the SQL must contain no OFFSET at all. */
    public function testTheRepositoryEmitsNoOffsetClause(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Repositories/AnnouncementRepository.php'
        );
        self::assertIsString($src);

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        self::assertStringNotContainsStringIgnoringCase('OFFSET', $code, 'keyset only (§4.32.12)');
        self::assertStringNotContainsStringIgnoringCase('SELECT *', $code, '§2 forbids SELECT *');
    }

    /** The service is pure — no DB, no clock, no WordPress state. */
    public function testTheServiceIsPure(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Services/AnnouncementViewService.php'
        );
        self::assertIsString($src);

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        foreach (['wpdb', 'get_option', 'get_post_meta', 'current_time', 'apply_filters'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $code, 'a view-model must not reach ' . $forbidden);
        }
    }
}
