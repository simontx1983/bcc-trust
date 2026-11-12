<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * F-272: no bcc-trust code may call an F-272 writer door except through
 * GroupJoinRequestService, and there only inside `$this->locked(...)`.
 *
 * Why it matters: the service's per-(user, group) lock is what makes the
 * gate-then-write sequence (authority, eligibility, cooldown, then the door)
 * one step from bcc-trust's side. A door called elsewhere would skip the
 * gates (peepso-write-guard), and one called outside the lock could
 * interleave with another BCC request for the same pair.
 *
 * The service's key must also differ from bcc-core's own pair lock
 * (PeepSoGroupWriter::memberLockKey, "bcc_group_member_…"): MySQL allows a
 * session to re-acquire its own lock, so sharing a key would silently turn
 * two independent guarantees into one.
 */
final class GroupJoinRequestLockedDoorsTest extends TestCase
{
    private const DOORS = 'requestJoin|cancelJoinRequest|approveJoinRequest|denyJoinRequest|invite|revokeInvite|acceptInvite|declineInvite';

    private const SERVICE = 'Domain/Core/Services/GroupJoinRequestService.php';

    /** @return list<array{file: string, line: int, text: string}> */
    private static function doorCalls(): array
    {
        $root  = dirname(__DIR__, 2) . '/app';
        $found = [];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $lines = file($file->getPathname()) ?: [];
            foreach ($lines as $i => $text) {
                if (preg_match('/PeepSoGroupWriter::(' . self::DOORS . ')\s*\(/', $text) === 1) {
                    $found[] = [
                        'file' => str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)),
                        'line' => $i + 1,
                        'text' => trim($text),
                    ];
                }
            }
        }
        return $found;
    }

    public function testEveryDoorCallIsInTheServiceInsideItsLock(): void
    {
        $calls = self::doorCalls();

        self::assertCount(8, $calls, 'one call per door; a new call site must come through the service');
        foreach ($calls as $call) {
            self::assertSame(self::SERVICE, $call['file'], "door called outside the service: {$call['file']}:{$call['line']}");
            self::assertStringContainsString('$this->locked(', $call['text'], "door called outside the lock: {$call['file']}:{$call['line']}");
        }
    }

    public function testTheServiceLockKeyIsNotCoresPairLockKey(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/' . self::SERVICE);

        self::assertMatchesRegularExpression("/\\\$key = 'bcc_gjr_'/", $source);
        self::assertStringNotContainsString('bcc_group_member_', $source);
    }
}
