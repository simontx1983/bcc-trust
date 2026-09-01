<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Security\AuditMeta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PINNED DEPENDENCY for the not-yet-built collection-identity repair (PR 5b
 * part 2). This file exists so the repair cannot be blindsided by a later
 * tightening of the redaction policy.
 *
 * WHY THIS IS A TEST AND NOT A COMMENT
 * ------------------------------------
 * The repair's whole value is its audit trail: for each of the eight gated
 * Solana collections it must record the EXACT before value (a Magic Eden
 * alias) and the EXACT after value (a 32-byte base58 mint), so the change is
 * reviewable and reversible. Those values now travel through
 * {@see AuditMeta}, which drops, masks and truncates.
 *
 * Today nothing in the policy touches them. But redaction rules accumulate:
 * someone adds `contract` to the truncate list, or widens a value-shape
 * pattern, and the repair records silently become useless — WITHOUT any test
 * failing, because the repair does not exist yet to notice. This file makes
 * that regression loud today rather than expensive later.
 *
 * THE SPECIFIC HAZARD IS CASE. Solana identity is byte-exact base58; PR 5a
 * exists precisely because Magic Eden aliases were being case-folded into a
 * case-insensitive unique key. A metadata boundary that lowercased, masked or
 * shortened a mint would re-introduce that defect in the audit trail while
 * the repair itself looked correct.
 *
 * Identifiers below are PUBLIC on-chain collection addresses — not secrets,
 * not user data.
 *
 * @see docs: PR 5b design — "Recorded per repair action"
 */
final class AuditMetaRepairFidelityTest extends TestCase
{
    /**
     * The eight production gated collections: alias (before) => mint (after).
     * Mixed case throughout, deliberately.
     *
     * @return array<string, array{string, string}>
     */
    public static function repairPairs(): array
    {
        return [
            'alpha_gardener' => ['alpha_gardener', '4fKR1UC2UA5R5m3ZGJwisZD4tkqQ2ZEPgGeZn51bB8uy'],
            'fidelion'       => ['fidelion',       'HRisSNFkwrju4WoEeHABNWZ8wTsTWZCRApqoq9cK4VxC'],
            'saga'           => ['saga',           '1yPMtWU5aqcF72RdyRD5yipmcMRC8NGNK59NvYubLkZ'],
            'drifella2'      => ['drifella2',      '7cHTjqr2S8uUCrG3TVFvFix3vcLjhPiwrtRsAeJtESRj'],
            'degenfatcats'   => ['degenfatcats',   'EEcmjWts6buEvjBzapATc5CHZrQYZYn9fenpf3SPcVi4'],
            'cyber_frogs'    => ['cyber_frogs',    '2kEAck1FyW8TxB5SprEnasb4gkaahTdDV83wPtxm9y32'],
            'mushboomers'    => ['mushboomers',    'sCoELoMQdP5uHswMxUWWbpWHzZeaMJNArEDUw4L2Boz'],
            'bozosgroup'     => ['bozosgroup',     '8Db41NmU1i3gSPq6AZWK1tsndJPPTLRP22LDGAz8CHxD'],
        ];
    }

    #[DataProvider('repairPairs')]
    public function testRepairBeforeAndAfterValuesSurviveByteExact(string $before, string $after): void
    {
        $result = AuditMeta::encode([
            'run_id'           => 'pr5b-2026-09-01T00:00:00Z-0001',
            'manifest_version' => 1,
            'chain_slug'       => 'solana',
            'chain_id'         => 20,
            'collection_id'    => 100,
            'post_id'          => 6509,
            'field'            => 'canonical_identifier',
            'before'           => $before,
            'after'            => $after,
        ]);

        self::assertIsString($result['json'], 'a repair record must always encode');
        self::assertFalse($result['failed']);

        $decoded = json_decode($result['json'], true);
        self::assertIsArray($decoded);

        // === and assertSame are byte comparisons in PHP, so a single flipped
        // case or a stripped character fails here.
        self::assertSame($before, $decoded['before'], 'the BEFORE alias must survive byte-exact');
        self::assertSame($after, $decoded['after'], 'the AFTER mint must survive byte-exact');

        // Belt and braces on the specific defect PR 5a removed.
        self::assertNotSame(strtolower($after), $decoded['after'], 'the mint must NOT be lower-cased');
        self::assertSame(strlen($after), strlen($decoded['after']), 'the mint must NOT be truncated');
    }

    #[DataProvider('repairPairs')]
    public function testMintsSurviveUnderIdentifierShapedKeyNames(string $before, string $after): void
    {
        // The repair may name these fields differently. Pin the obvious
        // candidates too, so a future truncate-list entry on any of them is
        // caught here rather than in production forensics.
        foreach (['contract_address', 'canonical_identifier', 'collection', 'contract', 'mint', 'identifier'] as $key) {
            $result  = AuditMeta::encode([$key => $after, 'previous' => $before]);
            self::assertIsString($result['json'], "key `{$key}` must encode");

            $decoded = json_decode($result['json'], true);
            self::assertSame($after, $decoded[$key] ?? null, "`{$key}` must carry a mint byte-exact");
            self::assertSame($before, $decoded['previous'] ?? null, "`previous` must carry an alias byte-exact");
        }
    }

    /**
     * The free-text policy replaces content outright, so a repair that parked
     * an identifier under a free-text key would lose it entirely — not
     * truncated, GONE. Pin both halves of that interaction so the constraint
     * is discoverable from the test rather than from a broken audit trail.
     */
    public function testIdentifiersMustNotBeCarriedUnderFreeTextKeys(): void
    {
        $mint = '8Db41NmU1i3gSPq6AZWK1tsndJPPTLRP22LDGAz8CHxD';

        // The keys the repair is allowed to use: value survives byte-exact.
        $safe = AuditMeta::encode(['before' => 'bozosgroup', 'after' => $mint]);
        self::assertSame($mint, json_decode((string) $safe['json'], true)['after']);

        // A free-text key: the value is REPLACED, deliberately. If a future
        // repair record starts failing this expectation, the repair changed
        // key names into the free-text set and its trail is now useless.
        $unsafe = AuditMeta::encode(['note' => $mint]);
        $decoded = json_decode((string) $unsafe['json'], true);

        self::assertIsArray($decoded['note'], 'free-text keys become descriptors');
        self::assertStringNotContainsString($mint, (string) $unsafe['json']);
    }

    public function testAFullEightRowRepairBatchStaysWithinTheSizeCeiling(): void
    {
        // If the runner ever logged the whole manifest in one record, the
        // size cap would replace it with a truncation marker and the audit
        // trail would lose every value. Prove the realistic shape (one record
        // per row) is nowhere near the ceiling, and document the constraint.
        $pairs  = self::repairPairs();
        $single = AuditMeta::encode([
            'run_id' => 'pr5b-2026-09-01T00:00:00Z-0001',
            'chain_slug' => 'solana',
            'collection_id' => 100,
            'post_id' => 6509,
            'before' => 'bozosgroup',
            'after' => '8Db41NmU1i3gSPq6AZWK1tsndJPPTLRP22LDGAz8CHxD',
        ]);

        self::assertIsString($single['json']);
        self::assertLessThan(
            AuditMeta::MAX_ENCODED_BYTES / 4,
            strlen($single['json']),
            'one repair record must sit comfortably inside the cap'
        );

        // And state the negative explicitly: the whole batch in one record is
        // NOT safe, which is why the runner must log per row.
        $batch = AuditMeta::encode(['rows' => array_values($pairs), 'run_id' => 'x']);
        self::assertIsString($batch['json']);
        self::assertLessThanOrEqual(AuditMeta::MAX_ENCODED_BYTES, strlen($batch['json']));
    }
}
