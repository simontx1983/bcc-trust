<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\GuardsReadFailures;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * STRUCTURAL INVARIANT: every read in the three guarded repositories is
 * guarded.
 *
 * ── WHY IT IS SHAPED LIKE THIS ──────────────────────────────────────────
 * The obvious version of this test asserts a NUMBER — "25 reads, 25
 * guards". That number is wrong the moment somebody adds a legitimate
 * query, so the next person deletes the assertion or edits the constant
 * without thinking, and the guard stops guarding anything. It also cannot
 * fail for the case that actually matters: a NEW unguarded read, which
 * moves both sides of the equation at once if you count guards rather
 * than checking each read.
 *
 * So this walks every method of every guarded repository, finds the ones
 * that touch `$wpdb->get_*`, and requires a guard call INSIDE THAT SAME
 * METHOD. Add a read tomorrow and forget the guard, and this fails by
 * name. Add a read WITH a guard and nothing here has to be updated.
 *
 * ── THE VACUOUS-PASS TRAP ───────────────────────────────────────────────
 * A structural test that finds nothing to check passes loudly and proves
 * nothing — the exact failure mode of a guard script that prints PASS
 * having scanned zero files. So each repository must yield at least one
 * read-bearing method, and the sweep as a whole must find a plausible
 * number of them; a refactor that moves the reads somewhere this test
 * cannot see is itself a failure.
 */
#[CoversNothing]
final class RepositoryReadGuardCoverageTest extends TestCase
{
    /** Every repository that uses {@see GuardsReadFailures}. */
    private const GUARDED_REPOSITORIES = [
        ChainCheckpointRepository::class,
        CosmwasmCodeFamilyRepository::class,
        CosmwasmContractRepository::class,
    ];

    /** The `$wpdb` calls that return data and can silently return nothing. */
    private const READ_CALLS = ['get_results', 'get_row', 'get_col', 'get_var'];

    /**
     * The reads whose failure must PROPAGATE, because their answer is
     * rendered to an operator as fact.
     *
     * This list is a POLICY PIN, not a count: it exists so that quietly
     * downgrading one of these to the log-only guard — which would restore
     * the "healthy scanner, zero everything" panel — fails the suite and
     * has to be argued for. Adding a read does not require touching it.
     *
     * @var array<class-string, list<string>>
     */
    private const MUST_FAIL_CLOSED = [
        ChainCheckpointRepository::class => [
            // The panel's per-chain state. Empty renders as "every chain
            // idle, never scanned".
            'readAll',
        ],
        CosmwasmCodeFamilyRepository::class => [
            'countsByChainAndClassification',
            'pendingCountsByChain',
            'findManyForChains',
        ],
        CosmwasmContractRepository::class => [
            'inventoryByChain',
            'findManyForChains',
            // Confirms an operator hide/unhide actually reached the
            // scanner cache; "could not read" must not read as "nothing
            // to sync".
            'deniedFlag',
        ],
    ];

    /**
     * The reads that must stay FAIL-SAFE, because they run inside cron and
     * an exception there converts a logged degradation into a fatal.
     *
     * Also a policy pin — and the counterweight to the list above, so
     * "guard everything harder" cannot be applied blindly to the worker.
     *
     * @var array<class-string, list<string>>
     */
    private const MUST_FAIL_SAFE = [
        ChainCheckpointRepository::class => [
            'get',
            'nextCwDiscoveryChain',
        ],
        CosmwasmCodeFamilyRepository::class => [
            'findPendingClassification',
            'findEnumerable',
            'findDueForMetadataCheck',
        ],
        CosmwasmContractRepository::class => [
            'knownMap',
            'findPendingClassification',
            'findEmittable',
        ],
    ];

    public function test_every_read_in_a_guarded_repository_is_guarded(): void
    {
        $inspected = 0;

        foreach (self::GUARDED_REPOSITORIES as $repository) {
            $reads = $this->readBearingMethods($repository);

            self::assertNotSame(
                [],
                $reads,
                $repository . ' yielded no read-bearing methods — this test just stopped testing anything'
            );

            foreach ($reads as $method => $body) {
                $inspected++;
                self::assertTrue(
                    $this->hasGuardCall($body),
                    sprintf(
                        '%s::%s() reads via $wpdb but never calls a read guard. An empty result there is '
                            . 'indistinguishable from a failed query — add guardRead(), guardReadOrThrow() or readFailed().',
                        $repository,
                        $method
                    )
                );
            }
        }

        self::assertGreaterThan(
            15,
            $inspected,
            'the sweep found implausibly few reads — the repositories were probably restructured out from under it'
        );
    }

    public function test_the_repositories_actually_use_the_guard_trait(): void
    {
        foreach (self::GUARDED_REPOSITORIES as $repository) {
            self::assertContains(
                GuardsReadFailures::class,
                class_uses($repository) ?: [],
                $repository . ' dropped the read guard trait'
            );
        }
    }

    public function test_operator_facing_reads_fail_closed(): void
    {
        foreach (self::MUST_FAIL_CLOSED as $repository => $methods) {
            $reads = $this->readBearingMethods($repository);

            foreach ($methods as $method) {
                self::assertArrayHasKey(
                    $method,
                    $reads,
                    $repository . '::' . $method . '() no longer contains the read this policy is about'
                );
                self::assertStringContainsString(
                    'guardReadOrThrow(',
                    $reads[$method],
                    sprintf(
                        '%s::%s() feeds an operator surface and must THROW on a failed read. Absorbing it there '
                            . 'reports a healthy, empty, up-to-date system that nobody managed to look at.',
                        $repository,
                        $method
                    )
                );
            }
        }
    }

    public function test_worker_reads_stay_fail_safe(): void
    {
        foreach (self::MUST_FAIL_SAFE as $repository => $methods) {
            $reads = $this->readBearingMethods($repository);

            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $reads, $repository . '::' . $method . '() lost its read');
                self::assertStringNotContainsString(
                    'guardReadOrThrow(',
                    $reads[$method],
                    sprintf(
                        '%s::%s() runs inside cron. Throwing there turns a logged degradation into a fatal tick; '
                            . 'log it and let the worker come back next pass.',
                        $repository,
                        $method
                    )
                );
                self::assertTrue($this->hasGuardCall($reads[$method]), $repository . '::' . $method . '() is unguarded');
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * Every method of $repository whose body issues a `$wpdb` read, keyed
     * by method name with its source as the value.
     *
     * @param  class-string          $repository
     * @return array<string, string>
     */
    private function readBearingMethods(string $repository): array
    {
        $reflection = new ReflectionClass($repository);
        $file       = $reflection->getFileName();
        self::assertIsString($file, $repository . ' has no source file to inspect');

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, 'could not read ' . $file);

        $out = [];
        foreach ($reflection->getMethods() as $method) {
            // A trait method reports the COMPOSING class as its declaring
            // class while its line numbers point into the trait's file, so
            // slicing them out of this file yields nonsense. Compare files,
            // not class names. (The guard trait is not itself a repository
            // read, and its own reads are the guard.)
            if ($method->getFileName() !== $file) {
                continue;
            }

            $body = $this->methodSource($method, $lines);
            if ($this->hasReadCall($body)) {
                $out[$method->getName()] = $body;
            }
        }

        return $out;
    }

    /** @param list<string> $lines */
    private function methodSource(ReflectionMethod $method, array $lines): string
    {
        $start = $method->getStartLine();
        $end   = $method->getEndLine();
        if ($start <= 0 || $end < $start) {
            return '';
        }

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }

    private function hasReadCall(string $body): bool
    {
        foreach (self::READ_CALLS as $call) {
            if (str_contains($body, '$wpdb->' . $call . '(')) {
                return true;
            }
        }

        return false;
    }

    private function hasGuardCall(string $body): bool
    {
        return str_contains($body, 'guardRead(')
            || str_contains($body, 'guardReadOrThrow(')
            || str_contains($body, 'readFailed(');
    }
}
