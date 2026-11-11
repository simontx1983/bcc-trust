<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\DTO\XConnectionDTO;
use BCC\Trust\Core\Repositories\XRepository;
use BCC\Trust\Core\Services\x\XOAuthService;
use BCC\Trust\Core\Services\x\XVerificationService;
use BCC\Trust\Core\Support\Encryption;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-146 — an X token is stored encrypted, or not at all.
 *
 * XRepository::encryptToken() used to fall back to wp_hash($token) when
 * encryption was unavailable: a one-way value Encryption::decrypt() can never
 * turn back into a token, so the connection looked "connected" while every X
 * call silently got a null token. Pinned against the REAL XRepository,
 * Encryption and XVerificationService::getValidAccessToken():
 *   - without BCC_ENCRYPTION_KEY, saveConnection() and updateTokens() THROW,
 *     log, and write nothing (no hash is ever stored);
 *   - with the key, the stored value decrypts back to the token;
 *   - a wp_hash-shaped value can never decrypt (why the old fallback was dead);
 *   - a refresh whose result cannot be stored still returns the fresh token
 *     to the caller (and logs), instead of throwing out of a read path.
 *
 * ## Isolation
 * Subprocess-only (BCC_ENCRYPTION_KEY is a constant, defined per test);
 * setUp() loads tests/Stubs/core-dtoassert-stub.php, then
 * tests/Stubs/account-prehijack-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class XTokenFailClosedTest extends TestCase
{
    private const USER = 71;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/core-dtoassert-stub.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = ['users' => [], 'meta' => [], 'transients' => [], 'mail' => [], 'actions' => []];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            /** @var list<string> */
            public array $calls = [];
            /** @var list<array<string, mixed>> */
            public array $written = [];

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            /** @return null */
            public function get_var(string $sql)
            {
                $this->calls[] = 'get_var';
                return null;
            }

            /**
             * @param array<string, mixed> $data
             * @param mixed                ...$rest
             */
            public function insert(string $table, array $data, ...$rest): int
            {
                $this->calls[]   = 'insert';
                $this->written[] = $data;
                return 1;
            }

            /**
             * @param array<string, mixed> $data
             * @param mixed                ...$rest
             */
            public function update(string $table, array $data, ...$rest): int
            {
                $this->calls[]   = 'update';
                $this->written[] = $data;
                return 1;
            }

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('unexpected $wpdb->' . $name);
            }
        };
        if (!function_exists('current_time')) {
            eval('function current_time(string $type, $gmt = 0): string { return gmdate("Y-m-d H:i:s"); }');
        }
    }

    private static function db(): object
    {
        return $GLOBALS['wpdb'];
    }

    public function testWithoutAKeySaveConnectionThrowsAndWritesNothing(): void
    {
        try {
            (new XRepository())->saveConnection(self::USER, ['id' => 'x1', 'username' => 'alice'], 'access-token-1', 'refresh-1', 7200);
            self::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Encryption failed', $e->getMessage());
        }

        self::assertSame([], self::db()->calls, 'no query at all — nothing is stored');
        self::assertSame(['[bcc-trust] X token not stored: encryption unavailable'], $GLOBALS['__bcc_ph']['log_errors'] ?? []);
    }

    public function testWithoutAKeyUpdateTokensThrowsAndWritesNothing(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            (new XRepository())->updateTokens(self::USER, 'access-token-2', 'refresh-2', 7200);
        } finally {
            self::assertSame([], self::db()->calls);
        }
    }

    public function testWithAKeyTheStoredTokenDecryptsBack(): void
    {
        define('BCC_ENCRYPTION_KEY', 'test-key-f146');

        (new XRepository())->saveConnection(self::USER, ['id' => 'x1', 'username' => 'alice'], 'access-token-3', 'refresh-3', 7200);

        $row = self::db()->written[0] ?? [];
        self::assertSame('access-token-3', Encryption::decrypt((string) ($row['access_token'] ?? '')));
        self::assertSame('refresh-3', Encryption::decrypt((string) ($row['refresh_token'] ?? '')));
        self::assertDoesNotMatchRegularExpression('/^[0-9a-f]{32}$/', (string) $row['access_token'], 'never a 32-hex hash');
    }

    public function testAHashShapedValueCanNeverDecrypt(): void
    {
        define('BCC_ENCRYPTION_KEY', 'test-key-f146');

        self::assertSame('', Encryption::decrypt(md5('access-token-4')), 'the old fallback value was dead on arrival');
    }

    public function testARefreshThatCannotBeStoredStillReturnsTheFreshToken(): void
    {
        $repo = new class extends XRepository {
            public function __construct()
            {
            }

            public function getConnection(int $userId): ?XConnectionDTO
            {
                return new XConnectionDTO('x1', 'alice', null, 0.0, 0, 'enc', null, null, null, 'stale-access', 'refresh-5', null);
            }

            public function updateTokens(int $userId, string $accessToken, ?string $refreshToken, ?int $expiresIn): void
            {
                throw new \RuntimeException('Encryption failed — BCC_ENCRYPTION_KEY may be missing.');
            }
        };
        $oauth = new class extends XOAuthService {
            public function __construct()
            {
            }

            /** @return array<string, mixed> */
            public function refreshAccessToken(string $refreshToken): array
            {
                return ['access_token' => 'fresh-access', 'refresh_token' => 'refresh-6', 'expires_in' => 7200];
            }
        };

        $svc = (new \ReflectionClass(XVerificationService::class))->newInstanceWithoutConstructor();
        foreach (['repository' => $repo, 'oauthService' => $oauth] as $prop => $value) {
            $p = new \ReflectionProperty(XVerificationService::class, $prop);
            $p->setValue($svc, $value);
        }

        self::assertSame('fresh-access', $svc->getValidAccessToken(self::USER));
        self::assertSame(['x_token_refresh_not_stored'], $GLOBALS['__bcc_ph']['log_errors'] ?? []);
    }
}
