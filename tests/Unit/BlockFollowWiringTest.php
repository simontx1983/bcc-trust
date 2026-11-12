<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\WatchingService;
use PHPUnit\Framework\TestCase;

/**
 * F-29 wiring that a unit test cannot drive end to end (a REST handler
 * with WP/PeepSo collaborators, and the claim-time follow migration):
 *  - every successful block (created OR existing) severs the follows;
 *  - the claim migration treats a blocked pair as HANDLED (skipped, never
 *    retried) and an unreadable block state as an error (retried).
 */
final class BlockFollowWiringTest extends TestCase
{
    public function testEverySuccessfulBlockSeversTheFollows(): void
    {
        $src = $this->read('app/Domain/Core/REST/MyBlocksEndpoint.php');
        $block  = strpos($src, 'PeepSoBlockWriter::block($viewerId, $targetId)');
        $sever  = strpos($src, '->watchingService()->severFollowsBetween($viewerId, $targetId)');
        $okResp = strpos($src, "'state'   => \$result,");
        self::assertNotFalse($block);
        self::assertNotFalse($sever, 'the block handler severs follows');
        self::assertNotFalse($okResp);
        self::assertGreaterThan($block, $sever, 'after the block is recorded');
        self::assertLessThan($okResp, $sever, 'and before the success response, on created AND existing');
        self::assertTrue(method_exists(WatchingService::class, 'severFollowsBetween'));
    }

    public function testTheClaimMigrationSkipsABlockedPairAsHandled(): void
    {
        $src = $this->read('app/Domain/Core/Integration/PeepSoIntegration.php');
        self::assertMatchesRegularExpression(
            '/\$blocked = \\\\BCC\\\\Core\\\\Repositories\\\\PeepSoBlockRepository::isMutuallyBlockedOrUnknown\(\$viewerId, \$newAuthorId\);\s*'
            . 'if \(\$blocked === true\) \{\s*\$skipped\+\+;\s*\$handledIds\[\] = \$rowId;\s*continue;\s*\}\s*'
            . 'if \(\$blocked === null\) \{\s*\$erroredIds\[\$rowId\] = true;/',
            $src
        );
        self::assertLessThan(
            strpos($src, 'PeepSoFollowWriter::follow($viewerId, $newAuthorId)'),
            strpos($src, 'isMutuallyBlockedOrUnknown($viewerId, $newAuthorId)'),
            'checked before the follow is attempted'
        );
    }

    private function read(string $path): string
    {
        $src = file_get_contents(__DIR__ . '/../../' . $path);
        self::assertIsString($src);
        return $src;
    }
}
