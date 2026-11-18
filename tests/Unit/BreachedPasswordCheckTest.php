<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\BreachedPasswordCheck;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * BreachedPasswordCheck: Have I Been Pwned's k-anonymity range API.
 *   - only the 5-character SHA-1 prefix leaves the server, over a padded,
 *     short, no-redirect request with a neutral User-Agent;
 *   - a listed suffix (any case) with a count > 0 is breached; a padding
 *     row (count 0) or an unlisted suffix is not;
 *   - FAILS OPEN: transport error, non-200, empty body, a throwing client
 *     → allowed, with DegradationMetrics password_breach_check/unavailable
 *     and a log line that carries neither the password nor its hash;
 *   - the rejection is 422 bcc_password_breached with plain copy.
 * HTTP is stubbed (tests/Stubs/breached-password-stubs.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BreachedPasswordCheckTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/breached-password-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php'; // WP_Error, WP_REST_Response
        $GLOBALS['__bcc_hibp'] = ['calls' => [], 'metrics' => [], 'logs' => []];
    }

    private static function sha1(): string
    {
        return strtoupper(sha1(self::PASSWORD));
    }

    /** @return array<string, mixed> */
    private static function ok(string $body): array
    {
        return ['response' => ['code' => 200], 'body' => $body];
    }

    private static function rows(string ...$rows): string
    {
        return implode("\r\n", $rows) . "\r\n";
    }

    public function testOnlyTheFiveCharacterPrefixIsSentOverAPaddedShortRequest(): void
    {
        BreachedPasswordCheck::isBreached(self::PASSWORD);

        self::assertCount(1, $GLOBALS['__bcc_hibp']['calls']);
        $call = $GLOBALS['__bcc_hibp']['calls'][0];
        self::assertSame('https://api.pwnedpasswords.com/range/' . substr(self::sha1(), 0, 5), $call['url']);
        self::assertSame('true', $call['args']['headers']['Add-Padding'] ?? null);
        self::assertSame('password-range-check', $call['args']['headers']['User-Agent'] ?? null);
        self::assertSame(2, $call['args']['timeout'] ?? null);
        self::assertSame(0, $call['args']['redirection'] ?? null);

        $sent = json_encode($call);
        self::assertStringNotContainsString(substr(self::sha1(), 5), (string) $sent, 'the suffix never leaves');
        self::assertStringNotContainsString(self::PASSWORD, (string) $sent);
    }

    public function testAListedSuffixIsBreached(): void
    {
        $GLOBALS['__bcc_hibp']['response'] = self::ok(self::rows(
            '0018A45C4D1DEF81644B54AB7F969B88D65:3',
            substr(self::sha1(), 5) . ':42',
            '00D4F6E8FA6EECAD2A3AA415EEC418D38EC:0'
        ));

        self::assertTrue(BreachedPasswordCheck::isBreached(self::PASSWORD));
        self::assertSame([], $GLOBALS['__bcc_hibp']['metrics']);
    }

    public function testTheSuffixMatchesInAnyCaseAndOnABareNewline(): void
    {
        $GLOBALS['__bcc_hibp']['response'] = self::ok('AAAA:1' . "\n" . strtolower(substr(self::sha1(), 5)) . ':7');

        self::assertTrue(BreachedPasswordCheck::isBreached(self::PASSWORD));
    }

    public function testAPaddingRowIsNotABreach(): void
    {
        $GLOBALS['__bcc_hibp']['response'] = self::ok(self::rows(substr(self::sha1(), 5) . ':0'));

        self::assertFalse(BreachedPasswordCheck::isBreached(self::PASSWORD));
    }

    public function testAnUnlistedSuffixIsNotABreachAndNotAnOutage(): void
    {
        $GLOBALS['__bcc_hibp']['response'] = self::ok(self::rows(
            '0018A45C4D1DEF81644B54AB7F969B88D65:3',
            substr(self::sha1(), 5, 34) . 'Z:9' // one character off
        ));

        self::assertFalse(BreachedPasswordCheck::isBreached(self::PASSWORD));
        self::assertSame([], $GLOBALS['__bcc_hibp']['metrics']);
        self::assertSame([], $GLOBALS['__bcc_hibp']['logs']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function outages(): array
    {
        return [
            'transport error' => ['wp_error', 'transport'],
            'rate limited'    => ['429', 'http_429'],
            'server error'    => ['503', 'http_503'],
            'empty body'      => ['empty', 'empty'],
            'client throws'   => ['throw', 'exception'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outages')]
    public function testAnOutageFailsOpenWithAMetricAndAQuietLog(string $case, string $reason): void
    {
        $GLOBALS['__bcc_hibp']['response'] = match ($case) {
            'wp_error' => new \WP_Error('http_request_failed', 'timed out'),
            '429'      => ['response' => ['code' => 429], 'body' => substr(self::sha1(), 5) . ':42'],
            '503'      => ['response' => ['code' => 503], 'body' => ''],
            'empty'    => self::ok("  \r\n"),
            'throw'    => static function (): never {
                throw new \RuntimeException('boom');
            },
        };

        self::assertFalse(BreachedPasswordCheck::isBreached(self::PASSWORD), 'allowed: never blocked on an outage');
        self::assertSame(['password_breach_check/unavailable'], $GLOBALS['__bcc_hibp']['metrics']);

        self::assertCount(1, $GLOBALS['__bcc_hibp']['logs']);
        [$level, $message, $context] = $GLOBALS['__bcc_hibp']['logs'][0];
        self::assertSame('warning', $level);
        self::assertSame(['reason' => $reason], $context, 'a reason, nothing else');
        $logged = $message . json_encode($context);
        self::assertStringNotContainsString(self::PASSWORD, $logged);
        self::assertStringNotContainsString(substr(self::sha1(), 0, 5), $logged, 'not even the prefix');
    }

    public function testTheRejectionIs422WithPlainCopy(): void
    {
        $res  = BreachedPasswordCheck::rejection();
        $data = $res->get_data();

        self::assertSame(422, $res->get_status());
        self::assertSame('bcc_password_breached', $data['error']['code'] ?? null);
        self::assertSame(
            'This password has appeared in a data breach; please choose a different one.',
            $data['error']['message'] ?? null
        );
    }
}
