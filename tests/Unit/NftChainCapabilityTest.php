<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared NFT discovery verdict.
 *
 * ── WHAT IS BEING PINNED ────────────────────────────────────────────────
 * `verdict()` is fail-closed, and the value of a fail-closed rule is
 * entirely in its NEGATIVE branches. A suite that proved only "a fully
 * configured chain is SCANNABLE" would pass against a rule that returned
 * SCANNABLE for everything.
 *
 * So the bulk of this file asserts refusals, and specifically that the
 * refusals stay DISTINGUISHABLE. Four of the seven verdicts are "no" for
 * four different reasons and send an operator to four different places:
 *
 *   NO_BCC_SUPPORT        a product decision   -> nothing to configure
 *   NO_ENUMERATION_DRIVER structural           -> nothing WILL EVER help
 *   MANUAL_DISABLED       a permission         -> one click
 *   PROVIDER_UNAVAILABLE  configuration        -> finish setup, maybe pay
 *
 * Collapsing any pair would be invisible in a boolean test and actively
 * misleading in the admin surface these feed. The most dangerous collapse is
 * the last two: it would make a chain look one API key away from a
 * capability no provider sells.
 */
#[CoversClass(NftChainCapability::class)]
final class NftChainCapabilityTest extends TestCase
{
    private const COSMWASM = NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION;

    /** A chain that is fully permitted and fully configured. */
    private static function scannableArgs(): array
    {
        return [true, true, true, [self::COSMWASM], [self::COSMWASM]];
    }

    // ── The one YES ─────────────────────────────────────────────────────

    public function testFullyConfiguredChainIsScannable(): void
    {
        $verdict = NftChainCapability::verdict(...self::scannableArgs());

        self::assertSame(NftChainCapability::SCANNABLE, $verdict);
        self::assertTrue(NftChainCapability::isScannable($verdict));
    }

    // S5 deleted `testUnmeasuredChainIsNotRefusedForBeingUnmeasured` here.
    // It asserted that a chain with no checkpoint row is not refused for
    // being unmeasured — a guard against a permanent deadlock, since the
    // first pass was what created the measurement. With no measurement input
    // at all, the case became a byte-for-byte duplicate of
    // testFullyConfiguredChainIsScannable above and was removed rather than
    // kept passing for a reason that no longer exists.

    // ── Defaults fail closed ────────────────────────────────────────────

    /**
     * THE SHIPPING STATE. Both columns land at 0 on every install, and this
     * is what that produces. If this test ever reads SCANNABLE, the
     * migration has enabled something it must not have.
     */
    public function testShippedDefaultsAreNotScannable(): void
    {
        $verdict = NftChainCapability::verdict(true, false, false, [self::COSMWASM], [self::COSMWASM]);

        self::assertSame(NftChainCapability::NO_BCC_SUPPORT, $verdict);
        self::assertFalse(NftChainCapability::isScannable($verdict));
    }

    /**
     * Only ONE of the six verdicts may ever be scannable. Written as an
     * exhaustive sweep so a new verdict constant added later without
     * thought cannot quietly become a second "yes".
     */
    #[DataProvider('everyVerdict')]
    public function testExactlyOneVerdictMeansYes(string $verdict, bool $expectedScannable): void
    {
        self::assertSame($expectedScannable, NftChainCapability::isScannable($verdict));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function everyVerdict(): array
    {
        return [
            'scannable'             => [NftChainCapability::SCANNABLE, true],
            'unknown'               => [NftChainCapability::UNKNOWN, false],
            'no bcc support'        => [NftChainCapability::NO_BCC_SUPPORT, false],
            'no enumeration driver' => [NftChainCapability::NO_ENUMERATION_DRIVER, false],
            'manual disabled'       => [NftChainCapability::MANUAL_DISABLED, false],
            'provider unavailable'  => [NftChainCapability::PROVIDER_UNAVAILABLE, false],
        ];
    }

    /**
     * A verdict this build has never heard of is NOT scannable.
     *
     * `isScannable()` is an identity test rather than a list of exclusions
     * precisely so a value from a newer build, a typo, an empty string, or a
     * column a partial row never set lands on "no".
     */
    #[DataProvider('nonsenseVerdicts')]
    public function testUnrecognisedVerdictIsNotScannable(string $verdict): void
    {
        self::assertFalse(NftChainCapability::isScannable($verdict));
    }

    /** @return array<string, array{0: string}> */
    public static function nonsenseVerdicts(): array
    {
        return [
            'empty'       => [''],
            'uppercase'   => ['SCANNABLE'],
            'typo'        => ['scannble'],
            'future value'=> ['scannable_with_relay'],
            'truthy word' => ['yes'],
            'numeric'     => ['1'],
        ];
    }

    // ── Validator support does not imply NFT support ────────────────────

    /**
     * Osmosis, Akash, Juno, Jackal and Cronos POS all carry validators and
     * none of them is an NFT chain for BCC. The two systems are separate,
     * and a chain being present and active is not a capability claim.
     */
    public function testValidatorChainWithoutProductSupportIsRefused(): void
    {
        self::assertSame(
            NftChainCapability::NO_BCC_SUPPORT,
            NftChainCapability::verdict(true,false, true, [self::COSMWASM], [self::COSMWASM]),
            'a validator-carrying chain must not become scannable merely by being permitted'
        );
    }

    // ── NFT support does not imply an enumeration driver ────────────────

    /**
     * The EVM answer. Product support ON, permission ON, provider fully
     * configured — and still refused, because nothing in this build (or on
     * the market) can enumerate an EVM chain.
     */
    public function testProductSupportAndPermissionDoNotCreateADriver(): void
    {
        self::assertSame(
            NftChainCapability::NO_ENUMERATION_DRIVER,
            NftChainCapability::verdict(true,true, true, [], [])
        );
    }

    // ── The distinction that matters most ───────────────────────────────

    /**
     * PROVIDER_UNAVAILABLE and NO_ENUMERATION_DRIVER are different answers
     * to different problems, and the difference is the point of this class.
     *
     * "You have no enumerating implementation" is permanent.
     * "You have one, it is not configured" is a task.
     */
    public function testProviderUnavailableIsDistinctFromNoDriver(): void
    {
        $noDriver = NftChainCapability::verdict(true,true, true, [], []);
        $notReady = NftChainCapability::verdict(true,true, true, [self::COSMWASM], []);

        self::assertSame(NftChainCapability::NO_ENUMERATION_DRIVER, $noDriver);
        self::assertSame(NftChainCapability::PROVIDER_UNAVAILABLE, $notReady);
        self::assertNotSame($noDriver, $notReady);
    }

    /**
     * A DRIVER LIST THAT IS NON-EMPTY BUT ENTIRELY UNREADY still refuses.
     *
     * The failure mode: an implementation that checked `drivers !== []` and
     * forgot to check readiness would return SCANNABLE here and hand the
     * operator a job that cannot make one successful call.
     */
    public function testDriversPresentButNoneReadyRefuses(): void
    {
        self::assertSame(
            NftChainCapability::PROVIDER_UNAVAILABLE,
            NftChainCapability::verdict(true,true, true, [self::COSMWASM, 'another'], [])
        );
    }

    /** One ready driver out of several is enough. */
    public function testOneReadyDriverIsSufficient(): void
    {
        self::assertSame(
            NftChainCapability::SCANNABLE,
            NftChainCapability::verdict(true,true, true, ['a', self::COSMWASM], [self::COSMWASM])
        );
    }

    // S5 deleted `testMeasuredUnsupportedOutranksEveryPermission` here. It
    // asserted that a stored 501 measurement produced CHAIN_UNSUPPORTED even
    // when every operator-controlled input said yes. Both the rung and the
    // verdict are gone, so there is nothing left to assert — and asserting
    // the OPPOSITE (that such a chain is now SCANNABLE) would be a test of
    // the absence of a feature rather than of a behaviour. What replaces the
    // coverage lives in CosmosNoWasmModuleIsNeverNotAnNftTest, which pins
    // that a 501 is UNAVAILABLE per request and never "not an NFT".

    // ── An unreadable override store fails closed ───────────────────────

    /**
     * THE FAIL-OPEN THIS GUARDS AGAINST.
     *
     * An absent override row MEANS "registry default applies". So if a
     * failed read were reported as "no overrides", a chain whose operator
     * had DISABLED its enumeration driver would silently get the driver
     * back — at exactly the moment the database is least healthy.
     *
     * `overridesAvailable = false` therefore refuses, and it refuses BEFORE
     * anything derived from the driver list is considered, because that list
     * is precisely what cannot be trusted.
     */
    public function testUnavailableOverridesRefuseEvenWhenEverythingElsePasses(): void
    {
        $verdict = NftChainCapability::verdict(
            false,                       // overrides could not be established
            true,
            true,
            [self::COSMWASM],
            [self::COSMWASM]
        );

        self::assertSame(NftChainCapability::UNKNOWN, $verdict);
        self::assertFalse(NftChainCapability::isScannable($verdict));
    }

    /**
     * An unreadable override store can NEVER produce SCANNABLE, whatever
     * else is true. Swept across every combination of the remaining inputs
     * so no future reordering can open a path through.
     */
    public function testUnavailableOverridesCanNeverBeScannable(): void
    {
        // S5 dropped a `$measured` dimension from this sweep. The remaining
        // 36 combinations still exercise every other input, so the sweep is
        // narrower but not vacuous — it continues to assert the property it
        // was written for.
        $combinations = 0;
        foreach ([true, false, null] as $support) {
            foreach ([true, false, null] as $manual) {
                foreach ([[], [self::COSMWASM]] as $drivers) {
                    foreach ([[], [self::COSMWASM]] as $ready) {
                        self::assertNotSame(
                            NftChainCapability::SCANNABLE,
                            NftChainCapability::verdict(false, $support, $manual, $drivers, $ready)
                        );
                        $combinations++;
                    }
                }
            }
        }

        // Anti-vacuity: a refactor that emptied one of the loops would
        // otherwise leave this test green having asserted nothing.
        self::assertSame(36, $combinations);
    }

    // S5 deleted `testMeasuredUnsupportedOutranksUnavailableOverrides` here.
    // It asserted that the measurement rung outranked an unreadable override
    // store. With the rung gone, UNKNOWN is the answer in that situation, and
    // the case immediately above already asserts it for every combination.

    // ── Unknown fails closed, and stays its own answer ──────────────────

    /**
     * A pre-migration projection carries neither column. That is NOT "the
     * operator said no" — it is "nobody has been able to decide anything
     * yet", and telling somebody they declined something they were never
     * offered sends them hunting for a switch that does not exist.
     */
    #[DataProvider('absentColumnCombinations')]
    public function testAbsentColumnYieldsUnknown(?bool $support, ?bool $manual): void
    {
        self::assertSame(
            NftChainCapability::UNKNOWN,
            NftChainCapability::verdict(true,$support, $manual, [self::COSMWASM], [self::COSMWASM])
        );
    }

    /** @return array<string, array{0: bool|null, 1: bool|null}> */
    public static function absentColumnCombinations(): array
    {
        return [
            'both absent'          => [null, null],
            'support absent'       => [null, true],
            'permission absent'    => [true, null],
            'support absent, perm off' => [null, false],
        ];
    }

    public function testUnknownIsReachedEvenWhenNoDriverExists(): void
    {
        // A pre-migration install cannot make ANY claim, including the
        // structural one — the projection is not trustworthy enough to
        // report a specific reason.
        self::assertSame(
            NftChainCapability::UNKNOWN,
            NftChainCapability::verdict(true,null, null, [], [])
        );
    }

    // ── Permission ──────────────────────────────────────────────────────

    public function testPermissionOffRefusesEvenWhenEverythingElsePasses(): void
    {
        self::assertSame(
            NftChainCapability::MANUAL_DISABLED,
            NftChainCapability::verdict(true,true, false, [self::COSMWASM], [self::COSMWASM])
        );
    }

    /**
     * Structural refusal is named BEFORE the permission.
     *
     * Otherwise an EVM chain with the permission off would report
     * MANUAL_DISABLED, sending an operator to flip a switch that cannot
     * possibly help — the chain still could not be enumerated afterwards.
     */
    public function testStructuralRefusalIsNamedBeforePermission(): void
    {
        self::assertSame(
            NftChainCapability::NO_ENUMERATION_DRIVER,
            NftChainCapability::verdict(true,true, false, [], [])
        );
    }

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
