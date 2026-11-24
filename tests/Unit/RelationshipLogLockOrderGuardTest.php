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
 * Source guard over app/ (comments removed): every method that appends to
 * the log acquires the vrl lock, acquires it BEFORE any other advisory lock
 * and before the append, and releases it.
 */
final class RelationshipLogLockOrderGuardTest extends TestCase
{
    private const APPEND = '/(?:ValidatorRelationshipLogRepository|\bLog)::append\(/';
    private const VRL    = '/AdvisoryLock::acquire\(\s*(?:ValidatorRelationshipLogRepository|Log)::LOCK_NAME/';

    public function testEveryLogAppendHoldsTheVrlLockFirst(): void
    {
        $root    = dirname(__DIR__, 2);
        $checked = 0;
        $it      = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_ends_with($relative, 'ValidatorRelationshipLogRepository.php')) {
                continue; // the append itself, which checks the lock is held
            }
            $code = GateOrder::code($relative);
            if (preg_match(self::APPEND, $code) !== 1) {
                continue;
            }
            preg_match_all('/function\s+(\w+)\s*\(/', $code, $m);
            foreach ($m[1] as $method) {
                $body = GateOrder::body($code, $method);
                if (preg_match(self::APPEND, $body, $append, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                ++$checked;
                $label = "{$relative} {$method}()";
                self::assertMatchesRegularExpression(self::VRL, $body, "{$label} appends without taking the vrl lock");
                preg_match(self::VRL, $body, $vrl, PREG_OFFSET_CAPTURE);
                self::assertLessThan($append[0][1], $vrl[0][1], "{$label}: the vrl lock comes before the append");

                if (preg_match_all('/AdvisoryLock::acquire\(/', $body, $locks, PREG_OFFSET_CAPTURE) > 1) {
                    self::assertSame($vrl[0][1], $locks[0][0][1], "{$label}: the vrl lock is the FIRST lock taken");
                }
                self::assertMatchesRegularExpression('/AdvisoryLock::release\(\s*(?:ValidatorRelationshipLogRepository|Log)::LOCK_NAME/', $body, "{$label} never releases the vrl lock");
            }
        }
        self::assertGreaterThanOrEqual(1, $checked, 'the scan found the known append paths (it is not scanning nothing)');
    }
}
