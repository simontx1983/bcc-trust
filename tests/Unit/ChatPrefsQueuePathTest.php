<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\MessagesService;
use BCC\Trust\Core\Support\PeepSoChatPrefs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * H6, the validator QUEUE path (a message to a never-claimed validator):
 * its sender gate reads the sender's chat_enabled like the direct send,
 * and an unreadable setting is the same retryable 503, never "enabled".
 *
 * enqueueForPage() is called directly: everything before it in
 * sendMessageToPage() is page and claim resolution, which this change does
 * not touch. Same world as ChatPrefsReadFailClosedTest, plus the queue's
 * rate-limit count.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(MessagesService::class)]
final class ChatPrefsQueuePathTest extends TestCase
{
    private const VIEWER = 7;
    private const PAGE   = 300;

    private \TokenStateWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/token-state-wpdb-stubs.php';
        class_exists(PeepSoChatPrefs::class);
        require_once __DIR__ . '/../Stubs/dm-thread-stubs.php';
        require_once __DIR__ . '/../Stubs/validator-queue-count-stub.php';
        \DmWorld::reset();

        $this->db = new \TokenStateWpdb();
        $GLOBALS['wpdb'] = $this->db;
    }

    /** @return array<string, mixed> */
    private static function enqueue(): array
    {
        /** @var MessagesService $svc */
        $svc = (new ReflectionClass(MessagesService::class))->newInstanceWithoutConstructor();
        $m   = new ReflectionMethod(MessagesService::class, 'enqueueForPage');

        /** @var array<string, mixed> */
        return $m->invoke($svc, self::VIEWER, self::PAGE, 'hello');
    }

    public function testAnUnreadableSenderSettingIsARetryable503(): void
    {
        $this->db->put(self::VIEWER, PeepSoChatPrefs::ENABLED_KEY, '0');
        $this->db->last_result = [(object) [
            'user_id'    => (string) self::VIEWER,
            'meta_key'   => PeepSoChatPrefs::ENABLED_KEY,
            'meta_value' => '1',
        ]];
        $this->db->mode = 'false_early';

        $out = self::enqueue();

        self::assertSame('bcc_unavailable', $out['error'] ?? null);
        self::assertSame('Messaging is temporarily unavailable. Please try again.', $out['message'] ?? null);
    }

    public function testASendersStoredOffIsStillForbidden(): void
    {
        $this->db->put(self::VIEWER, PeepSoChatPrefs::ENABLED_KEY, '0');

        $out = self::enqueue();

        self::assertSame('bcc_forbidden', $out['error'] ?? null);
        self::assertSame('Messaging is disabled in your account settings.', $out['message'] ?? null);
    }
}
