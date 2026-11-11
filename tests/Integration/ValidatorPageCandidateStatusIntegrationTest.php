<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The page-candidate query, against real SQL: `status` must not decide
 * eligibility, and the non-membership guards must still exclude.
 *
 * This is the behavioural half of
 * ValidatorPageExistenceIndependentOfMembershipTest. That suite asserts the
 * scope resolver by value and the absence of a `status` predicate in the
 * source; this one runs the query and proves an `inactive` row is actually
 * returned as a candidate.
 *
 * ⚠ An end-to-end mint cannot be tested here: the harness `wp_posts` has five
 * columns with no `post_author`/`post_date_gmt` and there is no
 * `wp_insert_post`, so ValidatorPageMinter::mintForValidator() cannot run.
 * What IS testable — and is the thing this change alters — is which rows the
 * repository offers as candidates.
 *
 * ⛔ `bcc_chains` is NOT truncated. The bootstrap seeds it, other suites
 * depend on those rows, and the candidate query INNER JOINs it, so the
 * fixtures attach to a chain that is already there.
 */
#[Group('integration')]
#[Group('mariadb')]
final class ValidatorPageCandidateStatusIntegrationTest extends TestCase
{
    private int $chainId = 0;

    protected function setUp(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_onchain_validators` ');

        // Attach to a seeded chain rather than inventing one: the query
        // INNER JOINs bcc_chains, and that table carries a UNIQUE slug.
        $this->chainId = (int) $wpdb->get_var(
            'SELECT id FROM `' . $wpdb->prefix . 'bcc_chains` ORDER BY id ASC LIMIT 1'
        );
        self::assertGreaterThan(0, $this->chainId, 'the bootstrap must seed at least one chain');

        $wpdb->query(
            "DELETE FROM `{$wpdb->postmeta}` WHERE meta_key = '_bcc_onchain_validator_id'"
        );
    }

    /** @return int inserted validator id */
    private function seedValidator(string $address, string $status, ?int $walletLinkId = null): int
    {
        global $wpdb;

        $ok = $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'operator_address' => $address,
            'chain_id'         => $this->chainId,
            'moniker'          => 'fixture ' . $address,
            'status'           => $status,
            'wallet_link_id'   => $walletLinkId,
            'fetched_at'       => '2026-06-01 00:00:00',
            'expires_at'       => '2026-06-01 04:00:00',
        ]);
        // ⚠ $wpdb->insert returns false silently on a rejected row; assert it.
        self::assertNotFalse($ok, "fixture insert must succeed for {$address}");

        return (int) $wpdb->insert_id;
    }

    private function givePage(int $validatorId, int $postId): void
    {
        global $wpdb;

        $ok = $wpdb->insert($wpdb->postmeta, [
            'post_id'    => $postId,
            'meta_key'   => '_bcc_onchain_validator_id',
            'meta_value' => (string) $validatorId,
        ]);
        self::assertNotFalse($ok, 'fixture page meta must insert');
    }

    /** @param list<object> $rows */
    private static function ids(array $rows): array
    {
        return array_map(static fn (object $r): int => (int) $r->id, $rows);
    }

    /**
     * ⭐ THE POINT OF THE CHANGE. With no allowlist, an `inactive` validator
     * is a candidate exactly like an `active` one. Production 2026-11-04 had
     * 51 such rows that could never get a page.
     */
    public function testAnInactiveValidatorIsAPageCandidate(): void
    {
        $inactive = $this->seedValidator('fixturevaloper1inactive', 'inactive');
        $active   = $this->seedValidator('fixturevaloper1active', 'active');

        $rows = ValidatorRepository::findMissingPlaceholderPage($this->chainId, 50, 0, null);
        $ids  = self::ids($rows);

        self::assertContains($inactive, $ids, 'an inactive validator must be offered a page');
        self::assertContains($active, $ids);
        self::assertCount(2, $ids);
    }

    /** The status is still carried to the caller, it just does not filter. */
    public function testTheStatusIsReturnedButDoesNotFilter(): void
    {
        $inactive = $this->seedValidator('fixturevaloper1carried', 'inactive');

        $rows = ValidatorRepository::findMissingPlaceholderPage($this->chainId, 50, 0, null);

        self::assertCount(1, $rows);
        self::assertSame($inactive, (int) $rows[0]->id);
        self::assertSame('inactive', $rows[0]->status);
    }

    /**
     * ⛔ The guards that are NOT about membership must keep excluding, or
     * widening the allowlist would also mint for claimed validators and for
     * rows that already have a page.
     */
    public function testClaimedRowsAndRowsWithAPageAreStillExcluded(): void
    {
        $paged   = $this->seedValidator('fixturevaloper1paged', 'inactive');
        $claimed = $this->seedValidator('fixturevaloper1claimed', 'inactive', 4242);
        $open    = $this->seedValidator('fixturevaloper1open', 'inactive');

        $this->givePage($paged, 910001);

        $ids = self::ids(ValidatorRepository::findMissingPlaceholderPage($this->chainId, 50, 0, null));

        self::assertSame([$open], $ids, 'only the unclaimed, page-less row is a candidate');
        self::assertNotContains($paged, $ids);
        self::assertNotContains($claimed, $ids);
    }

    /**
     * The old, fetch-scoped behaviour is still available and still exact —
     * the manual CLI path and any future caller can still pass a list.
     */
    public function testAnExplicitAllowlistStillRestrictsToThoseIds(): void
    {
        $a = $this->seedValidator('fixturevaloper1aaa', 'inactive');
        $b = $this->seedValidator('fixturevaloper1bbb', 'active');

        $ids = self::ids(ValidatorRepository::findMissingPlaceholderPage($this->chainId, 50, 0, [$a]));

        self::assertSame([$a], $ids);
        self::assertNotContains($b, $ids);
    }

    /** ⛔ Empty means mint none, and must never widen back to everything. */
    public function testAnEmptyAllowlistReturnsNothing(): void
    {
        $this->seedValidator('fixturevaloper1empty', 'inactive');

        self::assertSame(
            [],
            ValidatorRepository::findMissingPlaceholderPage($this->chainId, 50, 0, [])
        );
    }

    /** A row on another chain is never offered under this chain's id. */
    public function testTheQueryStaysScopedToTheChain(): void
    {
        global $wpdb;

        $mine  = $this->seedValidator('fixturevaloper1mine', 'inactive');
        $other = (int) $wpdb->get_var(
            'SELECT id FROM `' . $wpdb->prefix . 'bcc_chains` WHERE id <> ' . $this->chainId . ' ORDER BY id ASC LIMIT 1'
        );

        if ($other <= 0) {
            self::markTestSkipped('the harness seeded only one chain');
        }

        $ids = self::ids(ValidatorRepository::findMissingPlaceholderPage($other, 50, 0, null));

        self::assertNotContains($mine, $ids);
    }
}
