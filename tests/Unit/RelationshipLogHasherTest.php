<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Support\RelationshipLogHasher as H;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The relationship log's hash chain: canonical JSON, the HKDF-derived key,
 * and a row hash that covers every hashed field and its predecessor.
 *
 * Separate processes: tests define BCC_ENCRYPTION_KEY, which must not leak.
 */
#[CoversClass(H::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RelationshipLogHasherTest extends TestCase
{
    private const SECRET = 'unit-test-key-not-a-secret';

    private static function withKey(): void
    {
        if (!defined('BCC_ENCRYPTION_KEY')) {
            define('BCC_ENCRYPTION_KEY', self::SECRET);
        }
    }

    /** @return array{seq: int, created_at: string, action: string, page_id: int, subject_user_id: int|null, actor_kind: string, actor_user_id: int|null, payload: array<mixed>, evidence_note_sha256: string|null, evidence_ref_sha256: string|null, key_id: int} */
    private static function row(): array
    {
        return [
            'seq'                  => 4,
            'created_at'           => '2026-11-23 18:00:00',
            'action'               => 'owner_linked',
            'page_id'              => 501,
            'subject_user_id'      => 7001,
            'actor_kind'           => 'staff',
            'actor_user_id'        => 7002,
            'payload'              => ['reason' => null, 'new' => ['user_id' => 7001, 'method' => 'staff']],
            'evidence_note_sha256' => hash('sha256', 'note'),
            'evidence_ref_sha256'  => null,
            'key_id'               => 1,
        ];
    }

    public function testCanonicalJsonSortsKeysRecursivelyAndKeepsListOrder(): void
    {
        self::assertSame(
            '{"a":{"x":1,"y":[3,1,2]},"b":"é/ü","c":null}',
            H::canonical(['c' => null, 'b' => 'é/ü', 'a' => ['y' => [3, 1, 2], 'x' => 1]])
        );
    }

    public function testFloatsAndObjectsAreRefused(): void
    {
        $refused = 0;
        foreach ([['x' => 0.5], ['x' => [1.0]], ['x' => new \stdClass()]] as $bad) {
            try {
                H::canonical($bad);
                self::fail('must be refused');
            } catch (\InvalidArgumentException) {
                ++$refused;
            }
        }
        self::assertSame(3, $refused);
    }

    public function testTheKeyIsTheHkdfOfTheSecretWithItsOwnInfo(): void
    {
        self::withKey();
        self::assertSame(hash_hkdf('sha256', self::SECRET, 32, 'bcc-validator-relationship-log-v1'), H::key());
        self::assertNotSame(hash('sha256', self::SECRET, true), H::key(), 'never the raw secret or a plain hash of it');
    }

    public function testNoSecretMeansNoChain(): void
    {
        $this->expectException(\RuntimeException::class);
        H::key();
    }

    public function testAnUnknownKeyIdIsRefused(): void
    {
        self::withKey();
        $this->expectException(\RuntimeException::class);
        H::key(2);
    }

    /** Pinned against an independent construction of the hash input. */
    public function testTheRowHashIsTheHmacOfTheCanonicalFieldsAndPrevHash(): void
    {
        self::withKey();
        $prev     = str_repeat('b', 64);
        $expected = hash_hmac(
            'sha256',
            '{"action":"owner_linked","actor_kind":"staff","actor_user_id":7002,"created_at":"2026-11-23 18:00:00",'
            . '"evidence_note_sha256":"' . hash('sha256', 'note') . '","evidence_ref_sha256":null,"key_id":1,'
            . '"page_id":501,"payload":{"new":{"method":"staff","user_id":7001},"reason":null},'
            . '"prev_hash":"' . $prev . '","seq":4,"subject_user_id":7001}',
            hash_hkdf('sha256', self::SECRET, 32, 'bcc-validator-relationship-log-v1')
        );
        self::assertSame($expected, H::rowHash(self::row(), $prev));
    }

    public function testEveryHashedFieldAndThePredecessorChangeTheHash(): void
    {
        self::withKey();
        $prev = H::GENESIS;
        $base = H::rowHash(self::row(), $prev);

        $changes = [
            'seq' => 5, 'created_at' => '2026-11-23 18:00:01', 'action' => 'owner_revoked', 'page_id' => 502,
            'subject_user_id' => null, 'actor_kind' => 'system', 'actor_user_id' => null,
            'payload' => ['reason' => 'linked_in_error'], 'evidence_note_sha256' => null,
            'evidence_ref_sha256' => hash('sha256', 'https://example.org'),
        ];
        foreach ($changes as $field => $value) {
            $row         = self::row();
            $row[$field] = $value;
            self::assertNotSame($base, H::rowHash($row, $prev), $field);
        }
        self::assertNotSame($base, H::rowHash(self::row(), str_repeat('c', 64)), 'prev_hash');
        self::assertSame($base, H::rowHash(self::row(), $prev), 'deterministic');
    }

    public function testAMalformedPredecessorIsRefused(): void
    {
        self::withKey();
        $this->expectException(\InvalidArgumentException::class);
        H::rowHash(self::row(), 'not-a-hash');
    }

    public function testDigest(): void
    {
        self::assertNull(H::digest(null));
        self::assertSame(hash('sha256', ''), H::digest(''));
        self::assertSame(64, strlen(H::GENESIS));
    }
}
