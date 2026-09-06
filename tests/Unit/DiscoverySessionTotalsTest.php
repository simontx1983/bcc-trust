<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\DiscoverySessionTotals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The one authority for a session's cumulative totals.
 *
 * ── WHAT THIS TYPE IS FOR ───────────────────────────────────────────────
 * The 2026-09-06 defect was an array reaching the wrong parameter: the
 * executor's `$counts` — ONE chunk's telemetry — was handed to
 * `auditTerminal()`, which wrote `41 / 9 / 0` for a session whose ledger row
 * correctly said `1136 / 371 / 2`.
 *
 * The fix is not "remember to pass the other array". It is that the audit
 * takes a type which CANNOT be built from a delta: the only constructor
 * needs a persisted row, and a `$counts` array has no row to offer.
 */
#[CoversClass(DiscoverySessionTotals::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoverySessionTotalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/');
        }
    }

    /** The live Cosmos Hub session's row, as the ledger stored it. */
    private static function liveRow(): object
    {
        return (object) [
            'id'                  => 4,
            'run_uuid'            => '5078a600-2b38-44d3-981a-524f6ff2a4ac',
            'chain_id'            => 8,
            'scan_mode'           => 'incremental',
            'status'              => 'succeeded',
            'stop_reason'         => 'session_chunk_ceiling',
            'chunks_used'         => 25,
            'partial'             => 1,
            'audit_degraded'      => 0,
            'requests_used'       => 1136,
            'pages_fetched'       => 44,
            'families_seen'       => 371,
            'contracts_seen'      => 1248,
            'collections_emitted' => 2,
            'collections_denied'  => 0,
        ];
    }

    // ── THE LIVE REGRESSION ─────────────────────────────────────────────

    /** ⚠ THE SESSION'S NUMBERS, not the final chunk's `41 / 9 / 0`. */
    public function testItReportsTheCumulativeSessionTotals(): void
    {
        $meta = DiscoverySessionTotals::fromPersistedRow(self::liveRow())?->toAuditMeta();

        self::assertIsArray($meta);
        self::assertSame(1136, $meta['requests_used']);
        self::assertSame(371, $meta['families_seen']);
        self::assertSame(2, $meta['collections_emitted']);
        self::assertSame(25, $meta['chunks_used']);
        self::assertSame(1248, $meta['contracts_seen']);
        self::assertSame(44, $meta['pages_fetched']);
    }

    /** The bounded identifiers and outcome fields travel with them. */
    public function testTheOutcomeFieldsAreCarried(): void
    {
        $meta = DiscoverySessionTotals::fromPersistedRow(self::liveRow())?->toAuditMeta();

        self::assertIsArray($meta);
        self::assertSame('5078a600-2b38-44d3-981a-524f6ff2a4ac', $meta['run_uuid']);
        self::assertSame(8, $meta['chain_id']);
        self::assertSame('incremental', $meta['scan_mode']);
        self::assertSame('succeeded', $meta['status']);
        self::assertSame('session_chunk_ceiling', $meta['stop_reason']);
        self::assertSame(1, $meta['partial']);
        self::assertSame(0, $meta['audit_degraded']);
    }

    /**
     * ⚠ `stop_reason` COMES OFF THE ROW, not from a caller's variable.
     *
     * The executor holds a `$stopReason` at the terminal write and passing it
     * would look harmless — but a second source for a stored value is exactly
     * how the counts drifted. The audit reports the ledger, whole.
     */
    public function testTheStopReasonIsReadFromTheRowNotSupplied(): void
    {
        $row = self::liveRow();
        $row->stop_reason = 'session_request_ceiling';

        $meta = DiscoverySessionTotals::fromPersistedRow($row)?->toAuditMeta();

        self::assertIsArray($meta);
        self::assertSame('session_request_ceiling', $meta['stop_reason']);
    }

    // ── IT CANNOT BE BUILT OUT OF A DELTA ───────────────────────────────

    /**
     * ⚠ NULL MEANS "NOT CONFIRMED", and the caller must degrade rather than
     * guess. `findById()` returns null both for a missing row and a failed
     * read; here they are the same fact — we do not know what was persisted.
     */
    public function testAnAbsentRowProducesNoTotals(): void
    {
        self::assertNull(DiscoverySessionTotals::fromPersistedRow(null));
    }

    /** A row without a uuid is not a run row we can vouch for. */
    public function testARowWithoutAUuidProducesNoTotals(): void
    {
        $row = self::liveRow();
        $row->run_uuid = '';

        self::assertNull(DiscoverySessionTotals::fromPersistedRow($row));

        $bare = (object) ['requests_used' => 1136, 'families_seen' => 371];
        self::assertNull(DiscoverySessionTotals::fromPersistedRow($bare), 'counts alone are not a session');
    }

    /**
     * ⚠ THE CONSTRUCTOR IS PRIVATE, so no caller can assemble totals out of
     * a chunk delta even deliberately. This is the structural half of the
     * fix; every other test here only checks the arithmetic.
     */
    public function testTheOnlyWayInIsAPersistedRow(): void
    {
        $ctor = (new \ReflectionClass(DiscoverySessionTotals::class))->getConstructor();

        self::assertNotNull($ctor);
        self::assertTrue($ctor->isPrivate(), 'the constructor must stay private');

        $factories = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(DiscoverySessionTotals::class))->getMethods(\ReflectionMethod::IS_STATIC)
        );

        self::assertSame(['fromPersistedRow'], $factories, 'exactly one way to build these totals');
    }

    // ── BOUNDED, ALWAYS ─────────────────────────────────────────────────

    /**
     * ⚠ NO FREE TEXT REACHES DURABLE STORAGE. Every value is an integer or a
     * bounded identifier — the PR 5b rule, still holding.
     */
    public function testEveryAuditValueIsBounded(): void
    {
        $row = self::liveRow();
        $row->stop_reason = str_repeat('x', 500);

        $meta = DiscoverySessionTotals::fromPersistedRow($row)?->toAuditMeta();
        self::assertIsArray($meta);

        foreach ($meta as $key => $value) {
            self::assertIsScalar($value, $key . ' must be scalar');

            if (is_string($value)) {
                self::assertLessThanOrEqual(
                    512,
                    strlen($value),
                    $key . ' must stay bounded'
                );
            }
        }

        // …and no key that could carry provider or credential material.
        foreach (['url', 'response', 'body', 'sql', 'exception', 'lease', 'token'] as $forbidden) {
            foreach (array_keys($meta) as $key) {
                self::assertStringNotContainsString($forbidden, (string) $key);
            }
        }
    }

    /** A missing or negative counter reads as zero, never as a negative total. */
    public function testMissingCountersReadAsZero(): void
    {
        $row = (object) [
            'run_uuid'      => 'abc',
            'requests_used' => -5,
        ];

        $meta = DiscoverySessionTotals::fromPersistedRow($row)?->toAuditMeta();

        self::assertIsArray($meta);
        self::assertSame(0, $meta['requests_used'], 'a negative total is not a total');
        self::assertSame(0, $meta['collections_emitted']);
        self::assertSame(0, $meta['chunks_used']);
        self::assertSame('', $meta['status']);
    }
}
