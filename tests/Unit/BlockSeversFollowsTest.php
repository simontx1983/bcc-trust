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

        // The follow-id-for-pair lookup reads BlockFollowWorld::$follows
        // through the guarded query() path (GuardsReadFailures::runRead).
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var list<object>|null */
            public ?array $last_result = null;
            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql . '|' . implode(',', array_map('strval', $args));
            }
            public function query(string $sql): int|false
            {
                [, $args] = explode('|', $sql, 2) + ['', ''];
                [$active, $passive] = array_map('intval', explode(',', $args)) + [0, 0];
                $key = $active . ':' . $passive;
                if (in_array($key, BlockFollowWorld::$failReads, true)) {
                    // A failed read leaves the PREVIOUS rows behind, as wpdb does.
                    $this->last_error = 'MySQL server has gone away';
                    return false;
                }
                $id = BlockFollowWorld::$follows[$key] ?? null;
                $this->last_result = $id === null ? [] : [(object) ['uf_id' => (string) $id]];
                return count($this->last_result);
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

    public function testAFailedFollowReadIsLoggedAndTheOtherDirectionStillGoes(): void
    {
        // M2: a DB blip used to read as "no follow" and skip the direction silently.
        BlockFollowWorld::$follows   = [self::ME . ':' . self::THEM => 901, self::THEM . ':' . self::ME => 902];
        BlockFollowWorld::$failReads = [self::ME . ':' . self::THEM];

        self::assertSame(1, $this->service()->severFollowsBetween(self::ME, self::THEM));
        self::assertSame([[self::THEM, self::ME]], BlockFollowWorld::$unfollowCalls, 'the readable direction is still removed');
        self::assertSame([902], BlockFollowWorld::$metaDeleted);

        $messages = array_column(BlockFollowWorld::$errors, 0);
        self::assertContains('[bcc-trust] block: follow read failed, this direction was NOT removed', $messages);
        $ctx = BlockFollowWorld::$errors[array_search('[bcc-trust] block: follow read failed, this direction was NOT removed', $messages, true)][1];
        self::assertSame(self::ME, $ctx['active_user_id']);
        self::assertSame(self::THEM, $ctx['passive_user_id']);
    }

    public function testAFailedUnfollowIsLoggedAndKeepsItsSidecar(): void
    {
        BlockFollowWorld::$follows       = [self::ME . ':' . self::THEM => 901, self::THEM . ':' . self::ME => 902];
        BlockFollowWorld::$failUnfollows = [self::THEM . ':' . self::ME];

        self::assertSame(1, $this->service()->severFollowsBetween(self::ME, self::THEM));
        self::assertSame([901], BlockFollowWorld::$metaDeleted, 'the follow that is still there keeps its sidecar');
        self::assertCount(1, BlockFollowWorld::$errors);
        self::assertSame('[bcc-trust] block: unfollow failed, this direction was NOT removed', BlockFollowWorld::$errors[0][0]);
        self::assertSame(902, BlockFollowWorld::$errors[0][1]['follow_id']);
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
