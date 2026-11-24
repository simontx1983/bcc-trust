<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\PeepSoChatPrefs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * H6: PeepSoChatPrefs gives PeepSoChatModel's answers from a TRUSTED read
 * and never writes.
 *
 * PeepSoChatModel::chat_enabled() (peepso-messages classes/chatmodel.php)
 * writes a default when its get_user_meta() read is empty, and a failed
 * meta read is empty too. The reader must:
 *   - answer exactly what PeepSo would for every stored value. The grid
 *     below checks it against PeepSo's OWN expression, evaluated by PHP,
 *     not against a restatement of it;
 *   - answer null, never a default, when the read failed, including the
 *     pre-flush stale-rows case;
 *   - only ever SELECT.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoChatPrefsTest extends TestCase
{
    private const USER = 7;

    private \TokenStateWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/token-state-wpdb-stubs.php';
        require_once __DIR__ . '/../Stubs/peepso-chat-options-stubs.php';
        $this->db = new \TokenStateWpdb();
        $GLOBALS['wpdb'] = $this->db;
    }

    /** @return array<string, array{?string}> */
    public static function storedValues(): array
    {
        return [
            'no row'          => [null],
            'empty'           => [''],
            'on'              => ['1'],
            'off'             => ['0'],
            'not a number'    => ['abc'],
            'out of range'    => ['2'],
            'leading space 0' => [' 0'],
            'trailing space 1'=> ['1 '],
            'decimal 0'       => ['0.0'],
            'decimal 1'       => ['1.0'],
            'negative zero'   => ['-0'],
            'hex-looking'     => ['0x0'],
        ];
    }

    /**
     * chatmodel.php's chat_enabled(), minus the write, with the site option
     * on:
     *   if (!strlen($v) || !in_array($v, array(0, 1))) { $v = 1; }
     *   return 1 == $v;
     * where get_user_meta() gave '' for no row.
     */
    #[DataProvider('storedValues')]
    public function testChatEnabledMatchesPeepSosOwnExpression(?string $stored): void
    {
        $v = $stored ?? '';
        if (!strlen($v) || !in_array($v, [0, 1])) {
            $v = 1;
        }
        $peepso = (1 == $v);

        self::assertSame($peepso, PeepSoChatPrefs::enabledFrom($stored));
    }

    /**
     * chatmodel.php's chat_friends_only() with PeepSoFriends present and the
     * site option at PeepSo's default (1):
     *   $default = FALSE;
     *   if ($v === "") { $default = (1 == get_option('messages_friends_only', 1)); }
     *   return (1 == $v) ? TRUE : $default;
     */
    #[DataProvider('storedValues')]
    public function testFriendsOnlyMatchesPeepSosOwnExpression(?string $stored): void
    {
        require_once __DIR__ . '/../Stubs/peepso-friends-present-stub.php';

        $v       = $stored ?? '';
        $default = false;
        if ($v === '') {
            $default = (1 == \PeepSo::get_option('messages_friends_only', 1));
        }
        $peepso = (1 == $v) ? true : $default;

        self::assertSame($peepso, PeepSoChatPrefs::friendsOnlyFrom($stored));
    }

    public function testTheSiteWideChatSwitchTurnsEveryoneOff(): void
    {
        \PeepSo::$options['messages_chat_enable'] = 0;

        self::assertFalse(PeepSoChatPrefs::enabledFrom('1'));
        self::assertFalse(PeepSoChatPrefs::enabledFrom(null));
    }

    public function testWithoutPeepSoFriendsNobodyIsFriendsOnly(): void
    {
        self::assertFalse(PeepSoChatPrefs::friendsOnlyFrom('1'));
        self::assertFalse(PeepSoChatPrefs::friendsOnlyFrom(null));
    }

    /** MISSING friends-only is the site option, never a hardcoded false. */
    public function testAMissingFriendsOnlyFollowsTheSiteOption(): void
    {
        require_once __DIR__ . '/../Stubs/peepso-friends-present-stub.php';

        self::assertTrue(PeepSoChatPrefs::friendsOnlyFrom(null), 'PeepSo default 1');
        \PeepSo::$options['messages_friends_only'] = 0;
        self::assertFalse(PeepSoChatPrefs::friendsOnlyFrom(null));
        self::assertTrue(PeepSoChatPrefs::friendsOnlyFrom('1'), 'a stored choice beats the option');
    }

    public function testForUsersReadsEveryMemberInOneQuery(): void
    {
        require_once __DIR__ . '/../Stubs/peepso-friends-present-stub.php';
        $this->db->put(7, PeepSoChatPrefs::ENABLED_KEY, '0');
        $this->db->put(8, PeepSoChatPrefs::FRIENDS_ONLY_KEY, '0');

        self::assertSame(
            [
                7 => ['chat_enabled' => false, 'chat_friends_only' => true],
                8 => ['chat_enabled' => true, 'chat_friends_only' => false],
                9 => ['chat_enabled' => true, 'chat_friends_only' => true],
            ],
            PeepSoChatPrefs::forUsers([7, 8, 9])
        );
        self::assertCount(1, $this->db->queries);
    }

    /** @return iterable<string, array{0: list<string>, 1: bool}> */
    public static function duplicatedEnabledRows(): iterable
    {
        yield 'on, then off'     => [['1', '0'], false];
        yield 'off, then on'     => [['0', '1'], false];
        yield 'on, then on'      => [['1', '1'], true];
        yield 'empty, then off'  => [['', '0'], false];
        yield 'junk, then on'    => [['abc', '1'], true];
    }

    /**
     * A key stored twice is read whole, and the strictest row wins: any row
     * that turns DMs off turns them off, whichever row comes first.
     *
     * @param list<string> $rows
     */
    #[DataProvider('duplicatedEnabledRows')]
    public function testADuplicatedChatEnabledKeyIsOffIfAnyRowIsOff(array $rows, bool $expected): void
    {
        foreach ($rows as $value) {
            $this->db->put(self::USER, PeepSoChatPrefs::ENABLED_KEY, $value);
        }

        self::assertSame($expected, PeepSoChatPrefs::forUser(self::USER)['chat_enabled'] ?? null);
    }

    /** @return iterable<string, array{0: list<string>, 1: bool}> */
    public static function duplicatedFriendsOnlyRows(): iterable
    {
        yield 'off, then on'  => [['0', '1'], true];
        yield 'on, then off'  => [['1', '0'], true];
        yield 'off, then off' => [['0', '0'], false];
    }

    /**
     * Any row that turns friends-only ON turns it on, whichever row comes
     * first.
     *
     * @param list<string> $rows
     */
    #[DataProvider('duplicatedFriendsOnlyRows')]
    public function testADuplicatedFriendsOnlyKeyIsOnIfAnyRowIsOn(array $rows, bool $expected): void
    {
        require_once __DIR__ . '/../Stubs/peepso-friends-present-stub.php';
        foreach ($rows as $value) {
            $this->db->put(self::USER, PeepSoChatPrefs::FRIENDS_ONLY_KEY, $value);
        }

        self::assertSame($expected, PeepSoChatPrefs::forUser(self::USER)['chat_friends_only'] ?? null);
    }

    /** No row at all: PeepSo's defaults, and still nothing written. */
    public function testNoRowIsEnabledAndNothingIsWritten(): void
    {
        self::assertSame(
            ['chat_enabled' => true, 'chat_friends_only' => false],
            PeepSoChatPrefs::forUser(self::USER)
        );
        self::assertSame([], $this->db->rows, 'no row was created');
        foreach ($this->db->queries as $sql) {
            self::assertStringStartsWith('SELECT', ltrim($sql));
        }
    }

    public function testAFailedReadIsNullNotEnabled(): void
    {
        $this->db->put(self::USER, PeepSoChatPrefs::ENABLED_KEY, '0');
        $this->db->mode = 'error';

        self::assertNull(PeepSoChatPrefs::forUser(self::USER));
        self::assertNull(PeepSoChatPrefs::forUsers([self::USER, 8]));
    }

    /**
     * ⚠⚠ The pre-flush trap: query() bails before flush(), and last_result
     * still holds an earlier query's rows saying this member's DMs are ON.
     * The member's stored choice is OFF. The answer must be null.
     */
    public function testStaleRowsFromAnEarlierQueryAreNullNotEnabled(): void
    {
        $this->db->put(self::USER, PeepSoChatPrefs::ENABLED_KEY, '0');
        $this->db->last_result = [(object) [
            'user_id'    => (string) self::USER,
            'meta_key'   => PeepSoChatPrefs::ENABLED_KEY,
            'meta_value' => '1',
        ]];
        $this->db->mode = 'false_early';

        self::assertNull(PeepSoChatPrefs::forUser(self::USER));
    }

    public function testANonPositiveIdIsNotRead(): void
    {
        self::assertNull(PeepSoChatPrefs::forUser(0));
        self::assertSame([], PeepSoChatPrefs::forUsers([0, -1]));
        self::assertSame([], $this->db->queries);
    }
}
