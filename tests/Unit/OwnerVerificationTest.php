<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\ValueObjects\OwnerVerification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The public owner badge: the KIND of proof only (never the method, never a
 * date), with server-authoritative copy; the strongest proof wins; anything
 * unknown is no badge.
 */
#[CoversClass(OwnerVerification::class)]
final class OwnerVerificationTest extends TestCase
{
    /** @return array<string, array{list<string>, array{kind: string, label: string, description: string}}> */
    public static function proofs(): array
    {
        $wallet = ['kind' => 'wallet', 'label' => 'Wallet Verified', 'description' => 'Cryptographic proof'];

        return [
            'wallet signature'  => [['wallet'], $wallet],
            'Solana sign-in'    => [['siws'], $wallet],
            'self-send memo'    => [['memo_proof'], $wallet],
            'identity key'      => [['solana_identity'], ['kind' => 'node_key', 'label' => 'Node Key Verified', 'description' => 'Cryptographic proof']],
            'staff approval'    => [['staff'], ['kind' => 'staff', 'label' => 'Verified by BCC', 'description' => 'Staff verification']],
            'proof beats staff' => [['staff', 'solana_identity'], ['kind' => 'node_key', 'label' => 'Node Key Verified', 'description' => 'Cryptographic proof']],
            'wallet strongest'  => [['solana_identity', 'wallet', 'staff'], $wallet],
            'unknown ignored'   => [['mystery', 'staff'], ['kind' => 'staff', 'label' => 'Verified by BCC', 'description' => 'Staff verification']],
        ];
    }

    /**
     * @param list<string> $methods
     * @param array{kind: string, label: string, description: string} $expected
     */
    #[DataProvider('proofs')]
    public function testTheBadgeShowsTheKindOfTheStrongestProof(array $methods, array $expected): void
    {
        self::assertSame($expected, OwnerVerification::fromMethods($methods)?->toApiResponse());
    }

    public function testNoKnownProofIsNoBadge(): void
    {
        self::assertNull(OwnerVerification::fromMethods([]));
        self::assertNull(OwnerVerification::fromMethods(['mystery', '']));
    }

    public function testThePayloadCarriesNoMethodAndNoDate(): void
    {
        $payload = OwnerVerification::fromMethods(['memo_proof'])?->toApiResponse();

        self::assertSame(['kind', 'label', 'description'], array_keys((array) $payload));
        self::assertStringNotContainsString('memo', (string) json_encode($payload));
    }
}
