<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

/**
 * ONE lock order on every path that appends to the validator relationship
 * log: GET_LOCK bcc_vrl_append → any claim (or page) advisory lock → START
 * TRANSACTION → append → COMMIT → release the inner lock → release vrl.
 *
 * Source guard over app/ (comments removed). A method that appends to the
 * log must either:
 *  (a) take the vrl lock itself, before the append, and release it; or
 *  (b) append inside the work it hands to `self::underLocks(`, where that
 *      file's underLocks() takes the vrl lock FIRST and releases it; or
 *  (c) be a helper whose every call site is inside such wrapped work.
 * And in those files, any method that takes more than one advisory lock
 * takes vrl first.
 */
final class RelationshipLogLockOrderGuardTest extends TestCase
{
    private const APPEND  = '/(?:ValidatorRelationshipLogRepository|\bLog)::append\(/';
    private const VRL     = '/AdvisoryLock::acquire\(\s*(?:ValidatorRelationshipLogRepository|Log)::LOCK_NAME/';
    private const RELEASE = '/AdvisoryLock::release\(\s*(?:ValidatorRelationshipLogRepository|Log)::LOCK_NAME/';
    private const ADVANCE = '/RelationshipLogAnchor::advance\(/';
    private const WRAP    = 'self::underLocks(';
    /** Calls that take an advisory lock themselves (directly or inside a helper). */
    private const LOCK_TAKERS = '/AdvisoryLock::acquire\(|createExclusiveClaim\(|ClaimRepository::revoke\(|ClaimService::revokeClaim\(/';

    public function testEveryLogAppendHoldsTheVrlLockFirst(): void
    {
        $checked = 0;
        foreach (self::sources() as $relative => $code) {
            preg_match_all('/function\s+(\w+)\s*\(/', $code, $m);
            $bodies = [];
            foreach ($m[1] as $method) {
                $bodies[$method] = GateOrder::body($code, $method);
            }

            foreach ($bodies as $method => $body) {
                if (preg_match_all('/AdvisoryLock::acquire\(/', $body, $locks, PREG_OFFSET_CAPTURE) > 1) {
                    self::assertMatchesRegularExpression(self::VRL, $body, "{$relative} {$method}() takes several locks but not vrl");
                    preg_match(self::VRL, $body, $vrl, PREG_OFFSET_CAPTURE);
                    self::assertSame($vrl[0][1], $locks[0][0][1], "{$relative} {$method}(): the vrl lock is the FIRST lock taken");
                }

                if (preg_match(self::APPEND, $body, $append, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                ++$checked;
                $label = "{$relative} {$method}()";

                if (preg_match(self::VRL, $body, $vrl, PREG_OFFSET_CAPTURE) === 1) {
                    self::assertLessThan($append[0][1], $vrl[0][1], "{$label}: the vrl lock comes before the append");
                    self::assertMatchesRegularExpression(self::RELEASE, $body, "{$label} never releases the vrl lock");
                    continue;
                }

                $wrap = strpos($body, self::WRAP);
                if ($wrap !== false && $wrap < $append[0][1]) {
                    self::assertArrayHasKey('underLocks', $bodies, "{$label} hands its append to underLocks(), which this file must define");
                    self::assertMatchesRegularExpression(self::VRL, $bodies['underLocks'], "{$relative} underLocks() takes the vrl lock");
                    self::assertMatchesRegularExpression(self::RELEASE, $bodies['underLocks'], "{$relative} underLocks() releases the vrl lock");
                    continue;
                }

                // (c) a helper: every call site must be inside wrapped work.
                $sites = 0;
                foreach ($bodies as $caller => $callerBody) {
                    $pos = strpos($callerBody, 'self::' . $method . '(');
                    if ($pos === false || $caller === $method) {
                        continue;
                    }
                    ++$sites;
                    $callerWrap = strpos($callerBody, self::WRAP);
                    self::assertTrue($callerWrap !== false && $callerWrap < $pos, "{$label} is called from {$caller}() outside underLocks() work");
                }
                self::assertGreaterThan(0, $sites, "{$label} appends without the vrl lock and is not a wrapped helper");
            }
        }
        self::assertGreaterThanOrEqual(3, $checked, 'the scan found the known append paths (it is not scanning nothing)');
    }

    /** @return array<string, string> relative path => code without comments, for app/ files that append to the log */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_ends_with($relative, 'ValidatorRelationshipLogRepository.php')) {
                continue; // the append itself, which checks the lock is held
            }
            $code = GateOrder::code($relative);
            if (preg_match(self::APPEND, $code) === 1) {
                $out[$relative] = $code; // relationship code: the files that append to the log
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The anchor moves only after a COMMIT and while the vrl lock is held:
     * every method that advances it takes the vrl lock, advances after the
     * transaction ran, and releases the lock only after advancing.
     */
    public function testTheAnchorAdvancesAfterTheCommitAndBeforeTheVrlRelease(): void
    {
        $root    = dirname(__DIR__, 2);
        $checked = 0;
        $it      = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $code     = GateOrder::code($relative);
            if (preg_match(self::ADVANCE, $code) !== 1) {
                continue;
            }
            preg_match_all('/function\s+(\w+)\s*\(/', $code, $m);
            foreach ($m[1] as $method) {
                $body = GateOrder::body($code, $method);
                if (preg_match(self::ADVANCE, $body, $advance, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                ++$checked;
                $label = "{$relative} {$method}()";
                self::assertSame(1, preg_match(self::VRL, $body, $vrl, PREG_OFFSET_CAPTURE), "{$label} advances the anchor without taking the vrl lock");
                self::assertSame(1, preg_match('/TransactionManager::run\(/', $body, $run, PREG_OFFSET_CAPTURE), "{$label} advances outside a committed transaction");
                self::assertSame(1, preg_match(self::RELEASE, $body, $release, PREG_OFFSET_CAPTURE), "{$label} never releases the vrl lock");
                self::assertLessThan($advance[0][1], $vrl[0][1], "{$label}: the vrl lock comes before the advance");
                self::assertLessThan($advance[0][1], $run[0][1], "{$label}: the advance comes after the transaction");
                self::assertGreaterThan($advance[0][1], $release[0][1], "{$label}: the vrl lock is released only after the advance");
            }
        }
        self::assertGreaterThanOrEqual(1, $checked, 'the scan found the known advance paths (it is not scanning nothing)');
    }

    /**
     * In a method that takes the vrl lock, NOTHING that takes a lock runs
     * before it: not a direct AdvisoryLock::acquire(), and not a helper that
     * takes one inside (createExclusiveClaim(), ClaimRepository::revoke(),
     * ClaimService::revokeClaim()). Any of those first = out of order.
     */
    public function testNoLockTakingHelperRunsBeforeTheVrlLock(): void
    {
        $root    = dirname(__DIR__, 2);
        $checked = 0;
        $it      = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $code     = GateOrder::code($relative);
            if (preg_match(self::VRL, $code) !== 1) {
                continue;
            }
            preg_match_all('/function\s+(\w+)\s*\(/', $code, $m);
            foreach ($m[1] as $method) {
                $body = GateOrder::body($code, $method);
                if (preg_match(self::VRL, $body, $vrl, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                ++$checked;
                preg_match(self::LOCK_TAKERS, $body, $first, PREG_OFFSET_CAPTURE);
                self::assertSame(
                    $vrl[0][1],
                    $first[0][1] ?? -1,
                    "{$relative} {$method}(): '" . trim($first[0][0] ?? '') . "' runs before the vrl lock"
                );
            }
        }
        self::assertGreaterThanOrEqual(2, $checked, 'the scan found the known vrl paths (it is not scanning nothing)');
    }
}
