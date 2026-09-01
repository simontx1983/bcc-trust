<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Repair\SolanaGateIdentityManifest;
use BCC\Trust\Onchain\Support\Base58;
use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use PHPUnit\Framework\TestCase;

/**
 * The manifest is the reviewed authority for what the repair writes, so
 * these tests exist to make an unreviewed change LOUD rather than to
 * restate the table.
 *
 * The load-bearing one is the checksum. The 91-alias screening audit
 * produced 64 `A_candidate` rows, and "candidate" is exactly the kind of
 * word that erodes: it would be easy, months from now, to paste one more
 * row in "because we're confident about that one too". The checksum makes
 * that a red build instead of a silent ninth repair.
 */
final class SolanaGateIdentityManifestTest extends TestCase
{
    public function testManifestHasExactlyEightEntries(): void
    {
        self::assertSame(8, SolanaGateIdentityManifest::count());
        self::assertCount(8, SolanaGateIdentityManifest::entries());
        self::assertSame(8, SolanaGateIdentityManifest::EXPECTED_COUNT);
    }

    /**
     * The count constant and the table must agree. Two separate statements
     * of "eight" can drift; this makes them one fact.
     */
    public function testCountConstantMatchesTheTable(): void
    {
        self::assertSame(
            SolanaGateIdentityManifest::EXPECTED_COUNT,
            SolanaGateIdentityManifest::count(),
            'EXPECTED_COUNT and the MAPPINGS table disagree — a row was added or removed'
        );
    }

    public function testManifestVersionIsOne(): void
    {
        self::assertSame(1, SolanaGateIdentityManifest::VERSION);
    }

    /**
     * Pins the exact reviewed content. Any edit to a collection id, post
     * id, alias or address changes this.
     */
    public function testChecksumIsPinned(): void
    {
        self::assertSame(
            '646f502d2c763f2c6c6d5412f110a57b5d8fe648fa93d7039bc45306b7a74b36',
            SolanaGateIdentityManifest::checksum(),
            'The manifest changed. This is not a test to "fix" — re-review the mapping.'
        );
    }

    public function testEveryAddressIsARealThirtyTwoByteSolanaKey(): void
    {
        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            $address = $entry['new_canonical_identifier'];

            self::assertSame(
                Base58::decodedLength($address),
                32,
                "manifest address for collection {$entry['collection_id']} is not a 32-byte key"
            );

            $identity = NftCollectionIdentifier::canonicalize(
                NftCollectionIdentifier::FAMILY_SOLANA,
                $address
            );

            self::assertTrue($identity->isAccepted(), "refused: {$address}");
            self::assertSame(
                $address,
                $identity->canonical(),
                'canonicalising a manifest address must be a no-op — it is already canonical'
            );
        }
    }

    /**
     * An alias is NOT an address. If one of these ever canonicalises, the
     * alias column and the identity column have been confused somewhere.
     */
    public function testNoAliasIsMistakableForAnIdentity(): void
    {
        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            $identity = NftCollectionIdentifier::canonicalize(
                NftCollectionIdentifier::FAMILY_SOLANA,
                $entry['alias']
            );

            self::assertFalse(
                $identity->isAccepted(),
                "alias '{$entry['alias']}' was accepted as a Solana identity"
            );
        }
    }

    public function testCollectionIdsPostIdsAliasesAndAddressesAreAllDistinct(): void
    {
        $entries = SolanaGateIdentityManifest::entries();

        foreach (['collection_id', 'post_id', 'alias', 'new_canonical_identifier'] as $field) {
            $values = array_column($entries, $field);
            self::assertSame(
                count($values),
                count(array_unique($values)),
                "duplicate {$field} in the manifest"
            );
        }
    }

    /**
     * Case-folding the addresses must not collapse any two of them. If it
     * did, a case-insensitive index could merge two repaired rows.
     */
    public function testAddressesStayDistinctUnderCaseFolding(): void
    {
        $addresses = array_column(SolanaGateIdentityManifest::entries(), 'new_canonical_identifier');
        $folded    = array_map('strtolower', $addresses);

        self::assertSame(
            count(array_unique($addresses)),
            count(array_unique($folded)),
            'two manifest addresses differ only by case'
        );
    }

    public function testEveryEntryExpectsTheReviewedRowShape(): void
    {
        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            self::assertSame(1, $entry['manifest_version']);
            self::assertSame('solana', $entry['chain_slug']);
            // The alias is expected in BOTH places; the repair changes only
            // the gate meta and leaves contract_address alone.
            self::assertSame($entry['alias'], $entry['expected_contract_address']);
            self::assertSame($entry['alias'], $entry['expected_gate_contract_address']);
            self::assertNull($entry['expected_canonical_identifier']);
            self::assertSame(1, $entry['expected_is_verified']);
            self::assertSame('toplist', $entry['expected_source']);
            self::assertSame(1, $entry['expected_gate_min_balance']);
            self::assertSame('DAS collection-group mapping', $entry['evidence']);
        }
    }

    /**
     * The evidence string is a claim about how these mappings were
     * established, and overstating it would licence a future reader to
     * trust them further than the work supports. No Metaplex certification
     * was performed.
     */
    public function testEvidenceIsNotOverstated(): void
    {
        $evidence = strtolower(SolanaGateIdentityManifest::EVIDENCE);

        self::assertStringNotContainsString('metaplex', $evidence);
        self::assertStringNotContainsString('certified', $evidence);
        self::assertStringNotContainsString('verified', $evidence);
    }

    /**
     * The confirmation token must change when the manifest does — that is
     * the whole point of binding it to the checksum. An operator cannot
     * approve one table and execute another.
     */
    public function testConfirmationTokenIsBoundToVersionAndChecksum(): void
    {
        $token = SolanaGateIdentityManifest::confirmationToken();

        self::assertStringStartsWith('solana-gate-identity-v1-', $token);
        self::assertStringEndsWith(substr(SolanaGateIdentityManifest::checksum(), 0, 12), $token);
    }

    /**
     * The manifest must agree with the eight pairs already pinned by the
     * merged PR-5b-Part-1 fidelity test. Two hard-coded copies of the same
     * mapping is exactly how a repair ends up writing something the audit
     * test never checked.
     */
    public function testManifestAgreesWithTheAuditFidelityPairs(): void
    {
        $pairs = AuditMetaRepairFidelityTest::repairPairs();

        self::assertCount(
            SolanaGateIdentityManifest::count(),
            $pairs,
            'the manifest and the audit fidelity provider disagree on how many gates there are'
        );

        $byAlias = [];
        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            $byAlias[$entry['alias']] = $entry['new_canonical_identifier'];
        }

        foreach ($pairs as $label => $pair) {
            [$alias, $mint] = $pair;

            self::assertArrayHasKey($alias, $byAlias, "fidelity pair '{$label}' is absent from the manifest");
            self::assertSame(
                $mint,
                $byAlias[$alias],
                "manifest and fidelity test disagree on the mint for '{$alias}'"
            );
        }
    }
}
