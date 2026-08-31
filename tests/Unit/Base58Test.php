<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Support\Base58;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The base58 decoder that Solana collection identity is built on.
 *
 * A wrong decoder is worse than no decoder: it would silently accept
 * non-keys as collection identities, or reject real ones. The known-vector
 * tests below are the anchor — they use published Solana program and mint
 * addresses, so they fail loudly if the arithmetic drifts.
 */
final class Base58Test extends TestCase
{
    /**
     * Published Solana addresses. Every Solana public key is 32 bytes, so
     * each of these is simultaneously a correctness vector and a
     * demonstration that the 32-byte rule matches reality.
     *
     * @return array<string, array{string}>
     */
    public static function realAddresses(): array
    {
        return [
            'System Program'      => ['11111111111111111111111111111111'],
            'Token Program'       => ['TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA'],
            'Assoc Token Program' => ['ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL'],
            'Memo Program'        => ['MemoSq4gqABAXKb96qnH8TysNcWxMyWCqXgDLGmfcHr'],
            'USDC mint'           => ['EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v'],
            'Wrapped SOL mint'    => ['So11111111111111111111111111111111111111112'],
            'Metaplex Token Meta' => ['metaqbxxUerdq28cj1RbAWkYQm3ybzjb6a8bt518x1s'],
        ];
    }

    #[DataProvider('realAddresses')]
    public function testRealSolanaAddressesDecodeToThirtyTwoBytes(string $address): void
    {
        self::assertSame(32, Base58::decodedLength($address));
    }

    // ── Known vectors ───────────────────────────────────────────────────

    public function testSingleOneDecodesToASingleZeroByte(): void
    {
        // In base58 a leading '1' IS a zero byte. This is the case a
        // decoder that strips leading zeros gets wrong.
        self::assertSame("\x00", Base58::decode('1'));
    }

    public function testLeadingOnesBecomeLeadingZeroBytes(): void
    {
        self::assertSame(str_repeat("\x00", 32), Base58::decode(str_repeat('1', 32)));
        self::assertSame("\x00\x00\x01", Base58::decode('112'));
    }

    public function testSmallValuesDecodeExactly(): void
    {
        // '2' is digit 1.
        self::assertSame("\x01", Base58::decode('2'));
        // '2''1' = 1*58 + 0 = 58 = 0x3A.
        self::assertSame("\x3A", Base58::decode('21'));
        // '5''7' = 4*58 + 6 = 238 = 0xEE.
        self::assertSame("\xEE", Base58::decode('57'));
    }

    // ── Rejections ──────────────────────────────────────────────────────

    public function testEmptyInputIsRejected(): void
    {
        self::assertNull(Base58::decode(''));
        self::assertNull(Base58::decodedLength(''));
    }

    /** 0, O, I and l are deliberately absent from the alphabet. */
    public function testCharactersOutsideTheAlphabetAreRejected(): void
    {
        foreach (['0', 'O', 'I', 'l', '_', '-', '+', '/', ' ', 'мир'] as $bad) {
            self::assertNull(Base58::decode('2222' . $bad . '2222'), "'{$bad}' must be rejected");
        }
    }

    /**
     * The bound exists so a pathological input never reaches the decode
     * loop at all — decoding is O(len * bytes).
     */
    public function testOverlongInputIsRejectedBeforeDecoding(): void
    {
        $atLimit  = str_repeat('2', Base58::MAX_INPUT_LENGTH);
        $overLimit = str_repeat('2', Base58::MAX_INPUT_LENGTH + 1);

        self::assertNotNull(Base58::decode($atLimit), 'the limit itself must still decode');
        self::assertNull(Base58::decode($overLimit), 'one character over the limit must be refused');
    }

    /** No exception, no warning, no silent truncation — just null. */
    public function testMalformedInputFailsClosedRatherThanThrowing(): void
    {
        foreach (['!!!', "\x00\x01", str_repeat('O', 40), 'mad_lads', 'okay-bears'] as $malformed) {
            self::assertNull(Base58::decode($malformed));
        }
    }

    // ── The property the identity rule depends on ───────────────────────

    /**
     * Being in the 32-44 character band does NOT imply a 32-byte value.
     * This is the whole reason the shape regex was insufficient.
     */
    public function testLengthBandDoesNotImplyThirtyTwoBytes(): void
    {
        self::assertSame(24, Base58::decodedLength(str_repeat('a', 32)));
        self::assertSame(33, Base58::decodedLength(str_repeat('a', 44)));
        self::assertSame(31, Base58::decodedLength('mhuioF2uqSeGwJustsK8U4xo6Pbqc4m9TFR86wKeaY'));
        self::assertSame(33, Base58::decodedLength('V144wP39My4EhyEkJqXwXby9HqEFZ8ZHbpf1DBcZoyrW'));
    }

    /** Case matters: two spellings are two different values. */
    public function testCaseChangesTheDecodedValue(): void
    {
        $a = '7cmUkdkC4Z5fBWk42hqnvjPftNNWwBy9GKe6FcyFVwH9';
        $b = '7cmUkdkC4Z5fBWk42hqnvjPftNNWwBy9GKe6FcyFVwh9';

        self::assertSame(32, Base58::decodedLength($a));
        self::assertSame(32, Base58::decodedLength($b));
        self::assertNotSame(Base58::decode($a), Base58::decode($b));
    }
}
