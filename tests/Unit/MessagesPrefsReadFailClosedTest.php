<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\MyMessagesPrefsEndpoint;
use BCC\Trust\Core\Support\PeepSoChatPrefs;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * H6: GET and PATCH /me/messages-prefs read the member's settings through
 * the trusted reader, and a read that fails is a retryable 503.
 *
 * Both used to read through PeepSoChatModel::chat_enabled(), which writes
 * a default when its read is empty. PeepSoChatModel throws in this world.
 *
 * ⚠ Two stores, by construction of the harness: the PATCH writes through
 * the update_user_meta() stub ($GLOBALS['__bcc_ph']['meta']), and the read
 * is the $wpdb double. A test that needs the re-read to see the write seeds
 * the double with it.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MessagesPrefsReadFailClosedTest extends TestCase
{
    private const USER = 42;

    private \TokenStateWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/token-state-wpdb-stubs.php';
        require_once __DIR__ . '/../Stubs/peepso-chat-model-throws-stub.php';
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'        => [],
            'meta'         => [self::USER => ['peepso_chat_enabled' => '1', 'peepso_chat_friends_only' => '0']],
            'current_user' => self::USER,
        ];
        $this->db = new \TokenStateWpdb();
        $GLOBALS['wpdb'] = $this->db;
    }

    private static function get(): \WP_REST_Response
    {
        return (new MyMessagesPrefsEndpoint())->get(new \WP_REST_Request([]));
    }

    /** @param array<string, mixed> $params */
    private static function patch(array $params): \WP_REST_Response
    {
        return (new MyMessagesPrefsEndpoint())->patch(new \WP_REST_Request($params));
    }

    private function staleRowsSayOn(): void
    {
        $this->db->last_result = [(object) [
            'user_id'    => (string) self::USER,
            'meta_key'   => PeepSoChatPrefs::ENABLED_KEY,
            'meta_value' => '1',
        ]];
        $this->db->mode = 'false_early';
    }

    public function testGetReadsTheStoredChoice(): void
    {
        $this->db->put(self::USER, PeepSoChatPrefs::ENABLED_KEY, '0');

        $res = self::get();

        self::assertSame(200, $res->get_status());
        self::assertSame(['chat_enabled' => false, 'chat_friends_only' => false], $res->get_data()['data'] ?? null);
    }

    public function testAFailedGetIsA503NotEnabled(): void
    {
        $this->db->put(self::USER, PeepSoChatPrefs::ENABLED_KEY, '0');
        $this->db->mode = 'error';

        $res = self::get();

        self::assertSame(503, $res->get_status());
        self::assertSame('bcc_unavailable', $res->get_data()['error']['code'] ?? null);
        self::assertSame(
            'Could not read your messaging preferences. Please try again.',
            $res->get_data()['error']['message'] ?? null
        );
    }

    /** ⚠⚠ The pre-flush trap: stale rows say ON, the member chose OFF. */
    public function testStaleRowsAreA503NotEnabled(): void
    {
        $this->db->put(self::USER, PeepSoChatPrefs::ENABLED_KEY, '0');
        $this->staleRowsSayOn();

        self::assertSame(503, self::get()->get_status());
    }

    /**
     * The PATCH echo is a FRESH read: a GET earlier in the same request left
     * a trusted answer in UserMetaRepository's memo, and this harness's
     * update_user_meta() fires no hook to drop it. The echo must still be
     * the stored value, not the remembered one.
     */
    public function testThePatchEchoIsNotAnEarlierRememberedRead(): void
    {
        $this->db->put(self::USER, PeepSoChatPrefs::ENABLED_KEY, '1');
        self::assertSame(true, self::get()->get_data()['data']['chat_enabled'] ?? null, 'control: the GET remembered "on"');
        $this->db->rows[0]['meta_value'] = '0';

        $res = self::patch(['chat_enabled' => false]);

        self::assertSame(false, $res->get_data()['data']['chat_enabled'] ?? null);
    }

    public function testPatchReturnsTheReRead(): void
    {
        $this->db->put(self::USER, PeepSoChatPrefs::ENABLED_KEY, '0');

        $res = self::patch(['chat_enabled' => false]);

        self::assertSame(200, $res->get_status());
        self::assertSame(false, $res->get_data()['data']['chat_enabled'] ?? null);
        self::assertSame('0', $GLOBALS['__bcc_ph']['meta'][self::USER]['peepso_chat_enabled']);
    }

    /**
     * Switching DMs OFF and then failing the re-read: the write stands, the
     * answer is a 503, nothing else is written.
     */
    public function testAFailedReReadAfterSwitchingOffIsA503AndTheOffStands(): void
    {
        $this->db->put(self::USER, PeepSoChatPrefs::ENABLED_KEY, '0');
        $this->staleRowsSayOn();

        $res = self::patch(['chat_enabled' => false]);

        self::assertSame(503, $res->get_status());
        self::assertSame('0', $GLOBALS['__bcc_ph']['meta'][self::USER]['peepso_chat_enabled'], 'the OFF stands');
        self::assertCount(1, $this->db->rows);
        foreach ($this->db->queries as $sql) {
            self::assertStringStartsWith('SELECT', ltrim($sql));
        }
    }
}
