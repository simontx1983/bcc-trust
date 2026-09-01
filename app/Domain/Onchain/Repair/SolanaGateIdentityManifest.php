<?php
/**
 * The reviewed, static repair manifest for the eight unresolved Solana
 * holder gates.
 *
 * ── WHY A STATIC TABLE AND NOT A LOOKUP ─────────────────────────────────
 * The obvious implementation of this repair is "ask Magic Eden / DAS /
 * Helius what mint this symbol means, then write it down." That is exactly
 * what must NOT happen, for three reasons:
 *
 *  1. It would make a data repair depend on a third party being up and
 *     honest at the moment it runs. A repair that silently resolves
 *     differently on a retry is not a repair.
 *  2. A provider answer is not reviewable before the fact. A constant in
 *     a diff is: these eight rows were read, checked and approved by a
 *     human, and any change to them shows up in review.
 *  3. The whole defect being fixed here came from trusting a marketplace
 *     alias as an identity. Curing it with another marketplace lookup
 *     would repeat the mistake with extra steps.
 *
 * So the mapping is frozen here, and the runner makes NO network call of
 * any kind. A test asserts this file's transitive dependencies contain no
 * fetcher, and the runner's dry run is byte-identical with the provider
 * endpoints pointed at a dead port.
 *
 * ── WHAT THE EVIDENCE ACTUALLY IS ───────────────────────────────────────
 * `DAS collection-group mapping` — for each alias, the DAS
 * `grouping[].group_value` observed on assets of that collection, checked
 * both directions (50/50 reverse coherence). That is stronger than the
 * screening pass applied to the other 91 aliases, and weaker than a
 * Metaplex certification, which was NOT performed. Do not describe these
 * as Metaplex-certified.
 *
 * ── THE COUNT IS PART OF THE CONTRACT ───────────────────────────────────
 * Exactly eight. The separate 91-alias audit produced 64 `A_candidate`
 * rows, and an `A_candidate` is a SCREENING verdict, not authority to
 * write. Adding a ninth row here is a decision that requires the same
 * review these eight had — so {@see checksum()} is pinned by a test and a
 * ninth row turns CI red rather than quietly repairing something.
 *
 * @package BCC\Trust\Onchain\Repair
 * @since PR 5b — Solana holder-gate identity repair
 */

namespace BCC\Trust\Onchain\Repair;

if (!defined('ABSPATH')) {
    exit;
}

final class SolanaGateIdentityManifest
{
    /**
     * Manifest version. Bumped only when the MAPPINGS change.
     *
     * The apply-confirmation token is derived from this plus the checksum,
     * so editing the table invalidates any token an operator was holding —
     * they cannot approve one manifest and execute another.
     */
    public const VERSION = 1;

    /** Every entry is on this chain, resolved by SLUG at runtime. */
    public const CHAIN_SLUG = 'solana';

    /**
     * How each mapping was established. Recorded in the audit row.
     *
     * NOT "Metaplex certified" — no certification was checked.
     */
    public const EVIDENCE = 'DAS collection-group mapping';

    /** Expected `is_verified` on every row, before and after. */
    public const EXPECTED_IS_VERIFIED = 1;

    /** Expected `source` on every row, before and after. */
    public const EXPECTED_SOURCE = 'toplist';

    /** Expected `_bcc_gate_min_balance` on every gate, before and after. */
    public const EXPECTED_MIN_BALANCE = 1;

    /** Expected post type / status for every gated community. */
    public const EXPECTED_POST_TYPE   = 'peepso-group';
    public const EXPECTED_POST_STATUS = 'publish';

    /** Exactly this many mappings. Enforced, not documentation. */
    public const EXPECTED_COUNT = 8;

    /**
     * collection_id => [post_id, legacy alias, canonical collection address]
     *
     * `alias` is BOTH the expected `wp_bcc_onchain_collections.contract_address`
     * AND the expected `_bcc_gate_contract_address` post meta. The repair
     * asserts both and changes only the second.
     *
     * The addresses are byte-exact base58 and MUST NOT be reformatted,
     * re-cased or sorted by value — each decodes to exactly 32 bytes and
     * case is part of the identity.
     */
    private const MAPPINGS = [
        79  => [6502, 'alpha_gardener', '4fKR1UC2UA5R5m3ZGJwisZD4tkqQ2ZEPgGeZn51bB8uy'],
        80  => [6503, 'fidelion',       'HRisSNFkwrju4WoEeHABNWZ8wTsTWZCRApqoq9cK4VxC'],
        82  => [6504, 'saga',           '1yPMtWU5aqcF72RdyRD5yipmcMRC8NGNK59NvYubLkZ'],
        84  => [6505, 'drifella2',      '7cHTjqr2S8uUCrG3TVFvFix3vcLjhPiwrtRsAeJtESRj'],
        87  => [6506, 'degenfatcats',   'EEcmjWts6buEvjBzapATc5CHZrQYZYn9fenpf3SPcVi4'],
        93  => [6507, 'cyber_frogs',    '2kEAck1FyW8TxB5SprEnasb4gkaahTdDV83wPtxm9y32'],
        94  => [6508, 'mushboomers',    'sCoELoMQdP5uHswMxUWWbpWHzZeaMJNArEDUw4L2Boz'],
        100 => [6509, 'bozosgroup',     '8Db41NmU1i3gSPq6AZWK1tsndJPPTLRP22LDGAz8CHxD'],
    ];

    /**
     * The manifest as a list of self-describing entries.
     *
     * @return list<array{
     *     manifest_version: int,
     *     chain_slug: string,
     *     collection_id: int,
     *     post_id: int,
     *     alias: string,
     *     expected_contract_address: string,
     *     expected_canonical_identifier: null,
     *     expected_is_verified: int,
     *     expected_source: string,
     *     expected_gate_contract_address: string,
     *     expected_gate_min_balance: int,
     *     new_canonical_identifier: string,
     *     evidence: string
     * }>
     */
    public static function entries(): array
    {
        $out = [];

        foreach (self::MAPPINGS as $collectionId => $row) {
            [$postId, $alias, $canonical] = $row;

            $out[] = [
                'manifest_version'               => self::VERSION,
                'chain_slug'                     => self::CHAIN_SLUG,
                'collection_id'                  => (int) $collectionId,
                'post_id'                        => $postId,
                'alias'                          => $alias,
                // The collection row's own `contract_address` today.
                'expected_contract_address'      => $alias,
                // NULL is the documented "legacy identity unresolved" state.
                // A row that is NOT null is either already repaired or is a
                // different row than the one reviewed — both are refusals.
                'expected_canonical_identifier'  => null,
                'expected_is_verified'           => self::EXPECTED_IS_VERIFIED,
                'expected_source'                => self::EXPECTED_SOURCE,
                // The gate post meta today — the same alias.
                'expected_gate_contract_address' => $alias,
                'expected_gate_min_balance'      => self::EXPECTED_MIN_BALANCE,
                // What the repair writes, byte-exact.
                'new_canonical_identifier'       => $canonical,
                'evidence'                       => self::EVIDENCE,
            ];
        }

        return $out;
    }

    public static function count(): int
    {
        return count(self::MAPPINGS);
    }

    /**
     * A stable checksum over the whole mapping table.
     *
     * Deliberately computed from a CANONICAL SERIALISATION rather than from
     * the file, so reformatting, re-commenting or reordering the PHP does
     * not change it — only the data does. Pinned by a test, which is what
     * makes a ninth row or a single changed character fail CI instead of
     * silently repairing something nobody reviewed.
     *
     * Entries are sorted by collection id so the checksum cannot be
     * perturbed by array ordering alone.
     */
    public static function checksum(): string
    {
        $mappings = self::MAPPINGS;
        ksort($mappings);

        $parts = [];
        foreach ($mappings as $collectionId => $row) {
            [$postId, $alias, $canonical] = $row;
            $parts[] = implode("\x1f", [
                (string) $collectionId,
                (string) $postId,
                $alias,
                $canonical,
            ]);
        }

        return hash(
            'sha256',
            self::VERSION . "\x1e" . self::CHAIN_SLUG . "\x1e" . implode("\x1e", $parts)
        );
    }

    /**
     * The token an operator must pass to `--confirm` to mutate anything.
     *
     * Bound to BOTH the version and the checksum: a token minted while the
     * manifest said one thing cannot execute a manifest that now says
     * another. Short enough to copy from the dry-run output by hand.
     */
    public static function confirmationToken(): string
    {
        return 'solana-gate-identity-v' . self::VERSION . '-' . substr(self::checksum(), 0, 12);
    }
}
