<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoGroupPrivacy;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A group's privacy when it carries MORE THAN ONE `peepso_group_privacy`
 * row, on a real server. The group twin of
 * {@see SecretPageVisibilityIntegrationTest}.
 *
 * ⚠⚠ THE TWO-ROW GROUPS ARE THE POINT, and the reason this file is here
 * rather than in bcc-core or bcc-search: neither of those has an integration
 * harness, so until now the group rule had no test that ever EXECUTED the
 * case. Both halves of the rule are exercised here against the engine:
 *
 *   - bcc-core's `PeepSoGroupPrivacy::notSecretSql()` (F-429), through
 *     `PeepSoGroupRepository::listBrowsableGroupIds()`. "Secret" must mean
 *     ANY row reads 2, and a group must never be listed twice.
 *   - bcc-trust's `PeepSoPrivacy::fromGroupPostId()` (the F-429 follow-up),
 *     which must take the STRICTEST row, not the first one.
 *
 * ⭐ And one test asserts the two halves AGREE, because them disagreeing is
 * what actually went wrong: after F-429 the lists hid a 0+2 group while the
 * resolver still typed it Open, so it was invisible in every list and
 * openable by id at the same time.
 *
 * Both superseded forms are re-run on the same fixture
 * ({@see testTheSupersededListFormLeakedAndDuplicated},
 * {@see testTheSupersededSingleRowReadTypedASecretGroupOpen}) so these tests
 * are known to catch what they claim to catch. Only a real server has an
 * opinion about the SQL; the unit tests pin the wiring.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(PeepSoGroupPrivacy::class)]
#[CoversClass(PeepSoPrivacy::class)]
final class GroupPrivacyRowsIntegrationTest extends TestCase
{
    private const OPEN    = 994101;
    private const CLOSED  = 994102;
    private const SECRET  = 994103;
    private const NO_META = 994104;

    /** Rows '0' then '2' — secret. The case the old reads got wrong. */
    private const SECRET_AFTER_OPEN = 994105;
    /** Rows '2' then '0' — secret. Order must not matter. */
    private const SECRET_BEFORE_OPEN = 994106;
    /** Rows '1' then '2' — secret. */
    private const SECRET_AFTER_CLOSED = 994107;
    /** Rows '0' then '0' — open, and must be listed ONCE. */
    private const OPEN_TWICE = 994108;
    /** Rows '0' then '1' — closed, so still browsable, and listed once. */
    private const CLOSED_AFTER_OPEN = 994109;

    /** @var array<int, list<string>> fixture group => privacy rows in meta_id order */
    private const FIXTURE = [
        self::OPEN                => ['0'],
        self::CLOSED              => ['1'],
        self::SECRET              => ['2'],
        self::NO_META             => [],
        self::SECRET_AFTER_OPEN   => ['0', '2'],
        self::SECRET_BEFORE_OPEN  => ['2', '0'],
        self::SECRET_AFTER_CLOSED => ['1', '2'],
        self::OPEN_TWICE          => ['0', '0'],
        self::CLOSED_AFTER_OPEN   => ['0', '1'],
    ];

    /** Every group a browse read must return, each exactly once. */
    private const BROWSABLE = [
        self::OPEN,
        self::CLOSED,
        self::NO_META,
        self::OPEN_TWICE,
        self::CLOSED_AFTER_OPEN,
    ];

    /** Every group a browse read must withhold. */
    private const HIDDEN = [
        self::SECRET,
        self::SECRET_AFTER_OPEN,
        self::SECRET_BEFORE_OPEN,
        self::SECRET_AFTER_CLOSED,
    ];

    protected function setUp(): void
    {
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->purge();
    }

    // ── bcc-core's list rule, on the engine ────────────────────────────

    public function testBrowseHidesEverySecretGroupAndListsEachGroupOnce(): void
    {
        global $wpdb;

        $ids = PeepSoGroupRepository::listBrowsableGroupIds(500);

        self::assertSame('', (string) $wpdb->last_error);

        $sorted = $ids;
        sort($sorted);
        self::assertSame(self::BROWSABLE, $sorted, 'the browsable set is wrong');

        // Each once: a fan-out would repeat an id, and this method does not
        // de-duplicate, so a duplicate reaches the caller.
        self::assertSame(count($ids), count(array_unique($ids)), 'a group was listed more than once');

        foreach (self::HIDDEN as $groupId) {
            self::assertNotContains($groupId, $ids, 'a secret group was browsable: ' . $groupId);
        }
    }

    /**
     * ⚠ ANTI-VACUITY. The superseded LEFT JOIN form, on this same fixture,
     * leaks both 0+2 groups and lists the 0+0 group twice. If it did not,
     * the test above would pass whether or not the new rule were in place.
     */
    public function testTheSupersededListFormLeakedAndDuplicated(): void
    {
        global $wpdb;

        $ids = array_map('intval', $wpdb->get_col(
            "SELECT p.ID
               FROM {$wpdb->posts} p
          LEFT JOIN {$wpdb->postmeta} pm
                 ON pm.post_id = p.ID AND pm.meta_key = 'peepso_group_privacy'
              WHERE p.post_type = 'peepso-group'
                AND p.post_status = 'publish'
                AND (pm.meta_value IS NULL OR CAST(pm.meta_value AS UNSIGNED) <> 2)
              ORDER BY p.ID"
        ));

        self::assertSame('', (string) $wpdb->last_error);
        self::assertContains(self::SECRET_AFTER_OPEN, $ids, 'the old form should have leaked the 0+2 group');
        self::assertContains(self::SECRET_BEFORE_OPEN, $ids, 'the old form should have leaked the 2+0 group');
        self::assertSame(
            2,
            count(array_keys($ids, self::OPEN_TWICE, true)),
            'the old form should have listed the 0+0 group twice'
        );
    }

    /** The fragment is prepare()-safe: no placeholder for prepare() to consume. */
    public function testTheSharedFragmentCarriesNoPlaceholder(): void
    {
        self::assertStringNotContainsString('%', PeepSoGroupPrivacy::notSecretSql('p.ID'));
    }

    // ── bcc-trust's resolver, on the engine ────────────────────────────

    public function testTheResolverTakesTheStrictestRow(): void
    {
        $expected = [
            self::OPEN                => PeepSoPrivacy::Open,
            self::CLOSED              => PeepSoPrivacy::Closed,
            self::SECRET              => PeepSoPrivacy::Secret,
            self::NO_META             => PeepSoPrivacy::Open,
            self::SECRET_AFTER_OPEN   => PeepSoPrivacy::Secret,
            self::SECRET_BEFORE_OPEN  => PeepSoPrivacy::Secret,
            self::SECRET_AFTER_CLOSED => PeepSoPrivacy::Secret,
            self::OPEN_TWICE          => PeepSoPrivacy::Open,
            self::CLOSED_AFTER_OPEN   => PeepSoPrivacy::Closed,
        ];

        foreach ($expected as $groupId => $privacy) {
            self::assertSame(
                $privacy,
                PeepSoPrivacy::fromGroupPostId($groupId),
                'group ' . $groupId . ' resolved wrongly'
            );
        }
    }

    /**
     * ⚠ ANTI-VACUITY for the resolver. `$single = true` returns the FIRST
     * row by meta_id, which is why the 0+2 group used to type Open. Proven
     * here against the real engine rather than against a stub, because the
     * row ORDER is the engine's business.
     */
    public function testTheSupersededSingleRowReadTypedASecretGroupOpen(): void
    {
        $first = get_post_meta(self::SECRET_AFTER_OPEN, 'peepso_group_privacy', true);

        self::assertSame('0', (string) $first, 'the engine returns the first row for $single');
        self::assertSame(
            PeepSoPrivacy::Open,
            match ((int) $first) {
                2       => PeepSoPrivacy::Secret,
                1       => PeepSoPrivacy::Closed,
                default => PeepSoPrivacy::Open,
            },
            'the superseded resolver should have typed this group Open'
        );

        // And the current resolver does not.
        self::assertSame(PeepSoPrivacy::Secret, PeepSoPrivacy::fromGroupPostId(self::SECRET_AFTER_OPEN));
    }

    // ── the halves must agree ──────────────────────────────────────────

    /**
     * ⭐ THE TEST THIS FILE EXISTS FOR. A group hidden from every list while
     * the resolver types it Open is the exact shape of the defect: F-429
     * fixed the lists, and the resolver kept answering from the first row,
     * so the same group was invisible in search and openable by id.
     *
     * Asserted in BOTH directions, because either disagreement is a bug:
     * a group the list withholds must be Secret, and a group the list shows
     * must not be.
     */
    public function testTheListRuleAndTheResolverNeverDisagree(): void
    {
        $browsable = PeepSoGroupRepository::listBrowsableGroupIds(500);

        foreach (array_keys(self::FIXTURE) as $groupId) {
            $listed  = in_array($groupId, $browsable, true);
            $privacy = PeepSoPrivacy::fromGroupPostId($groupId);

            if ($listed) {
                self::assertNotSame(
                    PeepSoPrivacy::Secret,
                    $privacy,
                    'group ' . $groupId . ' is browsable but the resolver calls it Secret'
                );
                continue;
            }

            self::assertSame(
                PeepSoPrivacy::Secret,
                $privacy,
                'group ' . $groupId . ' is withheld from browse but the resolver does not call it Secret'
            );
        }
    }

    // ── fixture ────────────────────────────────────────────────────────

    private function seed(): void
    {
        global $wpdb;

        foreach (self::FIXTURE as $groupId => $values) {
            self::assertNotFalse(
                $wpdb->insert($wpdb->posts, [
                    'ID'          => $groupId,
                    'post_title'  => 'Group privacy fixture ' . $groupId,
                    'post_name'   => 'group-privacy-fixture-' . $groupId,
                    'post_type'   => 'peepso-group',
                    'post_status' => 'publish',
                ]),
                'group fixture: ' . $wpdb->last_error
            );

            foreach ($values as $value) {
                self::assertNotFalse(
                    $wpdb->insert($wpdb->postmeta, [
                        'post_id'    => $groupId,
                        'meta_key'   => 'peepso_group_privacy',
                        'meta_value' => $value,
                    ]),
                    'privacy fixture: ' . $wpdb->last_error
                );
            }
        }

        // ⚠ Asserted, not assumed. A silently empty fixture would make every
        // "hidden" assertion pass for the wrong reason — which is the failure
        // this whole file exists to rule out.
        self::assertSame(
            count(self::FIXTURE),
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'peepso-group'"),
            'fixture precondition: the fixture groups, and no stray from another suite'
        );
        // Counted from the fixture rather than hard-coded — I hand-counted it
        // wrong first, and a stale literal here would fail a correct fixture.
        // Still a real check: it compares what the ENGINE holds against what
        // the fixture declares, so a rejected insert or a stray row shows up.
        self::assertSame(
            array_sum(array_map('count', self::FIXTURE)),
            (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'peepso_group_privacy'"
            ),
            'fixture precondition: every declared privacy row reached the table'
        );
    }

    /**
     * wp_posts and wp_postmeta are emptied WHOLESALE: they are shared, and
     * another suite's leftover published `peepso-group` is a real match for
     * `listBrowsableGroupIds()` and would break the exact assertions above.
     */
    private function purge(): void
    {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->postmeta}");
        $wpdb->query("DELETE FROM {$wpdb->posts}");
    }
}
