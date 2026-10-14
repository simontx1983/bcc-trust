<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Announcement comments must not be served by WordPress's own surfaces.
 *
 * Drives the REAL guard callbacks on `AnnouncementPostType`, plus the real
 * registration arguments.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. A GENERIC COMMENT READ RETURNS ANNOUNCEMENT COMMENTS.
 *     `GET /wp/v2/comments` WITHOUT `?post=` skips the post loop, and core
 *     ends by allowing `edit_comment` (→ `moderate_comments`) — so an
 *     Editor or Administrator could enumerate announcement comments,
 *     removed ones included, outside the audited admin route. The
 *     `comments_clauses` exclusion is the only thing closing that.
 *  2. A PASSWORD MAKES THE TYPE READABLE. A password-protected post never
 *     reaches `check_read_permission()` at all: core takes the
 *     `post_password_required()` branch, which returns
 *     `current_user_can('edit_post', …)` and NEVER CONSULTS
 *     `show_in_rest`. Two defences are required, not one.
 *  3. A `note`-TYPE COMMENT IS TREATED AS OURS, OR OURS AS A NOTE. Core
 *     skips the entire post-permission check for `comment_type === 'note'`.
 *     Our type must not be `note`, and the exclusion must not swallow
 *     core's notes.
 *  4. A MALFORMED STORED RECORD ESCAPES THE GUARDS. "We only ever write
 *     the canonical value" is a claim about the writer, not a property of
 *     the table — a plugin, an import, a migration or hand-written SQL can
 *     store `BCC_Ann_Comment ` with different case and padding.
 *     Privacy must not depend on the writer being well-behaved.
 *  5. ORDINARY COMMENTS BREAK. A guard that withheld every comment would
 *     satisfy everything above while destroying the site's own discussion.
 *     Every assertion here has an ordinary-comment counterpart.
 *  6. A GUARD IS GATED ON THE FEATURE SWITCH. Turning announcements off
 *     must never *open* the bypass surface.
 *
 * ⚠ WHAT THIS FILE CANNOT PROVE. WordPress core is not loadable in this
 * suite, so core's permission ladders are not EXECUTED here — they were
 * verified by reading 7.0.2 source. What is proved here is the code
 * bcc-trust owns: the registration arguments and the four guard callbacks.
 * The exclusion's real SQL is exercised against real hostile rows in
 * {@see \BCC\Trust\Tests\Integration\AnnouncementCommentExclusionIntegrationTest}.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AnnouncementCommentGuardsTest extends TestCase
{
    /** @var class-string */
    private const PT = \BCC\Trust\Core\Announcements\AnnouncementPostType::class;

    private const ANN_POST      = 501;
    private const ORDINARY_POST = 502;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/announcement-guard-stubs.php';

        $GLOBALS['__bcc_ag_filters']    = [];
        $GLOBALS['__bcc_ag_actions']    = [];
        $GLOBALS['__bcc_ag_post_types_registered'] = [];
        $GLOBALS['__bcc_ag_post_types'] = [
            self::ANN_POST      => 'bcc_announcement',
            self::ORDINARY_POST => 'post',
        ];
        $GLOBALS['__bcc_ag_comments'] = [];
    }

    // ── 1. Registration arguments ─────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function cptArgs(): array
    {
        (self::PT)::registerPostType();
        /** @var array<string, array<string, mixed>> $reg */
        $reg = $GLOBALS['__bcc_ag_post_types_registered'] ?? [];
        self::assertArrayHasKey('bcc_announcement', $reg, 'the type must be registered');
        return $reg['bcc_announcement'];
    }

    /**
     * ⚠ `show_in_rest => false` is the control that closes the
     * single-comment routes, the post-scoped collection and comment
     * creation for EVERY role including administrators. Flipping it opens
     * all three at once, which is why it is asserted by value and not just
     * assumed from the array.
     */
    public function testTheTypeIsPrivateAndAbsentFromCoreRest(): void
    {
        $args = self::cptArgs();

        self::assertFalse($args['show_in_rest'], 'show_in_rest is a security control');
        foreach (['public', 'publicly_queryable', 'show_ui', 'show_in_menu',
                  'show_in_nav_menus', 'show_in_admin_bar', 'has_archive', 'rewrite'] as $k) {
            self::assertFalse($args[$k], $k);
        }
        self::assertTrue($args['exclude_from_search']);
    }

    /**
     * MUST-NOT-HAPPEN #2, defence 1. Custom capabilities are what make the
     * `post_password_required()` branch fail for an administrator: with
     * `map_meta_cap => true` and a custom `capability_type`, core maps
     * `edit_post` to `edit_bcc_announcement`, which no role holds by
     * default. A generic `post` capability type would hand it to every
     * Editor.
     */
    public function testCapabilitiesAreCustomSoGenericEditPostsCannotSatisfyThem(): void
    {
        $args = self::cptArgs();

        self::assertTrue($args['map_meta_cap'], 'map_meta_cap must be on or caps are not mapped');
        self::assertSame(['bcc_announcement', 'bcc_announcements'], $args['capability_type']);
        self::assertNotSame('post', $args['capability_type']);
    }

    /** `comments` support is absent — the discussion is ours, not core's. */
    public function testTheTypeDoesNotSupportCoreComments(): void
    {
        self::assertNotContains('comments', self::cptArgs()['supports']);
    }

    public function testTheSlugFitsThePostTypeColumn(): void
    {
        self::assertLessThanOrEqual(20, strlen((self::PT)::POST_TYPE));
    }

    /**
     * ⚠⚠ THE CONSTRAINT THAT ACTUALLY BIT. `wp_comments.comment_type` is
     * `varchar(20)` (`wp-admin/includes/schema.php:114`). The planning
     * value `bcc_announcement_comment` was 24 characters and could not be
     * stored at all — MySQL rejected the insert with "Data too long for
     * column 'comment_type'".
     *
     * The whole unit suite passed with the unstorable value, because no
     * unit test touches a width-constrained column. Only the integration
     * suite, executing real SQL against a real column, caught it. This
     * assertion is the cheap guard that stops it recurring.
     *
     * ⚠ It is an EXACT fit, so a future variant must be SHORTER. The
     * assertion is `<=` rather than `===` because shortening is fine and
     * lengthening is not.
     */
    public function testTheCommentTypeFitsTheCommentTypeColumn(): void
    {
        self::assertLessThanOrEqual(
            20,
            strlen((self::PT)::COMMENT_TYPE),
            'wp_comments.comment_type is varchar(20) — a longer value cannot be stored'
        );
    }

    /**
     * ⚠⚠ FITTING IS NOT ENOUGH — THE PADDED VARIANTS MUST FIT TOO, and a
     * 20-character exact fit silently fails this.
     *
     * The guards normalize whitespace because a padded record CAN exist in
     * the column. But it can only exist if the canonical value leaves room
     * for the padding: at 20 characters, ` bcc_announce_comment` is 21 and
     * MySQL rejects it — so the most realistic corruption becomes
     * impossible to store and therefore impossible to prove the guards
     * withhold. That is how the first fix for this still failed CI.
     *
     * Headroom is therefore a testability requirement, not a style
     * preference.
     */
    public function testThePaddedAndCaseShiftedVariantsAlsoFitTheColumn(): void
    {
        $type = (self::PT)::COMMENT_TYPE;

        foreach ([' ' . $type, $type . ' ', ' ' . $type . ' ', strtoupper($type)] as $variant) {
            self::assertLessThanOrEqual(
                20,
                strlen($variant),
                'a storable corruption must fit, or the privacy guarantee is unprovable: '
                    . var_export($variant, true)
            );
            // …and each must still be recognised as ours.
            self::assertTrue((self::PT)::isAnnouncementCommentType($variant));
        }
    }

    /**
     * ⚠⚠⚠ WHY THE LENGTH IS A PRIVACY MATTER, NOT A TIDINESS ONE.
     *
     * Under a non-strict SQL mode an over-long type is TRUNCATED rather
     * than rejected. A truncated value does not equal ours, so the
     * exclusion predicate would not match it — and the comment would
     * become publicly readable through exactly the generic read this
     * feature exists to close. A silent truncation is therefore a privacy
     * failure, not a cosmetic one.
     *
     * Pinned here so the reasoning survives: a one-character truncation of
     * our own type must NOT be recognised as ours.
     */
    public function testAOneCharacterTruncationIsNotRecognisedAsOurs(): void
    {
        $truncated = substr((self::PT)::COMMENT_TYPE, 0, -1);

        self::assertNotSame((self::PT)::COMMENT_TYPE, $truncated);
        self::assertFalse(
            (self::PT)::isAnnouncementCommentType($truncated),
            'a truncated type is a DIFFERENT type — which is why the value must fit the column'
        );
    }

    // ── 2. Guards are registered, and NOT behind the switch ───────────────

    /**
     * MUST-NOT-HAPPEN #6. `register()` adds the guards at load with no
     * reference to `bcc_announcements_enabled`, so the safest-looking
     * configuration cannot be the least safe one.
     */
    public function testAllFourGuardsRegisterAndNoneConsultsTheFeatureSwitch(): void
    {
        (self::PT)::register();

        foreach (['comments_open', 'comments_clauses', 'map_meta_cap', 'wp_insert_post_data'] as $hook) {
            self::assertArrayHasKey($hook, $GLOBALS['__bcc_ag_filters'], "{$hook} must be filtered");
        }
        self::assertArrayHasKey('init', $GLOBALS['__bcc_ag_actions']);

        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Announcements/AnnouncementPostType.php'
        );
        self::assertNotSame('', $src, 'source must be readable for this guard to mean anything');
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $src);   // docblocks DISCUSS the switch
        self::assertStringNotContainsString(
            'bcc_announcements_enabled',
            $code,
            'no guard may be gated on the feature switch'
        );
    }

    // ── 3. Guard 1: comments_open ─────────────────────────────────────────

    public function testCommentsAreClosedForTheTypeAndOpenElsewhere(): void
    {
        self::assertFalse((self::PT)::closeComments(true, self::ANN_POST));
        self::assertFalse((self::PT)::closeComments(false, self::ANN_POST));

        // MUST-NOT-HAPPEN #5: an ordinary post is passed through verbatim.
        self::assertTrue((self::PT)::closeComments(true, self::ORDINARY_POST));
        self::assertFalse((self::PT)::closeComments(false, self::ORDINARY_POST));
    }

    public function testAnUnknownPostIsPassedThroughRatherThanClosed(): void
    {
        self::assertTrue((self::PT)::closeComments(true, 999999));
    }

    // ── 4. Guard 2: the generic-read exclusion — MUST-NOT-HAPPEN #1 ───────

    public function testTheExclusionIsAppendedToAnExistingWhereClause(): void
    {
        $out = (self::PT)::excludeFromCommentQueries(['where' => "comment_approved = '1'"]);

        self::assertStringStartsWith("comment_approved = '1' AND ", $out['where']);
        self::assertStringContainsString((self::PT)::commentExclusionSql(), $out['where']);
    }

    public function testTheExclusionIsIdempotent(): void
    {
        $once  = (self::PT)::excludeFromCommentQueries(['where' => 'x = 1']);
        $twice = (self::PT)::excludeFromCommentQueries($once);

        self::assertSame($once['where'], $twice['where'], 'a doubled predicate is a bug');
    }

    public function testAnEmptyOrMalformedClauseSetIsHandled(): void
    {
        self::assertStringContainsString(
            (self::PT)::commentExclusionSql(),
            (self::PT)::excludeFromCommentQueries([])['where']
        );
        self::assertSame([], (self::PT)::excludeFromCommentQueries('not an array'));
        self::assertStringContainsString(
            (self::PT)::commentExclusionSql(),
            (self::PT)::excludeFromCommentQueries(['where' => 12345])['where'],
            'a non-string where must not silently drop the exclusion'
        );
    }

    /**
     * MUST-NOT-HAPPEN #3, second half: the predicate names OUR type only.
     * Core's `note` comments and ordinary `comment` rows must be untouched
     * — a broader exclusion would quietly break the site's discussion and
     * core's editorial notes.
     */
    public function testTheExclusionNamesOnlyOurTypeAndSparesNotesAndOrdinaryComments(): void
    {
        $sql = (self::PT)::commentExclusionSql();

        self::assertStringContainsString('bcc_ann_comment', $sql);
        self::assertStringNotContainsString("'note'", $sql);
        self::assertStringNotContainsString("'comment'", $sql);
        self::assertStringNotContainsString('comment_type IS NOT NULL', $sql);
    }

    // ── 5. Guard 4: edit_comment denial ───────────────────────────────────

    public function testEditCommentIsHardDeniedForAnAnnouncementComment(): void
    {
        $GLOBALS['__bcc_ag_comments'][11] = (object) [
            'comment_ID' => 11, 'comment_type' => 'bcc_ann_comment',
        ];

        self::assertSame(
            ['do_not_allow'],
            (self::PT)::denyCommentEditing(['moderate_comments'], 'edit_comment', 7, [11])
        );
    }

    public function testEditCommentIsUntouchedForAnOrdinaryComment(): void
    {
        $GLOBALS['__bcc_ag_comments'][12] = (object) [
            'comment_ID' => 12, 'comment_type' => 'comment',
        ];

        self::assertSame(
            ['moderate_comments'],
            (self::PT)::denyCommentEditing(['moderate_comments'], 'edit_comment', 7, [12])
        );
    }

    /** MUST-NOT-HAPPEN #3: a core `note` is not ours to deny. */
    public function testEditCommentIsUntouchedForACoreNote(): void
    {
        $GLOBALS['__bcc_ag_comments'][13] = (object) [
            'comment_ID' => 13, 'comment_type' => 'note',
        ];

        self::assertSame(
            ['moderate_comments'],
            (self::PT)::denyCommentEditing(['moderate_comments'], 'edit_comment', 7, [13])
        );
    }

    public function testOtherCapabilitiesAndUnknownCommentsArePassedThrough(): void
    {
        $GLOBALS['__bcc_ag_comments'][14] = (object) [
            'comment_ID' => 14, 'comment_type' => 'bcc_ann_comment',
        ];

        // A different capability is not ours to touch.
        self::assertSame(
            ['moderate_comments'],
            (self::PT)::denyCommentEditing(['moderate_comments'], 'edit_post', 7, [14])
        );
        // An unresolvable comment must not be WIDENED either — core decides.
        self::assertSame(
            ['moderate_comments'],
            (self::PT)::denyCommentEditing(['moderate_comments'], 'edit_comment', 7, [999999])
        );
        // No args at all.
        self::assertSame(
            ['moderate_comments'],
            (self::PT)::denyCommentEditing(['moderate_comments'], 'edit_comment', 7, [])
        );
    }

    // ── 6. Malformed stored records — MUST-NOT-HAPPEN #4 ──────────────────

    /**
     * Nothing of ours writes these spellings. That is exactly why they are
     * tested: privacy must not rest on the writer being well-behaved.
     */
    public function testAMalformedStoredCommentTypeIsStillRecognisedAsOurs(): void
    {
        foreach ([
            'bcc_ann_comment',
            'BCC_Ann_Comment',
            ' bcc_ann_comment ',
            "bcc_ann_comment\t",
            'BCC_ANN_COMMENT',
        ] as $stored) {
            self::assertTrue(
                (self::PT)::isAnnouncementCommentType($stored),
                'stored as ' . var_export($stored, true)
            );
        }
    }

    public function testAMalformedTypeIsAlsoHardDeniedAtTheCapabilityGate(): void
    {
        $GLOBALS['__bcc_ag_comments'][15] = (object) [
            'comment_ID' => 15, 'comment_type' => ' BCC_Ann_Comment ',
        ];

        self::assertSame(
            ['do_not_allow'],
            (self::PT)::denyCommentEditing(['moderate_comments'], 'edit_comment', 7, [15])
        );
    }

    /** …and a lookalike that is NOT ours must not be captured. */
    public function testALookalikeTypeIsNotTreatedAsOurs(): void
    {
        foreach ([
            'bcc_ann_commen',
            'bcc_announcement',
            'announcement_comment',
            'comment',
            'note',
            '',
        ] as $other) {
            self::assertFalse(
                (self::PT)::isAnnouncementCommentType($other),
                'must not capture ' . var_export($other, true)
            );
        }
    }

    // ── 7. The password residual — MUST-NOT-HAPPEN #2, defence 2 ──────────

    /**
     * Enforced, not assumed. A password on an announcement sends core down
     * the `post_password_required()` branch, which never consults
     * `show_in_rest`. Making the branch UNREACHABLE is stronger than
     * relying on nobody setting one, because a plugin, an import or a
     * migration can.
     */
    public function testAPasswordOnAnAnnouncementIsForcedEmptyOnSave(): void
    {
        $out = (self::PT)::forcePasswordEmpty([
            'post_type'     => 'bcc_announcement',
            'post_password' => 'hunter2',
        ]);

        self::assertSame('', $out['post_password']);
    }

    public function testAPasswordOnAnOrdinaryPostIsLeftAlone(): void
    {
        $out = (self::PT)::forcePasswordEmpty([
            'post_type'     => 'post',
            'post_password' => 'hunter2',
        ]);

        self::assertSame('hunter2', $out['post_password'], 'ordinary posts keep their passwords');
    }

    public function testTheFilterToleratesMissingKeysAndNonArrays(): void
    {
        self::assertSame([], (self::PT)::forcePasswordEmpty('nope'));
        self::assertSame(
            ['post_type' => 'bcc_announcement', 'post_password' => ''],
            (self::PT)::forcePasswordEmpty(['post_type' => 'bcc_announcement'])
        );
        self::assertSame(['foo' => 'bar'], (self::PT)::forcePasswordEmpty(['foo' => 'bar']));
    }

    // ── 8. Our type is not a core `note` ──────────────────────────────────

    /**
     * MUST-NOT-HAPPEN #3, first half. Core skips the entire
     * post-permission check for `comment_type === 'note'`, so naming our
     * type `note` would opt us out of the very ladder `show_in_rest`
     * guards. Pinned by value so a later rename cannot land on it.
     */
    public function testOurCommentTypeIsNeitherNoteNorAnythingCoreSpecialCases(): void
    {
        self::assertSame('bcc_ann_comment', (self::PT)::COMMENT_TYPE);
        self::assertNotSame('note', (self::PT)::COMMENT_TYPE);
        self::assertNotSame('comment', (self::PT)::COMMENT_TYPE);
        self::assertNotSame('', (self::PT)::COMMENT_TYPE);
    }
}
