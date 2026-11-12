<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\PageFollowRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Repositories\WatchMetaRepository;
use BCC\Trust\Core\Services\WatchingService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-29: someone you blocked can no longer follow you, and a block removes
 * the follows that already exist (user decision: BOTH directions, with
 * their watch sidecars; unblocking restores nothing).
 *
 * The real WatchingService and the real (final) WatchingRepository over a
 * recording $wpdb; bcc-core's follow writer and block read are stubbed.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BlockSeversFollowsTest extends TestCase
{
    private const ME   = 5201;
    private const THEM = 5202;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/block-follow-stubs.php';
        BlockFollowWorld::reset();

        // The follow-id-for-pair lookup reads BlockFollowWorld::$follows.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql . '|' . implode(',', array_map('strval', $args));
            }
            public function get_var(string $sql): ?string
            {
                [, $args] = explode('|', $sql, 2) + ['', ''];
                [$active, $passive] = array_map('intval', explode(',', $args)) + [0, 0];
                $id = BlockFollowWorld::$follows[$active . ':' . $passive] ?? null;
                return $id === null ? null : (string) $id;
            }
        };
    }

    private function service(): WatchingService
    {
        $meta = new class extends WatchMetaRepository {
            public function __construct()
            {
            }
            public function delete(int $followId): int|false
            {
                BlockFollowWorld::$metaDeleted[] = $followId;
                return 1;
            }
        };
        $r = new \ReflectionClass(ReputationRepository::class);
        $p = new \ReflectionClass(PageFollowRepository::class);
        return new WatchingService(new WatchingRepository(), $meta, $r->newInstanceWithoutConstructor(), $p->newInstanceWithoutConstructor());
    }

    public function testABlockRemovesFollowsInBothDirectionsWithTheirSidecars(): void
    {
        BlockFollowWorld::$follows = [self::ME . ':' . self::THEM => 901, self::THEM . ':' . self::ME => 902];

        self::assertSame(2, $this->service()->severFollowsBetween(self::ME, self::THEM));
        self::assertSame([[self::ME, self::THEM], [self::THEM, self::ME]], BlockFollowWorld::$unfollowCalls);
        self::assertSame([901, 902], BlockFollowWorld::$metaDeleted);
    }

    public function testSeveringWithNoFollowsIsANoOp(): void
    {
        self::assertSame(0, $this->service()->severFollowsBetween(self::ME, self::THEM));
        self::assertSame([], BlockFollowWorld::$unfollowCalls);
        self::assertSame([], BlockFollowWorld::$metaDeleted);
    }

    public function testABlockedViewerGetsAGenericRefusalAndNoFollowIsAttempted(): void
    {
        BlockFollowWorld::$blocks[BlockFollowWorld::pair(self::ME, self::THEM)] = true;

        $result = $this->service()->watch(self::ME, 'member', self::THEM);

        self::assertSame('bcc_forbidden', $result['error'] ?? null);
        self::assertSame("You can't watch this account.", $result['message'] ?? null, 'generic: does not say who blocked whom');
        self::assertSame([], BlockFollowWorld::$followCalls);
    }

    public function testAnUnreadableBlockStateIsAFailureNotARefusal(): void
    {
        BlockFollowWorld::$blocks[BlockFollowWorld::pair(self::ME, self::THEM)] = null;

        $result = $this->service()->watch(self::ME, 'member', self::THEM);

        self::assertSame('bcc_internal_error', $result['error'] ?? null);
        self::assertSame([], BlockFollowWorld::$followCalls, 'fail closed: still no follow');
    }
}
