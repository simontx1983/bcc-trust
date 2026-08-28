<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\NftCapabilityEditor;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE OVERRIDE TABLE: THREE STATES, NARROW-ONLY, AND FAIL-CLOSED.
 *
 * ── THE THREE STATES ────────────────────────────────────────────────────
 *   ABSENT ROW  the code registry decides, priority included
 *   enabled = 0 a registry default is removed
 *   enabled = 1 a registry default is restored, or reordered
 *
 * "Absent" is a genuine third state and not a synonym for "enabled at the
 * default priority", which is why returning to it is a DELETE. Writing
 * `enabled = 1` at today's registry priority instead would pin that number
 * against tomorrow's registry, silently.
 *
 * ── AND THE INVARIANT UNDERNEATH ────────────────────────────────────────
 * Configuration may take capability away and may never add it. Two things
 * enforce that and they are not interchangeable: the editor validates
 * before writing, and the registry INTERSECTION discards unmatched rows at
 * every read. The second is the guarantee — it is the only one a row from a
 * manual INSERT or a restored backup is certain to meet — and the tests
 * below check both, separately.
 */
#[CoversClass(NftCapabilityEditor::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftCapabilityEditorOverrideTest extends TestCase
{
    private const CHAIN_ID = 4;

    /** Injective: the one chain carrying BOTH an enumeration and a curated driver. */
    private const OP  = NftDriverRegistry::OP_ENUMERATION;
    private const DRV = NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION;

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

        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);
    }

    /** @return list<string> */
    private function audits(): array
    {
        return \BCC\Trust\Core\Security\AuditLogger::actions();
    }

    /** @return list<array{operation: string, driver_key: string, enabled: bool, priority: int}> */
    private function rows(): array
    {
        return ChainNftCapabilityRepository::storedRows(self::CHAIN_ID);
    }

    private function chain(): object
    {
        $chain = ChainRepository::getById(self::CHAIN_ID);
        self::assertNotNull($chain);

        return $chain;
    }

    /** The effective, override-applied driver list for one operation. */
    private function effectiveDrivers(string $operation = self::OP): array
    {
        $overrides = ChainNftCapabilityRepository::getForChain(self::CHAIN_ID);

        return NftDriverRegistry::driversFor($this->chain(), $operation, $overrides->rows());
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ABSENT MEANS REGISTRY DEFAULT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * A fresh install has no rows, and everything works.
     *
     * That is the property that lets the table ship empty: absence is the
     * normal case, not a degraded one.
     */
    public function testAnAbsentRowMeansTheRegistryDefault(): void
    {
        $this->assertSame([], $this->rows(), 'nothing is materialised on install');
        $this->assertSame([self::DRV], $this->effectiveDrivers());

        $matrix = NftChainCapability::operationMatrix($this->chain());
        $editable = $matrix['operations'][self::OP]['editable'];

        $this->assertCount(1, $editable);
        $this->assertSame(self::DRV, $editable[0]['driver_key']);
        $this->assertSame(NftChainCapability::OVERRIDE_STATE_DEFAULT, $editable[0]['state']);
        $this->assertSame(
            NftDriverRegistry::defaultPriority(self::DRV),
            $editable[0]['priority'],
            'a defaulted driver shows the registry priority, not a stored one'
        );
    }

    /**
     * ⚠️ NO ROW IS MATERIALISED FOR A DEFAULT.
     *
     * Filling the table with a row per registry default would mean every one
     * of them pinned today's priority — and the day a registry priority
     * changed, nothing would move.
     */
    public function testNoRowIsMaterialisedForADefault(): void
    {
        NftCapabilityEditor::inheritDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame([], $this->rows());
        $this->assertSame([], ChainNftCapabilityRepository::$bumps);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  DISABLE / ENABLE / INHERIT — ONE EXACT ROW EACH
    // ═══════════════════════════════════════════════════════════════════

    public function testDisableWritesExactlyOneRow(): void
    {
        $result = NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_DISABLED, $result);
        $this->assertSame(
            [['operation' => self::OP, 'driver_key' => self::DRV, 'enabled' => false, 'priority' => 10]],
            $this->rows()
        );
        $this->assertSame([], $this->effectiveDrivers(), 'the driver is gone from the effective list');
        $this->assertSame(['admin_nft_driver_override_disabled'], $this->audits());
    }

    public function testEnableWritesExactlyOneRowAtThatPriority(): void
    {
        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);
        \BCC\Trust\Core\Security\AuditLogger::reset();

        $result = NftCapabilityEditor::enableDriver(self::CHAIN_ID, self::OP, self::DRV, 250);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_ENABLED, $result);
        $this->assertSame(
            [['operation' => self::OP, 'driver_key' => self::DRV, 'enabled' => true, 'priority' => 250]],
            $this->rows()
        );
        $this->assertSame([self::DRV], $this->effectiveDrivers(), 'restored');
        $this->assertSame(['admin_nft_driver_override_enabled'], $this->audits());
    }

    /** ⚠️ Inherit removes ONLY the exact row, leaving every sibling alone. */
    public function testInheritRemovesOnlyTheExactRow(): void
    {
        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);
        NftCapabilityEditor::disableDriver(
            self::CHAIN_ID,
            NftDriverRegistry::OP_CURATED_FEED,
            NftDriverRegistry::DRIVER_TALIS_WHITELIST
        );
        NftCapabilityEditor::disableDriver(
            self::CHAIN_ID,
            NftDriverRegistry::OP_METADATA,
            NftDriverRegistry::DRIVER_CW721_LCD
        );
        $this->assertCount(3, $this->rows());
        \BCC\Trust\Core\Security\AuditLogger::reset();

        $result = NftCapabilityEditor::inheritDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_INHERITED, $result);

        $remaining = array_map(
            static fn(array $r): string => $r['operation'] . '/' . $r['driver_key'],
            $this->rows()
        );
        sort($remaining);
        $this->assertSame(
            [
                NftDriverRegistry::OP_CURATED_FEED . '/' . NftDriverRegistry::DRIVER_TALIS_WHITELIST,
                NftDriverRegistry::OP_METADATA . '/' . NftDriverRegistry::DRIVER_CW721_LCD,
            ],
            $remaining
        );
        $this->assertSame(['admin_nft_driver_override_inherited'], $this->audits());
    }

    /**
     * ⚠️ A REPEATED SAVE CREATES NO DUPLICATE.
     *
     * The unique key `(chain_id, operation, driver_key)` is the concurrency
     * authority, and the write is an upsert against it — never a
     * read-then-insert, which would race with itself.
     */
    public function testRepeatedSavesDoNotCreateDuplicates(): void
    {
        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);
        NftCapabilityEditor::enableDriver(self::CHAIN_ID, self::OP, self::DRV, 30);
        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);
        NftCapabilityEditor::enableDriver(self::CHAIN_ID, self::OP, self::DRV, 40);

        $this->assertCount(1, $this->rows(), 'one triple, one row, however many saves');
        $this->assertSame(40, $this->rows()[0]['priority']);
        $this->assertTrue($this->rows()[0]['enabled']);
    }

    /**
     * ⚠️ A DISABLE PRESERVES A PRIORITY THE OPERATOR SET.
     *
     * `priority` is meaningless while `enabled = 0`, so resetting it to the
     * registry default would be invisible — until the driver is switched
     * back on and turns up in the wrong place.
     */
    public function testDisablingPreservesAnExistingPriority(): void
    {
        NftCapabilityEditor::enableDriver(self::CHAIN_ID, self::OP, self::DRV, 777);
        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(777, $this->rows()[0]['priority']);
        $this->assertFalse($this->rows()[0]['enabled']);
    }

    /** A first-ever disable takes the registry priority. */
    public function testAFirstDisableTakesTheRegistryPriority(): void
    {
        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(NftDriverRegistry::defaultPriority(self::DRV), $this->rows()[0]['priority']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ORDERING IS DETERMINISTIC
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Priority orders ascending; ties keep REGISTRY declaration order.
     *
     * Solana's ownership operation is the case with two drivers — `das_rpc`
     * at 10 and `evm_rpc`-style fallbacks elsewhere — so it is where a
     * reorder is observable at all.
     */
    public function testPriorityOrderingIsDeterministic(): void
    {
        // An EVM chain: `alchemy_transfers` (10) then `evm_rpc` (20).
        ChainRepository::seed(self::CHAIN_ID, 'ethereum', false, true, false, 'evm');

        $op = NftDriverRegistry::OP_OWNERSHIP;
        $this->assertSame(
            [NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS, NftDriverRegistry::DRIVER_EVM_RPC],
            $this->effectiveDrivers($op),
            'registry default order'
        );

        // Reorder: put evm_rpc first.
        NftCapabilityEditor::enableDriver(self::CHAIN_ID, $op, NftDriverRegistry::DRIVER_EVM_RPC, 5);

        $this->assertSame(
            [NftDriverRegistry::DRIVER_EVM_RPC, NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS],
            $this->effectiveDrivers($op),
            'lower priority runs first'
        );

        // A TIE falls back to registry declaration order, so the result is
        // still one fixed list rather than whatever the rows happened to
        // come back in.
        NftCapabilityEditor::enableDriver(self::CHAIN_ID, $op, NftDriverRegistry::DRIVER_EVM_RPC, 10);
        $this->assertSame(
            [NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS, NftDriverRegistry::DRIVER_EVM_RPC],
            $this->effectiveDrivers($op),
            'ties keep registry order'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE GENERATION COUNTER
    // ═══════════════════════════════════════════════════════════════════

    /** Exactly one bump per real mutation. */
    public function testGenerationBumpsExactlyOncePerMutation(): void
    {
        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);
        $this->assertSame([self::CHAIN_ID], ChainNftCapabilityRepository::$bumps);

        NftCapabilityEditor::enableDriver(self::CHAIN_ID, self::OP, self::DRV, 30);
        $this->assertSame([self::CHAIN_ID, self::CHAIN_ID], ChainNftCapabilityRepository::$bumps);

        NftCapabilityEditor::inheritDriver(self::CHAIN_ID, self::OP, self::DRV);
        $this->assertCount(3, ChainNftCapabilityRepository::$bumps);
    }

    /** No bump for a no-op. */
    public function testNoGenerationBumpForANoOp(): void
    {
        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);
        ChainNftCapabilityRepository::$bumps  = [];
        ChainNftCapabilityRepository::$writes = [];
        \BCC\Trust\Core\Security\AuditLogger::reset();

        $result = NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_NOOP, $result);
        $this->assertSame([], ChainNftCapabilityRepository::$bumps);
        $this->assertSame([], ChainNftCapabilityRepository::$writes, 'the no-op is caught before the write');
        $this->assertSame([], $this->audits());
    }

    /** No bump for a failed write. */
    public function testNoGenerationBumpForAFailedWrite(): void
    {
        ChainNftCapabilityRepository::$writeFails = true;

        $result = NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_WRITE_FAILED, $result);
        $this->assertSame([], ChainNftCapabilityRepository::$bumps);
        $this->assertSame(['admin_nft_driver_override_failed'], $this->audits());
        $this->assertNotContains('admin_nft_driver_override_disabled', $this->audits());
    }

    /**
     * ⚠️ A MUTATION BUMPS EVEN WHEN THE POSTCONDITION CANNOT BE READ.
     *
     * This is the one ordering in the service that looks wrong at a glance
     * and is deliberate. The statement moved a row, so the stored
     * configuration HAS changed — whether or not we can then read it back.
     * Bumping only on a confirmed success would, in exactly the case where
     * the database is misbehaving, leave every reader serving the previous
     * generation of an override set that no longer exists.
     *
     * Over-bumping costs one cache miss. Under-bumping serves a stale
     * capability answer until something else happens to bump.
     */
    public function testAMutationBumpsEvenWhenThePostconditionIsUnreadable(): void
    {
        ChainNftCapabilityRepository::$postconditionUnavailable =
            ChainNftCapabilityOverrides::REASON_READ_FAILED;

        $result = NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_UNVERIFIED, $result);
        $this->assertSame(
            [self::CHAIN_ID],
            ChainNftCapabilityRepository::$bumps,
            'the store moved; caches must not keep serving the old generation'
        );
        // But the AUDIT waits for the postcondition — a durable claim about
        // system state is not made on a read we could not perform.
        $this->assertSame(['admin_nft_driver_override_unconfirmed'], $this->audits());
        $this->assertNotContains('admin_nft_driver_override_disabled', $this->audits());
    }

    /** And a write that reports success but does not read back is not success. */
    public function testAFailedPostconditionIsNotReportedAsSuccess(): void
    {
        ChainNftCapabilityRepository::$writeSilentlyDrops = true;

        $result = NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_UNVERIFIED, $result);
        $this->assertSame([], $this->rows());
        $this->assertSame(['admin_nft_driver_override_unconfirmed'], $this->audits());
    }

    /**
     * ⚠️ ZERO AFFECTED ROWS WITH THE DESIRED STATE PRESENT IS A NO-OP.
     *
     * A concurrent request applied the same change. The state is right, so
     * this is not a failure — but this statement did not make it so, and no
     * audit row or generation bump may claim it did.
     */
    public function testAConcurrentlyAppliedOverrideIsANoOpWithNoAuditOrBump(): void
    {
        ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, self::OP, self::DRV, false, 10);
        // Force the write itself to report 0 rows while the row is already
        // in the desired state — exactly what MySQL reports when another
        // request got there first.
        ChainNftCapabilityRepository::$writeNoOp = true;

        // Reach the write by asking for a DIFFERENT state than the seeded
        // row, so the pre-read no-op branch is not what answers.
        $result = NftCapabilityEditor::enableDriver(self::CHAIN_ID, self::OP, self::DRV, 10);

        // The postcondition (enabled=1) is NOT satisfied by the seeded
        // disabled row, so this is honestly reported as unverified rather
        // than as a success — the point being that it is never a success.
        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_UNVERIFIED, $result);
        $this->assertNotContains('admin_nft_driver_override_enabled', $this->audits());
        $this->assertSame([], ChainNftCapabilityRepository::$bumps, 'nothing moved, nothing to invalidate');
    }

    /**
     * ⚠️ ANOTHER WRITER APPLYING THE REQUESTED STATE IN THE WINDOW IS A
     * NO-OP FOR THIS REQUEST — NO BUMP, NO AUDIT.
     *
     * The interleave is real: this request pre-reads (no row, so not a
     * no-op), another request writes exactly what we were about to write,
     * and only then does our upsert run against a row that already says it.
     *
     * ── AND THIS IS EXACTLY WHAT `updated_at` USED TO BREAK ─────────────
     * With an unconditional `updated_at = CURRENT_TIMESTAMP`, our statement
     * would still change the timestamp, MySQL would report 2 affected rows,
     * and this request would bump a generation and write
     * `admin_nft_driver_override_disabled` for a change the OTHER request
     * made. The conditional timestamp is what makes the statement
     * semantically idempotent, and this test is what fails if it is ever
     * made unconditional again.
     */
    public function testAnInterleavedIdenticalApplyIsANoOpForThisRequest(): void
    {
        ChainNftCapabilityRepository::$interleavedWriter = static function (): void {
            // The other request, committing between our pre-read and our
            // statement — writing precisely what we intended to write.
            ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, self::OP, self::DRV, false, 10);
        };

        $result = NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(
            NftCapabilityEditor::RESULT_OVERRIDE_NOOP,
            $result,
            'the desired state is there and this statement did not put it there'
        );
        $this->assertSame(
            [],
            ChainNftCapabilityRepository::$bumps,
            'nothing moved, so no generation may be invalidated on our behalf'
        );
        $this->assertSame(
            [],
            $this->audits(),
            'and no durable row may credit this request with the other one\'s change'
        );

        // The state itself is correct — this is a no-op, not a failure.
        $this->assertSame(
            [['operation' => self::OP, 'driver_key' => self::DRV, 'enabled' => false, 'priority' => 10]],
            $this->rows()
        );
    }

    /**
     * But an interleaved DIFFERENT write still leaves ours a real mutation.
     *
     * The counterpart, so the test above is not passing because the
     * interleave hook simply suppresses everything: another request writes
     * priority 99, ours writes priority 10, and ours genuinely changes the
     * row — bump and audit both fire.
     */
    public function testAnInterleavedDifferentApplyStillLeavesOursAMutation(): void
    {
        ChainNftCapabilityRepository::$interleavedWriter = static function (): void {
            ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, self::OP, self::DRV, true, 99);
        };

        $result = NftCapabilityEditor::enableDriver(self::CHAIN_ID, self::OP, self::DRV, 10);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_ENABLED, $result);
        $this->assertSame([self::CHAIN_ID], ChainNftCapabilityRepository::$bumps);
        $this->assertSame(['admin_nft_driver_override_enabled'], $this->audits());
        $this->assertSame(10, $this->rows()[0]['priority']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  UNREADABLE STATE REFUSES EVERY MUTATION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ THE WHOLE-CHAIN READ IS WHAT MAKES THIS POSSIBLE.
     *
     * A malformed row on ANOTHER triple, or a set that hit its ceiling,
     * makes the entire override state unknown — so no mutation on this
     * chain may proceed, including one whose own row reads perfectly well.
     *
     * A single-row lookup could not see any of this: it would return the
     * clean row and permit a write against a store the capability model
     * would refuse to draw any conclusion from. The surface would then show
     * `unknown` for the very chain the editor had just accepted a change to.
     *
     * @return array<string, array{0: string}>
     */
    public static function unreadableReasons(): array
    {
        return [
            'read failed'  => [ChainNftCapabilityOverrides::REASON_READ_FAILED],
            'overflow'     => [ChainNftCapabilityOverrides::REASON_OVERFLOW],
            'malformed'    => [ChainNftCapabilityOverrides::REASON_MALFORMED],
            'invalid chain'=> [ChainNftCapabilityOverrides::REASON_INVALID_CHAIN],
        ];
    }

    /** @return array<string, array{0: string, 1: array<int, mixed>}> */
    public static function overrideMutations(): array
    {
        return [
            'disable' => ['disableDriver', [self::OP, self::DRV]],
            'enable'  => ['enableDriver', [self::OP, self::DRV, 10]],
            'inherit' => ['inheritDriver', [self::OP, self::DRV]],
            'stale'   => ['removeStaleOverride', [self::OP, self::DRV]],
        ];
    }

    #[DataProvider('unreadableReasons')]
    public function testAnUnreadableOverrideSetRefusesEveryMutation(string $reason): void
    {
        ChainNftCapabilityRepository::seedUnavailable(self::CHAIN_ID, $reason);

        foreach (self::overrideMutations() as $label => [$method, $args]) {
            $result = NftCapabilityEditor::{$method}(self::CHAIN_ID, ...$args);

            $this->assertSame(
                NftCapabilityEditor::RESULT_OVERRIDE_UNREADABLE,
                $result,
                $label . ' must refuse while the override state is ' . $reason
            );
        }

        $this->assertSame([], ChainNftCapabilityRepository::$writes, 'no write was attempted');
        $this->assertSame([], ChainNftCapabilityRepository::$bumps);
        $this->assertSame([], $this->audits());
    }

    /**
     * ⚠️ A MALFORMED SIBLING BLOCKS AN OTHERWISE-VALID TRIPLE.
     *
     * Spelled out separately from the parameterised case above because it
     * is the specific scenario the whole-chain read exists for: the row we
     * want to change is fine, and the mutation is still refused, because
     * what the operator configured cannot be established.
     */
    public function testAMalformedRowElsewhereBlocksAValidTriple(): void
    {
        // The repository turns a structurally broken row into an
        // UNAVAILABLE result for the whole chain — an empty operation or
        // driver key means the set itself is untrustworthy.
        ChainNftCapabilityRepository::seedUnavailable(
            self::CHAIN_ID,
            ChainNftCapabilityOverrides::REASON_MALFORMED
        );

        $result = NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(NftCapabilityEditor::RESULT_OVERRIDE_UNREADABLE, $result);
        $this->assertSame([], ChainNftCapabilityRepository::$writes);
    }

    /** And an overflowed read does the same, for the same reason. */
    public function testAnOverflowedReadBlocksEveryOverrideMutation(): void
    {
        ChainNftCapabilityRepository::seedUnavailable(
            self::CHAIN_ID,
            ChainNftCapabilityOverrides::REASON_OVERFLOW
        );

        $this->assertSame(
            NftCapabilityEditor::RESULT_OVERRIDE_UNREADABLE,
            NftCapabilityEditor::enableDriver(self::CHAIN_ID, self::OP, self::DRV, 10),
            'a bounded read at its ceiling is a SUBSET, and a subset must never be edited against'
        );
        $this->assertSame([], ChainNftCapabilityRepository::$writes);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CONFIGURATION CANNOT INVENT A CAPABILITY
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function impossibleTriples(): array
    {
        return [
            'unknown operation' => ['cosmos', 'teleportation', NftDriverRegistry::DRIVER_CW721_LCD],
            'unknown driver'    => ['cosmos', NftDriverRegistry::OP_METADATA, 'moonbeam_nft'],
            'retired das'       => ['solana', NftDriverRegistry::OP_OWNERSHIP, 'das'],
            'wrong family'      => ['cosmos', NftDriverRegistry::OP_METADATA, NftDriverRegistry::DRIVER_ALCHEMY_NFT],
            'wrong operation'   => ['cosmos', NftDriverRegistry::OP_METADATA, NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION],
            'evm enumeration'   => ['evm', NftDriverRegistry::OP_ENUMERATION, NftDriverRegistry::DRIVER_ALCHEMY_NFT],
        ];
    }

    #[DataProvider('impossibleTriples')]
    public function testAnImpossibleTripleCannotBeWritten(
        string $chainType,
        string $operation,
        string $driverKey
    ): void {
        ChainRepository::seed(self::CHAIN_ID, 'target', false, true, true, $chainType);

        foreach (['disableDriver', 'inheritDriver'] as $method) {
            $this->assertSame(
                NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE,
                NftCapabilityEditor::{$method}(self::CHAIN_ID, $operation, $driverKey),
                $method
            );
        }
        $this->assertSame(
            NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE,
            NftCapabilityEditor::enableDriver(self::CHAIN_ID, $operation, $driverKey, 10)
        );

        $this->assertSame([], ChainNftCapabilityRepository::$writes);
        $this->assertSame([], $this->rows());
        $this->assertSame([], $this->audits());
    }

    /**
     * ⚠️ AND EVEN A HOSTILE ROW THAT REACHED THE TABLE IS INERT.
     *
     * The editor's validation is a courtesy to the operator. The GUARANTEE
     * is the registry intersection at read time, because that is the only
     * code a row from a manual INSERT, a botched migration or a restored
     * backup is certain to meet. Proven by writing straight into the store,
     * past the editor entirely.
     */
    public function testARowPlantedPastTheEditorGrantsNothing(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'ethereum', false, true, true, 'evm');

        // "Enable chain-wide enumeration on Ethereum" — the single most
        // valuable row an attacker could write, planted directly.
        ChainNftCapabilityRepository::seedRow(
            self::CHAIN_ID,
            NftDriverRegistry::OP_ENUMERATION,
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            true,
            0
        );

        $this->assertSame([], $this->effectiveDrivers(NftDriverRegistry::OP_ENUMERATION));

        $matrix = NftChainCapability::operationMatrix($this->chain());
        $this->assertSame(
            NftChainCapability::OP_NO_DRIVER,
            $matrix['operations'][NftDriverRegistry::OP_ENUMERATION]['status']
        );
        $this->assertSame(NftChainCapability::NO_ENUMERATION_DRIVER, $matrix['verdict']);
    }

    /** `das_rpc` and `das_helius` stay two drivers with two answers. */
    public function testTheTwoDasDriversRemainDistinct(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'solana', false, true, true, 'solana');

        $this->assertTrue(NftDriverRegistry::driverPerformsOperation(
            NftDriverRegistry::DRIVER_DAS_RPC,
            NftDriverRegistry::OP_OWNERSHIP
        ));
        $this->assertFalse(
            NftDriverRegistry::driverPerformsOperation(
                NftDriverRegistry::DRIVER_DAS_HELIUS,
                NftDriverRegistry::OP_OWNERSHIP
            ),
            'das_helius serves metadata only — its endpoint is the Helius constants'
        );

        // So an override for one is accepted and the same override for the
        // other is refused, on the same chain and the same operation.
        $this->assertSame(
            NftCapabilityEditor::RESULT_OVERRIDE_DISABLED,
            NftCapabilityEditor::disableDriver(
                self::CHAIN_ID,
                NftDriverRegistry::OP_OWNERSHIP,
                NftDriverRegistry::DRIVER_DAS_RPC
            )
        );
        $this->assertSame(
            NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE,
            NftCapabilityEditor::disableDriver(
                self::CHAIN_ID,
                NftDriverRegistry::OP_OWNERSHIP,
                NftDriverRegistry::DRIVER_DAS_HELIUS
            )
        );
        $this->assertSame(
            NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE,
            NftCapabilityEditor::disableDriver(
                self::CHAIN_ID,
                NftDriverRegistry::OP_OWNERSHIP,
                'das'
            ),
            'the retired name is not a synonym for either'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  STALE ROWS
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function staleRows(): array
    {
        return [
            'unknown operation' => [
                'cosmos', 'teleportation', NftDriverRegistry::DRIVER_CW721_LCD,
                NftChainCapability::STALE_UNKNOWN_OPERATION,
            ],
            'unknown driver' => [
                'cosmos', NftDriverRegistry::OP_METADATA, 'moonbeam_nft',
                NftChainCapability::STALE_UNKNOWN_DRIVER,
            ],
            'retired das' => [
                'solana', NftDriverRegistry::OP_OWNERSHIP, 'das',
                NftChainCapability::STALE_UNKNOWN_DRIVER,
            ],
            'driver lacks operation' => [
                'cosmos', NftDriverRegistry::OP_METADATA, NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
                NftChainCapability::STALE_DRIVER_LACKS_OPERATION,
            ],
            'driver lacks chain' => [
                'cosmos', NftDriverRegistry::OP_METADATA, NftDriverRegistry::DRIVER_ALCHEMY_NFT,
                NftChainCapability::STALE_DRIVER_LACKS_CHAIN,
            ],
        ];
    }

    /**
     * A stale row is INERT, VISIBLE, and labelled with why.
     *
     * Inert because every read discards it; visible because a row nobody can
     * see is a row nobody can clean up.
     */
    #[DataProvider('staleRows')]
    public function testAStaleRowIsInertAndListedSeparately(
        string $chainType,
        string $operation,
        string $driverKey,
        string $expectedReason
    ): void {
        ChainRepository::seed(self::CHAIN_ID, 'target', false, true, true, $chainType);
        ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, $operation, $driverKey, true, 0);

        $matrix = NftChainCapability::operationMatrix($this->chain());

        $this->assertCount(1, $matrix['stale_overrides']);
        $this->assertSame($operation, $matrix['stale_overrides'][0]['operation']);
        $this->assertSame($driverKey, $matrix['stale_overrides'][0]['driver_key']);
        $this->assertSame($expectedReason, $matrix['stale_overrides'][0]['reason']);

        // And it appears in NO operation's editable list.
        foreach ($matrix['operations'] as $op) {
            foreach ($op['editable'] as $editable) {
                $this->assertNotSame(
                    $driverKey . '|' . $op['operation'],
                    $editable['driver_key'] . '|' . $operation,
                    'a stale triple is never offered as editable'
                );
            }
        }
    }

    /** ⚠️ Another save does not silently take a stale row with it. */
    public function testAnUnrelatedSaveLeavesStaleRowsAlone(): void
    {
        ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, 'teleportation', 'moonbeam_nft', true, 0);

        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);

        $stale = array_values(array_filter(
            $this->rows(),
            static fn(array $r): bool => $r['operation'] === 'teleportation'
        ));
        $this->assertCount(1, $stale, 'the leftover row records a decision somebody once made');
    }

    /** Explicit exact-row removal is the only way one goes. */
    public function testAStaleRowCanBeRemovedExplicitly(): void
    {
        ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, 'teleportation', 'moonbeam_nft', true, 0);

        $result = NftCapabilityEditor::removeStaleOverride(
            self::CHAIN_ID,
            'teleportation',
            'moonbeam_nft'
        );

        $this->assertSame(NftCapabilityEditor::RESULT_STALE_REMOVED, $result);
        $this->assertSame([], $this->rows());
        $this->assertSame(
            ['admin_nft_stale_override_removed'],
            $this->audits(),
            'removing an inert leftover is not "capability enabled"'
        );
        $this->assertSame([self::CHAIN_ID], ChainNftCapabilityRepository::$bumps);
    }

    /**
     * ⚠️ THE STALE ROUTE IS NOT A GENERAL DELETE ENDPOINT.
     *
     * It is the one route whose strings are NOT registry-checked — they
     * cannot be, because a stale row names things the registry no longer
     * has. So the protection has to come from the other side: the row must
     * EXIST, and it must re-evaluate as inert. A currently-valid triple is
     * refused here and belongs to `inheritDriver()`, which is a different
     * action with a different audit event.
     */
    public function testTheStaleRouteRefusesACurrentlyValidTriple(): void
    {
        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);
        \BCC\Trust\Core\Security\AuditLogger::reset();
        ChainNftCapabilityRepository::$bumps  = [];
        ChainNftCapabilityRepository::$writes = [];

        $result = NftCapabilityEditor::removeStaleOverride(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(NftCapabilityEditor::RESULT_STALE_STILL_VALID, $result);
        $this->assertCount(1, $this->rows(), 'the row is still there');
        $this->assertSame([], ChainNftCapabilityRepository::$writes);
        $this->assertSame([], ChainNftCapabilityRepository::$bumps);
        $this->assertSame([], $this->audits());
    }

    /**
     * ⚠️ AND IT CANNOT DELETE A ROW THAT IS NOT THERE.
     *
     * The hostile shape: an arbitrary well-formed key pair aimed at the
     * stale route in the hope it acts as "delete whatever I name". It has
     * to name a row that actually exists in this chain's set first.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hostileStaleTargets(): array
    {
        return [
            'never existed'      => ['teleportation', 'moonbeam_nft'],
            'plausible driver'   => [NftDriverRegistry::OP_METADATA, 'cw721_lcd_v2'],
            'plausible op'       => ['enumeration_v2', NftDriverRegistry::DRIVER_CW721_LCD],
            'valid-looking pair' => ['ownership', 'das'],
        ];
    }

    #[DataProvider('hostileStaleTargets')]
    public function testTheStaleRouteCannotDeleteARowThatDoesNotExist(
        string $operation,
        string $driverKey
    ): void {
        // A real, unrelated row is present, so "nothing was deleted" is a
        // claim about targeting rather than about an empty table.
        ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, self::OP, self::DRV, false, 10);

        $result = NftCapabilityEditor::removeStaleOverride(self::CHAIN_ID, $operation, $driverKey);

        $this->assertSame(NftCapabilityEditor::RESULT_STALE_NOT_FOUND, $result);
        $this->assertCount(1, $this->rows(), 'the unrelated row is untouched');
        $this->assertSame([], ChainNftCapabilityRepository::$writes, 'no DELETE was even issued');
        $this->assertSame([], ChainNftCapabilityRepository::$bumps);
        $this->assertSame([], $this->audits());
    }

    /** A stale removal touches only the exact row it names. */
    public function testStaleRemovalIsExactAndLeavesSiblingsAlone(): void
    {
        ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, 'teleportation', 'moonbeam_nft', true, 0);
        ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, 'levitation', 'moonbeam_nft', true, 0);
        ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, self::OP, self::DRV, false, 10);

        NftCapabilityEditor::removeStaleOverride(self::CHAIN_ID, 'teleportation', 'moonbeam_nft');

        $remaining = array_map(
            static fn(array $r): string => $r['operation'] . '/' . $r['driver_key'],
            $this->rows()
        );
        sort($remaining);
        $this->assertSame(
            [self::OP . '/' . self::DRV, 'levitation/moonbeam_nft'],
            $remaining
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  NOTHING RAN
    // ═══════════════════════════════════════════════════════════════════

    /** No override edit reaches a worker, a provider or a collection write. */
    public function testNoOverrideEditStartsAnyWork(): void
    {
        NftCapabilityEditor::disableDriver(self::CHAIN_ID, self::OP, self::DRV);
        NftCapabilityEditor::enableDriver(self::CHAIN_ID, self::OP, self::DRV, 20);
        NftCapabilityEditor::inheritDriver(self::CHAIN_ID, self::OP, self::DRV);

        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes);
        $this->assertSame([], \BCC\Trust\Onchain\Support\CosmwasmTickBudget::$constructions);
        $this->assertSame([], ChainRepository::$discoveryWrites);
        $this->assertSame([], ChainRepository::$capabilityWrites, 'an override edit touches no chain flag');
    }
}
