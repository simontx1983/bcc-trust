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

    // ── hide_from_search: unconditional, and additive ──────────────

    public function testHideFromSearchClauseIsAlwaysSpliced(): void
    {
        $args = PrivacySettings::filterPeepSoSearchArgs([]);

        $json = json_encode($args);
        self::assertIsString($json);
        self::assertStringContainsString(
            'peepso_is_hide_profile_from_user_listing',
            $json,
            'the setting must be honoured without PeepSo\'s admin option'
        );
    }

    public function testTheDiscoveryOptOutClauseSurvivesAlongsideIt(): void
    {
        // Control: adding the new clause must not displace the existing
        // one. Both are privacy filters; losing either is a silent leak.
        $json = json_encode(PrivacySettings::filterPeepSoSearchArgs([]));
        self::assertIsString($json);

        self::assertStringContainsString('bcc_privacy_discovery_optout', $json);
        self::assertStringContainsString('peepso_is_hide_profile_from_user_listing', $json);
    }

    public function testAnExistingMetaQueryIsPreservedNotOverwritten(): void
    {
        $args = PrivacySettings::filterPeepSoSearchArgs([
            'meta_query' => [['key' => 'someone_elses_filter', 'value' => 'x']],
        ]);

        $json = json_encode($args);
        self::assertIsString($json);
        self::assertStringContainsString('someone_elses_filter', $json, 'a sibling filter must not be dropped');
        self::assertStringContainsString('bcc_privacy_discovery_optout', $json);
        self::assertStringContainsString('peepso_is_hide_profile_from_user_listing', $json);
    }

    /**
     * NOT EXISTS is load-bearing: a user who never touched the setting
     * has no meta row, and a bare `!= '1'` would exclude them all.
     */
    public function testBothClausesUseNotExistsSoUntouchedUsersSurvive(): void
    {
        $json = json_encode(PrivacySettings::filterPeepSoSearchArgs([]));
        self::assertIsString($json);

        self::assertSame(
            2,
            substr_count($json, 'NOT EXISTS'),
            'one NOT EXISTS per clause — without them every user with no meta row is filtered out'
        );
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
}
