<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\TokenStateRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-462: the REAL TokenStateRepository, through bcc-core's ProbesReads,
 * over the $wpdb double in tests/Stubs/token-state-wpdb-stubs.php.
 *
 * A failed read must be null, never "tv 0, empty lists": that is the
 * poisoned meta-cache answer this repository replaces, and it accepts
 * revoked, signed-out and retired tokens.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TokenStateRepositoryTest extends TestCase
{
    private const USER = 7;

    private \TokenStateWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/token-state-wpdb-stubs.php';
        $this->db = new \TokenStateWpdb();
        $GLOBALS['wpdb'] = $this->db;
    }

    public function testItReadsTheThreeValuesInOneQuery(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5);
        $this->db->put(self::USER, 'bcc_jwt_denylist', ['abc' => 1900000000]);
        $this->db->put(self::USER, 'bcc_jwt_retired', ['def' => ['from' => 1, 'until' => 2]]);
        $this->db->put(self::USER, 'bcc_handle', 'alice');

        self::assertSame(
            ['tv' => 5, 'denylist' => [['abc' => 1900000000]], 'retired' => [['def' => ['from' => 1, 'until' => 2]]]],
            TokenStateRepository::read(self::USER)
        );
        self::assertCount(1, $this->db->queries);
    }

    public function testTheQueryIsBoundedAndNamesItsColumns(): void
    {
        TokenStateRepository::read(self::USER);

        $sql = (string) preg_replace('/\s+/', ' ', $this->db->queries[0]);
        self::assertStringContainsString('SELECT meta_key, meta_value FROM wp_usermeta', $sql);
        self::assertStringNotContainsString('SELECT *', $sql);
        self::assertStringContainsString('WHERE user_id = 7', $sql);
        self::assertStringContainsString("meta_key IN ('bcc_token_version', 'bcc_jwt_denylist', 'bcc_jwt_retired')", $sql);
        self::assertStringContainsString('ORDER BY umeta_id ASC', $sql);
        self::assertMatchesRegularExpression('/LIMIT \d+$/', trim($sql));
    }

    public function testAnotherUsersRowsAreNotRead(): void
    {
        $this->db->put(8, 'bcc_token_version', 9);

        self::assertSame(0, TokenStateRepository::read(self::USER)['tv'] ?? null);
    }

    /** A real "no rows" is the right answer for a user never revoked. */
    public function testNoRowsIsTvZeroAndEmptyLists(): void
    {
        self::assertSame(['tv' => 0, 'denylist' => [], 'retired' => []], TokenStateRepository::read(self::USER));
    }

    /**
     * F-495: update_metadata() INSERTS a second row when its look-up of the
     * existing one fails. The revocation is in that newer row, so the first
     * row (get_user_meta(…, true)'s answer) must not win: the highest does,
     * whichever row it is in.
     */
    public function testADuplicatedVersionReadsAsTheHighest(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 3);
        $this->db->put(self::USER, 'bcc_token_version', 9);
        $this->db->put(self::USER, 'bcc_token_version', 4);

        self::assertSame(9, TokenStateRepository::read(self::USER)['tv'] ?? null);
    }

    /** F-495: every stored list comes back, oldest row first; JwtToken takes the union. */
    public function testEveryRowOfADuplicatedListComesBack(): void
    {
        $this->db->put(self::USER, 'bcc_jwt_denylist', ['old' => 1900000000]);
        $this->db->put(self::USER, 'bcc_jwt_retired', ['r1' => ['from' => 1, 'until' => 2]]);
        $this->db->put(self::USER, 'bcc_jwt_denylist', ['new' => 1900000001]);

        $state = TokenStateRepository::read(self::USER);

        self::assertSame([['old' => 1900000000], ['new' => 1900000001]], $state['denylist'] ?? null);
        self::assertSame([['r1' => ['from' => 1, 'until' => 2]]], $state['retired'] ?? null);
    }

    /**
     * F-495: a FULL read may have left rows out (the tv row among them, if
     * older list rows stacked up), so it is unknown, not a partial answer.
     */
    public function testAFullReadIsNull(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->db->put(self::USER, 'bcc_jwt_denylist', ['h' . $i => 1900000000]);
        }
        $this->db->put(self::USER, 'bcc_token_version', 6);

        self::assertNull(TokenStateRepository::read(self::USER));
    }

    /** One row under the cap is still a whole answer. */
    public function testOneRowUnderTheCapIsRead(): void
    {
        for ($i = 0; $i < 28; $i++) {
            $this->db->put(self::USER, 'bcc_jwt_denylist', ['h' . $i => 1900000000]);
        }
        $this->db->put(self::USER, 'bcc_token_version', 6);

        $state = TokenStateRepository::read(self::USER);

        self::assertSame(6, $state['tv'] ?? null);
        self::assertCount(28, $state['denylist'] ?? []);
    }

    /**
     * ⚠ The pre-flush trap: query() bails BEFORE flush(), so last_result
     * still holds an earlier query's rows. Trusting them would answer
     * someone else's state, or "never revoked".
     */
    public function testAQueryThatReturnsFalseWithStaleRowsIsNull(): void
    {
        $this->db->last_result = [(object) ['meta_key' => 'bcc_token_version', 'meta_value' => '0']];
        $this->db->mode = 'false_early';

        self::assertNull(TokenStateRepository::read(self::USER));
    }

    public function testADriverErrorIsNull(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5);
        $this->db->mode = 'error';

        self::assertNull(TokenStateRepository::read(self::USER));
    }

    public function testAnErrorBesideRowsIsNull(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5);
        $this->db->mode = 'error_rows';

        self::assertNull(TokenStateRepository::read(self::USER));
    }

    /** An unrelated earlier failure in the request must not fail this read. */
    public function testAnEarlierErrorDoesNotFailTheRead(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 5);
        $this->db->last_error = 'an earlier query failed';

        self::assertSame(5, TokenStateRepository::read(self::USER)['tv'] ?? null);
    }
}
