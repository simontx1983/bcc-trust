<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A4 — §4.32.8 announcement feed projection, asserted over source.
 *
 * ⚠ The BEHAVIOUR lives in `scripts/tests/announcement-feed-projection.php`,
 * which drives real publish / schedule / archive journeys against a real
 * `peepso_activities` table in the disposable WordPress stack. It cannot be
 * CI-wired (no WordPress in either harness), so what lives here is what source
 * can prove: the module registration, the delegation, the ordering, and a set
 * of absences no input could demonstrate.
 *
 * ⚠⚠ The single most important case in this file is
 * {@see testTheProjectionDoesNotReimplementTheVisibilityPredicate}. §4.32.8
 * requires projection only once a notice is "due AND authorized", which is the
 * §4.32.1 three-predicate test — and the whole point of routing that through
 * `AnnouncementRepository::findPublic()` is that there is exactly ONE copy of
 * it. A hand-written PHP copy in the projection is the §11 duplication this
 * codebase forbids, and predicate 3 (the publication-authorization marker) is
 * the one such a copy would be likeliest to drop.
 */
#[Group('unit')]
final class AnnouncementFeedProjectionTest extends TestCase
{
    private const WRITER  = 'app/Domain/Core/Services/Feed/ActivityStreamWriter.php';
    private const ACT     = 'app/Domain/Core/Repositories/PeepSoActivityWriter.php';
    private const COLD    = 'app/Domain/Core/Services/FeedColdStartService.php';
    private const SERVICE = 'app/Domain/Core/Services/AnnouncementWriteService.php';
    private const GUARD   = 'app/Domain/Core/Announcements/AnnouncementPublicationGuard.php';
    private const PLUGIN  = 'app/Domain/Core/Plugin.php';

    private static function src(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $s    = file_get_contents($path);

        self::assertIsString($s, 'could not read ' . $rel);
        self::assertGreaterThan(500, strlen($s), $rel . ' is implausibly short — wrong path?');

        return $s;
    }

    /** Source with comments and docblocks stripped, so prose cannot satisfy an assertion. */
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

    /** The body of one method, by name, bounded to the next method declaration. */
    private static function body(string $rel, string $method): string
    {
        $code  = self::code($rel);
        $start = strpos($code, 'function ' . $method . '(');
        self::assertIsInt($start, $method . ' not found in ' . $rel);

        $rest = substr($code, $start + 1);
        $next = strpos($rest, 'function ');

        return $next === false ? $rest : substr($rest, 0, $next);
    }

    // ── 1. the module is registered, or insert() refuses everything ──────

    /**
     * ⚠ `PeepSoActivityWriter::insert()` returns 0 for any module name absent
     * from its map, silently. Without this entry the whole projection is a
     * no-op that logs an error nobody asked for.
     */
    public function testTheAnnouncementModuleIsRegisteredAs205(): void
    {
        $code = self::code(self::ACT);

        self::assertMatchesRegularExpression(
            "/'announcement'\s*=>\s*205/",
            $code,
            'module 205 must be registered under the name the projection passes'
        );
    }

    /**
     * The numeric id is not ours to choose, and the two halves live in two
     * different bcc-core files — which is exactly why both are pinned:
     *
     *   - `FeedItemNormalizer::MODULE_TO_KIND` turns 205 into the kind
     *     `announcement`. Pick a different number in bcc-trust and the card
     *     renders with no kind at all.
     *   - `ReactionGrammarMap` pins that kind to `GRAMMAR_NONE`, which is what
     *     makes §4.32.8's "no reactions, no stoke, no helpful mark" a property
     *     of the grammar rather than a hope.
     *
     * ⚠ A first version of this case looked for the module map inside
     * `ReactionGrammarMap.php` and failed. The map was never there; the
     * assertion was pointed at the wrong file. Worth recording, because a
     * cross-repo assertion that reads the wrong file is the kind that later
     * gets "fixed" by deleting it.
     */
    #[DataProvider('coreMapHalves')]
    public function testTheKindAndGrammarMatchTheCanonicalCoreMaps(
        string $relPath,
        string $pattern,
        string $why
    ): void {
        $core = dirname(__DIR__, 3) . '/bcc-core/src/' . $relPath;

        if (!is_file($core)) {
            self::markTestSkipped('bcc-core sibling not checked out — CI provides it at this path');
        }

        $src = (string) file_get_contents($core);
        self::assertGreaterThan(500, strlen($src), $relPath . ' is implausibly short — wrong path?');

        self::assertMatchesRegularExpression('/' . $pattern . '/', $src, $why);
    }

    /** @return array<string, array{string, string, string}> */
    public static function coreMapHalves(): array
    {
        return [
            'module 205 -> kind announcement' => [
                'Feed/FeedItemNormalizer.php',
                "'205'\s*=>\s*'announcement'",
                'bcc-core must still map module 205 to the announcement kind',
            ],
            'kind announcement -> GRAMMAR_NONE' => [
                'Feed/ReactionGrammarMap.php',
                "'announcement'\s*=>\s*self::GRAMMAR_NONE",
                'the announcement kind must still take no reactions',
            ],
        ];
    }

    // ── 2. the cold-start verb ───────────────────────────────────────────

    #[DataProvider('coldStartKeys')]
    public function testTheColdStartVerbExistsForBothSpellings(string $key): void
    {
        $code = self::code(self::COLD);

        self::assertMatchesRegularExpression(
            "/'" . preg_quote($key, '/') . "'\s*=>\s*'POSTED an announcement'/",
            $code,
            'without this entry an announcement falls through to FALLBACK_RECENT_ACTION'
        );
    }

    /** @return array<string, array{string}> */
    public static function coldStartKeys(): array
    {
        return [
            'numeric module id' => ['205'],
            'module name'       => ['announcement'],
        ];
    }

    /**
     * ⚠⚠ §2.7 / §J.5 CADENCE-PRESSURE POLICY, CHECKED HERE BECAUSE THE GUARD
     * CANNOT SEE THIS FILE.
     *
     * `scripts/cadence-pressure-guard.sh` scopes its PHP half to four named
     * services — ContestedStateExplainer, NotificationDispatcher,
     * AttestationService and PolarizationTransitionNotifier. FeedColdStartService
     * is NOT among them, so the guard neither passes nor fails this copy: it
     * never reads it. Review is otherwise the only check, which is exactly the
     * situation that lets nudge-shaped copy in. This case is the check.
     *
     * The patterns below are copied from the guard so the two cannot disagree
     * about what a nudge is.
     */
    #[DataProvider('nudgePatterns')]
    public function testTheAnnouncementVerbIsNotNudgeShaped(string $pattern): void
    {
        $code = self::code(self::COLD);
        $start = strpos($code, 'VERB_BY_MODULE');
        self::assertIsInt($start);

        $map = substr($code, $start, 1400);

        self::assertDoesNotMatchRegularExpression(
            '/' . $pattern . '/i',
            $map,
            'the feed verb map must carry no cadence-pressure copy'
        );
    }

    /** @return array<string, array{string}> */
    public static function nudgePatterns(): array
    {
        return [
            'havent'            => ["haven['\u{2019}]?t"],
            'days since you'    => ['days since (you|your)'],
            'in the last N'     => ['in the last [0-9]+ (day|week|month)'],
            'streak'            => ['\bstreak\b'],
            'reminder to act'   => ['remind(er|ing)? to (attest|vouch|cast|back|stand behind)'],
            'you should'        => ['you should (attest|vouch|cast|back|stand behind)'],
            'consider verbing'  => ['consider (attesting|casting|vouching|backing|standing behind)'],
            'active operators'  => ['active operators (attest|vouch|cast)'],
        ];
    }

    // ── 3. THE CENTRAL ONE: no second copy of the predicate ──────────────

    /**
     * ⚠⚠ The projection must ASK for the predicate, never restate it.
     *
     * `findPublic()` composes `AnnouncementVisibility::publicPredicateSql()`,
     * which is the single canonical expression of §4.32.1. If this handler ever
     * grows its own status/date/marker comparison, the two can drift and the
     * drift is invisible until an unauthorized notice reaches the floor.
     */
    public function testTheProjectionDoesNotReimplementTheVisibilityPredicate(): void
    {
        $body = self::body(self::WRITER, 'handleAnnouncementPublished');

        // It must delegate.
        self::assertStringContainsString(
            'findPublic(',
            $body,
            'the projection must resolve due-and-authorized through the repository'
        );

        // And it must NOT restate any of the three predicates itself.
        self::assertStringNotContainsString(
            'META_AUTHORIZED_AT',
            $body,
            'predicate 3 must not be re-derived in the projection'
        );
        self::assertStringNotContainsString(
            'META_AUTHORIZED_USER_ID',
            $body,
            'predicate 3 must not be re-derived in the projection'
        );
        self::assertStringNotContainsString(
            "post_status",
            $body,
            'predicate 1 must not be re-derived in the projection'
        );
        self::assertStringNotContainsString(
            'post_date_gmt',
            $body,
            'predicate 2 must not be re-derived in the projection'
        );
    }

    /**
     * An unreadable predicate is not permission to publish. `findPublic()`
     * throws `RepositoryException` on a DB fault rather than returning null,
     * and the projection must treat the throw as a refusal.
     */
    public function testAnUnreadablePredicateFailsClosed(): void
    {
        $body = self::body(self::WRITER, 'handleAnnouncementPublished');

        self::assertStringContainsString('try{', str_replace([' ', "\n"], '', $body));
        self::assertStringContainsString('catch', $body);

        // The catch arm must return, not fall through to the insert.
        $catchAt = strpos($body, 'catch');
        self::assertIsInt($catchAt);
        $insertAt = strpos($body, '->insert(');
        self::assertIsInt($insertAt);
        $arm = substr($body, $catchAt, $insertAt - $catchAt);
        self::assertStringContainsString('return;', $arm, 'the catch arm must refuse, not continue to insert');
    }

    // ── 4. exactly one activity, and edits do not bump ───────────────────

    /**
     * ⚠ "Exactly one activity per announcement" is the idempotency check, and
     * it must run BEFORE the insert — asserted by position, not by prose.
     *
     * ⚠⚠ CORRECTED: an earlier version of this docblock said this was ALSO
     * what makes "edits do not bump the feed" true. It is not.
     * {@see testAnEditFiresNoEventAtAll()} carries the real reason, and a
     * mutation control proved the difference: disabling this check broke no
     * case until a RE-DELIVERY case was added, because nothing in the suite
     * fired the event twice on a projectable row — and an edit cannot, since
     * it emits no event. The live probe's L7 now covers it.
     */
    public function testTheIdempotencyCheckPrecedesTheInsert(): void
    {
        $body = self::body(self::WRITER, 'handleAnnouncementPublished');

        $check  = strpos($body, 'findActIdForExternal(');
        $insert = strpos($body, '->insert(');

        self::assertIsInt($check, 'the idempotency check must exist');
        self::assertIsInt($insert, 'the insert must exist');
        self::assertLessThan(
            $insert,
            $check,
            'the existing-activity check must precede the insert, or an edit bumps the feed'
        );
    }

    public function testAnExistingActivityReturnsWithoutInserting(): void
    {
        $body = self::body(self::WRITER, 'handleAnnouncementPublished');

        $check = strpos($body, 'findActIdForExternal(');
        self::assertIsInt($check);
        $insert = strpos($body, '->insert(');
        self::assertIsInt($insert);

        $between = substr($body, $check, $insert - $check);
        self::assertStringContainsString('return;', $between, 'an existing activity must short-circuit');
    }

    /**
     * ⚠⚠ THE REAL REASON "EDITS DO NOT BUMP THE FEED" HOLDS: `edit()` emits no
     * event at all.
     *
     * It returns through `readBackOrFail()` and never reaches
     * `finishPublicationWrite()`, which is the only place the direct-publish
     * event is fired. So the edit path never arrives at the projection, let
     * alone at its idempotency check.
     *
     * This case exists because the opposite was documented, and a reader who
     * believed it could move the event into `edit()` thinking idempotency
     * protected feed position.
     */
    public function testAnEditFiresNoEventAtAll(): void
    {
        $body = self::body(self::SERVICE, 'edit');

        self::assertStringNotContainsString(
            'do_action(',
            $body,
            'edit() must emit no event — that is what keeps an edit off the feed'
        );
        self::assertStringNotContainsString(
            'finishPublicationWrite(',
            $body,
            'edit() must not route through the publication write, which fires the event'
        );
    }

    /**
     * ⚠ And the DATE half of §4.32.8's "retains the original `published_at`":
     * a re-schedule is refused unless the row is still `future`, so a
     * published announcement's date cannot be moved by any edit.
     */
    public function testAPublishedRowCannotBeRescheduled(): void
    {
        $body = self::body(self::SERVICE, 'edit');

        self::assertStringContainsString('STATUS_FUTURE', $body, 'the re-schedule gate must test for future');
        self::assertStringContainsString('ERR_CONFLICT', $body, 'and refuse with a conflict otherwise');

        // The date write must sit AFTER that gate, or the gate is decorative.
        $gate = strpos($body, 'STATUS_FUTURE');
        $date = strpos($body, 'post_date_gmt');
        self::assertIsInt($gate);
        self::assertIsInt($date);
        self::assertLessThan($date, $gate, 'the status gate must precede the date write');
    }

    // ── 5. the switch, in both directions ────────────────────────────────

    /** §4.32.10 — while the switch is OFF, "creation, projection and reconciliation stop". */
    public function testProjectionIsGatedOnTheFeatureSwitch(): void
    {
        $body = self::body(self::WRITER, 'handleAnnouncementPublished');

        self::assertStringContainsString(
            'AnnouncementFeature::isEnabled()',
            $body,
            'projection must stop while the feature is off'
        );
    }

    /**
     * ⚠⚠ AND THE WITHDRAWAL MUST **NOT** BE GATED.
     *
     * If the switch were turned off between a publish and an archive, a gated
     * withdrawal would leave a live card on the floor for a notice its operator
     * has retired. Turning a feature off must never strand content that was
     * already projected — so this absence is deliberate and is pinned.
     */
    public function testWithdrawalIsNotGatedOnTheFeatureSwitch(): void
    {
        $body = self::body(self::WRITER, 'handleAnnouncementArchived');

        self::assertStringNotContainsString(
            'isEnabled()',
            $body,
            'withdrawal must work regardless of the switch, or an archived notice keeps its card'
        );
        self::assertStringContainsString('deleteByActId(', $body, 'withdrawal must actually delete');
    }

    // ── 6. blocked and archived rows ─────────────────────────────────────

    /**
     * A blocked row is stored `draft` with its marker stripped, so
     * `findPublic()` already refuses it. The explicit check is redundant BY
     * DESIGN: arm C owns the block and the repository owns the predicate, and a
     * change to either must not quietly let a withheld notice onto the floor.
     */
    public function testBlockedRowsAreRefusedExplicitly(): void
    {
        $body = self::body(self::WRITER, 'handleAnnouncementPublished');

        self::assertStringContainsString(
            'AnnouncementPublicationGuard::isBlocked(',
            $body,
            'a withheld notice must be refused by name, not only by predicate'
        );
    }

    /**
     * §4.32.6 keeps an archived announcement publicly readable, so
     * `findPublic()` returns it — which means archival must be checked
     * separately or archiving-then-republishing would mint a second card.
     */
    public function testArchivedRowsProjectNoNewActivity(): void
    {
        $body = self::body(self::WRITER, 'handleAnnouncementPublished');

        self::assertStringContainsString(
            'META_ARCHIVED_AT',
            $body,
            'an archived notice must gain no new feed presence'
        );
    }

    // ── 7. the card never hosts a discussion ─────────────────────────────

    /** §4.32.8 — "`peepso_disable_comments` is set on the backing post". */
    public function testTheCardCannotHostADiscussion(): void
    {
        $body = self::body(self::WRITER, 'handleAnnouncementPublished');

        self::assertStringContainsString(
            'peepso_disable_comments',
            $body,
            'the feed card must never host a discussion — the thread lives on the canonical page'
        );
    }

    // ── 8. the two firing sites, and why there are two ───────────────────

    /**
     * ⚠⚠ THE ORDERING FINDING THAT MADE TWO SITES NECESSARY.
     *
     * `publish()` calls `wp_update_post()` first, so `transition_post_status`
     * fires BEFORE `finishPublicationWrite()` writes the marker. A projection
     * listening only on the transition would be refused by its own authority
     * gate on every direct publish. So the direct path fires from the write
     * service (where both halves are stored) and the scheduled path fires from
     * the transition (where `affirm()` already wrote the marker at priority 1).
     */
    public function testTheDirectPublishEventFiresFromTheWriteService(): void
    {
        $body = self::body(self::SERVICE, 'finishPublicationWrite');

        self::assertStringContainsString(
            "do_action('bcc_announcement_published'",
            $body,
            'the direct-publish path must announce itself where the marker is written'
        );
    }

    /** It must be gated on the read-back, not merely on the meta writes. */
    public function testTheDirectPublishEventIsGatedOnTheReadBack(): void
    {
        $body = self::body(self::SERVICE, 'finishPublicationWrite');

        $fire = strpos($body, "do_action('bcc_announcement_published'");
        self::assertIsInt($fire);

        $before = substr($body, 0, $fire);
        self::assertStringContainsString(
            'readBackOrFail(',
            $before,
            'a row that could not be confirmed stored must not be announced'
        );
        self::assertMatchesRegularExpression(
            "/\\\$result\[['\"]ok['\"]\]/",
            $before,
            'the fire must be conditional on the confirmed result'
        );
    }

    /**
     * ⚠ And the guard's site must be SCOPED to the scheduled promotion. Firing
     * it for a direct publish too would be refused by the projection and would
     * log a misleading refusal for a publish that in fact succeeded.
     */
    public function testTheScheduledEventIsScopedToTheFuturePromotion(): void
    {
        $body = self::body(self::GUARD, 'onTransition');

        self::assertStringContainsString(
            "do_action('bcc_announcement_published'",
            $body,
            'the scheduled promotion must announce itself'
        );
        self::assertMatchesRegularExpression(
            "/\\\$old\s*===\s*'future'/",
            $body,
            'the guard must fire only for the future -> publish promotion'
        );
    }

    /** It must fire on the AUTHORIZED branch, never alongside a block. */
    public function testTheScheduledEventFiresOnlyWhenAuthorityHeld(): void
    {
        $body = self::body(self::GUARD, 'onTransition');

        $fire  = strpos($body, "do_action('bcc_announcement_published'");
        $block = strpos($body, 'self::block(');

        self::assertIsInt($fire);
        self::assertIsInt($block);
        self::assertLessThan(
            $block,
            $fire,
            'the event must sit on the authorized branch, which returns before the block'
        );
    }

    /** §4.32.13 — archive "withdraws the feed activity", only once confirmed. */
    public function testTheArchivedEventFiresOnlyAfterArchivalIsConfirmed(): void
    {
        $body = self::body(self::SERVICE, 'archive');

        $fire = strpos($body, "do_action('bcc_announcement_archived'");
        self::assertIsInt($fire, 'archive must withdraw the activity');

        $before = substr($body, 0, $fire);
        self::assertStringContainsString(
            'isArchived(',
            $before,
            'the withdrawal must follow the archival read-back, not precede it'
        );
    }

    // ── 9. the subscriptions ─────────────────────────────────────────────

    /**
     * ⚠ Registered UNCONDITIONALLY. Registering inside the feature switch would
     * make the withdrawal depend on the switch state at boot — so an operator
     * who disabled the feature could never retire an already-projected card.
     */
    #[DataProvider('subscribedEvents')]
    public function testBothEventsAreSubscribedAtBoot(string $event, string $handler): void
    {
        $code = self::code(self::PLUGIN);

        self::assertStringContainsString("add_action('" . $event . "'", $code, $event . ' must be subscribed');
        self::assertStringContainsString($handler . '(', $code, $handler . ' must be wired');
    }

    /** @return array<string, array{string, string}> */
    public static function subscribedEvents(): array
    {
        return [
            'published' => ['bcc_announcement_published', 'handleAnnouncementPublished'],
            'archived'  => ['bcc_announcement_archived', 'handleAnnouncementArchived'],
        ];
    }

    // ── 10. absences: no engagement, ever ────────────────────────────────

    /**
     * §4.32.8 / §4.32.15 — "No reactions, no stoke, no helpful mark." The
     * guarantee is enforced by `NoReactionsGate` and bcc-core's GRAMMAR_NONE,
     * both already merged and tested. What this case pins is that A4 did not
     * quietly open a door while adding the projection: the projection must not
     * mention any engagement surface at all.
     */
    #[DataProvider('engagementSurfaces')]
    public function testTheProjectionTouchesNoEngagementSurface(string $needle): void
    {
        $body = self::body(self::WRITER, 'handleAnnouncementPublished');

        self::assertStringNotContainsString(
            $needle,
            $body,
            'the announcement projection must never touch ' . $needle
        );
    }

    /** @return array<string, array{string}> */
    public static function engagementSurfaces(): array
    {
        return [
            'reactions'    => ['Reaction'],
            'stoke'        => ['Stoke'],
            'helpful mark' => ['HelpfulMark'],
            'rank events'  => ['RankEvent'],
            'score'        => ['Score'],
        ];
    }
}
