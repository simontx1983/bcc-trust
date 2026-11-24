<?php

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The hash chain of the validator relationship log. Pure apart from reading
 * BCC_ENCRYPTION_KEY.
 *
 *   row_hash = HMAC-SHA256( canonical_json(hashed fields + prev_hash), K )
 *   K        = HKDF-SHA256( BCC_ENCRYPTION_KEY, info "bcc-validator-relationship-log-v1" )
 *
 * Canonical JSON: object keys sorted recursively (lists keep their order),
 * JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, and no floats anywhere
 * (a float's text form is not stable, so one is refused). The hashed fields
 * are exactly those of {@see hashedFields()}: the evidence enters only as
 * the SHA-256 digests stored beside it, so a logged redaction can clear the
 * text later without breaking the chain.
 *
 * @package BCC\Trust\Onchain\Support
 */
final class RelationshipLogHasher
{
    /** prev_hash of the first row. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** The key derivation rows are written with today. */
    public const KEY_ID = 1;

    /** @var array<int, string> key_id => HKDF info */
    private const KEY_INFO = [
        1 => 'bcc-validator-relationship-log-v1',
    ];

    /**
     * The derived HMAC key for $keyId.
     *
     * @throws \RuntimeException when BCC_ENCRYPTION_KEY is missing or the key id is unknown
     */
    public static function key(int $keyId = self::KEY_ID): string
    {
        if (!isset(self::KEY_INFO[$keyId])) {
            throw new \RuntimeException('Unknown relationship log key id: ' . $keyId);
        }
        $secret = defined('BCC_ENCRYPTION_KEY') ? (string) constant('BCC_ENCRYPTION_KEY') : '';
        if ($secret === '') {
            throw new \RuntimeException('BCC_ENCRYPTION_KEY is not set: the relationship log cannot be written or verified.');
        }

        return hash_hkdf('sha256', $secret, 32, self::KEY_INFO[$keyId]);
    }

    /**
     * The fields a row's hash covers, normalised to their canonical types.
     * The evidence enters only as its stored digests.
     *
     * @param array{seq: int, created_at: string, action: string, page_id: int, subject_user_id: int|null, actor_kind: string, actor_user_id: int|null, payload: array<mixed>, evidence_note_sha256: string|null, evidence_ref_sha256: string|null, key_id: int} $row
     * @return array<string, mixed>
     */
    public static function hashedFields(array $row): array
    {
        return [
            'seq'                  => (int) $row['seq'],
            'created_at'           => (string) $row['created_at'],
            'action'               => (string) $row['action'],
            'page_id'              => (int) $row['page_id'],
            'subject_user_id'      => $row['subject_user_id'] === null ? null : (int) $row['subject_user_id'],
            'actor_kind'           => (string) $row['actor_kind'],
            'actor_user_id'        => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            'payload'              => $row['payload'],
            'evidence_note_sha256' => $row['evidence_note_sha256'] === null ? null : (string) $row['evidence_note_sha256'],
            'evidence_ref_sha256'  => $row['evidence_ref_sha256'] === null ? null : (string) $row['evidence_ref_sha256'],
            'key_id'               => (int) $row['key_id'],
        ];
    }

    /**
     * @param array{seq: int, created_at: string, action: string, page_id: int, subject_user_id: int|null, actor_kind: string, actor_user_id: int|null, payload: array<mixed>, evidence_note_sha256: string|null, evidence_ref_sha256: string|null, key_id: int} $row
     */
    public static function rowHash(array $row, string $prevHash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $prevHash) !== 1) {
            throw new \InvalidArgumentException('prev_hash must be 64 lowercase hex characters.');
        }
        $fields              = self::hashedFields($row);
        $fields['prev_hash'] = $prevHash;

        return hash_hmac('sha256', self::canonical($fields), self::key((int) $row['key_id']));
    }

    /** SHA-256 hex digest of a text, or null for none. */
    public static function digest(?string $text): ?string
    {
        return $text === null ? null : hash('sha256', $text);
    }

    /**
     * Canonical JSON of $value: object keys sorted recursively, no floats.
     *
     * @throws \InvalidArgumentException for a float or a non-JSON value
     */
    public static function canonical(mixed $value): string
    {
        try {
            return json_encode(self::normalise($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Relationship log value is not JSON-encodable: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException('Relationship log values may not contain floats.');
        }
        if (is_object($value)) {
            throw new \InvalidArgumentException('Relationship log values may not contain objects.');
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::normalise($v);
        }
        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }
}
