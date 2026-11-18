<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\MyMessagesPrefsEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-294: PATCH /me/messages-prefs read its two switches with a bare
 * FILTER_VALIDATE_BOOLEAN, so a value that is not a boolean silently turned
 * the setting OFF and answered 200. It is a 422 now, and BOTH switches are
 * validated before either is written. Against the real patch(); the success
 * path's read-back needs PeepSo's chat model, so only the refusals run here.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MessagesPrefsStrictBoolTest extends TestCase
{
    private const USER = 42;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users'        => [],
            'meta'         => [self::USER => ['peepso_chat_enabled' => '1', 'peepso_chat_friends_only' => '0']],
            'current_user' => self::USER,
        ];
    }

    private static function patch(array $params): \WP_REST_Response
    {
        return (new MyMessagesPrefsEndpoint())->patch(new \WP_REST_Request($params));
    }

    public function testANonBooleanIs422AndLeavesTheSettingAlone(): void
    {
        $res = self::patch(['chat_enabled' => 'maybe']);

        self::assertSame(422, $res->get_status());
        self::assertSame('`chat_enabled` must be true or false.', $res->get_data()['error']['message'] ?? null);
        self::assertSame('1', $GLOBALS['__bcc_ph']['meta'][self::USER]['peepso_chat_enabled'], 'not switched off');
    }

    public function testAValidSwitchIsNotWrittenWhenTheOtherIsInvalid(): void
    {
        $res = self::patch(['chat_enabled' => false, 'chat_friends_only' => 'sometimes']);

        self::assertSame(422, $res->get_status());
        self::assertSame('1', $GLOBALS['__bcc_ph']['meta'][self::USER]['peepso_chat_enabled'], 'validated before any write');
        self::assertSame('0', $GLOBALS['__bcc_ph']['meta'][self::USER]['peepso_chat_friends_only']);
    }

    public function testNoSwitchesIsStillTheNoFieldsError(): void
    {
        $res = self::patch([]);

        self::assertSame(422, $res->get_status());
        self::assertSame('No messages preferences provided.', $res->get_data()['error']['message'] ?? null);
    }
}
