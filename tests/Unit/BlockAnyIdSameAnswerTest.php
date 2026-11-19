<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\MyBlocksEndpoint;
use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * POST /me/blocks gives every positive user id the same answer.
 *
 * It used to answer 404 "User not found." for an id nobody holds and 200 for
 * a real member, which let any member list the ids that exist. Now the row is
 * written for any positive id: 'created' the first time, 'existing' after
 * that, either way. A block on an id nobody holds affects only the blocker
 * (main agent's call). The per-viewer throttle still bounds the writes.
 *
 * The real handler and the real bcc-core PeepSoBlockWriter run over an
 * in-memory peepso_blocks table.
 */
#[CoversClass(MyBlocksEndpoint::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BlockAnyIdSameAnswerTest extends TestCase
{
    private const VIEWER    = 7;
    private const REAL_USER = 12;
    private const NOBODY    = 9999;

    private \BccBlockAnyIdFakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/block-any-id-stubs.php';
        require_once CoreSibling::require('src/PeepSo/PeepSoBlockWriter.php');

        $GLOBALS['__bcc_blk'] = [
            'viewer'         => self::VIEWER,
            'users'          => [self::VIEWER, self::REAL_USER],
            'userdata_reads' => [],
            'actions'        => [],
            'throttle'       => [],
            'throttle_allow' => true,
            'errors'         => [],
            'audit'          => [],
            'severed'        => [],
        ];
        $this->wpdb      = new \BccBlockAnyIdFakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testAnIdNobodyHoldsGetsTheSameAnswerAsARealMember(): void
    {
        $real   = self::block(self::REAL_USER);
        $nobody = self::block(self::NOBODY);

        self::assertSame(200, $real->get_status());
        self::assertSame($real->get_status(), $nobody->get_status());
        self::assertSame($real->get_headers(), $nobody->get_headers());
        self::assertSame(self::answer($real), self::answer($nobody));
        self::assertSame('created', self::answer($nobody)['state']);
    }

    public function testARetryAnswersExistingForBoth(): void
    {
        self::block(self::REAL_USER);
        self::block(self::NOBODY);

        self::assertSame('existing', self::answer(self::block(self::REAL_USER))['state']);
        self::assertSame('existing', self::answer(self::block(self::NOBODY))['state']);
    }

    public function testTheBlockIsWrittenAndNobodyIsAskedWhetherTheUserExists(): void
    {
        self::block(self::NOBODY);

        self::assertSame([['blk_user_id' => self::VIEWER, 'blk_blocked_id' => self::NOBODY]], $this->wpdb->blocks);
        self::assertSame([], $GLOBALS['__bcc_blk']['userdata_reads'], 'existence is never consulted');
    }

    public function testTheThrottleStillComesFirst(): void
    {
        $GLOBALS['__bcc_blk']['throttle_allow'] = false;

        $resp = self::block(self::NOBODY);

        self::assertSame(429, $resp->get_status());
        self::assertSame([['block_create:' . self::VIEWER, 20, 60]], $GLOBALS['__bcc_blk']['throttle']);
        self::assertSame([], $this->wpdb->blocks, 'nothing written');
    }

    public function testSelfAndNonPositiveIdsAreStillRefused(): void
    {
        self::assertSame(400, self::block(self::VIEWER)->get_status());
        self::assertSame(400, self::block(0)->get_status());
        self::assertSame([], $this->wpdb->blocks);
    }

    private static function block(int $userId): \WP_REST_Response
    {
        return (new MyBlocksEndpoint())->create(new \WP_REST_Request(['user_id' => $userId]));
    }

    /** @return array<string, mixed> the payload without the echoed id */
    private static function answer(\WP_REST_Response $resp): array
    {
        $body = $resp->get_data();
        self::assertIsArray($body);
        $data = $body['data'] ?? null;
        self::assertIsArray($data);
        unset($data['user_id']);
        return $data;
    }
}
