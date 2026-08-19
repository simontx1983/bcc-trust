<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The two selectors part ways on `is_verified`, against REAL SQL.
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * The unit suite fakes `CollectionRepository`, so "does the gallery selector
 * filter on is_verified" is answered by the double, not by MySQL. The double
 * is only ever an ASSERTION about production. This file is the EVIDENCE: the
 * real `WHERE c.is_verified = 1`, the real JOIN, the real admin listing —
 * one unverified row and one verified row, and the two selectors disagreeing
 * about them exactly as the policy requires.
 *
 * The policy, in one line: an unverified discovery candidate is INVISIBLE to
 * the wallet gallery and VISIBLE to the operator queue. A change that makes
 * those two agree has broken one of them, and this test says which.
 */
#[Group('integration')]
#[CoversClass(CollectionRepository::class)]
final class CollectionVerifiedOnlySelectionIntegrationTest extends TestCase
{
    private const VERIFIED   = 'cosmos1verifiedaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const UNVERIFIED = 'cosmos1unverifiedbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private int $chainId = 0;
    private string $chainSlug = '';

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CollectionRepository::table() . '`');

        // The installer seeds the chain registry; listVerifiedByChain INNER
        // JOINs it, so a fixture chain that is absent from it would make the
        // verified positive control vacuously empty.
        $chain = $wpdb->get_row(
            'SELECT id, slug FROM `' . ChainRepository::table() . '` ORDER BY id ASC LIMIT 1'
        );
        self::assertNotNull($chain, 'the chain registry must be seeded for the JOIN to resolve');

        $this->chainId   = (int) $chain->id;
        $this->chainSlug = (string) $chain->slug;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->query('DELETE FROM `' . CollectionRepository::table() . '`');
    }

    private function insertCollection(string $contract, string $name, int $isVerified): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . CollectionRepository::table() . '`
                (contract_address, chain_id, collection_name, token_standard, is_verified, expires_at)
             VALUES (%s, %d, %s, %s, %d, %s)',
            $contract,
            $this->chainId,
            $name,
            'CW-721',
            $isVerified,
            '2099-01-01 00:00:00'
        ));
        self::assertSame('', (string) $wpdb->last_error, 'fixture insert failed');

        return (int) $wpdb->insert_id;
    }

    private function seedBoth(): void
    {
        $this->insertCollection(self::VERIFIED, 'Verified Collection', 1);
        $this->insertCollection(self::UNVERIFIED, 'Discovery Candidate', 0);
    }

    /**
     * THE CONTRACT. The gallery selector returns the verified row and only
     * the verified row — with an unverified sibling present on the same
     * chain, so "returns one row" cannot be an artefact of an empty table.
     */
    public function testTheGallerySelectorReturnsVerifiedRowsOnly(): void
    {
        $this->seedBoth();

        $rows = CollectionRepository::listVerifiedByChain($this->chainId, 50);
        self::assertSame('', (string) $GLOBALS['wpdb']->last_error, 'the selector left a SQL error behind');

        self::assertCount(1, $rows, 'the unverified sibling must not be selected');
        self::assertSame(self::VERIFIED, (string) $rows[0]->contract_address);

        $addresses = array_map(static fn ($r): string => (string) $r->contract_address, $rows);
        self::assertNotContains(self::UNVERIFIED, $addresses);
    }

    /**
     * The other half of the policy: the operator queue still shows the row.
     * Containment is not deletion — a candidate the gallery ignores is
     * exactly the candidate an admin needs to see in order to act on it.
     */
    public function testVerifyCollectionsStillListsTheUnverifiedRow(): void
    {
        $this->seedBoth();

        $listing = CollectionRepository::listForAdminVerification(1, 50, $this->chainSlug);
        $addresses = array_map(
            static fn ($r): string => (string) $r->contract_address,
            $listing['items']
        );

        self::assertContains(self::UNVERIFIED, $addresses, 'the operator queue must still hold the candidate');
        self::assertContains(self::VERIFIED, $addresses);
        self::assertSame(2, (int) $listing['total']);

        // And it is reachable through the tab the admin page actually opens.
        $unverifiedOnly = CollectionRepository::listForAdminVerification(1, 50, $this->chainSlug, null, false);
        $unverifiedAddresses = array_map(
            static fn ($r): string => (string) $r->contract_address,
            $unverifiedOnly['items']
        );
        self::assertSame([self::UNVERIFIED], $unverifiedAddresses);

        self::assertSame(
            ['verified' => 1, 'unverified' => 1],
            CollectionRepository::countByVerification($this->chainSlug)
        );
    }

    /**
     * The display annotation keeps telling the truth for the chains whose
     * holdings arrive from an indexer rather than from this selector.
     */
    public function testVerificationMapReportsTheCandidateUnverified(): void
    {
        $this->seedBoth();

        $map = CollectionRepository::verifiedMapForContracts(
            $this->chainId,
            [self::VERIFIED, self::UNVERIFIED]
        );

        self::assertTrue($map[strtolower(self::VERIFIED)] ?? null);
        self::assertFalse($map[strtolower(self::UNVERIFIED)] ?? null);
    }
}
