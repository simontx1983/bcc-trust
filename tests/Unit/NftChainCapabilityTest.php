<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\NftChainCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three-answer capability column readers.
 *
 * ── WHAT THIS FILE USED TO BE, AND WHY IT SHRANK ────────────────────────
 * It was 17 cases about the chain-level `verdict()`: that it was fail-closed,
 * and that its four distinct refusals stayed DISTINGUISHABLE —
 * `NO_BCC_SUPPORT` (a product decision), `NO_ENUMERATION_DRIVER`
 * (structural), `MANUAL_DISABLED` (a permission), `PROVIDER_UNAVAILABLE`
 * (configuration) — because collapsing any pair is invisible in a boolean
 * test and actively misleading in the admin surface they fed.
 *
 * S7 removed `OP_ENUMERATION`, which removed that verdict's entire subject,
 * and with it `verdict()`, `isScannable()`, `forChain()`, `forChainId()` and
 * the six verdict constants. Fifteen cases went with them. They are not
 * replaced: a refusal vocabulary for a capability that no longer exists has
 * nothing to assert, and asserting the ABSENCE of a function is what the
 * class-level note in NftChainCapability is for.
 *
 * ⚠ The distinguishable-refusals discipline itself did NOT go away — it moved
 * to the per-operation statuses, where `NftDiscoveryCapabilityMatrixTest`
 * pins `OP_NO_DRIVER` vs `OP_DISABLED` vs `OP_PROVIDER_UNAVAILABLE` for
 * exactly the same reason.
 *
 * ── WHAT REMAINS HERE ───────────────────────────────────────────────────
 * The two column readers, which survive untouched and are read directly by
 * manual intake: `bccNftSupportState()` and `manualDiscoveryState()`. Both
 * answer three ways — yes, no, and "this install cannot say" — and the third
 * answer is the one a boolean cast would silently destroy.
 */
#[CoversClass(NftChainCapability::class)]
final class NftChainCapabilityTest extends TestCase
{
    // ── The three-answer column readers ─────────────────────────────────

    public function testColumnReadersDistinguishAbsentFromZero(): void
    {
        $preMigration = (object) ['id' => '1', 'slug' => 'cosmos'];
        $zero         = (object) ['bcc_supports_nft_collections' => '0', 'manual_collection_discovery_enabled' => '0'];
        $one          = (object) ['bcc_supports_nft_collections' => '1', 'manual_collection_discovery_enabled' => '1'];

        self::assertNull(NftChainCapability::bccNftSupportState($preMigration));
        self::assertNull(NftChainCapability::manualDiscoveryState($preMigration));

        self::assertFalse(NftChainCapability::bccNftSupportState($zero));
        self::assertFalse(NftChainCapability::manualDiscoveryState($zero));

        self::assertTrue(NftChainCapability::bccNftSupportState($one));
        self::assertTrue(NftChainCapability::manualDiscoveryState($one));
    }

    /**
     * Only the exact integer 1 is "on".
     *
     * A TINYINT column arrives from wpdb as a string, and anything that is
     * not 1 — including a NULL that a malformed row might carry — must read
     * as off rather than as truthy.
     */
    #[DataProvider('nonEnablingValues')]
    public function testOnlyOneEnables(mixed $raw): void
    {
        $chain = (object) ['bcc_supports_nft_collections' => $raw];

        self::assertFalse(NftChainCapability::bccNftSupportState($chain));
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonEnablingValues(): array
    {
        return [
            'string zero' => ['0'],
            'int zero'    => [0],
            'empty'       => [''],
            'null'        => [null],
            'two'         => ['2'],
            'word'        => ['yes'],
        ];
    }
}
