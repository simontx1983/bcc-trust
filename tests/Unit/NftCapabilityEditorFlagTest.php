<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\NftCapabilityEditor;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE TWO CHAIN FLAGS: WHAT EACH ONE MEANS, AND WHAT IT REFUSES TO MEAN.
 *
 * ── THE THREE CONTROLS ARE NOT ONE CONTROL ──────────────────────────────
 * `bcc_supports_nft_collections` is BCC's PRODUCT decision.
 * `manual_collection_discovery_enabled` is PERMISSION to start something.
 * `cosmwasm_nft_discovery_enabled` is the CosmWasm engine's own opt-in and
 * is not touched by anything in this file.
 *
 * Most of what is pinned here is the ways they must NOT collapse into one
 * another: granting product support must not grant permission, permission
 * must not be grantable without product support, and neither may be
 * aliased to the CosmWasm opt-in.
 *
 * ── AND NONE OF THEM STARTS WORK ────────────────────────────────────────
 * Every test asserts the worker's pass counter is still zero. That is the
 * single claim this whole PR rests on: a capability edit makes something
 * POSSIBLE for somebody who later presses a different button. It never
 * performs one.
 */
#[CoversClass(NftCapabilityEditor::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftCapabilityEditorFlagTest extends TestCase
{
    private const CHAIN_ID = 4;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-capability-editor-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        ChainRepository::reset();
        ChainNftCapabilityRepository::reset();
        CosmwasmDiscoveryWorker::reset();
    }

    /** @return list<string> */
    private function audits(): array
    {
        return \BCC\Trust\Core\Security\AuditLogger::actions();
    }

    private function product(): ?bool
    {
        return ChainRepository::storedFlag(self::CHAIN_ID, 'bcc_supports_nft_collections');
    }

    private function manual(): ?bool
    {
        return ChainRepository::storedFlag(self::CHAIN_ID, 'manual_collection_discovery_enabled');
    }

    /**
     * Nothing was started, verified, provisioned or persisted.
     *
     * Asserted after EVERY case in this file, successes included — a
     * capability edit that quietly kicked off a scan would still return the
     * right result code.
     */
    private function assertNoWorkRan(): void
    {
        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes, 'no discovery ran');
        $this->assertSame([], \BCC\Trust\Onchain\Support\CosmwasmTickBudget::$constructions, 'no budget built');
        $this->assertSame([], ChainRepository::$discoveryWrites, 'the CosmWasm opt-in was not touched');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PRODUCT SUPPORT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ ENABLING PRODUCT SUPPORT DOES NOT GRANT THE PERMISSION.
     *
     * The two are separate grants and answer to different people: a product
     * decision that implicitly armed an operator button would mean nobody
     * ever consciously decided a discovery could be started on that chain.
     */
    public function testProductEnableChangesOnlyProductSupport(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, false, false);
        $before = clone ChainRepository::$rows[self::CHAIN_ID];

        $result = NftCapabilityEditor::enableProductSupport(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_PRODUCT_ENABLED, $result);
        $this->assertTrue($this->product());
        $this->assertFalse($this->manual(), 'product support must not grant the permission');

        // Surgical: exactly one column moved on the whole row.
        $after   = ChainRepository::$rows[self::CHAIN_ID];
        $changed = [];
        foreach (get_object_vars($before) as $column => $value) {
            if ($after->{$column} !== $value) {
                $changed[] = $column;
            }
        }
        $this->assertSame(['bcc_supports_nft_collections'], $changed);

        $this->assertSame(['admin_nft_product_support_enabled'], $this->audits());
        $this->assertNoWorkRan();
    }

    /**
     * ⚠️ DISABLING PRODUCT SUPPORT CLEARS THE PERMISSION WITH IT.
     *
     * A permission left behind on an unsupported chain is invisible — the
     * capability model reports `no_bcc_support` and stops — right up until
     * support is granted again, at which point the chain returns already
     * permitted on the strength of a decision nobody remembers taking.
     */
    public function testProductDisableAlsoClearsTheManualPermission(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, true);

        $result = NftCapabilityEditor::disableProductSupport(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_PRODUCT_DISABLED_CASCADE, $result);
        $this->assertFalse($this->product());
        $this->assertFalse($this->manual(), 'the dormant permission must not survive');

        // The result code and the audit event both SAY the cascade happened,
        // so the operator notice can too.
        $this->assertSame(['admin_nft_product_disabled_manual_cleared'], $this->audits());
        $this->assertNoWorkRan();
    }

    /** With no permission set, the cascade is a no-op and the notice says so. */
    public function testProductDisableWithoutAPermissionSaysSoDifferently(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);

        $result = NftCapabilityEditor::disableProductSupport(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_PRODUCT_DISABLED, $result);
        $this->assertSame(['admin_nft_product_support_disabled'], $this->audits());
        $this->assertNoWorkRan();
    }

    /**
     * ⚠️ ALREADY-OFF PRODUCT SUPPORT WITH A STALE PERMISSION IS NOT A NO-OP.
     *
     * This is the state a restored backup or an older build leaves behind,
     * and it is exactly the one the cascade exists to clean up. Treating it
     * as "already disabled, nothing to do" would leave the invisible
     * permission in place forever.
     */
    public function testProductDisableStillClearsAStalePermissionWhenSupportIsAlreadyOff(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, false, true);

        $result = NftCapabilityEditor::disableProductSupport(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_PRODUCT_DISABLED_CASCADE, $result);
        $this->assertFalse($this->manual());
        $this->assertNoWorkRan();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  MANUAL PERMISSION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ REFUSED WHILE PRODUCT SUPPORT IS OFF — CHECKED AT EXECUTION TIME.
     *
     * Read from the authoritative row when the request runs, never from
     * whatever the browser was rendering. A page that showed product
     * support as on minutes ago is not evidence of anything.
     */
    public function testManualEnableIsRefusedWhileProductSupportIsOff(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, false, false);

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_NO_PRODUCT, $result);
        $this->assertFalse($this->manual());
        $this->assertSame([], ChainRepository::$capabilityWrites, 'refused before any write');
        $this->assertSame([], $this->audits());
        $this->assertNoWorkRan();
    }

    public function testManualEnableSucceedsWhenProductSupportIsOn(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_ENABLED, $result);
        $this->assertTrue($this->manual());
        $this->assertTrue($this->product(), 'granting permission must not disturb product support');
        $this->assertSame(['admin_nft_manual_discovery_enabled'], $this->audits());
        $this->assertNoWorkRan();
    }

    public function testManualDisableSucceeds(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, true);

        $result = NftCapabilityEditor::disableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_DISABLED, $result);
        $this->assertFalse($this->manual());
        $this->assertTrue($this->product(), 'withdrawing permission is not withdrawing support');
        $this->assertSame(['admin_nft_manual_discovery_disabled'], $this->audits());
        $this->assertNoWorkRan();
    }

    /**
     * ⚠️ WITHDRAWING IS ALWAYS PERMITTED — EVEN WITH PRODUCT SUPPORT OFF.
     *
     * The asymmetry is the design. The gates in front of GRANTING exist to
     * stop a permission being created where it cannot mean anything;
     * applying them to REMOVAL would mean a database already holding a
     * wrong value could not be returned to the safe state through the only
     * sanctioned path.
     */
    public function testManualDisableIsPermittedEvenWithProductSupportOff(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, false, true);

        $result = NftCapabilityEditor::disableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_DISABLED, $result);
        $this->assertFalse($this->manual());
        $this->assertNoWorkRan();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  EVM AND SOLANA CANNOT BE MADE CHAIN-ENUMERABLE
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string, 1: string}> */
    public static function nonEnumerableFamilies(): array
    {
        return [
            'ethereum (evm)' => ['ethereum', 'evm'],
            'base (evm)'     => ['base', 'evm'],
            'solana'         => ['solana', 'solana'],
        ];
    }

    /**
     * ⚠️ THE PERMISSION IS REFUSED, NOT MERELY HIDDEN.
     *
     * No control is rendered for these families — but the absence of a
     * button is not a boundary. A crafted POST with a valid nonce reaches
     * the service, and the service is where it stops. Storing the intent
     * "for later" would leave a row asserting somebody granted a
     * capability, which is exactly the misreading the model exists to
     * prevent, and a restored backup or a later build could read it as
     * consent it never was.
     */
    #[DataProvider('nonEnumerableFamilies')]
    public function testManualEnableIsRefusedOnAFamilyWithNoEnumerationDriver(
        string $slug,
        string $chainType
    ): void {
        ChainRepository::seed(self::CHAIN_ID, $slug, false, true, false, $chainType);

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_NO_STARTABLE, $result);
        $this->assertFalse($this->manual(), 'nothing was stored');
        $this->assertSame([], ChainRepository::$capabilityWrites);
        $this->assertSame([], $this->audits());
        $this->assertNoWorkRan();
    }

    /**
     * And the refusal is STRUCTURAL — it is named before the product-support
     * gate, so an operator is not sent to turn on a switch that would not
     * help.
     */
    #[DataProvider('nonEnumerableFamilies')]
    public function testTheStructuralRefusalOutranksTheProductSupportRefusal(
        string $slug,
        string $chainType
    ): void {
        // Product support ALSO off, so both refusals apply and only the
        // more useful one may be returned.
        ChainRepository::seed(self::CHAIN_ID, $slug, false, false, false, $chainType);

        $this->assertSame(
            NftCapabilityEditor::RESULT_MANUAL_NO_STARTABLE,
            NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID)
        );
    }

    /**
     * ⚠️ AND THE STORED PERMISSION WOULD NOT HAVE HELPED ANYWAY.
     *
     * Proven by writing the flag directly — as a restored backup would —
     * and showing the capability model still refuses enumeration for a
     * structural reason. This is the claim that makes the refusal above a
     * correctness fix rather than a policy: no setting can add chain-wide
     * enumeration to these families.
     */
    #[DataProvider('nonEnumerableFamilies')]
    public function testEvenAStoredPermissionLeavesTheChainNonEnumerable(
        string $slug,
        string $chainType
    ): void {
        ChainRepository::seed(self::CHAIN_ID, $slug, false, true, true, $chainType);

        $chain = ChainRepository::getById(self::CHAIN_ID);
        self::assertNotNull($chain);

        $matrix = NftChainCapability::operationMatrix($chain);
        $enumeration = $matrix['operations'][NftDriverRegistry::OP_ENUMERATION];

        $this->assertSame(NftChainCapability::OP_NO_DRIVER, $enumeration['status']);
        $this->assertSame(NftChainCapability::REASON_NO_REGISTERED_DRIVER, $enumeration['reason']);
        $this->assertSame([], $enumeration['registered'], 'the registry offers nothing to enumerate with');
        $this->assertSame(NftChainCapability::NO_ENUMERATION_DRIVER, $matrix['verdict']);
        $this->assertFalse(NftChainCapability::isScannable($matrix['verdict']));
    }

    /**
     * ⚠️ BUT A STORED-WRONG VALUE CAN STILL BE CLEARED.
     *
     * The only way out of the state above. If withdrawal were blocked by
     * the same structural check that blocks granting, a restored backup
     * would be permanently stuck holding a permission nobody can remove
     * through the sanctioned path.
     */
    #[DataProvider('nonEnumerableFamilies')]
    public function testAStoredPermissionOnSuchAChainCanStillBeWithdrawn(
        string $slug,
        string $chainType
    ): void {
        ChainRepository::seed(self::CHAIN_ID, $slug, false, true, true, $chainType);

        $result = NftCapabilityEditor::disableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_DISABLED, $result);
        $this->assertFalse($this->manual());
        $this->assertNoWorkRan();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ⚠️ THE FORBIDDEN STATE IS UNREACHABLE FROM EITHER INTERLEAVING
    // ═══════════════════════════════════════════════════════════════════
    //
    // `product = 0, manual = 1` is a permission nothing can see: the model
    // reports `no_bcc_support` and stops, so the row sits there invisibly
    // until support is restored — at which point the chain returns already
    // permitted, on a decision nobody remembers taking.
    //
    // Two writes can interleave two ways, and each is closed by a DIFFERENT
    // mechanism. That is why both are tested, and why neither test would
    // catch the other's defect.

    /**
     * ORDERING A — the withdrawal commits while the grant is deciding.
     *
     * The grant reads `product = 1`, a product-withdrawal atomically writes
     * `product = 0, manual = 0`, and only then does the grant's UPDATE run.
     * It carries `AND bcc_supports_nft_collections = 1`, so it matches no
     * row: zero affected rows, nothing written.
     *
     * ⚠️ NO SERVICE-LAYER CHECK CAN CLOSE THIS. The window is between the
     * read and the write, and re-reading only makes a smaller window. The
     * interleave hook fires INSIDE the repository call, which is the only
     * place a test can honestly stand.
     */
    public function testAWithdrawalDuringAGrantLeavesNoDormantPermission(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);

        ChainRepository::$interleavedWriter = static function (): void {
            // The other request, committing in the window.
            ChainRepository::disableNftProductSupport(self::CHAIN_ID);
        };

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(
            NftCapabilityEditor::RESULT_MANUAL_NO_PRODUCT,
            $result,
            'the predicate refused it, and the operator is told which permission is missing'
        );
        $this->assertFalse($this->product());
        $this->assertFalse($this->manual(), 'THE FORBIDDEN STATE');
        $this->assertNotContains(
            'admin_nft_manual_discovery_enabled',
            $this->audits(),
            'nothing changed, so no state-change row may claim it did'
        );
        $this->assertNoWorkRan();
    }

    /**
     * ORDERING B — the grant commits first, then the withdrawal runs.
     *
     * Closed by the OTHER mechanism: the withdrawal's cascade sets both
     * columns in one statement, so it cannot clear product support and leave
     * the permission standing.
     */
    public function testAGrantFollowedByAWithdrawalLeavesNoDormantPermission(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);

        $this->assertSame(
            NftCapabilityEditor::RESULT_MANUAL_ENABLED,
            NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID)
        );
        $this->assertTrue($this->manual());

        NftCapabilityEditor::disableProductSupport(self::CHAIN_ID);

        $this->assertFalse($this->product());
        $this->assertFalse($this->manual(), 'THE FORBIDDEN STATE');
        $this->assertNoWorkRan();
    }

    /**
     * And the exhaustive statement of it: after ANY sequence of these four
     * actions, the row is never `product = 0, manual = 1`.
     *
     * @return array<string, array{0: list<string>}>
     */
    public static function actionSequences(): array
    {
        return [
            'grant then withdraw support' => [['enableManualDiscovery', 'disableProductSupport']],
            'support off then grant'      => [['disableProductSupport', 'enableManualDiscovery']],
            'grant, withdraw, re-enable'  => [['enableManualDiscovery', 'disableProductSupport', 'enableProductSupport']],
            'churn'                       => [[
                'enableProductSupport', 'enableManualDiscovery', 'disableProductSupport',
                'enableProductSupport', 'disableManualDiscovery', 'enableManualDiscovery',
                'disableProductSupport',
            ]],
        ];
    }

    /** @param list<string> $sequence */
    #[DataProvider('actionSequences')]
    public function testNoSequenceReachesTheForbiddenState(array $sequence): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);

        foreach ($sequence as $step) {
            NftCapabilityEditor::{$step}(self::CHAIN_ID);

            $this->assertFalse(
                $this->product() === false && $this->manual() === true,
                'reached product=0, manual=1 after ' . $step
            );
        }

        // ⚠️ And re-enabling support never resurrects a permission: the
        // cascade cleared it, so it has to be granted again explicitly.
        if (end($sequence) === 'enableProductSupport') {
            $this->assertFalse($this->manual(), 'support came back WITHOUT the permission');
        }

        $this->assertNoWorkRan();
    }

    /**
     * A zero-row conditional grant is a REFUSAL, not a success.
     *
     * The affected-row count alone cannot say which: zero means "already
     * granted" or "the predicate matched nothing". Only reading BOTH columns
     * back separates them, and product support is asked about first.
     */
    public function testAZeroRowGrantWithProductOffIsRefusedNotReportedAsSuccess(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);

        ChainRepository::$interleavedWriter = static function (): void {
            ChainRepository::disableNftProductSupport(self::CHAIN_ID);
        };

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_NO_PRODUCT, $result);
        $this->assertNotSame(NftCapabilityEditor::RESULT_MANUAL_ENABLED, $result);
        $this->assertNotSame(NftCapabilityEditor::RESULT_MANUAL_NOOP_ENABLED, $result);
        $this->assertSame([], $this->audits(), 'a refusal is not a state change');
    }

    /**
     * A CONCURRENT IDENTICAL GRANT is a no-op with no state-change audit.
     *
     * Product support is still on and the permission is now on — but this
     * statement moved nothing, so the change belongs to whoever made it.
     * This is the case a pre-write read can only guess at.
     */
    public function testAConcurrentIdenticalGrantIsANoOpWithNoAudit(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);

        ChainRepository::$interleavedWriter = static function (): void {
            // Another operator grants the same permission first.
            ChainRepository::grantManualCollectionDiscovery(self::CHAIN_ID);
        };
        // Our statement then matches a row already holding the value.
        ChainRepository::$capabilityWriteNoOp       = true;
        ChainRepository::$capabilityConcurrentApply = true;

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_NOOP_ENABLED, $result);
        $this->assertTrue($this->manual(), 'the desired state is there');
        $this->assertTrue($this->product());
        $this->assertNotContains('admin_nft_manual_discovery_enabled', $this->audits());
        $this->assertNoWorkRan();
    }

    /**
     * The grant statement really is the conditional one, and the withdrawal
     * really is not.
     *
     * Named-method assertion rather than a behavioural one: the two
     * directions must never collapse back into a single
     * `set…(bool $enabled)`, because the predicate belongs to exactly one of
     * them.
     */
    public function testTheTwoDirectionsUseTheTwoDifferentStatements(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);

        NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);
        NftCapabilityEditor::disableManualDiscovery(self::CHAIN_ID);

        $methods = array_column(ChainRepository::$capabilityWrites, 'method');
        $this->assertSame(
            ['grantManualCollectionDiscovery', 'withdrawManualCollectionDiscovery'],
            $methods
        );
    }

    /**
     * ⚠️ WITHDRAWAL IS UNCONDITIONAL — including with product support off.
     *
     * If the grant's predicate were copied onto the withdrawal, a row that
     * already holds `product = 0, manual = 1` (a restored backup, an older
     * build) could never be corrected through the sanctioned path.
     */
    public function testWithdrawalWorksOnARowAlreadyInTheForbiddenState(): void
    {
        // The state the predicate makes unreachable going forward, planted
        // directly as a restored backup would.
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, false, true);
        $this->assertFalse($this->product());
        $this->assertTrue($this->manual());

        $result = NftCapabilityEditor::disableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_DISABLED, $result);
        $this->assertFalse($this->manual(), 'the bad value can always be cleared');
        $this->assertNoWorkRan();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  NO-OPS WRITE NOTHING
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Repeating the current state changes nothing and records nothing.
     *
     * This is also what makes a stale tab safe: the route names its
     * direction, so a second submit of an already-applied direction lands
     * here instead of flipping it back.
     *
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: string}>
     */
    public static function noOps(): array
    {
        return [
            'product enable when on'   => ['enableProductSupport', true, false, NftCapabilityEditor::RESULT_PRODUCT_NOOP_ENABLED],
            'product disable when off' => ['disableProductSupport', false, false, NftCapabilityEditor::RESULT_PRODUCT_NOOP_DISABLED],
            'manual enable when on'    => ['enableManualDiscovery', true, true, NftCapabilityEditor::RESULT_MANUAL_NOOP_ENABLED],
            'manual disable when off'  => ['disableManualDiscovery', true, false, NftCapabilityEditor::RESULT_MANUAL_NOOP_DISABLED],
        ];
    }

    #[DataProvider('noOps')]
    public function testRepeatingTheCurrentStateIsANoOp(
        string $method,
        bool $product,
        bool $manual,
        string $expected
    ): void {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, $product, $manual);

        $result = NftCapabilityEditor::{$method}(self::CHAIN_ID);

        $this->assertSame($expected, $result);
        $this->assertSame([], ChainRepository::$capabilityWrites, 'a no-op writes nothing at all');
        $this->assertSame([], $this->audits(), 'a no-op is not a state change');
        $this->assertSame(0, ChainRepository::$cacheBusts, 'and busts no cache');
        $this->assertNoWorkRan();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  FAILURE, POSTCONDITION AND CONCURRENCY
    // ═══════════════════════════════════════════════════════════════════

    /** A refused write is never reported as a change. */
    public function testADatabaseFailureIsNotReportedAsSuccess(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);
        ChainRepository::$capabilityWriteFails = true;

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_WRITE_FAILED, $result);
        $this->assertFalse($this->manual(), 'nothing moved');
        $this->assertSame(
            ['admin_nft_manual_discovery_enable_failed'],
            $this->audits(),
            'an authorised change was attempted and refused — that is durable, and it is not a success'
        );
        $this->assertNoWorkRan();
    }

    /**
     * ⚠️ A WRITE THAT REPORTS SUCCESS AND DOES NOT READ BACK IS NOT SUCCESS.
     *
     * "The UPDATE did not error" is not "the flag is now what you asked
     * for" — the row may have gone, or the projection may not carry the
     * column. The value is READ BACK, from a re-resolved chain, and a
     * disagreement is reported as unconfirmed.
     */
    public function testAFailedPostconditionIsNotReportedAsSuccess(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);
        ChainRepository::$capabilityWriteSilentlyDrops = true;

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_UNVERIFIED, $result);
        $this->assertSame(['admin_nft_manual_discovery_enable_unconfirmed'], $this->audits());
        $this->assertNotContains('admin_nft_manual_discovery_enabled', $this->audits());
        $this->assertNoWorkRan();
    }

    /** A chain that disappears between the write and the read-back is unconfirmed too. */
    public function testAVanishedChainIsUnconfirmedNotSuccessful(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);
        ChainRepository::$readBackNull = true;

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_UNVERIFIED, $result);
    }

    /**
     * ⚠️ ZERO AFFECTED ROWS PLUS A VERIFIED POSTCONDITION IS A NO-OP.
     *
     * The concurrency case: our statement changed nothing because another
     * request applied the same change first. The desired state IS there, so
     * this is not a failure — but this request did not put it there, so no
     * audit row may claim it did. A pre-write read could never tell these
     * apart; only the affected-row count can.
     */
    public function testAConcurrentlyAppliedChangeIsANoOpWithNoAuditRow(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);
        ChainRepository::$capabilityWriteNoOp       = true;
        ChainRepository::$capabilityConcurrentApply = true;

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_NOOP_ENABLED, $result);
        $this->assertTrue($this->manual(), 'the desired state is there');
        $this->assertSame([], $this->audits(), 'but this request did not make it so');
        $this->assertNoWorkRan();
    }

    /** Zero affected rows AND the wrong state is unconfirmed, not a no-op. */
    public function testZeroRowsWithoutTheDesiredStateIsUnverified(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);
        ChainRepository::$capabilityWriteNoOp = true;   // no concurrent apply

        $result = NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_MANUAL_UNVERIFIED, $result);
        $this->assertSame(['admin_nft_manual_discovery_enable_unconfirmed'], $this->audits());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CACHE AND PRE-MIGRATION PROJECTIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The chain cache is cleared as part of the write, and after a
     * zero-row result too.
     *
     * `getActive()` serves from a five-minute object-cache/transient pair.
     * A write that skipped invalidation would leave every reader — and the
     * postcondition read itself — answering from the projection taken
     * BEFORE the change.
     */
    public function testEveryFlagWriteClearsTheChainCache(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);

        NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertGreaterThan(0, ChainRepository::$cacheBusts);
    }

    public function testTheCacheIsClearedEvenWhenTheWriteAffectedNoRows(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, false);
        ChainRepository::$capabilityWriteNoOp       = true;
        ChainRepository::$capabilityConcurrentApply = true;

        NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);

        $this->assertGreaterThan(
            0,
            ChainRepository::$cacheBusts,
            'a concurrent writer must not leave this request reading a stale memo'
        );
    }

    /** Re-reading the chain shows the new state. */
    public function testRereadingTheChainReturnsTheNewFlagState(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, false, false);

        NftCapabilityEditor::enableProductSupport(self::CHAIN_ID);

        $reread = ChainRepository::getById(self::CHAIN_ID);
        self::assertNotNull($reread);
        $this->assertTrue(NftChainCapability::bccNftSupportState($reread));
        $this->assertFalse(NftChainCapability::manualDiscoveryState($reread));
    }

    /**
     * A pre-migration projection cannot store the answer, and is told so.
     *
     * `null` is a third answer, not a synonym for "off". Reporting an
     * install that CANNOT record a decision as having declined one sends an
     * operator looking for a switch that is not there.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function absentColumns(): array
    {
        return [
            'product enable, product column gone'  => ['enableProductSupport', 'bcc_supports_nft_collections'],
            'product disable, product column gone' => ['disableProductSupport', 'bcc_supports_nft_collections'],
            'product disable, manual column gone'  => ['disableProductSupport', 'manual_collection_discovery_enabled'],
            'manual enable, manual column gone'    => ['enableManualDiscovery', 'manual_collection_discovery_enabled'],
            'manual enable, product column gone'   => ['enableManualDiscovery', 'bcc_supports_nft_collections'],
            'manual disable, manual column gone'   => ['disableManualDiscovery', 'manual_collection_discovery_enabled'],
        ];
    }

    #[DataProvider('absentColumns')]
    public function testAnAbsentColumnRefusesRatherThanAssuming(string $method, string $column): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, true, true);
        ChainRepository::dropColumn(self::CHAIN_ID, $column);

        $result = NftCapabilityEditor::{$method}(self::CHAIN_ID);

        $this->assertSame(NftCapabilityEditor::RESULT_COLUMN_ABSENT, $result);
        $this->assertSame([], ChainRepository::$capabilityWrites);
        $this->assertSame([], $this->audits());
        $this->assertNoWorkRan();
    }

    /** An unknown chain is refused before any lookup-dependent work. */
    public function testAnUnknownChainIsRefused(): void
    {
        foreach ([
            'enableProductSupport',
            'disableProductSupport',
            'enableManualDiscovery',
            'disableManualDiscovery',
        ] as $method) {
            $this->assertSame(
                NftCapabilityEditor::RESULT_UNKNOWN_CHAIN,
                NftCapabilityEditor::{$method}(999),
                $method . ' on a chain that does not exist'
            );
        }

        $this->assertSame([], ChainRepository::$capabilityWrites);
        $this->assertSame([], $this->audits());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE COSMWASM OPT-IN IS A DIFFERENT SWITCH
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ NEITHER NEW CONTROL IS AN ALIAS FOR `cosmwasm_nft_discovery_enabled`.
     *
     * That column is the CosmWasm ENGINE's own per-chain opt-in, it has had
     * a writer since the engine existed, and its routes, storage and audit
     * vocabulary are unchanged by this PR. Aliasing any of the three would
     * mean one operator decision silently made another.
     */
    public function testTheCosmwasmOptInIsNeverTouchedByACapabilityEdit(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'osmosis', false, false, false);

        NftCapabilityEditor::enableProductSupport(self::CHAIN_ID);
        NftCapabilityEditor::enableManualDiscovery(self::CHAIN_ID);
        NftCapabilityEditor::disableManualDiscovery(self::CHAIN_ID);
        NftCapabilityEditor::disableProductSupport(self::CHAIN_ID);

        $this->assertSame([], ChainRepository::$discoveryWrites);
        $this->assertFalse(
            ChainRepository::storedFlag(self::CHAIN_ID, 'cosmwasm_nft_discovery_enabled'),
            'the engine opt-in is still exactly where it was'
        );

        foreach ($this->audits() as $action) {
            $this->assertStringNotContainsString(
                'cw_discovery',
                $action,
                'a capability edit must not borrow the CosmWasm engine vocabulary'
            );
        }
    }
}
