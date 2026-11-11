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
 * F-75: a push is sent to the address its SSRF check approved, and nowhere
 * else (DNS rebinding).
 *
 * Before: the send-time check resolved the endpoint's host and refused a
 * private IP, then minishlink/web-push's Guzzle client resolved it AGAIN to
 * connect. A hostile DNS server could answer public for the check and
 * private for the send.
 *
 * Now every subscription gets its own WebPush client whose Guzzle options
 * carry SafeHttpClient's pin (CURLOPT_RESOLVE) on a FORCED cURL handler.
 * What is asserted is the boundary this code controls: the options each
 * client is built with. Whether cURL honours CURLOPT_RESOLVE is cURL's
 * contract (bcc-core #81 states the same limit for curl_setopt).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushDispatcherDnsPinTest extends TestCase
{
    private const USER = 7101;
    private const TYPE = 'mention';

    /** @var list<array{options: array<string, mixed>, endpoints: list<string>}> */
    private array $clients = [];

    /** @var array<string, int> endpoint => HTTP status the fake push service answers */
    private array $answers = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/push-dns-pin-stubs.php';
        PushPinWorld::reset();
        $this->clients = [];
        $this->answers = [];
        $GLOBALS['__push_pin'] = ['transients' => [], 'rows' => [], 'deleted' => [], 'touched' => []];

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
            /** @param array<string, mixed> $where */
            public function delete(string $table, array $where, array $f = []): int
            {
                $GLOBALS['__push_pin']['deleted'][] = (int) $where['id'];
                return 1;
            }
            /** @param array<string, mixed> $data @param array<string, mixed> $where */
            public function update(string $table, array $data, array $where, array $f = [], array $wf = []): int
            {
                $GLOBALS['__push_pin']['touched'][] = (int) $where['id'];
                return 1;
            }
        };
    }

    private function subscribe(int $id, string $endpoint): void
    {
        $GLOBALS['__push_pin']['rows'][] = (object) [
            'id' => $id, 'user_id' => self::USER, 'endpoint' => $endpoint,
            'p256dh_key' => 'p' . $id, 'auth_key' => 'a' . $id,
        ];
    }

    private function dispatcher(): PushDispatcher
    {
        $factory = function (array $vapid, array $options): WebPush {
            $record = &$this->clients[];
            $record = ['options' => $options, 'endpoints' => []];
            $answers = $this->answers;

            // A WebPush that never touches the network: records what it was
            // asked to send and answers like the push service would.
            return new class ($record, $answers) extends WebPush {
                /** @var list<string> */
                private array $queued = [];
                /** @param array<string, mixed> $record @param array<string, int> $answers */
                public function __construct(private array &$record, private array $answers)
                {
                }
                public function queueNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): void
                {
                    $this->queued[] = $subscription->getEndpoint();
                    $this->record['endpoints'][] = $subscription->getEndpoint();
                }
                public function flush(?int $batchSize = null): \Generator
                {
                    foreach ($this->queued as $endpoint) {
                        $status = $this->answers[$endpoint] ?? 201;
                        yield new MessageSentReport(new Request('POST', $endpoint), new Response($status), $status < 300, (string) $status);
                    }
                }
            };
        };

        return new PushDispatcher(new PushSubscriptionRepository(), $factory);
    }

    private function flush(): void
    {
        $GLOBALS['__push_pin']['transients']['bcc_push_q_' . self::USER . '_' . self::TYPE] = ['count' => 1, 'first_payload' => []];
        $this->dispatcher()->flush(self::USER, self::TYPE);
    }

    public function testEachSendIsPinnedToItsOwnEndpointsApprovedAddress(): void
    {
        $this->subscribe(1, 'https://fcm.googleapis.com/fcm/send/aaa');
        $this->subscribe(2, 'https://updates.push.services.mozilla.com/wpush/v2/bbb');
        PushPinWorld::$byHost = [
            'fcm.googleapis.com'               => 'fcm.googleapis.com:443:142.250.1.1',
            'updates.push.services.mozilla.com' => 'updates.push.services.mozilla.com:443:34.1.2.3',
        ];

        $this->flush();

        self::assertCount(2, $this->clients, 'one client per subscription: the pin is per endpoint');
        self::assertSame(['https://fcm.googleapis.com/fcm/send/aaa'], $this->clients[0]['endpoints']);
        self::assertSame([CURLOPT_RESOLVE => ['fcm.googleapis.com:443:142.250.1.1']], $this->clients[0]['options']['curl'] ?? null);
        self::assertSame(['https://updates.push.services.mozilla.com/wpush/v2/bbb'], $this->clients[1]['endpoints']);
        self::assertSame([CURLOPT_RESOLVE => ['updates.push.services.mozilla.com:443:34.1.2.3']], $this->clients[1]['options']['curl'] ?? null);
        foreach ($this->clients as $c) {
            self::assertFalse($c['options']['allow_redirects'], 'a redirect hop would not be pinned');
        }
        self::assertEquals(['attempt' => 2, 'success' => 2], PushPinWorld::$metrics);
        self::assertSame([], PushPinWorld::$errors);
    }

    /**
     * The pin only binds on cURL: Guzzle's stream handler silently ignores
     * the `curl` option. Every client must run on the cURL handler, inside a
     * full HandlerStack (http_errors is what turns a 410 into "expired").
     */
    public function testEverySendRunsOnACurlHandlerStackNeverAnotherHandler(): void
    {
        $this->subscribe(1, 'https://fcm.googleapis.com/fcm/send/aaa');
        $this->subscribe(2, 'https://203.0.113.9/push/ccc');
        PushPinWorld::$byHost = ['fcm.googleapis.com' => 'fcm.googleapis.com:443:142.250.1.1', '203.0.113.9' => null];

        $this->flush();

        self::assertCount(2, $this->clients);
        foreach ($this->clients as $c) {
            $handler = $c['options']['handler'] ?? null;
            self::assertInstanceOf(\GuzzleHttp\HandlerStack::class, $handler);
            // The stack's description prints an invokable handler only as
            // "callable(<id>)", so read the terminal handler itself.
            $terminal = (new \ReflectionProperty(\GuzzleHttp\HandlerStack::class, 'handler'))->getValue($handler);
            self::assertInstanceOf(\GuzzleHttp\Handler\CurlMultiHandler::class, $terminal, 'the request can only reach the cURL handler');
            self::assertStringContainsString("Name: 'http_errors'", (string) $handler);
        }
        self::assertArrayNotHasKey('curl', $this->clients[1]['options'], 'an IP-literal host has no DNS to pin');
    }

    public function testAnEndpointThatFailsTheCheckIsNeverSent(): void
    {
        $this->subscribe(1, 'https://fcm.googleapis.com/fcm/send/aaa');
        $this->subscribe(2, 'https://rebind.attacker.example/push');
        $this->subscribe(3, 'http://fcm.googleapis.com/fcm/send/plain');
        PushPinWorld::$byHost = ['fcm.googleapis.com' => 'fcm.googleapis.com:443:142.250.1.1'];

        $this->flush();

        self::assertCount(1, $this->clients, 'only the approved endpoint gets a client');
        self::assertSame(['https://fcm.googleapis.com/fcm/send/aaa'], $this->clients[0]['endpoints']);
        self::assertNotContains('http://fcm.googleapis.com/fcm/send/plain', PushPinWorld::$validated, 'non-https is refused before resolving');
        self::assertEquals(['failure' => 2, 'attempt' => 1, 'success' => 1], PushPinWorld::$metrics);
    }

    public function testWithoutCurlNothingIsSentUnpinned(): void
    {
        $this->subscribe(1, 'https://fcm.googleapis.com/fcm/send/aaa');
        $this->subscribe(2, 'https://updates.push.services.mozilla.com/wpush/v2/bbb');
        PushPinWorld::$byHost = ['fcm.googleapis.com' => 'fcm.googleapis.com:443:142.250.1.1'];
        PushPinWorld::$noCurl = true;

        $this->flush();

        self::assertSame([], $this->clients);
        self::assertEquals(['failure' => 2], PushPinWorld::$metrics);
    }

    public function testExpiredAndDeliveredReportsStillReachTheRightRow(): void
    {
        $this->subscribe(11, 'https://fcm.googleapis.com/fcm/send/alive');
        $this->subscribe(12, 'https://fcm.googleapis.com/fcm/send/gone');
        PushPinWorld::$byHost = ['fcm.googleapis.com' => 'fcm.googleapis.com:443:142.250.1.1'];
        $this->answers = ['https://fcm.googleapis.com/fcm/send/gone' => 410];

        $this->flush();

        self::assertSame([11], $GLOBALS['__push_pin']['touched']);
        self::assertSame([12], $GLOBALS['__push_pin']['deleted'], 'a 410 tombstones its own row');
        self::assertEquals(['attempt' => 2, 'success' => 1, 'tombstone' => 1], PushPinWorld::$metrics);
        self::assertSame([], PushPinWorld::$errors);
    }
}
