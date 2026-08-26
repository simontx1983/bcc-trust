<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use BCC\Trust\Onchain\Services\CosmwasmEvidenceNarrator;
use BCC\Trust\Onchain\Support\CosmwasmScanEligibility;
use BCC\Trust\Onchain\Support\ExplorerLinkBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the PURE half of the CosmWasm scanner admin surface.
 *
 * SCOPE, AND WHY IT STOPS WHERE IT DOES. Everything under test here runs
 * without WordPress, without a database and without a network: the
 * health-summary derivation over already-loaded rows, the plain-language
 * narrator, the pause/resume state derivation, and the explorer link.
 * The rendering itself and `buildSummary()`'s four repository reads need
 * a WP runtime and are deliberately NOT faked into existence — a test
 * that stubs `$wpdb` to assert on echoed HTML pins the stub, not the
 * page.
 *
 * The invariants that actually matter and are covered:
 *   - progress is NEVER expressed as a percentage (only one of the nine
 *     cosmos chains honours `pagination.count_total`, so there is no
 *     trustworthy denominator);
 *   - `deriveNextChain()` agrees with the SQL the worker actually runs;
 *   - resume never sends a drained chain back to a state that would
 *     re-walk its whole code listing;
 *   - the narrator never echoes a raw evidence token as prose.
 */
#[CoversClass(CosmwasmDiscoveryHealthSnapshot::class)]
#[CoversClass(CosmwasmScanEligibility::class)]
#[CoversClass(CosmwasmEvidenceNarrator::class)]
#[CoversClass(ExplorerLinkBuilder::class)]
final class CosmwasmScannerAdminTest extends TestCase
{
    private const NOW = 1785000000;

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * A checkpoint row shaped like the real one, with only the cw_*
     * fields a caller cares about overridden.
     *
     * @param array<string, mixed> $overrides
     */
    private function checkpoint(array $overrides = []): object
    {
        return (object) array_merge([
            'chain_id'                 => '8',
            'cw_discovery_state'       => ChainCheckpointRepository::CW_STATE_IDLE,
            'cw_code_cursor'           => null,
            'cw_max_code_id'           => '0',
            'cw_backfill_completed_at' => null,
            'cw_last_discovery_at'     => null,
            'cw_metadata_refreshed_at' => null,
            'cw_last_error'            => null,
        ], $overrides);
    }

    /**
     * A chain-panel row shaped like the real one.
     *
     * THE DEFAULT IS AN OPTED-IN, ELIGIBLE CHAIN. That is not decoration:
     * `deriveStatus()` reads `discovery_opted_in` to tell an idle scanner
     * (nobody opted anything in) from a working one, so a fixture that
     * omitted the key would make every status case in this file a
     * zero-opt-in case and the red/yellow/green arithmetic would never be
     * exercised at all. Tests that want the idle path say so by overriding
     * it.
     *
     * ── `state` IS THE INPUT; `paused`, `unsupported` AND `eligibility`
     *    ARE DERIVED FROM IT, EXACTLY AS deriveChainRow() DERIVES THEM ───
     * A fixture that let a caller set `paused => true` while leaving
     * `eligibility => 'eligible'` describes a row the production code
     * cannot produce, and a status test written against it proves nothing
     * about production. This has bitten twice — most recently when the
     * arithmetic started reading the eligibility verdict and half this
     * file's "paused" rows turned out to be claiming they were eligible.
     * So the shape is built the way the real builder builds it, from ONE
     * fact per question.
     *
     * An explicit override still wins, because two tests deliberately
     * describe rows nothing could produce (a missing key, a truthy
     * non-boolean) and that is exactly what they are for.
     *
     * The allowlist is not a parameter: it is not on the row in
     * production either. A test that wants the allowlist-excluded verdict
     * says `'eligibility' => ELIGIBILITY_ALLOWLIST_EXCLUDED`, which is
     * what the real builder would have written there.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function chainRow(array $overrides = []): array
    {
        $chainId = (int) ($overrides['chain_id'] ?? 8);
        $state   = (string) ($overrides['state'] ?? ChainCheckpointRepository::CW_STATE_BACKFILLED);
        /** @var mixed $optedIn */
        $optedIn = array_key_exists('discovery_opted_in', $overrides)
            ? $overrides['discovery_opted_in']
            : true;

        return array_merge([
            'chain_id'                    => $chainId,
            'slug'                        => 'cosmos',
            'name'                        => 'Cosmos Hub',
            'state'                       => $state,
            'state_label'                 => CosmwasmDiscoveryHealthSnapshot::stateLabel($state),
            'paused'                      => $state === ChainCheckpointRepository::CW_STATE_PAUSED,
            'unsupported'                 => $state === ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
            'discovery_opted_in'          => $optedIn,
            // The SHARED verdict, from the same function production calls.
            // `$optedIn === true` mirrors deriveChainRow()'s fail-closed
            // read: only a literal true is an opt-in.
            'eligibility'                 => CosmwasmScanEligibility::verdict($chainId, $state, $optedIn === true, null),
            'eligibility_reason'          => '',
            'backfill_complete'           => $state === ChainCheckpointRepository::CW_STATE_BACKFILLED,
            'progress_label'              => '',
            'max_code_id'                 => 713,
            'cursor_open'                 => false,
            'backfill_completed_at'       => '2026-08-01 00:00:00',
            'last_discovery_at'           => '2026-08-06 00:00:00',
            'last_discovery_age_seconds'  => 600,
            'metadata_refreshed_at'       => null,
            'last_error'                  => null,
            'families_total'              => 713,
            'families_pending'            => 0,
            // Default 0 = "no unresolved family errors", so every
            // pre-existing case keeps the verdict it had. Cases that mean
            // to exercise the new signal override it explicitly.
            'families_errored'            => 0,
            'families_by_classification'  => [],
            'contracts_total'             => 0,
            'contracts_inspected'         => 0,
            'contracts_denied'            => 0,
            'contracts_by_classification' => [],
            'candidates'                  => 0,
            'candidates_awaiting_emit'    => 0,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    // ── chain-row derivation ────────────────────────────────────────────

    public function test_derive_chain_row_folds_aggregate_slices_into_one_row(): void
    {
        $row = CosmwasmDiscoveryHealthSnapshot::deriveChainRow(
            8,
            'cosmos',
            'Cosmos Hub',
            $this->checkpoint([
                'cw_discovery_state'       => ChainCheckpointRepository::CW_STATE_BACKFILLED,
                'cw_max_code_id'           => '713',
                'cw_backfill_completed_at' => '2026-08-05 12:00:00',
                'cw_last_discovery_at'     => gmdate('Y-m-d H:i:s', self::NOW - 300),
            ]),
            [
                CosmwasmClassifier::CONFIRMED    => 12,
                CosmwasmClassifier::PROBABLE     => 4,
                CosmwasmClassifier::NOT_CW721    => 600,
                CosmwasmClassifier::INCONCLUSIVE => 97,
                CosmwasmClassifier::UNREACHABLE  => 0,
            ],
            3,
            0,
            [
                'total'                    => 2400,
                'inspected'                => 1800,
                'denied'                   => 5,
                'candidates'               => 61,
                'candidates_awaiting_emit' => 7,
                'by_classification'        => [CosmwasmClassifier::CONFIRMED => 61],
            ],
            self::NOW
        );

        $this->assertSame(713, $row['families_total'], 'families_total is the sum of the classification slice');
        $this->assertSame(3, $row['families_pending']);
        $this->assertSame(2400, $row['contracts_total']);
        $this->assertSame(1800, $row['contracts_inspected']);
        $this->assertSame(61, $row['candidates']);
        $this->assertSame(7, $row['candidates_awaiting_emit']);
        $this->assertSame(5, $row['contracts_denied']);
        $this->assertTrue($row['backfill_complete']);
        $this->assertFalse($row['paused']);
        $this->assertSame(300, $row['last_discovery_age_seconds']);
    }

    public function test_derive_chain_row_survives_a_chain_with_no_checkpoint_and_no_inventory(): void
    {
        $row = CosmwasmDiscoveryHealthSnapshot::deriveChainRow(
            9,
            'jackal',
            '',
            null,
            [],
            0,
            0,
            null,
            self::NOW
        );

        $this->assertSame(ChainCheckpointRepository::CW_STATE_IDLE, $row['state']);
        $this->assertSame('jackal', $row['name'], 'a blank display name falls back to the slug');
        $this->assertSame(0, $row['families_total']);
        $this->assertSame(0, $row['contracts_total']);
        $this->assertNull($row['last_discovery_age_seconds']);
        $this->assertSame('Never started.', $row['progress_label']);
    }

    public function test_unsupported_chain_is_flagged_and_says_why(): void
    {
        $row = CosmwasmDiscoveryHealthSnapshot::deriveChainRow(
            11,
            'cryptoorgchain',
            'Crypto.org',
            $this->checkpoint(['cw_discovery_state' => ChainCheckpointRepository::CW_STATE_UNSUPPORTED]),
            [],
            0,
            0,
            null,
            self::NOW
        );

        $this->assertTrue($row['unsupported']);
        $this->assertStringContainsString('No wasm module', $row['progress_label']);
    }

    // ── per-chain eligibility (PURE) ────────────────────────────────────
    //
    // The verdict is decided ONCE, in CosmwasmScanEligibility, and both
    // the worker's selector and this panel read it from there. These cases
    // are written against that class directly rather than through the
    // snapshot, because the snapshot no longer has an opinion to test.

    private const IDLE        = ChainCheckpointRepository::CW_STATE_IDLE;
    private const PAUSED      = ChainCheckpointRepository::CW_STATE_PAUSED;
    private const UNSUPPORTED = ChainCheckpointRepository::CW_STATE_UNSUPPORTED;

    /**
     * The control case. Every condition satisfied — and note that it takes
     * ALL of them, which is what the rest of these tests take away one at
     * a time.
     */
    public function test_a_chain_is_eligible_only_when_every_condition_holds(): void
    {
        self::assertSame(
            CosmwasmScanEligibility::ELIGIBLE,
            CosmwasmScanEligibility::verdict(8, self::IDLE, true, null)
        );
        self::assertSame(
            CosmwasmScanEligibility::ELIGIBLE,
            CosmwasmScanEligibility::verdict(8, self::IDLE, true, [8, 12]),
            'being named in the canary list is not an extra hurdle'
        );
        self::assertSame(
            CosmwasmScanEligibility::ELIGIBLE,
            CosmwasmScanEligibility::verdict(8, null, true, null),
            'a chain nobody has measured yet is allowed through — the first pass IS the measurement'
        );
    }

    public function test_an_opt_in_of_false_is_reported_as_not_opted_in(): void
    {
        self::assertSame(
            CosmwasmScanEligibility::NOT_OPTED_IN,
            CosmwasmScanEligibility::verdict(8, self::IDLE, false, null)
        );
    }

    /**
     * THE FAIL-CLOSED ONE. `null` means the projection has no such column
     * — a pre-migration install — and it must not be able to produce an
     * eligible chain by any route, allowlist or not.
     */
    public function test_an_unreadable_opt_in_is_never_eligible(): void
    {
        foreach ([null, [8], []] as $allowlist) {
            self::assertSame(
                CosmwasmScanEligibility::UNKNOWN,
                CosmwasmScanEligibility::verdict(8, self::IDLE, null, $allowlist)
            );
        }
    }

    public function test_a_measured_unsupported_chain_is_never_eligible_however_it_is_configured(): void
    {
        // Opted in, in the allowlist, and it still cannot be scanned:
        // operator intent does not create a wasm module.
        self::assertSame(
            CosmwasmScanEligibility::UNSUPPORTED,
            CosmwasmScanEligibility::verdict(8, self::UNSUPPORTED, true, [8])
        );
        self::assertSame(
            CosmwasmScanEligibility::UNSUPPORTED,
            CosmwasmScanEligibility::verdict(8, self::UNSUPPORTED, false, null)
        );
    }

    /**
     * THE VERDICT THIS AMENDMENT ADDED. Pause used to be invisible to the
     * eligibility column — it was a separate `if` in the status
     * arithmetic, and a separate `if` again in the worker, one layer below
     * the selector. All three now read this.
     */
    public function test_an_operator_paused_chain_is_not_eligible(): void
    {
        self::assertSame(
            CosmwasmScanEligibility::PAUSED,
            CosmwasmScanEligibility::verdict(8, self::PAUSED, true, null)
        );
        self::assertSame(
            CosmwasmScanEligibility::PAUSED,
            CosmwasmScanEligibility::verdict(8, self::PAUSED, true, [8]),
            'being inside the canary scope does not un-pause a chain'
        );
        self::assertFalse(
            CosmwasmScanEligibility::isScannable(CosmwasmScanEligibility::PAUSED)
        );
    }

    public function test_a_chain_outside_the_canary_allowlist_is_not_eligible(): void
    {
        self::assertSame(
            CosmwasmScanEligibility::ALLOWLIST_EXCLUDED,
            CosmwasmScanEligibility::verdict(8, self::IDLE, true, [12])
        );
    }

    /**
     * `[]` is the gate's "defined but names no usable chain id" answer,
     * which means scan NOTHING. It must not fall through to "no
     * restriction" — that is the fail-open shape this codebase already
     * shipped once.
     */
    public function test_a_defined_but_unusable_allowlist_excludes_every_chain(): void
    {
        self::assertSame(
            CosmwasmScanEligibility::ALLOWLIST_EXCLUDED,
            CosmwasmScanEligibility::verdict(8, self::IDLE, true, [])
        );
        self::assertStringContainsString(
            'names no usable chain id',
            CosmwasmDiscoveryHealthSnapshot::eligibilityReason(
                CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED,
                []
            )
        );
    }

    /**
     * EXACTLY ONE VERDICT MEANS "WILL BE SCANNED". Asserted as a whole set
     * rather than one value at a time, so a sixth verdict added later
     * cannot quietly default to scannable.
     */
    public function test_only_the_eligible_verdict_is_scannable(): void
    {
        $verdicts = [
            CosmwasmScanEligibility::ELIGIBLE           => true,
            CosmwasmScanEligibility::NOT_OPTED_IN       => false,
            CosmwasmScanEligibility::UNSUPPORTED        => false,
            CosmwasmScanEligibility::PAUSED             => false,
            CosmwasmScanEligibility::ALLOWLIST_EXCLUDED => false,
            CosmwasmScanEligibility::UNKNOWN            => false,
            ''                                          => false,
            'some_future_verdict'                       => false,
        ];

        foreach ($verdicts as $verdict => $expected) {
            self::assertSame(
                $expected,
                CosmwasmScanEligibility::isScannable((string) $verdict),
                "verdict '{$verdict}'"
            );
        }
    }

    /**
     * The panel's reader of that verdict, and the row shapes it must
     * refuse. `scannable()` derives nothing — it reads the stored verdict
     * — so anything that is not the eligible string is a NO.
     */
    public function test_the_panel_reads_scannability_from_the_stored_verdict_only(): void
    {
        self::assertTrue(CosmwasmDiscoveryHealthSnapshot::scannable($this->chainRow()));

        self::assertFalse(
            CosmwasmDiscoveryHealthSnapshot::scannable($this->chainRow(['state' => self::PAUSED]))
        );
        self::assertFalse(
            CosmwasmDiscoveryHealthSnapshot::scannable(
                $this->chainRow(['eligibility' => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED])
            ),
            'an allowlist-excluded chain is not scannable however healthy the rest of the row looks'
        );

        $noVerdict = $this->chainRow();
        unset($noVerdict['eligibility']);
        self::assertFalse(
            CosmwasmDiscoveryHealthSnapshot::scannable($noVerdict),
            'a row with no verdict at all must never read as scannable'
        );

        self::assertFalse(CosmwasmDiscoveryHealthSnapshot::scannable($this->chainRow(['eligibility' => null])));
        self::assertFalse(CosmwasmDiscoveryHealthSnapshot::scannable($this->chainRow(['eligibility' => 1])));
    }

    /**
     * A value from a newer build, or a typo. It degrades to "unknown" —
     * never to the one value that means "this chain will be scanned".
     */
    public function test_an_unrecognised_eligibility_value_degrades_to_unknown(): void
    {
        self::assertSame('Unknown', CosmwasmDiscoveryHealthSnapshot::eligibilityLabel('some_future_value'));
        self::assertSame('Unknown', CosmwasmDiscoveryHealthSnapshot::eligibilityLabel(''));
        self::assertStringContainsString(
            'could not be read',
            CosmwasmDiscoveryHealthSnapshot::eligibilityReason('some_future_value', null)
        );
    }

    /**
     * The row builder's defaults point the same way: a caller that does
     * not say whether the chain is opted in gets a row that is NOT
     * eligible and NOT opted in.
     */
    public function test_a_chain_row_built_without_an_opt_in_defaults_to_not_eligible(): void
    {
        $row = CosmwasmDiscoveryHealthSnapshot::deriveChainRow(
            8,
            'cosmos',
            'Cosmos Hub',
            $this->checkpoint(),
            [],
            0,
            0,
            null,
            self::NOW
        );

        self::assertSame(CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_UNKNOWN, $row['eligibility']);
        self::assertFalse($row['discovery_opted_in']);
        self::assertStringContainsString('NOT eligible', $row['eligibility_reason']);
    }

    public function test_a_chain_row_carries_the_opt_in_it_was_given(): void
    {
        $optedIn = CosmwasmDiscoveryHealthSnapshot::deriveChainRow(
            8,
            'cosmos',
            'Cosmos Hub',
            $this->checkpoint(),
            [],
            0,
            0,
            null,
            self::NOW,
            true,
            null
        );

        self::assertTrue($optedIn['discovery_opted_in']);
        self::assertSame(CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ELIGIBLE, $optedIn['eligibility']);
    }

    // ── the no-percentage invariant ─────────────────────────────────────

    /**
     * `pagination.count_total` is honoured by exactly one supported chain,
     * so a percentage would be a number invented from a denominator we do
     * not have. This pins that no state ever produces one.
     */
    public function test_progress_is_never_reported_as_a_percentage(): void
    {
        $states = [
            ChainCheckpointRepository::CW_STATE_IDLE,
            ChainCheckpointRepository::CW_STATE_BACKFILLING,
            ChainCheckpointRepository::CW_STATE_BACKFILLED,
            ChainCheckpointRepository::CW_STATE_PAUSED,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
        ];

        foreach ($states as $state) {
            foreach ([[0, false, 0], [713, true, 500], [5149, false, 5149]] as [$max, $cursor, $known]) {
                $label = CosmwasmDiscoveryHealthSnapshot::progressLabel($state, $max, $cursor, $known);
                $this->assertStringNotContainsString('%', $label, "state {$state} leaked a percentage");
            }
        }
    }

    public function test_an_open_cursor_is_reported_as_a_resume_point(): void
    {
        $label = CosmwasmDiscoveryHealthSnapshot::progressLabel(
            ChainCheckpointRepository::CW_STATE_BACKFILLING,
            5149,
            true,
            1200
        );

        $this->assertStringContainsString('resume point is open', $label);
        $this->assertStringContainsString('5,149', $label);
    }

    // ── rotation mirrors ────────────────────────────────────────────────

    /**
     * `deriveNextChain()` must agree with
     * `ChainCheckpointRepository::nextCwDiscoveryChain()`'s SQL:
     * never-worked first, then oldest stamp, skipping paused/unsupported.
     */
    public function test_next_chain_matches_the_worker_rotation_rule(): void
    {
        $chains = [
            $this->chainRow(['chain_id' => 1, 'slug' => 'juno',    'last_discovery_at' => '2026-08-06 10:00:00']),
            $this->chainRow(['chain_id' => 2, 'slug' => 'osmosis', 'last_discovery_at' => '2026-08-04 10:00:00']),
            $this->chainRow(['chain_id' => 3, 'slug' => 'jackal',  'last_discovery_at' => null]),
            $this->chainRow(['chain_id' => 4, 'slug' => 'kujira',  'last_discovery_at' => '2026-01-01 00:00:00', 'state' => self::PAUSED]),
        ];

        $next = CosmwasmDiscoveryHealthSnapshot::deriveNextChain($chains);

        $this->assertNotNull($next);
        $this->assertSame('jackal', $next['slug'], 'never-worked sorts ahead of every stamped chain');
    }

    public function test_next_chain_skips_paused_and_unsupported_entirely(): void
    {
        $chains = [
            $this->chainRow(['chain_id' => 4, 'slug' => 'kujira', 'last_discovery_at' => null, 'state' => self::PAUSED]),
            $this->chainRow(['chain_id' => 5, 'slug' => 'cryptoorgchain', 'last_discovery_at' => null, 'state' => self::UNSUPPORTED]),
        ];

        $this->assertNull(CosmwasmDiscoveryHealthSnapshot::deriveNextChain($chains));
    }

    public function test_working_chain_is_the_most_recently_touched_mid_backfill_chain(): void
    {
        $chains = [
            $this->chainRow([
                'chain_id'          => 1,
                'slug'              => 'juno',
                'state'             => ChainCheckpointRepository::CW_STATE_BACKFILLING,
                'last_discovery_at' => '2026-08-06 09:00:00',
            ]),
            $this->chainRow([
                'chain_id'          => 2,
                'slug'              => 'osmosis',
                'state'             => ChainCheckpointRepository::CW_STATE_BACKFILLING,
                'last_discovery_at' => '2026-08-06 11:00:00',
            ]),
            $this->chainRow(['chain_id' => 3, 'slug' => 'cosmos']), // backfilled — not "working"
        ];

        $working = CosmwasmDiscoveryHealthSnapshot::deriveWorkingChain($chains);

        $this->assertNotNull($working);
        $this->assertSame('osmosis', $working['slug']);
    }

    // ── status ──────────────────────────────────────────────────────────

    public function test_gate_off_reports_disabled_rather_than_red(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(false, [$this->chainRow()])
        );
    }

    /**
     * Both chains are opted IN here (the fixture default), so this is the
     * blocked case rather than the red one: the operator's whole selection
     * is unscannable, which has its own sentence now. `eligible === 0` is
     * still what detects it — see
     * test_an_empty_chain_list_is_not_idle() for the route that keeps RED.
     */
    public function test_every_opted_in_chain_paused_or_unsupported_is_blocked(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [$this->chainRow(['state' => self::PAUSED]), $this->chainRow(['chain_id' => 2, 'state' => self::UNSUPPORTED])]
            )
        );
    }

    public function test_a_recorded_chain_error_is_yellow_not_red(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [$this->chainRow(['last_error' => 'lcd 502']), $this->chainRow(['chain_id' => 2])]
            )
        );
    }

    public function test_healthy_chains_and_scheduled_hooks_are_green(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [$this->chainRow()]
            )
        );
    }

    // ── status: the zero-opt-in case ────────────────────────────────────
    //
    // A scanner nobody has pointed at a chain is not healthy, not degraded
    // and not broken. It used to read YELLOW, because deriveStatus()
    // counted every chain that was neither paused nor unsupported as
    // "eligible" — including the ones the worker skips for want of an
    // opt-in — and then found them all stale. Yellow about a system that
    // is doing exactly what it was configured to do is how an operator
    // learns to stop reading yellow.

    public function test_no_chain_opted_in_is_idle_and_nothing_else(): void
    {
        $status = CosmwasmDiscoveryHealthSnapshot::deriveStatus(
            true,
            [
                $this->chainRow(['discovery_opted_in' => false]),
                $this->chainRow(['chain_id' => 2, 'slug' => 'juno', 'discovery_opted_in' => false]),
            ]
            );

        $this->assertSame(CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE, $status);
        // Named one at a time so the failure says WHICH verdict leaked.
        $this->assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN, $status, 'idle is not healthy');
        $this->assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW, $status, 'idle is not degraded');
        $this->assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_RED, $status, 'idle is not failed');
    }

    /**
     * The zero-opt-in state stays idle even when every OTHER signal the
     * arithmetic keys on is screaming. None of them is about the operator's
     * intent, and none of them can make a scanner with nowhere to go into a
     * scanner that is behind: a chain that is not opted in cannot be
     * stale, and its recorded error answers for nothing.
     */
    public function test_zero_opt_in_outranks_the_stale_and_error_signals(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [$this->chainRow([
                    'discovery_opted_in'         => false,
                    'last_discovery_age_seconds' => null,
                    'last_error'                 => 'lcd 502',
                ])]
            )
        );
    }

    /**
     * IDLE OUTRANKS DISABLED. Both are intentional configuration, so the
     * only question is which one to say first, and with no chain opted in
     * the constant is the less useful of the two: defining it would change
     * nothing at all. The panel still names the constant in the idle copy.
     */
    public function test_zero_opt_in_is_idle_even_with_the_gate_switched_off(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                false,
                [$this->chainRow(['discovery_opted_in' => false])]
            ),
            'a gate-off site with nothing opted in is idle, not merely disabled'
        );
    }

    /**
     * The other side of that precedence, and the reason the gate check is
     * still there: opt a chain in and the constant becomes the operative
     * fact again.
     */
    public function test_one_opted_in_chain_brings_the_disabled_verdict_back(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                false,
                [$this->chainRow(['discovery_opted_in' => true])]
            )
        );
    }

    /**
     * An EMPTY chain list is not idle. "No operator opted a chain in" and
     * "the registry lists no Cosmos chain at all" are different facts, and
     * only the first is a choice — the second keeps the red it always had.
     *
     * It is also the second lock on the unavailable/idle precedence: the
     * unavailable summary carries no chain rows, so if an empty list could
     * derive into idle, a failed read would be one refactor away from
     * being reported as a calm, deliberate nothing.
     */
    public function test_an_empty_chain_list_is_not_idle(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_RED,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(true, [])
        );
    }

    /**
     * ONE opted-in chain is enough to hand the verdict back to the
     * pre-existing arithmetic, and the not-opted-in chains beside it change
     * nothing about the answer it gives. This is the regression fence
     * around "presentation and classification only": yellow and green must
     * still be reachable, and still on exactly the chain-derived conditions
     * they were reachable on before.
     *
     * The two cron-derived cases this provider used to carry are gone with
     * the schedule they keyed on — discovery has no scheduled passes, so
     * "unscheduled hook" and "overdue cron" are no longer states the
     * scanner can be in.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public static function preExistingVerdict(): array
    {
        return [
            'recorded chain error is still yellow' => [
                CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW,
                ['last_error' => 'lcd 502'],
            ],
            'a stale opted-in chain is still yellow' => [
                CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW,
                ['last_discovery_age_seconds' => CosmwasmDiscoveryHealthSnapshot::CHAIN_STALE_SECONDS + 1],
            ],
            'a healthy opted-in chain is still green' => [
                CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
                [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $chainOverrides
     */
    #[DataProvider('preExistingVerdict')]
    public function test_one_opted_in_chain_keeps_the_pre_existing_arithmetic(
        string $expected,
        array $chainOverrides
    ): void {
        $this->assertSame(
            $expected,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [
                    $this->chainRow($chainOverrides),
                    // Not opted in, and deliberately noisy: it must not
                    // drag the verdict either way.
                    $this->chainRow([
                        'chain_id'                   => 2,
                        'slug'                       => 'juno',
                        'discovery_opted_in'         => false,
                        'last_discovery_age_seconds' => null,
                    ]),
                ]
            )
        );
    }

    /**
     * The count fails closed the same way the panel does: only a literal
     * `true` is an opt-in. `'1'` is what a raw projection carries and a
     * missing key is what older code produces, and neither may switch the
     * scanner out of idle — the decision was already made, fail-closed, in
     * deriveChainRow(), and this only reads it back.
     */
    public function test_only_a_literal_true_counts_as_an_opt_in(): void
    {
        $this->assertSame(1, CosmwasmDiscoveryHealthSnapshot::optedInChainCount([
            $this->chainRow(['chain_id' => 1, 'discovery_opted_in' => true]),
            $this->chainRow(['chain_id' => 2, 'discovery_opted_in' => '1']),
            $this->chainRow(['chain_id' => 3, 'discovery_opted_in' => 1]),
            $this->chainRow(['chain_id' => 4, 'discovery_opted_in' => 'yes']),
        ]));

        $rowWithoutTheKey = $this->chainRow();
        unset($rowWithoutTheKey['discovery_opted_in']);
        $this->assertSame(0, CosmwasmDiscoveryHealthSnapshot::optedInChainCount([$rowWithoutTheKey]));
        $this->assertSame(0, CosmwasmDiscoveryHealthSnapshot::optedInChainCount([]));
    }

    // ── status: opted in, and none of it can be scanned ─────────────────
    //
    // The halves of the zero-opt-in defect that survived #186, in the
    // order they were found.
    //
    // FIRST: the counting loop still walked EVERY chain in the registry —
    // it only skipped the paused and the unsupported ones — so a chain
    // nobody had opted in counted as an eligible, healthy chain. A site
    // whose one opt-in was an unsupported chain therefore read GREEN off a
    // chain the operator had deliberately left switched off, which is the
    // most expensive possible way to be told nothing is wrong.
    //
    // SECOND: with the opt-in fixed, the loop still keyed on `paused ||
    // unsupported` and never consulted BCC_COSMWASM_CHAIN_ALLOWLIST. An
    // opted-in, supported, unpaused chain OUTSIDE the canary scope
    // therefore counted as scannable and could read GREEN — while
    // `eligible_chain_count` on the same summary reported 0 and the worker
    // skipped it. There is now one verdict, shared with the worker, and
    // the arithmetic reads it instead of re-deriving one.

    /**
     * THE LIVE REPRO, reproduced on staging against the deployed code:
     * one opted-in chain with no wasm module, beside one supported chain
     * nobody opted in. It derived `green`.
     *
     * The assertion is written both ways round on purpose. `assertSame`
     * pins the new answer; the `assertNotSame` says out loud which answer
     * must never come back, so a future refactor that reintroduces the
     * whole-registry loop fails on the sentence that describes the bug.
     */
    public function test_an_unsupported_opt_in_beside_a_chain_nobody_opted_in_is_not_green(): void
    {
        $status = CosmwasmDiscoveryHealthSnapshot::deriveStatus(
            true,
            [
                $this->chainRow([
                    'chain_id'           => 1,
                    'slug'               => 'jackal',
                    'discovery_opted_in' => true,
                    'state'              => ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                ]),
                $this->chainRow([
                    'chain_id'           => 2,
                    'slug'               => 'juno',
                    'discovery_opted_in' => false,
                ]),
            ]
            );

        $this->assertNotSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
            $status,
            'a chain nobody opted in must never make an unscannable selection look healthy'
        );
        $this->assertSame(CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED, $status);
    }

    /** The single-chain case: the only opt-in there is cannot be scanned. */
    public function test_one_opted_in_unsupported_chain_is_blocked(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [$this->chainRow([
                    'state'       => ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                ])]
            )
        );
    }

    /**
     * EVERY opted-in chain unsupported, and no other verdict may leak.
     * Named one at a time so a failure says WHICH one did.
     */
    public function test_every_opted_in_chain_unsupported_is_blocked_and_nothing_else(): void
    {
        $status = CosmwasmDiscoveryHealthSnapshot::deriveStatus(
            true,
            [
                $this->chainRow([
                    'chain_id'    => 1,
                    'slug'        => 'jackal',
                    'state'       => ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                ]),
                $this->chainRow([
                    'chain_id'    => 2,
                    'slug'        => 'stargaze',
                    'state'       => ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                ]),
            ]
            );

        $this->assertSame(CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED, $status);
        $this->assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN, $status, 'blocked is not healthy');
        $this->assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW, $status, 'blocked is not merely degraded');
        $this->assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE, $status, 'a selection was made — this is not idle');
        $this->assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED, $status, 'blocked is not a statement about the constant');
    }

    /** The two unscannable reasons mixed: one paused, one unsupported. */
    public function test_a_paused_opt_in_beside_an_unsupported_one_is_blocked(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [
                    $this->chainRow([
                        'chain_id' => 1,
                        'slug'     => 'juno',
                        'state'    => ChainCheckpointRepository::CW_STATE_PAUSED,
                    ]),
                    $this->chainRow([
                        'chain_id'    => 2,
                        'slug'        => 'jackal',
                        'state'       => ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                    ]),
                ]
            )
        );
    }

    /**
     * ONE opted-in chain, paused, and NOTHING else wrong with it. The
     * arithmetic used to have its own `paused ||` test beside the
     * eligibility column, which happened to catch this — and happened to
     * catch nothing else. It now reads the shared verdict, which catches
     * all four exclusions with one line.
     */
    public function test_one_opted_in_paused_chain_is_blocked(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [$this->chainRow(['state' => self::PAUSED])]
            )
        );
    }

    /**
     * THE CASE THIS AMENDMENT EXISTS FOR. The chain is opted in,
     * supported, unpaused and freshly stamped — every signal the old
     * arithmetic looked at says "fine" — and it is outside
     * `BCC_COSMWASM_CHAIN_ALLOWLIST`, so the worker will never touch it.
     * It read GREEN.
     *
     * Written both ways round: assertSame pins the answer, assertNotSame
     * names the one that must never come back.
     */
    public function test_one_opted_in_chain_outside_the_allowlist_is_blocked_not_green(): void
    {
        $status = CosmwasmDiscoveryHealthSnapshot::deriveStatus(
            true,
            [$this->chainRow([
                'eligibility' => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED,
            ])]
            );

        $this->assertNotSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
            $status,
            'a chain the canary allowlist excludes must never make the scanner look healthy'
        );
        $this->assertSame(CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED, $status);
    }

    /** Every opt-in outside the canary scope, and none of it scannable. */
    public function test_every_opted_in_chain_outside_the_allowlist_is_blocked(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [
                    $this->chainRow([
                        'chain_id'    => 1,
                        'slug'        => 'juno',
                        'eligibility' => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED,
                    ]),
                    $this->chainRow([
                        'chain_id'    => 2,
                        'slug'        => 'jackal',
                        'eligibility' => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED,
                    ]),
                ]
            )
        );
    }

    /**
     * THE MIXED ALLOWLIST CASE: one chain inside the canary scope, one
     * outside it. The arithmetic must describe only the chain that will
     * actually be walked — so the excluded one is made as noisy as
     * possible (never run, and carrying an error) and the answer must
     * still be green.
     */
    public function test_an_allowlist_excluded_chain_cannot_degrade_the_scannable_one(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [
                    $this->chainRow(['chain_id' => 1, 'slug' => 'juno']),
                    $this->chainRow([
                        'chain_id'                   => 2,
                        'slug'                       => 'jackal',
                        'eligibility'                => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED,
                        'last_error'                 => 'lcd 502',
                        'last_discovery_age_seconds' => null,
                    ]),
                ]
            ),
            'a chain outside the canary scope is excluded from the arithmetic, not folded into it as a fault'
        );
    }

    /**
     * BLOCKED OUTRANKS DISABLED, for the reason idle already does: with no
     * scannable opted-in chain, defining `BCC_COSMWASM_DISCOVERY_ENABLED`
     * would change nothing, so sending an operator to wp-config.php would
     * waste the trip. The panel still names the constant in the copy.
     */
    public function test_blocked_outranks_the_switched_off_gate(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                false,
                [$this->chainRow([
                    'state'       => ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                ])]
            )
        );
    }

    /**
     * And it outranks the staleness signal, again for the idle reason:
     * there is no chain a pass could walk, so "not touched recently" is
     * not the fact an operator needs first.
     */
    public function test_blocked_outranks_the_staleness_signal(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [$this->chainRow([
                    'state'                      => ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                    'last_discovery_age_seconds' => null,
                ])]
            )
        );
    }

    /**
     * An EMPTY chain list is not blocked either. Same lock as
     * test_an_empty_chain_list_is_not_idle(): the unavailable summary
     * carries no chain rows, so no derived verdict may be reachable from
     * an empty list except the RED it always had.
     */
    public function test_an_empty_chain_list_is_never_blocked(): void
    {
        $status = CosmwasmDiscoveryHealthSnapshot::deriveStatus(true, []);

        $this->assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED, $status);
        $this->assertSame(CosmwasmDiscoveryHealthSnapshot::STATUS_RED, $status);
    }

    // ── status: MIXED — some opted-in chains can be scanned ─────────────

    /**
     * ONE scannable opt-in is enough to hand the verdict back to the
     * arithmetic, and the unscannable opt-in beside it changes nothing.
     * That is the whole distinction the panel has to make: blocked is
     * about the WHOLE selection, never about one row, and the per-chain
     * "No wasm module" pill is what names the excluded chain.
     */
    public function test_a_scannable_opt_in_beside_an_unsupported_one_is_green(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [
                    $this->chainRow(['chain_id' => 1, 'slug' => 'juno']),
                    $this->chainRow([
                        'chain_id'                   => 2,
                        'slug'                       => 'jackal',
                        'state'                      => ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                        'last_discovery_age_seconds' => null,
                        'last_error'                 => 'lcd 501',
                    ]),
                ]
            ),
            'an unsupported chain is excluded from the arithmetic, not folded into it as a fault'
        );
    }

    /**
     * The other half of the mixed case: the chain that CAN be scanned is
     * the one the verdict describes. Degrade it and the panel degrades,
     * with the unsupported chain still sitting quietly beside it.
     */
    public function test_a_mixed_selection_reports_the_scannable_chain_degraded(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [
                    $this->chainRow(['chain_id' => 1, 'slug' => 'juno', 'last_error' => 'lcd 502']),
                    $this->chainRow([
                        'chain_id'    => 2,
                        'slug'        => 'jackal',
                        'state'       => ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                    ]),
                ]
            )
        );
    }

    /**
     * The counting loop is opted-in-only in BOTH directions. A chain
     * nobody opted in cannot make the scanner look healthy — the bug this
     * fixes — and it cannot make it look degraded either: the worker never
     * touches it, so its recorded error and its missing last-run stamp are
     * not facts about anything the scanner is doing.
     *
     * This is the crispest catch for "count the whole registry again": put
     * the error back in scope and the answer turns yellow.
     */
    public function test_a_chain_nobody_opted_in_cannot_make_the_scanner_degraded(): void
    {
        $this->assertSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
            CosmwasmDiscoveryHealthSnapshot::deriveStatus(
                true,
                [
                    $this->chainRow(['chain_id' => 1, 'slug' => 'juno']),
                    $this->chainRow([
                        'chain_id'                   => 2,
                        'slug'                       => 'osmosis',
                        'discovery_opted_in'         => false,
                        'last_error'                 => 'lcd 502',
                        'last_discovery_age_seconds' => null,
                    ]),
                ]
            )
        );
    }

    // ── issues ──────────────────────────────────────────────────────────

    public function test_gate_off_produces_exactly_one_issue_naming_the_constant(): void
    {
        $issues = CosmwasmDiscoveryHealthSnapshot::deriveIssues(
            false,
            false,
            [$this->chainRow(['last_error' => 'ignored while off'])]
            );

        $this->assertCount(1, $issues, 'a switched-off scanner must not also complain about downstream symptoms');
        $this->assertStringContainsString('BCC_COSMWASM_DISCOVERY_ENABLED', $issues[0]);
    }

    public function test_paused_and_unsupported_chains_are_explained_not_alarmed(): void
    {
        $issues = CosmwasmDiscoveryHealthSnapshot::deriveIssues(
            true,
            true,
            [
                $this->chainRow(['slug' => 'kujira', 'state' => self::PAUSED]),
                $this->chainRow(['chain_id' => 2, 'slug' => 'cryptoorgchain', 'state' => self::UNSUPPORTED]),
            ]
            );

        $joined = implode(' ', $issues);
        $this->assertStringContainsString('kujira is PAUSED', $joined);
        $this->assertStringContainsString('cryptoorgchain has no CosmWasm module', $joined);
    }

    public function test_stale_chain_is_reported_with_a_duration(): void
    {
        $issues = CosmwasmDiscoveryHealthSnapshot::deriveIssues(
            true,
            true,
            [$this->chainRow(['slug' => 'juno', 'last_discovery_age_seconds' => 3 * 86400])]
            );

        $this->assertStringContainsString('juno has not been touched in 3d 0h', implode(' ', $issues));
    }

    // ── small pure helpers ──────────────────────────────────────────────

    public function test_format_duration_scales_from_seconds_to_days(): void
    {
        $this->assertSame('12s', CosmwasmDiscoveryHealthSnapshot::formatDuration(12));
        $this->assertSame('47m', CosmwasmDiscoveryHealthSnapshot::formatDuration(2820));
        $this->assertSame('2h 14m', CosmwasmDiscoveryHealthSnapshot::formatDuration(8040));
        $this->assertSame('3d 1h', CosmwasmDiscoveryHealthSnapshot::formatDuration(3 * 86400 + 3600));
    }

    /**
     * The stored stamps are UTC (`current_time('mysql', true)`) while the
     * MySQL session runs `time_zone = SYSTEM` on this deployment. Parsing
     * them in the server's local zone would skew every age by the offset,
     * so the ' UTC' suffix in `ageSeconds()` is load-bearing.
     */
    public function test_age_seconds_reads_stamps_as_utc(): void
    {
        $stamp = gmdate('Y-m-d H:i:s', self::NOW - 7200);

        $this->assertSame(7200, CosmwasmDiscoveryHealthSnapshot::ageSeconds($stamp, self::NOW));
        $this->assertNull(CosmwasmDiscoveryHealthSnapshot::ageSeconds(null, self::NOW));
        $this->assertNull(CosmwasmDiscoveryHealthSnapshot::ageSeconds('', self::NOW));
    }

    public function test_age_seconds_clamps_a_future_stamp_to_zero(): void
    {
        $this->assertSame(
            0,
            CosmwasmDiscoveryHealthSnapshot::ageSeconds(gmdate('Y-m-d H:i:s', self::NOW + 600), self::NOW)
        );
    }

    public function test_cw721_total_is_confirmed_plus_probable(): void
    {
        $this->assertSame(16, CosmwasmDiscoveryHealthSnapshot::cw721Total([
            CosmwasmClassifier::CONFIRMED => 12,
            CosmwasmClassifier::PROBABLE  => 4,
            CosmwasmClassifier::NOT_CW721 => 600,
        ]));
        $this->assertSame(0, CosmwasmDiscoveryHealthSnapshot::cw721Total([]));
    }

    // ── pause / resume state derivation ─────────────────────────────────

    /**
     * The one that would actually hurt: resuming a chain whose backfill
     * had finished must NOT hand it back as `idle`, because the worker's
     * Phase A runs for every state except `backfilled` and would re-walk
     * the entire code listing.
     */
    public function test_resume_returns_a_drained_chain_to_backfilled(): void
    {
        $this->assertSame(
            ChainCheckpointRepository::CW_STATE_BACKFILLED,
            ChainCheckpointRepository::cwResumeState($this->checkpoint([
                'cw_backfill_completed_at' => '2026-08-01 00:00:00',
                'cw_max_code_id'           => '713',
            ]))
        );
    }

    public function test_resume_returns_a_mid_walk_chain_to_backfilling(): void
    {
        $this->assertSame(
            ChainCheckpointRepository::CW_STATE_BACKFILLING,
            ChainCheckpointRepository::cwResumeState($this->checkpoint(['cw_code_cursor' => 'opaque-key']))
        );
    }

    /**
     * A completed backfill CLEARS the cursor, and an incremental pass
     * never sets one — so a non-zero watermark alone still means work has
     * started. Cursor-presence alone would send this chain to `idle`.
     */
    public function test_resume_treats_a_watermark_without_a_cursor_as_mid_walk(): void
    {
        $this->assertSame(
            ChainCheckpointRepository::CW_STATE_BACKFILLING,
            ChainCheckpointRepository::cwResumeState($this->checkpoint(['cw_max_code_id' => '412']))
        );
    }

    public function test_resume_returns_an_untouched_chain_to_idle(): void
    {
        $this->assertSame(
            ChainCheckpointRepository::CW_STATE_IDLE,
            ChainCheckpointRepository::cwResumeState($this->checkpoint())
        );
    }

    // ── narrator ────────────────────────────────────────────────────────

    public function test_narrator_explains_the_confirmed_case_in_plain_language(): void
    {
        $sentence = CosmwasmEvidenceNarrator::describe(
            CosmwasmClassifier::CONFIRMED,
            'num_tokens_and_info',
            'num_tokens,contract_info',
            ''
        );

        $this->assertStringContainsString('counted its tokens', $sentence);
        $this->assertStringContainsString('token count', $sentence);
        $this->assertStringContainsString('collection name (classic)', $sentence);
        $this->assertStringNotContainsString('num_tokens', $sentence, 'raw probe tokens must not leak into prose');
    }

    public function test_narrator_names_the_minter_risk_on_an_info_only_verdict(): void
    {
        $sentence = CosmwasmEvidenceNarrator::describe(
            CosmwasmClassifier::PROBABLE,
            'info_only',
            'contract_info',
            'num_tokens:query_unsupported'
        );

        $this->assertStringContainsString('launchpad minter', $sentence);
        $this->assertStringContainsString('the contract does not implement it', $sentence);
    }

    public function test_narrator_distinguishes_a_node_failure_from_a_refusal(): void
    {
        $nodeSide = CosmwasmEvidenceNarrator::describe(
            CosmwasmClassifier::UNREACHABLE,
            'node_unreachable',
            '',
            'num_tokens:node_error'
        );
        $refusal = CosmwasmEvidenceNarrator::describe(
            CosmwasmClassifier::NOT_CW721,
            'no_cw721_queries',
            '',
            'num_tokens:query_unsupported,contract_info:query_unsupported'
        );

        $this->assertStringContainsString('chain node failed to answer', $nodeSide);
        $this->assertStringContainsString('the chain node errored', $nodeSide);
        $this->assertStringContainsString('definite answer from the contract itself', $refusal);
    }

    public function test_narrator_reads_the_checksum_twin_reason(): void
    {
        $sentence = CosmwasmEvidenceNarrator::describe(
            CosmwasmClassifier::CONFIRMED,
            'checksum_twin:434',
            '',
            ''
        );

        $this->assertStringContainsString('byte-identical to code 434', $sentence);
        $this->assertStringContainsString('without spending any requests', $sentence);
    }

    public function test_narrator_reads_the_sampled_family_reason(): void
    {
        $one   = CosmwasmEvidenceNarrator::reasonSentence(CosmwasmClassifier::NOT_CW721, 'sampled:1');
        $three = CosmwasmEvidenceNarrator::reasonSentence(CosmwasmClassifier::NOT_CW721, 'sampled:3');

        $this->assertStringContainsString('probing 1 contract ', $one . ' ');
        $this->assertStringContainsString('probing 3 contracts', $three);
        $this->assertStringContainsString('rather than every contract under it', $three);
    }

    public function test_narrator_explains_a_family_with_nothing_deployed(): void
    {
        $this->assertStringContainsString(
            'Nothing has been built from this code yet',
            CosmwasmEvidenceNarrator::reasonSentence(CosmwasmClassifier::INCONCLUSIVE, 'no_contracts')
        );
    }

    /**
     * A newer classifier version may write a reason this build has never
     * heard of. It must degrade to the verdict, not print the token — an
     * unexplained machine string is exactly the evidence dump the narrator
     * exists to replace.
     */
    public function test_narrator_degrades_an_unknown_reason_instead_of_echoing_it(): void
    {
        $sentence = CosmwasmEvidenceNarrator::reasonSentence(
            CosmwasmClassifier::PROBABLE,
            'some_future_reason_token'
        );

        $this->assertStringNotContainsString('some_future_reason_token', $sentence);
        $this->assertStringContainsString('CW-721 checks', $sentence);
    }

    public function test_narrator_returns_no_probe_clause_when_no_probes_were_recorded(): void
    {
        $this->assertSame('', CosmwasmEvidenceNarrator::probeSentence('', ''));
    }

    public function test_confidence_is_a_word_never_a_number(): void
    {
        foreach (CosmwasmClassifier::allClassifications() as $classification) {
            $confidence = CosmwasmEvidenceNarrator::confidence($classification);
            $this->assertContains($confidence, ['high', 'medium', 'low', 'none']);
        }
    }

    public function test_every_classification_has_an_operator_label_and_next_step(): void
    {
        foreach (CosmwasmClassifier::allClassifications() as $classification) {
            $this->assertNotSame('Unknown', CosmwasmEvidenceNarrator::classificationLabel($classification));
            $this->assertNotSame('', CosmwasmEvidenceNarrator::nextStep($classification));
        }
    }

    public function test_settled_negative_next_step_says_it_is_never_rechecked(): void
    {
        $this->assertStringContainsString(
            'never re-checked automatically',
            CosmwasmEvidenceNarrator::nextStep(CosmwasmClassifier::NOT_CW721)
        );
    }

    // ── explorer link ───────────────────────────────────────────────────

    public function test_explorer_link_appends_the_shared_address_path(): void
    {
        $this->assertSame(
            'https://www.mintscan.io/cosmos/address/cosmos1abc',
            ExplorerLinkBuilder::addressUrl('https://www.mintscan.io/cosmos/', 'cosmos1abc')
        );
    }

    public function test_explorer_link_is_null_without_a_base_or_an_address(): void
    {
        $this->assertNull(ExplorerLinkBuilder::addressUrl(null, 'cosmos1abc'));
        $this->assertNull(ExplorerLinkBuilder::addressUrl('  ', 'cosmos1abc'));
        $this->assertNull(ExplorerLinkBuilder::addressUrl('https://etherscan.io', ''));
    }
}
