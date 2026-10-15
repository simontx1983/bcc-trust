<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A5a — the two storage invariants the DATABASE CANNOT DEMONSTRATE.
 *
 * ── ⚠⚠ WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * `AnnouncementCommentRepositoryIntegrationTest` is the real evidence for the
 * comment repository: 35 cases against MySQL 8.0.46 and MariaDB 11.8.9, and a
 * mutation sweep killed 19 of 21 mutations. The two that SURVIVED are both
 * invariants a passing query cannot distinguish:
 *
 *   1. THE `comment_ID` TIEBREAK. Removing it from the `ORDER BY` kills no
 *      case, because both engines happen to return InnoDB primary-key order
 *      for the tied rows the fixture seeds. So
 *      `testCommentsInTheSameSecondAreOrderedByIdAscending` and
 *      `testPagingAcrossATieNeitherSkipsNorRepeats` pass on INCIDENTAL
 *      physical ordering, not on the guarantee. That is precisely the shape
 *      of the recorded creator-gallery defect — 313 rows tied on one column —
 *      which the tiebreak exists to prevent. A query plan change, a different
 *      engine, or a secondary-index scan could reorder tied rows at any time.
 *
 *   2. THE TOMBSTONE WRITE ORDER. `markRemoved()` writes the ACTOR before the
 *      MARKER, and swapping them kills no case. Presence of `removed_at` is
 *      what makes a row a tombstone, so marker-first leaves a window in which
 *      a reader sees a tombstone whose actor — and therefore whose label — is
 *      not yet stored, and `labelFor(null)` has no answer. Observing that
 *      window needs a concurrent reader mid-transaction, which this harness
 *      cannot stage.
 *
 * So both are pinned HERE, at source, by position. This is the same
 * construction `AnnouncementPublicationGuardTest` uses for arm C's block
 * ordering, and for the same reason: a positional assertion is weaker than a
 * behavioural one, but it is strictly better than an invariant nothing checks.
 */
#[Group('unit')]
final class AnnouncementCommentStorageInvariantsTest extends TestCase
{
    private const REPO = 'app/Domain/Core/Repositories/AnnouncementCommentRepository.php';

    private static function src(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $s    = file_get_contents($path);

        self::assertIsString($s, 'could not read ' . $rel);
        self::assertGreaterThan(2000, strlen($s), $rel . ' is implausibly short — wrong path?');

        return $s;
    }

    /** Source with comments stripped, so explanatory prose cannot satisfy an assertion. */
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

    private static function body(string $rel, string $method): string
    {
        $code  = self::code($rel);
        $start = strpos($code, 'function ' . $method . '(');
        self::assertIsInt($start, $method . ' not found in ' . $rel);

        $rest = substr($code, $start + 1);
        $next = strpos($rest, 'function ');

        return $next === false ? $rest : substr($rest, 0, $next);
    }

    // ── 1. the unique tiebreak ───────────────────────────────────────────

    /**
     * ⚠⚠ The ORDER BY must carry `comment_ID` as a second key. Without it,
     * two comments in the same second have no defined relative order, and a
     * keyset cursor over an undefined order can skip or repeat rows — which
     * dedupe cannot repair, because a SKIPPED row is simply never fetched.
     */
    public function testTheOrderByCarriesTheUniqueTiebreak(): void
    {
        $code = self::code(self::REPO);

        self::assertMatchesRegularExpression(
            '/ORDER\s+BY\s+c\.comment_date_gmt\s+ASC\s*,\s*c\.comment_ID\s+ASC/i',
            $code,
            'the thread order must be (comment_date_gmt, comment_ID) ASC — the second key is the tiebreak'
        );
    }

    /**
     * And the cursor comparison must use the SAME pair, or the keyset and the
     * sort disagree and paging drifts.
     */
    public function testTheCursorComparisonUsesTheSamePairAsTheOrderBy(): void
    {
        $code = self::code(self::REPO);

        self::assertMatchesRegularExpression(
            '/c\.comment_date_gmt\s*>\s*%s\s*OR\s*\(\s*c\.comment_date_gmt\s*=\s*%s\s*AND\s*c\.comment_ID\s*>\s*%d\s*\)/i',
            $code,
            'the cursor must compare the same two columns the ORDER BY sorts on'
        );
    }

    /**
     * ⚠ ASCENDING, in both places. This is the only ascending surface in the
     * announcement feature; every list is newest-first. A `<` here against an
     * `ASC` order serves the first page forever.
     */
    public function testTheComparisonIsStrictlyAfterNotBefore(): void
    {
        $body = self::body(self::REPO, 'listForAnnouncement');

        self::assertStringContainsString('comment_date_gmt > %s', $body, 'oldest-first pages with >');
        self::assertStringNotContainsString(
            'comment_date_gmt < %s',
            $body,
            'a descending comparison against an ascending ORDER BY repeats the first page forever'
        );
    }

    // ── 2. the tombstone write order ─────────────────────────────────────

    /**
     * ⚠⚠ THE ACTOR IS WRITTEN BEFORE THE MARKER.
     *
     * Presence of `removed_at` is what makes a row a tombstone. Writing it
     * first would leave a window where a reader sees a tombstone whose actor
     * is not yet stored — and `labelFor(null)` returns null, so there would be
     * no label to render. Asserted by POSITION because the window itself needs
     * a concurrent reader this harness cannot stage.
     */
    public function testTheRemovalWritesTheActorBeforeTheMarker(): void
    {
        $body = self::body(self::REPO, 'markRemoved');

        $actor  = strpos($body, 'META_REMOVED_ACTOR');
        $marker = strpos($body, 'META_REMOVED_AT');

        self::assertIsInt($actor, 'the actor write must exist');
        self::assertIsInt($marker, 'the marker write must exist');
        self::assertLessThan(
            $marker,
            $actor,
            'the ACTOR must be written first — the marker is what makes the row a tombstone'
        );
    }

    /**
     * ⚠ And if the marker write fails, the actor must be ROLLED BACK, so a
     * half-written tombstone cannot persist as an actor with no marker.
     */
    public function testAFailedMarkerWriteRollsTheActorBack(): void
    {
        $body = self::body(self::REPO, 'markRemoved');

        $marker = strpos($body, 'META_REMOVED_AT');
        self::assertIsInt($marker);

        $after = substr($body, $marker);
        self::assertStringContainsString(
            'dropMeta(',
            $after,
            'the failure arm must delete the actor it already wrote'
        );
        self::assertStringContainsString('RepositoryException', $after, 'and must refuse, not return true');
    }

    /**
     * ⚠ Restoration is the REVERSE order — marker first, then actor — so there
     * is no instant at which a row is a tombstone with no actor to label it.
     */
    public function testRestorationClearsTheMarkerBeforeTheActor(): void
    {
        $body = self::body(self::REPO, 'clearRemoved');

        $marker = strpos($body, 'META_REMOVED_AT');
        $actor  = strpos($body, 'META_REMOVED_ACTOR');

        self::assertIsInt($marker);
        self::assertIsInt($actor);
        self::assertLessThan(
            $actor,
            $marker,
            'clearing is the mirror of stamping: marker first, so the row stops being a tombstone first'
        );
    }

    // ── 3. absences that keep the storage honest ─────────────────────────

    /**
     * ⚠ The repository must never use WordPress's comment-meta API. It READS
     * its meta from an injected table, and the WP API always writes to the
     * real `wp_commentmeta` — so mixing them writes the tombstone to one table
     * and reads it from another, passing in production and proving nothing
     * under test. Found while writing the integration suite; pinned so it
     * cannot come back.
     */
    public function testTheRepositoryNeverUsesTheWordPressCommentMetaApi(): void
    {
        $code = self::code(self::REPO);

        foreach (['update_comment_meta', 'delete_comment_meta', 'get_comment_meta', 'add_comment_meta'] as $fn) {
            self::assertStringNotContainsString(
                $fn,
                $code,
                $fn . ' writes the REAL commentmeta table while the reads use the injected one'
            );
        }
    }

    /**
     * ⚠ And it must never use `WP_Comment_Query` / `get_comments()`: A1's
     * `comments_clauses` guard excludes this comment type from every comment
     * query in WordPress, so the repository's own reads would be filtered out
     * by its own protection.
     */
    public function testTheRepositoryNeverUsesTheWordPressCommentQuery(): void
    {
        $code = self::code(self::REPO);

        foreach (['WP_Comment_Query', 'get_comments(', 'get_comment('] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $code,
                $needle . ' is filtered by the A1 comments_clauses guard and cannot see announcement comments'
            );
        }
    }

    /** No `SELECT *` — §2, and arch-guardrails Rule 2 enforces it. */
    public function testEveryReadNamesItsColumns(): void
    {
        $code = self::code(self::REPO);

        self::assertStringNotContainsString('SELECT *', $code);
        self::assertStringContainsString('COLUMNS', $code, 'an explicit column list must exist');
    }

    /** Every read is bounded — a thread read must never be unbounded. */
    public function testTheThreadReadIsAlwaysBounded(): void
    {
        $body = self::body(self::REPO, 'listForAnnouncement');

        self::assertStringContainsString('LIMIT %d', $body, 'the list read must always carry a LIMIT');
        self::assertStringContainsString('clampLimit(', $body, 'and the limit must be clamped, not trusted');
    }
}
