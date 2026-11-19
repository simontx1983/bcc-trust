<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Services\PushDispatcher;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * PushDispatcher's logs never carry a Web Push endpoint URL.
 *
 * The endpoint is a per-device address (security review, log audit). A
 * failed send used to log it whole, and the failure reason Guzzle hands
 * WebPush quotes it again ("POST https://…/send/<token> resulted in …").
 * Now a log line names the push service's host and the first 12 hex of the
 * endpoint's SHA-256 (the `endpoint_hash` the subscriptions table stores),
 * and any copy of the URL inside a message becomes `[endpoint]`.
 *
 * Drives the real sendOne() with a WebPush double that never touches the
 * network.
 */
#[CoversClass(PushDispatcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushLogNoEndpointTest extends TestCase
{
    private const TOKEN    = 'dEvIcE-ToKeN-a1b2c3d4e5f6';
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/' . self::TOKEN;
    private const ROW_ID   = 55;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/push-log-endpoint-stubs.php';
        $GLOBALS['__push_log'] = [];
        $GLOBALS['wpdb']       = new class {
            public string $prefix = 'wp_';
        };
    }

    public function testAFailedSendLogsTheHostAndFingerprintNeverTheUrl(): void
    {
        self::send(static fn (string $endpoint) => new MessageSentReport(
            new Request('POST', $endpoint),
            new Response(500),
            false,
            "Server error: `POST {$endpoint}` resulted in a `500 Internal Server Error` response"
        ));

        [$level, $msg, $ctx] = self::onlyLine();
        self::assertSame(['warning', '[PushDispatcher] send failed'], [$level, $msg]);
        self::assertSame(self::ROW_ID, $ctx['sub_id']);
        self::assertSame('fcm.googleapis.com', $ctx['endpoint_host']);
        self::assertSame(substr(PushSubscriptionRepository::hashEndpoint(self::ENDPOINT), 0, 12), $ctx['endpoint_fp']);
        self::assertSame('Server error: `POST [endpoint]` resulted in a `500 Internal Server Error` response', $ctx['reason']);
        self::assertArrayNotHasKey('endpoint', $ctx);
        self::assertNoEndpointIn($ctx);
    }

    public function testALibraryExceptionLogsWithoutTheUrl(): void
    {
        self::send(null, new \RuntimeException('cURL error 6: Could not resolve host for ' . self::ENDPOINT));

        [$level, $msg, $ctx] = self::onlyLine();
        self::assertSame(['error', '[PushDispatcher] flush exception'], [$level, $msg]);
        self::assertSame('cURL error 6: Could not resolve host for [endpoint]', $ctx['error']);
        self::assertNoEndpointIn($ctx);
    }

    /**
     * @param (\Closure(string): MessageSentReport)|null $report
     */
    private static function send(?\Closure $report, ?\Throwable $throw = null): void
    {
        $factory = static function () use ($report, $throw): WebPush {
            if ($throw !== null) {
                throw $throw;
            }
            return new class ($report) extends WebPush {
                private string $queued = '';

                public function __construct(private \Closure $report)
                {
                }

                public function queueNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): void
                {
                    $this->queued = $subscription->getEndpoint();
                }

                public function flush(?int $batchSize = null): \Generator
                {
                    yield ($this->report)($this->queued);
                }
            };
        };

        $dispatcher = new PushDispatcher(new PushSubscriptionRepository(), $factory);
        $sub        = Subscription::create([
            'endpoint'        => self::ENDPOINT,
            'publicKey'       => 'BPcMbnWQL5GOYX/5LKZXT6sLmHiMsJSiEvIFvfcDvX7IZ9qqtq68onpTPEYmyxSQNiH7UD/98AUcQ12kBoFVgUQ',
            'authToken'       => 'BTBZMqHH6r4Tts7J_aSIgg',
            'contentEncoding' => 'aes128gcm',
        ]);

        (new \ReflectionMethod(PushDispatcher::class, 'sendOne'))->invoke(
            $dispatcher,
            ['subject' => 'mailto:ops@example.invalid', 'publicKey' => 'x', 'privateKey' => 'y'],
            null,
            $sub,
            '{}',
            self::ROW_ID,
            'mention'
        );
    }

    /** @return array{0: string, 1: string, 2: array<string, mixed>} */
    private static function onlyLine(): array
    {
        self::assertCount(1, $GLOBALS['__push_log'], 'exactly one log line');
        return $GLOBALS['__push_log'][0];
    }

    /** @param array<string, mixed> $ctx */
    private static function assertNoEndpointIn(array $ctx): void
    {
        $flat = (string) json_encode($ctx, JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(self::TOKEN, $flat, 'the per-device token never reaches a log');
        self::assertStringNotContainsString('/fcm/send/', $flat);
    }
}
