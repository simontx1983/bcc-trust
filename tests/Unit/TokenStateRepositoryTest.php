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
            ['tv' => 5, 'denylist' => ['abc' => 1900000000], 'retired' => ['def' => ['from' => 1, 'until' => 2]]],
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
        self::assertSame(['tv' => 0, 'denylist' => '', 'retired' => ''], TokenStateRepository::read(self::USER));
    }

    /** As get_user_meta(…, true): the first row (lowest umeta_id) of a duplicated key. */
    public function testTheFirstRowOfADuplicatedKeyWins(): void
    {
        $this->db->put(self::USER, 'bcc_token_version', 3);
        $this->db->put(self::USER, 'bcc_token_version', 9);

        self::assertSame(3, TokenStateRepository::read(self::USER)['tv'] ?? null);
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
