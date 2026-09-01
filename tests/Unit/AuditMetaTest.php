<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Security\AuditMeta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The redaction policy that guards newly-durable audit metadata.
 *
 * `$meta` was accepted and discarded for the life of this table. Persisting it
 * is a data-loss fix, but it also promotes ~70 callers' payloads from
 * transient to durable-for-90-days-then-archived. These tests pin the policy
 * that stands between those two facts.
 *
 * Every redaction case here is a POSITIVE CONTROL: it asserts the sensitive
 * value is absent from the encoded output, not merely that some transform ran.
 * A test that only checks "a masked value appears" would still pass if the raw
 * value appeared alongside it.
 */
final class AuditMetaTest extends TestCase
{
    // ---------------------------------------------------------------
    // Empty vs. failed — the distinction the shaped return exists for
    // ---------------------------------------------------------------

    public function testEmptyMetaEncodesToNullWithoutFailure(): void
    {
        $result = AuditMeta::encode([]);

        self::assertNull($result['json'], 'empty meta must store NULL, never "[]"');
        self::assertFalse($result['failed'], 'empty meta is not an encoding failure');
    }

    public function testPayloadEntirelyRemovedByPolicyIsNotAFailure(): void
    {
        // Everything here is on the drop list. "Nothing worth keeping" is a
        // successful encode of nothing — it must NOT raise the failure flag,
        // or /system/health would light up on ordinary moderation traffic.
        $result = AuditMeta::encode(['admin_name' => 'Phillip', 'email_hash' => sha1('a@b.co')]);

        self::assertNull($result['json']);
        self::assertFalse($result['failed']);
    }

    public function testUnencodablePayloadFailsWithoutLosingTheDistinction(): void
    {
        // INF is not representable in JSON and fails in both json_encode and
        // WordPress's wp_json_encode (its sanity check only repairs UTF-8).
        $result = AuditMeta::encode(['ratio' => INF]);

        self::assertNull($result['json']);
        self::assertTrue($result['failed'], 'an encode failure must be distinguishable from "no metadata"');
    }

    // ---------------------------------------------------------------
    // Layer 1 — per-key policy
    // ---------------------------------------------------------------

    public function testRateLimiterRawIpIsNeverStoredVerbatim(): void
    {
        // The sharpest case in the survey. wp_bcc_trust_activity already
        // stores ip_address as VARBINARY and masks it on read; an unmasked
        // copy in meta would be a second channel that bypasses that masking
        // entirely — same row, same value, no mask.
        $result = AuditMeta::encode([
            'action'  => 'vote',
            'user_id' => 7,
            'ip'      => '203.0.113.42',
            'count'   => 91,
        ]);

        self::assertIsString($result['json']);
        self::assertStringNotContainsString('203.0.113.42', $result['json']);
        self::assertStringContainsString('203.0.113.***', $result['json']);
        // Non-sensitive context must survive — redaction is not deletion.
        self::assertStringContainsString('"count":91', $result['json']);
    }

    public function testAdminDisplayNameIsDropped(): void
    {
        $result = AuditMeta::encode(['admin_id' => 3, 'admin_name' => 'Phillip', 'target' => 12]);

        self::assertIsString($result['json']);
        self::assertStringNotContainsString('Phillip', $result['json']);
        // admin_id is retained — the name was redundant, not the attribution.
        self::assertStringContainsString('"admin_id":3', $result['json']);
    }

    public function testUnsaltedEmailHashIsDropped(): void
    {
        $hash   = sha1('someone@example.com');
        $result = AuditMeta::encode(['email_hash' => $hash]);

        self::assertNull($result['json']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function truncatedKeys(): array
    {
        return [
            'device fingerprint' => ['fingerprint', '20'],
            'server fingerprint' => ['server_fingerprint', '20'],
            'raw exception text' => ['last_error', '120'],
        ];
    }

    #[DataProvider('truncatedKeys')]
    public function testIdentifyingAndFreeTextKeysAreTruncated(string $key, string $limitAsString): void
    {
        $limit = (int) $limitAsString;
        $long  = str_repeat('x', $limit + 50);

        $result = AuditMeta::encode([$key => $long]);

        self::assertIsString($result['json']);
        self::assertStringNotContainsString($long, $result['json']);

        // Assert on the DECODED value, not the JSON text: the marker's
        // ellipsis is stored escaped (…), so a substring check against
        // the raw JSON would fail even though the behaviour is correct.
        $decoded = json_decode($result['json'], true);
        self::assertIsArray($decoded);
        self::assertStringEndsWith(AuditMeta::TRUNCATION_MARKER, $decoded[$key]);
        self::assertSame($limit, mb_strlen(
            mb_substr($decoded[$key], 0, -mb_strlen(AuditMeta::TRUNCATION_MARKER), 'UTF-8'),
            'UTF-8'
        ), 'the kept prefix must be exactly the configured limit');
    }

    // ---------------------------------------------------------------
    // Layer 2 — value shape, whatever the key is called
    // ---------------------------------------------------------------

    public function testIpInsideARawExceptionMessageIsMasked(): void
    {
        // CronService stores substr($e->getMessage(), 0, 255). A key-name rule
        // alone would never catch an address embedded in free text.
        $result = AuditMeta::encode([
            'last_error' => 'Connection refused connecting to 198.51.100.7 during recalc',
        ]);

        self::assertIsString($result['json']);
        self::assertStringNotContainsString('198.51.100.7', $result['json']);
        self::assertStringContainsString('198.51.100.***', $result['json']);
    }

    public function testIpUnderAnUnknownKeyNameIsStillMasked(): void
    {
        // This is the case that survives a future caller inventing a key the
        // policy has never heard of.
        $result = AuditMeta::encode(['some_future_key' => '198.51.100.9']);

        self::assertIsString($result['json']);
        self::assertStringNotContainsString('198.51.100.9', $result['json']);
    }

    public function testEmailLiteralIsMaskedAnywhere(): void
    {
        $result = AuditMeta::encode(['note' => 'reported by victim@example.org today']);

        self::assertIsString($result['json']);
        self::assertStringNotContainsString('victim@example.org', $result['json']);
        self::assertStringContainsString('v***@example.org', $result['json']);
    }

    public function testVersionNumbersAreNotMistakenForIpAddresses(): void
    {
        // 8.3.30.256 has an out-of-range final octet, so it is not an address
        // and must survive intact. Over-eager masking would corrupt ordinary
        // operational context.
        $result = AuditMeta::encode(['build' => '8.3.30.256']);

        self::assertIsString($result['json']);
        self::assertStringContainsString('8.3.30.256', $result['json']);
    }

    // ---------------------------------------------------------------
    // Layer 3 — structural caps
    // ---------------------------------------------------------------

    public function testUnboundedArraysAreCappedWithAnExplicitCount(): void
    {
        // FraudDetector passes voter_ids with no ceiling.
        $ids    = range(1, AuditMeta::MAX_ARRAY_ELEMENTS + 40);
        $result = AuditMeta::encode(['voter_ids' => $ids]);

        self::assertIsString($result['json']);
        self::assertStringContainsString('_elements_omitted', $result['json'], 'omission must be stated, never silent');
    }

    public function testDeepNestingIsCapped(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too far']]]]]];

        $result = AuditMeta::encode($deep);

        self::assertIsString($result['json']);
        self::assertStringNotContainsString('too far', $result['json']);
        self::assertStringContainsString('_depth_capped', $result['json']);
    }

    public function testOversizePayloadStaysValidJson(): void
    {
        // Cutting a JSON string to fit would emit something no reader can
        // parse. The whole payload is replaced by a well-formed marker object
        // instead, so the row remains machine-readable.
        $result = AuditMeta::encode(['blob' => str_repeat('abcdefghij', 1200), 'other' => 'x']);

        self::assertIsString($result['json']);
        self::assertLessThanOrEqual(AuditMeta::MAX_ENCODED_BYTES, strlen($result['json']));
        self::assertNotNull(json_decode($result['json'], true), 'stored meta must always parse');
    }

    public function testObjectsAreRecordedAsTypeMarkersNotSerialised(): void
    {
        $result = AuditMeta::encode(['thing' => new \stdClass()]);

        self::assertIsString($result['json']);
        self::assertStringContainsString(AuditMeta::REDACTED_MARKER, $result['json']);
        self::assertStringContainsString('object', $result['json']);
    }

    public function testTruncationNeverProducesUnencodableUtf8(): void
    {
        // A cut through the middle of a multi-byte sequence would make the
        // whole encode fail and cost the entire payload, turning a cosmetic
        // limit into data loss.
        $result = AuditMeta::encode(['note' => str_repeat('é', 400)]);

        self::assertIsString($result['json']);
        self::assertFalse($result['failed']);
        self::assertNotNull(json_decode($result['json'], true));
    }

    public function testOrdinaryMetadataSurvivesIntact(): void
    {
        // The policy must not be so aggressive that it defeats the point of
        // recording metadata at all.
        $result = AuditMeta::encode([
            'chain_slug'    => 'solana',
            'collection_id' => 100,
            'before'        => null,
            'after'         => '8Db41NmU1i3gSPq6AZWK1tsndJPPTLRP22LDGAz8CHxD',
            'dry_run'       => false,
        ]);

        self::assertIsString($result['json']);
        $decoded = json_decode($result['json'], true);

        self::assertSame('solana', $decoded['chain_slug']);
        self::assertSame(100, $decoded['collection_id']);
        self::assertNull($decoded['before']);
        self::assertSame('8Db41NmU1i3gSPq6AZWK1tsndJPPTLRP22LDGAz8CHxD', $decoded['after']);
        self::assertFalse($decoded['dry_run']);
    }
}
