<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The pending-data-migration runner (includes/database/migration-runner.php).
 *
 * Proves the runner contract with controllable closure "migrations" and a
 * stubbed advisory lock — no WordPress, no MySQL. Each test asserts the exact
 * behaviour the runner promises: schema-independent execution, deterministic
 * order, independent completion, locking, resume, and safe coexistence of the
 * schema-triggered and runtime-triggered entry points.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MigrationRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/migration-runner-stubs.php';
        require_once __DIR__ . '/../../includes/database/migration-runner.php';
        $GLOBALS['__bcc_opts']         = [];
        $GLOBALS['__bcc_opt_autoload'] = [];
        $GLOBALS['__bcc_lock_grant']   = true;
        $GLOBALS['__bcc_lock_calls']   = [];
        $GLOBALS['__bcc_mig_calls']    = [];
    }

    /**
     * A migration whose callback records that it ran and returns $status.
     *
     * @return array{id: string, done_option: string, callback: callable}
     */
    private function migration(string $id, string $status): array
    {
        return [
            'id'          => $id,
            'done_option' => 'done_' . $id,
            'callback'    => static function () use ($id, $status): string {
                $GLOBALS['__bcc_mig_calls'][] = $id;
                return $status;
            },
        ];
    }

    /** @return list<string> ids of migrations whose callback actually ran */
    private function ran(): array
    {
        return $GLOBALS['__bcc_mig_calls'];
    }

    // ── schema independence ───────────────────────────────────────────

    public function testRunsWithNoSchemaVersionInvolvement(): void
    {
        // The runner never reads BCC_TRUST_SCHEMA_VERSION or any schema
        // option — a files-only deploy (unchanged schema hash) still runs
        // pending migrations. Proven by: the callback runs, and the only
        // options touched are the migration's own done-option + the
        // aggregate signature.
        bcc_trust_run_pending_migrations([$this->migration('a', BCC_TRUST_MIGRATION_COMPLETE)]);

        self::assertSame(['a'], $this->ran());
        self::assertArrayNotHasKey('bcc_trust_schema_version', $GLOBALS['__bcc_opts']);
        self::assertArrayHasKey('done_a', $GLOBALS['__bcc_opts']);
    }

    // ── order + independent completion ────────────────────────────────

    public function testExecutesInRegistryOrderAndCompletesIndependently(): void
    {
        bcc_trust_run_pending_migrations([
            $this->migration('first', BCC_TRUST_MIGRATION_COMPLETE),
            $this->migration('second', BCC_TRUST_MIGRATION_COMPLETE),
        ]);

        self::assertSame(['first', 'second'], $this->ran(), 'deterministic registry order');
        self::assertArrayHasKey('done_first', $GLOBALS['__bcc_opts']);
        self::assertArrayHasKey('done_second', $GLOBALS['__bcc_opts']);
    }

    public function testOneFailingMigrationDoesNotSuppressOrFalselyCompleteTheOther(): void
    {
        bcc_trust_run_pending_migrations([
            $this->migration('failer', BCC_TRUST_MIGRATION_INCOMPLETE),
            $this->migration('completer', BCC_TRUST_MIGRATION_COMPLETE),
        ]);

        // Both ran — the failure did not short-circuit the loop.
        self::assertSame(['failer', 'completer'], $this->ran());
        // Only the successful one is marked complete.
        self::assertArrayNotHasKey('done_failer', $GLOBALS['__bcc_opts']);
        self::assertArrayHasKey('done_completer', $GLOBALS['__bcc_opts']);
        // The aggregate fast-path is NOT armed while one remains incomplete.
        self::assertArrayNotHasKey('bcc_trust_migrations_all_done_signature', $GLOBALS['__bcc_opts']);
    }

    // ── done-flag no-op ───────────────────────────────────────────────

    public function testCompletedMigrationIsANoOp(): void
    {
        $GLOBALS['__bcc_opts']['done_x'] = time(); // already complete
        bcc_trust_run_pending_migrations([$this->migration('x', BCC_TRUST_MIGRATION_COMPLETE)]);

        self::assertSame([], $this->ran(), 'callback must not run when done-option is set');
        self::assertSame([], $GLOBALS['__bcc_lock_calls'], 'no lock acquired for a done migration');
    }

    public function testAggregateSignatureShortCircuitsWhenAllDone(): void
    {
        $migrations = [$this->migration('only', BCC_TRUST_MIGRATION_COMPLETE)];
        // First run completes it and arms the autoloaded aggregate.
        bcc_trust_run_pending_migrations($migrations);
        self::assertArrayHasKey('bcc_trust_migrations_all_done_signature', $GLOBALS['__bcc_opts']);
        self::assertTrue(($GLOBALS['__bcc_opt_autoload']['bcc_trust_migrations_all_done_signature'] ?? null) === true,
            'aggregate marker must be autoloaded for a free fast-path read');

        // Second run short-circuits on the signature: no callback, no lock.
        $GLOBALS['__bcc_mig_calls'] = [];
        $GLOBALS['__bcc_lock_calls'] = [];
        bcc_trust_run_pending_migrations($migrations);
        self::assertSame([], $this->ran());
        self::assertSame([], $GLOBALS['__bcc_lock_calls']);
    }

    // ── locking / concurrency ─────────────────────────────────────────

    public function testLockHeldByAPeerSkipsWithoutRunning(): void
    {
        $GLOBALS['__bcc_lock_grant'] = false; // a concurrent request holds it
        bcc_trust_run_pending_migrations([$this->migration('busy', BCC_TRUST_MIGRATION_COMPLETE)]);

        self::assertSame([], $this->ran(), 'must not double-process while locked elsewhere');
        self::assertArrayNotHasKey('done_busy', $GLOBALS['__bcc_opts']);
        // We attempted to acquire but never released a lock we did not hold.
        self::assertSame([['acquire', 'bcc_trust_mig_busy']], $GLOBALS['__bcc_lock_calls']);
    }

    public function testLockAcquiredThenReleasedAroundExecution(): void
    {
        bcc_trust_run_pending_migrations([$this->migration('m', BCC_TRUST_MIGRATION_COMPLETE)]);

        self::assertSame([
            ['acquire', 'bcc_trust_mig_m'],
            ['release', 'bcc_trust_mig_m'],
        ], $GLOBALS['__bcc_lock_calls']);
    }

    public function testLockIsReleasedEvenWhenMigrationThrows(): void
    {
        // Crash mid-migration: the finally-block must still release the lock,
        // so a caught failure never wedges it for the rest of the request.
        $throwing = [
            'id'          => 'boom',
            'done_option' => 'done_boom',
            'callback'    => static function (): string {
                throw new \RuntimeException('mid-migration crash');
            },
        ];

        try {
            bcc_trust_run_pending_migrations([$throwing]);
            self::fail('exception should propagate');
        } catch (\RuntimeException $e) {
            // expected
        }

        self::assertSame([
            ['acquire', 'bcc_trust_mig_boom'],
            ['release', 'bcc_trust_mig_boom'],
        ], $GLOBALS['__bcc_lock_calls'], 'lock released via finally on throw');
        self::assertArrayNotHasKey('done_boom', $GLOBALS['__bcc_opts'], 'not completed on crash');
    }

    // ── resume / idempotency / coexistence ────────────────────────────

    public function testPartialThenCompleteResumesAndFinishes(): void
    {
        $donePath = 'done_resumable';

        // Pass 1: reports INCOMPLETE (batch cap / transient) → not completed.
        bcc_trust_run_pending_migrations([[
            'id' => 'resumable', 'done_option' => $donePath,
            'callback' => static fn(): string => BCC_TRUST_MIGRATION_INCOMPLETE,
        ]]);
        self::assertArrayNotHasKey($donePath, $GLOBALS['__bcc_opts']);

        // Pass 2: same migration now drains → COMPLETE.
        bcc_trust_run_pending_migrations([[
            'id' => 'resumable', 'done_option' => $donePath,
            'callback' => static fn(): string => BCC_TRUST_MIGRATION_COMPLETE,
        ]]);
        self::assertArrayHasKey($donePath, $GLOBALS['__bcc_opts']);
    }

    public function testSchemaPathAndRuntimePathCoexistWithoutDoubleProcessing(): void
    {
        $migrations = [$this->migration('shared', BCC_TRUST_MIGRATION_COMPLETE)];

        // Simulate the schema-install path invoking the runner...
        bcc_trust_run_pending_migrations($migrations);
        // ...and then the runtime plugins_loaded path invoking it in the
        // same request. The second call must be a no-op.
        bcc_trust_run_pending_migrations($migrations);

        self::assertSame(['shared'], $this->ran(), 'processed exactly once across both entry points');
    }

    public function testToleratesWordPressHookStringArgument(): void
    {
        // Regression for the runtime bug the unit suite originally missed:
        // WordPress invokes plugins_loaded callbacks with the action's first
        // argument (an empty string), so the runner must accept a non-array
        // without a TypeError and fall back to the real registry. Here the
        // real registry's callbacks (string function names) are undefined in
        // the test process → not callable → each is a safe no-op.
        $this->expectNotToPerformAssertions();
        bcc_trust_run_pending_migrations('');   // must not throw
        bcc_trust_run_pending_migrations(null);
        bcc_trust_run_pending_migrations();
    }

    public function testMissingLockClassStaysRetryableRatherThanRunningUnguarded(): void
    {
        // Defensive: if the lock primitive is unavailable the runner must NOT
        // run the migration unguarded — it skips, leaving it retryable.
        // Simulated by forcing acquire to fail (same code path as "class
        // missing", which returns false).
        $GLOBALS['__bcc_lock_grant'] = false;
        bcc_trust_run_pending_migrations([$this->migration('guarded', BCC_TRUST_MIGRATION_COMPLETE)]);
        self::assertSame([], $this->ran());
        self::assertArrayNotHasKey('done_guarded', $GLOBALS['__bcc_opts']);
    }
}
