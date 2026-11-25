<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A home Hall accepts only published Halls (HomeHallPublishedHallsOnlyIntegrationTest
 * proves the read and the write on a real server). Wiring, source-level and
 * with comments removed: every reader of the home-Hall pointer goes
 * through HallRepository's checked read, and none reaches bcc-core's
 * unchecked batch read directly.
 */
final class HomeHallReadersWiringTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function listReaders(): array
    {
        return [
            'member cards'     => ['app/Domain/Core/Support/MemberSummaryPrefetcher.php'],
            'feed cold start'  => ['app/Domain/Core/Services/FeedColdStartService.php'],
        ];
    }

    #[DataProvider('listReaders')]
    public function testListReadersUseTheCheckedRead(string $file): void
    {
        $code = GateOrder::code($file);

        self::assertStringContainsString("'primary_halls'                => \\BCC\\Trust\\Onchain\\Repositories\\HallRepository::primaryHallsForUsers(\$userIds),", $code);
        self::assertStringNotContainsString('getPrimaryHallForUsers(', $code, 'never the unchecked core read');
    }

    public function testNothingElseInTrustCallsTheUncheckedRead(): void
    {
        $root  = dirname(__DIR__, 2) . '/app';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $hits  = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', dirname(__DIR__, 2))) + 1);
            if (str_contains(GateOrder::code($rel), 'getPrimaryHallForUsers(')) {
                $hits[] = $rel;
            }
        }

        self::assertSame(['app/Domain/Onchain/Repositories/HallRepository.php'], $hits, 'only the checked read wraps it');
    }

    public function testTheProfileKeepsAPointerOnlyThroughTheCheck(): void
    {
        $code = GateOrder::code('app/Domain/Core/Services/UserViewService.php');

        self::assertStringContainsString('$primaryGroupId = self::publishedHallOrNull(self::loadPrimaryGroupId($userId));', $code);
        self::assertSame(1, substr_count($code, 'self::loadPrimaryGroupId('), 'one read of the pointer, and it is checked');
    }

    public function testTheWriteChecksBeforeItWrites(): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Core/Services/HallsService.php'), 'setPrimaryHall');

        GateOrder::assertOrder($body, [
            'HallRepository::publishedHallIds([$groupId])',
            'update_user_meta($viewerId, self::META_PRIMARY_GROUP, $groupId);',
        ], 'setPrimaryHall: the published-Hall check comes before the write');
    }
}
