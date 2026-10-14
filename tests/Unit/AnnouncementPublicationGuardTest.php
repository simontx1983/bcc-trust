<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Reconciler arm C — the structural guarantees, asserted over source.
 *
 * ⚠ The BEHAVIOUR lives in `scripts/tests/announcement-cron-publication-safety.php`,
 * which drives WordPress's real `publish_future_post` event with a real claim
 * chain and 64 assertions. It cannot be CI-wired (no WordPress in either
 * harness), so what lives here is what source can prove: the hook wiring, the
 * ordering, and a set of absences no input could demonstrate.
 */
#[Group('unit')]
final class AnnouncementPublicationGuardTest extends TestCase
{
    private const GUARD  = 'app/Domain/Core/Announcements/AnnouncementPublicationGuard.php';
    private const BOOT   = 'bcc-trust.php';
    private const PLUGIN = 'app/Domain/Core/Plugin.php';

    private static function src(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $s    = file_get_contents($path);

        self::assertIsString($s, 'could not read ' . $rel);
        self::assertGreaterThan(500, strlen($s), $rel . ' is implausibly short — wrong path?');

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

    /**
     * ⚠⚠ REGISTERED AT BOOT, NOT IN THE REST ROUTE REGISTRATION.
     *
     * This case exists because the guard's first placement was inside
     * `Plugin::registerRoutes()`, which runs on `rest_api_init`. wp-cron is NOT
     * a REST request, so on the real scheduled path the guard would never have
     * hooked at all — `has_action()` returned false and the cron probe caught
     * it. A unit test cannot call `has_action()` without WordPress, so the
     * placement itself is pinned instead.
     */
    public function testTheGuardRegistersAtBootAndNotInTheRestRouteRegistration(): void
    {
        $boot = self::code(self::BOOT);
        self::assertStringContainsString(
            'AnnouncementPublicationGuard::register()',
            $boot,
            'the guard must register at plugin boot, where wp-cron can see it'
        );

        $plugin = self::code(self::PLUGIN);
        self::assertStringNotContainsString(
            'AnnouncementPublicationGuard::register()',
            $plugin,
            'registering in Plugin.php means REST-only, so cron would never fire the guard'
        );
    }

    /**
     * ⚠⚠ THE VETO MUST OUTRANK CORE. `check_and_publish_future_post()` is
     * hooked on `publish_future_post` at priority 10 and returns early unless
     * `post_status === 'future'`. Blocking at priority 1 therefore prevents
     * publication outright — there is no window in which the row is public.
     * At priority 10 or later the guard would only ever revert, which is
     * strictly weaker.
     */
    public function testTheVetoIsHookedBeforeCoresOwnPublisher(): void
    {
        $code = self::code(self::GUARD);

        self::assertMatchesRegularExpression(
            "/add_action\(\s*'publish_future_post'\s*,\s*\[self::class,\s*'vetoScheduledPublication'\]\s*,\s*1\s*,/",
            $code,
            'the veto must be registered at priority 1, ahead of core at 10'
        );
    }

    /** The backstop catches paths that are not the cron. */
    public function testABackstopCoversGenericTransitions(): void
    {
        $code = self::code(self::GUARD);

        self::assertStringContainsString("add_action('transition_post_status'", $code);
        self::assertStringContainsString('onTransition', $code);
    }

    /**
     * ⚠ The guard is registered OUTSIDE the feature switch, deliberately.
     * Gating it on the switch would mean turning the feature off between
     * scheduling and the due instant REMOVES the guard — so switching off
     * would open a publication path. Proved live in the cron probe (§10).
     */
    public function testTheGuardIsNotGatedOnTheFeatureSwitch(): void
    {
        $code = self::code(self::GUARD);

        self::assertStringNotContainsString('AnnouncementFeature', $code);
        self::assertStringNotContainsString('isEnabled', $code);
        self::assertStringNotContainsString('bcc_announcements_enabled', $code);
    }

    /**
     * ⚠⚠ MARKER FIRST, THEN STATUS. The publication marker is visibility
     * predicate 3: once it is gone the row cannot be public even if a
     * concurrent write puts the status back to `publish`. Reverting the status
     * first would leave a window in the opposite direction.
     */
    public function testTheMarkerIsStrippedBeforeTheStatusIsReverted(): void
    {
        $code  = self::code(self::GUARD);
        $start = strpos($code, 'function block(');
        self::assertIsInt($start, 'block() not found');
        $body = substr($code, $start, 1800);

        $marker = strpos($body, 'META_AUTHORIZED_AT');
        $status = strpos($body, 'forceStatus(');
        $blockA = strpos($body, 'META_BLOCKED_AT');

        self::assertIsInt($marker, 'block() must delete the publication marker');
        self::assertIsInt($status, 'block() must take the row out of a public status');
        self::assertIsInt($blockA, 'block() must stamp the blocked marker');

        self::assertLessThan($status, $marker, 'the marker must be removed BEFORE the status is reverted');
        self::assertLessThan($blockA, $status, 'the blocked marker is stamped last');
    }

    /**
     * ⚠ The status write goes through the REPOSITORY. §1 permits raw $wpdb only
     * there, and the guard is a forbidden layer — a mistake arch-guardrails
     * already caught once in this slice.
     */
    public function testTheStatusWriteIsDelegatedToTheRepository(): void
    {
        $code = self::code(self::GUARD);

        self::assertStringNotContainsString('global $wpdb', $code);
        self::assertStringNotContainsString('$wpdb->', $code);
        self::assertStringContainsString('AnnouncementRepository', $code);
        self::assertStringContainsString('forceStatus(', $code);
    }

    /**
     * ⚠⚠ THE AUTHORIZING USER, NOT THE CURRENT USER. On the cron path there is
     * no current user at all; using `get_current_user_id()` would make the
     * answer depend on who happened to trigger wp-cron.
     */
    public function testAuthorityIsCheckedForTheAuthorizingUserNotTheCurrentUser(): void
    {
        $code = self::code(self::GUARD);

        self::assertStringNotContainsString(
            'get_current_user_id',
            $code,
            'the cron has no current user; the marker names the authorizing user'
        );
        self::assertStringContainsString('META_AUTHORIZED_USER_ID', $code);
        self::assertStringContainsString('post_author', $code, 'with post_author as the fallback');
        self::assertStringContainsString('Authority::decide(', $code);
    }

    /** An unreadable authority answer is not permission. */
    public function testAnUnreadableAuthorityAnswerFailsClosed(): void
    {
        $code  = self::code(self::GUARD);
        $start = strpos($code, 'function stillAuthorized(');
        self::assertIsInt($start);
        $body = substr($code, $start, 900);

        self::assertMatchesRegularExpression(
            '/catch\s*\([^)]*\)\s*\{\s*return\s+false;/s',
            $body,
            'a thrown authority check must return false, never true'
        );
        // A row with no page, or no user, cannot be authorized.
        self::assertStringContainsString('return false;', $body);
    }

    /**
     * ⚠ The blocked reason is COARSE on purpose. `claim_absent`,
     * `claim_ambiguous` and `not_claimer` are the authority's private
     * vocabulary (§4.32.11); surfacing them through the blocked state would
     * turn it into a disclosure channel. Proved live: an ambiguous claim and a
     * revoked claim both record `authority_lost`.
     */
    #[DataProvider('privateReasons')]
    public function testTheBlockedReasonNeverCarriesAPrivateDenialReason(string $private): void
    {
        self::assertStringNotContainsString($private, self::code(self::GUARD));
    }

    /** @return iterable<string, array{string}> */
    public static function privateReasons(): iterable
    {
        yield 'claim_absent'    => ['claim_absent'];
        yield 'claim_ambiguous' => ['claim_ambiguous'];
        yield 'not_claimer'     => ['not_claimer'];
        yield 'auth_required'   => ['auth_required'];
    }

    /** The reason set is closed and operator-facing. */
    public function testTheReasonSetIsClosed(): void
    {
        $code = self::code(self::GUARD);

        self::assertStringContainsString("REASON_AUTHORITY_LOST = 'authority_lost'", $code);
        self::assertStringContainsString("REASON_UNVERIFIABLE   = 'authority_unverifiable'", $code);
    }

    /**
     * ⚠ No engagement, no scoring, no auto-hide — the same absences the write
     * layer holds. A reconciler is a tempting place to "record" something.
     */
    #[DataProvider('forbiddenSymbols')]
    public function testTheGuardPerformsNoEngagementOrScoringWrite(string $symbol): void
    {
        self::assertStringNotContainsString($symbol, self::code(self::GUARD));
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenSymbols(): iterable
    {
        yield 'activity writer' => ['ActivityStreamWriter'];
        yield 'peepso writer'   => ['PeepSoActivityWriter'];
        yield 'stokes'          => ['Stoke'];
        yield 'helpful marks'   => ['HelpfulMark'];
        yield 'rank events'     => ['RankEvent'];
        yield 'score events'    => ['ScoreEvent'];
        yield 'auto hide'       => ['AutoHide'];
        yield 'feed'            => ['Feed'];
    }

    /**
     * ⚠ Clearing a block is only reachable from the write layer, AFTER a fresh
     * operator check. The guard exposes `clearBlock()` but must not call it
     * itself — a self-clearing reconciler would undo its own refusal.
     */
    public function testTheGuardNeverClearsItsOwnBlock(): void
    {
        $code = self::code(self::GUARD);

        self::assertStringContainsString('function clearBlock(', $code, 'the recovery hook must exist');
        // Exactly one occurrence: the declaration. No internal call site.
        self::assertSame(
            1,
            substr_count($code, 'clearBlock('),
            'clearBlock() must be declared and never called from inside the guard'
        );
    }

    /** The pending cron event is cleared, or core retries and re-blocks forever. */
    public function testBlockingClearsThePendingCronEvent(): void
    {
        $code = self::code(self::GUARD);

        self::assertStringContainsString("wp_clear_scheduled_hook('publish_future_post'", $code);
    }
}
