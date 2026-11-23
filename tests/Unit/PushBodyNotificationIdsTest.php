<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Services\PushDispatcher;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-516: the push that goes out lists, as `notification_ids`, the bell rows
 * of the events it stands for, and has no such key when there are none.
 *
 * Asserted on the JSON each device is actually sent (a fake WebPush records
 * it; nothing touches the network). Actor 0 (system) keeps the flush-time
 * block re-check out of the way; PushNotificationIdsTest covers that.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushBodyNotificationIdsTest extends TestCase
{
    private const USER = 7201;
    private const TYPE = 'mention';

    /** @var list<string> the payload JSON of every send */
    private array $sent = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/push-dns-pin-stubs.php';
        PushPinWorld::reset();
        PushPinWorld::$byHost = ['fcm.googleapis.com' => 'fcm.googleapis.com:443:142.250.1.1'];
        $this->sent = [];
        $GLOBALS['__push_pin'] = [
            'transients' => [],
            'deleted'    => [],
            'touched'    => [],
            'rows'       => [(object) [
                'id' => 1, 'user_id' => self::USER, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/aaa',
                'p256dh_key' => 'p1', 'auth_key' => 'a1',
            ]],
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }
            /** @return list<object> */
            public function get_results(string $sql): array
            {
                return $GLOBALS['__push_pin']['rows'];
            }
            /** @param array<string, mixed> $data @param array<string, mixed> $where */
            public function update(string $table, array $data, array $where, array $f = [], array $wf = []): int
            {
                return 1;
            }
        };
    }

    private function dispatcher(): PushDispatcher
    {
        $sent = &$this->sent;
        $factory = static function (array $vapid, array $options) use (&$sent): WebPush {
            return new class ($sent) extends WebPush {
                /** @var list<string> */
                private array $queued = [];
                /** @param list<string> $sent */
                public function __construct(private array &$sent)
                {
                }
                public function queueNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): void
                {
                    $this->sent[]   = (string) $payload;
                    $this->queued[] = $subscription->getEndpoint();
                }
                public function flush(?int $batchSize = null): \Generator
                {
                    foreach ($this->queued as $endpoint) {
                        yield new MessageSentReport(new Request('POST', $endpoint), new Response(201), true, '201');
                    }
                }
            };
        };
        return new PushDispatcher(new PushSubscriptionRepository(), $factory);
    }

    /**
     * @param array<string, mixed> $window
     * @return array<string, mixed> the body the device got
     */
    private function flushWindow(array $window): array
    {
        $GLOBALS['__push_pin']['transients']['bcc_push_q_' . self::USER . '_' . self::TYPE] = $window;
        $this->dispatcher()->flush(self::USER, self::TYPE);
        self::assertCount(1, $this->sent, 'the push went out');
        $body = json_decode($this->sent[0], true);
        self::assertIsArray($body);
        return $body;
    }

    public function testThePushListsTheBellIdsOfTheEventsItStandsFor(): void
    {
        $body = $this->flushWindow([
            'count' => 3, 'first_payload' => ['actor_handle' => 'x'], 'first_at' => time(),
            'actors' => [0 => ['n' => 3, 'payload' => ['actor_handle' => 'x'], 'ids' => [11, 12, 13]]],
        ]);

        self::assertSame([11, 12, 13], $body['notification_ids'] ?? null);
        self::assertArrayHasKey('title', $body, 'the wording is unchanged beside it');
    }

    public function testAPushWithNoBellRowsHasNoIdsKey(): void
    {
        $body = $this->flushWindow([
            'count' => 1, 'first_payload' => [], 'first_at' => time(),
            'actors' => [0 => ['n' => 1, 'payload' => [], 'ids' => []]],
        ]);

        self::assertArrayNotHasKey('notification_ids', $body);
    }

    public function testAWindowQueuedBeforeIdsExistedSendsNone(): void
    {
        $body = $this->flushWindow([
            'count' => 1, 'first_payload' => [], 'first_at' => time(),
            'actors' => [0 => ['n' => 1, 'payload' => []]],
        ]);
        self::assertArrayNotHasKey('notification_ids', $body);
    }

    public function testAWindowQueuedBeforeActorsExistedSendsNone(): void
    {
        $body = $this->flushWindow(['count' => 1, 'first_payload' => []]);

        self::assertArrayNotHasKey('notification_ids', $body);
    }
}
