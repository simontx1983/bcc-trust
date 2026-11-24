<?php
/**
 * BCC's own clauses on PeepSo's user search: the level-30 exclusion and
 * `hide_from_search`.
 *
 * ── Why BCC carries these at all ────────────────────────────────────
 *
 * PeepSo CORE's search excludes only level 40 and gates level 20. The
 * level-30 clause came solely from the OPTIONAL peepso-friends plugin.
 * Remove that plugin and nothing excludes 30 — so a "friends only"
 * profile becomes unreachable on its profile routes (the gate denies it)
 * while still appearing in the member directory and its search. Listed
 * but unreachable is the exact inconsistency F-02 exists to remove, and
 * it arrives by plugin removal rather than by any code change.
 *
 * `hide_from_search` has a PeepSo clause too, but gated on PeepSo's
 * `allow_hide_user_from_user_listing` admin option, which is OFF on
 * production — so the setting has never worked, while BCC's contract has
 * promised since v1.64 that it excludes the user from directory and
 * member search.
 *
 * Both promises are exposed through BCC's own API, so both have to hold
 * regardless of which PeepSo add-ons are installed or which admin
 * checkboxes are ticked. That is what these tests pin.
 *
 * ⚠ Subprocess-isolated: these exercise behaviour that depends on a
 * class being ABSENT (`PeepSoFriendsPlugin`), and a class declaration
 * cannot be undone within a process.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\UserSyncRepository;
use BCC\Trust\Core\Support\PrivacySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ListingPrivacyClausesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/listing-privacy-stubs.php';
        $GLOBALS['__bcc_lp'] = ['admin' => false];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_lp'], $GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function query(int $viewerId = 0, bool $peepsoSearch = true): \WP_User_Query
    {
        $q = new \WP_User_Query();
        $q->query_vars = $peepsoSearch ? ['_peepso_user_id' => $viewerId] : ['number' => 10];

        return $q;
    }

    // ── hide_from_search (F-519): the args filter flags, pre_user_query excludes in SQL ──

    /**
     * The one shared predicate, as the user query must carry it. No test
     * sets the fold-done option unless it says so, so the legacy opt-out
     * still counts by default.
     */
    private static function optOutClause(bool $withLegacy = true): string
    {
        return UserSyncRepository::listingOptOutSql($GLOBALS['wpdb']->users . '.ID', $withLegacy);
    }

    /**
     * A query as PeepSoUserSearch builds it: its args (with `_peepso_user_id`
     * merged in) go through `peepso_user_search_args` and become the
     * WP_User_Query's query_vars; then `pre_user_query` fires.
     *
     * @param array<string, mixed> $args
     */
    private function searchQuery(array $args = [], int $viewerId = 42): \WP_User_Query
    {
        $q = new \WP_User_Query();
        $q->query_vars = PrivacySettings::filterPeepSoSearchArgs($args + ['_peepso_user_id' => $viewerId]);
        PrivacySettings::filterPeepSoUserQuery($q);

        return $q;
    }

    public function testASearchExcludesOptOutsWithTheSharedPredicate(): void
    {
        $q = $this->searchQuery();

        self::assertTrue($q->query_vars[PrivacySettings::QV_EXCLUDE_SEARCH_OPTOUT] ?? false, 'the args filter flags a search');
        self::assertStringContainsString(' AND ' . self::optOutClause(), $q->query_where, 'the setting is honoured without PeepSo\'s admin option');
    }

    /**
     * F-519: the search excludes on ANY stored row that reads as on, so
     * the strictest row wins. The shared predicate's SQL is pinned on real
     * engines with F-518's tests. Here: that this is what the search
     * carries, and that no `meta_query` form of the opt-out is left.
     */
    public function testNoMetaQueryFormOfTheOptOutIsLeft(): void
    {
        $q    = $this->searchQuery();
        $json = (string) json_encode($q->query_vars);

        self::assertStringNotContainsString('peepso_is_hide_profile_from_user_listing', $json);
        self::assertStringNotContainsString('bcc_privacy_discovery_optout', $json);
        self::assertArrayNotHasKey('meta_query', $q->query_vars, 'no meta_query is added');
        $clause = self::optOutClause();
        self::assertStringStartsWith('NOT EXISTS (', $clause);
        self::assertStringContainsString('peepso_is_hide_profile_from_user_listing', $clause);
        self::assertStringContainsString('bcc_privacy_discovery_optout', $clause, 'the legacy opt-out is read too');
    }

    /** F-257 rollout: until the fold has run, the legacy opt-out still hides. */
    public function testTheLegacyOptOutCountsUntilTheFoldIsDone(): void
    {
        $q = $this->searchQuery();

        self::assertTrue(PrivacySettings::legacyOptOutStillRead());
        self::assertStringContainsString(self::optOutClause(true), $q->query_where);
        self::assertStringContainsString('bcc_privacy_discovery_optout', $q->query_where);
    }

    /**
     * Once folded, the legacy rows no longer count. The fold copied every ON
     * legacy opt-out into the one flag but LEFT THE LEGACY ROWS IN PLACE, so
     * reading them would hide again a member who has since turned search
     * visibility back on (hide '0' beside a stale legacy '1').
     */
    public function testOnceFoldedTheLegacyRowsNoLongerCount(): void
    {
        $GLOBALS['__bcc_lp']['options'][PrivacySettings::FOLD_DONE_OPTION] = 1_700_000_000;

        $q = $this->searchQuery();

        self::assertStringContainsString(self::optOutClause(false), $q->query_where);
        self::assertStringNotContainsString('bcc_privacy_discovery_optout', $q->query_where);
    }

    /**
     * F-518 / F-519: the ONE answer every listing gives on the legacy key
     * (this search, and the fragment the public index and the directory
     * share). It stays true until the done-marker reads as set, so a missing
     * or falsy marker keeps honouring the legacy key (the fail-closed side).
     * The runner stores time().
     */
    public function testTheLegacyOptOutIsStillReadUntilTheFoldMarkerIsSet(): void
    {
        self::assertTrue(PrivacySettings::legacyOptOutStillRead(), 'no marker: the fold is not done');

        foreach ([0, '0', '', false] as $falsy) {
            $GLOBALS['__bcc_lp']['options'][PrivacySettings::FOLD_DONE_OPTION] = $falsy;
            self::assertTrue(
                PrivacySettings::legacyOptOutStillRead(),
                'a falsy marker is not a done fold: ' . var_export($falsy, true)
            );
        }

        $GLOBALS['__bcc_lp']['options'][PrivacySettings::FOLD_DONE_OPTION] = 1_700_000_000;
        self::assertFalse(PrivacySettings::legacyOptOutStillRead(), 'the marker the runner writes');
    }

    /**
     * The opt-out does not depend on peepso-friends: that plugin owns only
     * level 30, and its early return must not skip the opt-out.
     */
    public function testTheOptOutStillAppliesWhenTheFriendsPluginIsPresent(): void
    {
        eval('final class PeepSoFriendsPlugin {}');

        $q = $this->searchQuery();

        self::assertStringContainsString(self::optOutClause(), $q->query_where);
        self::assertStringNotContainsString('usr_profile_acc', $q->query_where, 'level 30 is the friends plugin\'s then');
    }

    /** `pre_user_query` can fire twice on one query: the opt-out is appended once. */
    public function testFilteringTwiceAppendsTheOptOutOnce(): void
    {
        $q = $this->searchQuery();
        PrivacySettings::filterPeepSoUserQuery($q);

        self::assertSame(1, substr_count($q->query_where, self::optOutClause()));
    }

    /** An unflagged PeepSo search (a roster) gets level 30, not the opt-out. */
    public function testAnUnflaggedSearchGetsNoOptOut(): void
    {
        $q = $this->query(42);
        PrivacySettings::filterPeepSoUserQuery($q);

        self::assertStringNotContainsString('bcc_optout', $q->query_where);
        self::assertStringContainsString('usr_profile_acc', $q->query_where, 'control: the hook did run');
    }

    public function testAFlagOnANonPeepSoQueryDoesNothing(): void
    {
        $q = new \WP_User_Query();
        $q->query_vars = [PrivacySettings::QV_EXCLUDE_SEARCH_OPTOUT => true];
        PrivacySettings::filterPeepSoUserQuery($q);

        self::assertSame('', $q->query_where);
    }

    /**
     * Admins must be able to find a hidden or opted-out member in member
     * search: the one job the "admins exempt" rule exists for. §F-190
     * widened the exemption to the legacy opt-out too.
     */
    public function testAdminsAreExemptFromTheOptOut(): void
    {
        $GLOBALS['__bcc_lp']['admin'] = true;

        $q = $this->searchQuery();

        self::assertArrayNotHasKey(PrivacySettings::QV_EXCLUDE_SEARCH_OPTOUT, $q->query_vars);
        self::assertStringNotContainsString('bcc_optout', $q->query_where);
    }

    /**
     * An admin gets the caller's args back EXACTLY: no flag, and no
     * rewrapping of a `meta_query` the caller set (PeepSo's VIP filter).
     */
    public function testAnAdminGetsTheCallersArgsBackByteForByte(): void
    {
        $GLOBALS['__bcc_lp']['admin'] = true;

        $incoming = [
            'number'     => 10,
            'meta_query' => [
                ['key' => 'peepso_vip', 'value' => '1', 'compare' => '='],
            ],
        ];

        self::assertSame($incoming, PrivacySettings::filterPeepSoSearchArgs($incoming));
    }

    /**
     * hide_from_search scopes to member search and the directory (user
     * decision 2026-11-12). A roster asks with ARG_IGNORE_SEARCH_OPTOUT:
     * no flag, the caller's args come back otherwise untouched, and the
     * marker itself is not passed on to WP_User_Query.
     */
    public function testARosterListingSkipsOnlyTheSearchOptOut(): void
    {
        $incoming = [
            'include'    => [11, 12],
            'meta_query' => [['key' => 'peepso_vip', 'value' => '1', 'compare' => '=']],
            PrivacySettings::ARG_IGNORE_SEARCH_OPTOUT => true,
        ];

        $args = PrivacySettings::filterPeepSoSearchArgs($incoming);

        self::assertArrayNotHasKey(PrivacySettings::ARG_IGNORE_SEARCH_OPTOUT, $args);
        self::assertArrayNotHasKey(PrivacySettings::QV_EXCLUDE_SEARCH_OPTOUT, $args);
        unset($incoming[PrivacySettings::ARG_IGNORE_SEARCH_OPTOUT]);
        self::assertSame($incoming, $args);
    }

    public function testAFalsyMarkerStillAppliesTheSearchOptOut(): void
    {
        $args = PrivacySettings::filterPeepSoSearchArgs([PrivacySettings::ARG_IGNORE_SEARCH_OPTOUT => false]);

        self::assertTrue($args[PrivacySettings::QV_EXCLUDE_SEARCH_OPTOUT] ?? false);
    }

    /** A caller's own `meta_query` (PeepSo's VIP filter) is handed back exactly. */
    public function testAnExistingMetaQueryIsLeftExactlyAsItCame(): void
    {
        $vip  = [['key' => 'someone_elses_filter', 'value' => 'x']];
        $args = PrivacySettings::filterPeepSoSearchArgs(['meta_query' => $vip]);

        self::assertSame($vip, $args['meta_query']);
        self::assertTrue($args[PrivacySettings::QV_EXCLUDE_SEARCH_OPTOUT]);
    }

    /**
     * #826's directory chips count through the list's own search
     * (MemberDirectoryTypeCounts → PeepSoUserSearch with the list's args,
     * no roster marker). So the chip totals leave opted-out members out
     * exactly as the list does: the same flag, the same SQL, inside the
     * counted statement.
     */
    public function testTheDirectoryChipSearchCarriesTheOptOut(): void
    {
        $seen = [];
        $counts = new \BCC\Trust\Core\Support\MemberDirectoryTypeCounts(
            static fn (string $type): array => [101, 102],
            static function (array $args, ?int $viewer, string $term) use (&$seen): object {
                $seen[] = $args;
                return (object) ['total' => 0, 'results' => []];
            }
        );
        $counts->forViewer(42);

        self::assertNotSame([], $seen, 'control: the chips did search');
        foreach ($seen as $args) {
            $q = $this->searchQuery($args);
            self::assertStringContainsString(self::optOutClause(), $q->query_where, 'a chip counts what the list may show');
        }
    }

    // ── the level-30 exclusion ─────────────────────────────────────

    public function testLevel30IsExcludedWhenTheFriendsPluginIsAbsent(): void
    {
        self::assertFalse(
            class_exists('\\PeepSoFriendsPlugin', false),
            'this test requires the friends plugin ABSENT — otherwise it proves nothing'
        );

        $q = $this->query(42);
        PrivacySettings::filterPeepSoUserQuery($q);

        self::assertStringContainsString('peepso_users` `bcc_acc`', $q->query_from, 'own join, own alias');
        self::assertStringContainsString('usr_profile_acc', $q->query_where);
        self::assertStringContainsString('<> 30', $q->query_where, 'level 30 excluded');
        self::assertStringContainsString('= 42', $q->query_where, 'except the viewer themselves');
    }

    /**
     * A missing `peepso_users` row means "never set", whose schema
     * default is public. Excluding those would hide unsynced accounts for
     * no reason, so the clause admits NULL explicitly.
     */
    public function testAMissingPeepSoRowIsNotExcluded(): void
    {
        $q = $this->query(42);
        PrivacySettings::filterPeepSoUserQuery($q);

        self::assertStringContainsString('IS NULL', $q->query_where);
    }

    // ── scoping: the three cases where it must do nothing ──────────

    /**
     * ⚠ `pre_user_query` fires for EVERY WP_User_Query in the request —
     * wp-admin's user list, other plugins' lookups. A privacy clause
     * leaking into those is a bug of its own, so the hook is scoped by
     * the query var PeepSoUserSearch always sets.
     */
    public function testANonPeepSoUserQueryIsLeftCompletelyAlone(): void
    {
        $q = $this->query(42, false);
        PrivacySettings::filterPeepSoUserQuery($q);

        self::assertSame('', $q->query_from, 'no join');
        self::assertSame('', $q->query_where, 'no clause');
    }

    public function testAdminsAreNotFiltered(): void
    {
        $GLOBALS['__bcc_lp']['admin'] = true;

        $q = $this->query(42);
        PrivacySettings::filterPeepSoUserQuery($q);

        self::assertSame('', $q->query_where, 'admins see everyone, matching is_accessible_static');
    }

    /**
     * Control for the two assertions above: prove the clause IS added on
     * the ordinary path, so an empty `query_where` elsewhere is
     * meaningful rather than the hook simply never doing anything.
     */
    public function testTheOrdinaryPathDoesAddAClause(): void
    {
        $q = $this->query(42);
        PrivacySettings::filterPeepSoUserQuery($q);

        self::assertNotSame('', $q->query_where);
        self::assertNotSame('', $q->query_from);
    }

    /**
     * `pre_user_query` is not guaranteed to fire once per query —
     * `WP_User_Query::prepare_query()` can run again on the same object.
     * A second copy of the join is not a cosmetic duplicate: MySQL
     * rejects the whole statement with "Not unique table/alias", so the
     * listing fails hard rather than returning slightly wrong rows.
     */
    public function testFilteringTheSameQueryTwiceAppendsTheJoinOnlyOnce(): void
    {
        $q = $this->query(42);

        PrivacySettings::filterPeepSoUserQuery($q);
        $afterFirst = [$q->query_from, $q->query_where];
        PrivacySettings::filterPeepSoUserQuery($q);

        // ⚠ Count 'LEFT JOIN', not the alias: one append names the alias
        // twice (its declaration and the ON clause), so counting the
        // alias reads 2 after a single, correct append.
        self::assertSame(
            1,
            substr_count($q->query_from, 'LEFT JOIN'),
            'a second firing must not add a second join'
        );
        self::assertSame(
            $afterFirst,
            [$q->query_from, $q->query_where],
            'the second firing must be a complete no-op, WHERE arm included'
        );
    }
}
