<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\TestCase;

/**
 * The three optional provisioning columns must hold genuine SQL NULL.
 *
 * ── WHY THIS NEEDS A REAL SERVER ────────────────────────────────────────
 * `wpdb::prepare()` cannot express SQL NULL: `%d`/`%f` turn a PHP null into
 * `0` and `%s` turns it into `''`. Both are VALUES. This repository already
 * documents the trap on `sqlStringOrNull()` — it cost #212 and #220 — and
 * the fix has to be re-made by every new writer, because nothing in the
 * type system stops the next one binding a nullable through `%s`.
 *
 * Every assertion here goes through `IS NULL` in SQL rather than reading the
 * value back into PHP, because the read path is exactly where the two become
 * indistinguishable: `$row->provisioning_failure_code` is `''` for an empty
 * string and `null` for SQL NULL, and a `(string)` cast or a `?? ''` collapses
 * them. Only the server can tell you which one is actually stored.
 *
 * ── WHY IT MATTERS BEYOND TIDINESS ──────────────────────────────────────
 * `ProvisioningState::fieldViolations()` treats `''` as absent, so a row
 * storing `''` LOOKS consistent to PHP while being wrong in the database.
 * The damage shows up where SQL does the reasoning instead: an operator
 * filtering `WHERE provisioning_failure_code IS NULL` to find healthy rows
 * would silently miss every one of them, and any future index or partial
 * constraint on those columns would be built on fictional values.
 */
final class ProvisioningNullSemanticsIntegrationTest extends TestCase
{
    private const OPERATOR = 2;

    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CollectionRepository::table() . '`');

        parent::tearDown();
    }

    /** @return int the new collection id */
    private function seedCollection(string $contract = '0xnull0000000000000000000000000000000000'): int
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (contract_address, chain_id, fetched_at, expires_at, is_verified)
             VALUES (%s, %d, NOW(), NOW(), 1)",
            $contract,
            1
        ));

        return (int) $wpdb->insert_id;
    }

    /**
     * Ask the SERVER, not PHP. Returns one row of three booleans.
     *
     * @return array{at: bool, by: bool, code: bool}
     */
    private function nullness(int $collectionId): array
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                (provisioning_requested_at IS NULL) AS at_null,
                (provisioning_requested_by IS NULL) AS by_null,
                (provisioning_failure_code IS NULL) AS code_null
               FROM `{$table}` WHERE id = %d",
            $collectionId
        ));

        self::assertNotNull($row, 'the seeded row must exist');

        return [
            'at'   => (int) $row->at_null === 1,
            'by'   => (int) $row->by_null === 1,
            'code' => (int) $row->code_null === 1,
        ];
    }

    /**
     * Belt-and-braces: prove the column does not hold the EMPTY STRING
     * either, so a future change that stores `''` cannot pass by making
     * `IS NULL` true some other way.
     */
    private function assertNotEmptyString(int $collectionId, string $column): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE id = %d AND `{$column}` = ''",
            $collectionId
        ));

        self::assertSame(0, $count, $column . " holds the empty string, not SQL NULL");
    }

    // ── requested: a genuine NULL failure code ──────────────────────────

    public function testARequestedRowHasAGenuinelyNullFailureCode(): void
    {
        $id = $this->seedCollection();

        self::assertTrue(CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::NONE,
            ProvisioningState::REQUESTED,
            self::OPERATOR,
            gmdate('Y-m-d H:i:s'),
            null
        ));

        $n = $this->nullness($id);

        self::assertFalse($n['at'], 'requested carries a timestamp');
        self::assertFalse($n['by'], 'requested carries a requester');
        self::assertTrue($n['code'], 'requested must have a genuine SQL NULL failure code');

        $this->assertNotEmptyString($id, 'provisioning_failure_code');
    }

    // ── failed: a non-NULL bounded code, and the requester preserved ────

    public function testAFailedRowHasANonNullBoundedFailureCode(): void
    {
        $id  = $this->seedCollection();
        $now = gmdate('Y-m-d H:i:s');

        CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::NONE,
            ProvisioningState::REQUESTED,
            self::OPERATOR,
            $now,
            null
        );

        self::assertTrue(CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::REQUESTED,
            ProvisioningState::FAILED,
            self::OPERATOR,
            $now,
            ProvisioningFailureCode::GROUP_CREATE_FAILED
        ));

        $n = $this->nullness($id);

        self::assertFalse($n['code'], 'failed must carry a code');
        self::assertFalse($n['at'], 'the request timestamp is preserved across a failure');
        self::assertFalse($n['by'], 'the requester is preserved across a failure');

        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();
        self::assertSame(
            ProvisioningFailureCode::GROUP_CREATE_FAILED,
            $wpdb->get_var($wpdb->prepare(
                "SELECT provisioning_failure_code FROM `{$table}` WHERE id = %d",
                $id
            ))
        );
    }

    // ── provisioned: a genuine NULL failure code ────────────────────────

    public function testAProvisionedRowHasAGenuinelyNullFailureCode(): void
    {
        $id  = $this->seedCollection();
        $now = gmdate('Y-m-d H:i:s');

        CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::NONE,
            ProvisioningState::REQUESTED,
            self::OPERATOR,
            $now,
            null
        );

        self::assertTrue(CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::REQUESTED,
            ProvisioningState::PROVISIONED,
            self::OPERATOR,
            $now,
            null
        ));

        self::assertTrue($this->nullness($id)['code']);
        $this->assertNotEmptyString($id, 'provisioning_failure_code');
    }

    // ── legacy backfill: NULL requester AND NULL timestamp ──────────────

    /**
     * The migration exemption, proven in the database.
     *
     * The 28 pre-PR-6 communities have no requester because nobody requested
     * them, and writing a plausible one would fabricate an authorization
     * that never happened. `''` and `0` are both fabrications — `0` in
     * particular would read as a user id.
     */
    public function testALegacyBackfilledProvisionedRowKeepsGenuineNullsForBothRequesterFields(): void
    {
        $id  = $this->seedCollection();
        $now = gmdate('Y-m-d H:i:s');

        CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::NONE,
            ProvisioningState::REQUESTED,
            self::OPERATOR,
            $now,
            null
        );

        // The shape the migration produces: provisioned, nobody named.
        self::assertTrue(CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::REQUESTED,
            ProvisioningState::PROVISIONED,
            null,
            null,
            null
        ));

        $n = $this->nullness($id);

        self::assertTrue($n['at'], 'the timestamp must be genuine SQL NULL');
        self::assertTrue($n['by'], 'the requester must be genuine SQL NULL');
        self::assertTrue($n['code']);

        $this->assertNotEmptyString($id, 'provisioning_requested_at');
        $this->assertNotEmptyString($id, 'provisioning_failure_code');

        // `provisioning_requested_by` is BIGINT UNSIGNED: a null bound
        // through %d becomes 0, which is not "absent", it is user zero.
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();
        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE id = %d AND provisioning_requested_by = 0",
                $id
            )),
            'a null requester must not land as the integer 0'
        );
    }

    // ── withdrawal returns all three to genuine NULL ────────────────────

    public function testWithdrawalReturnsAllThreeOptionalFieldsToGenuineNull(): void
    {
        $id  = $this->seedCollection();
        $now = gmdate('Y-m-d H:i:s');

        CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::NONE,
            ProvisioningState::REQUESTED,
            self::OPERATOR,
            $now,
            null
        );
        CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::REQUESTED,
            ProvisioningState::FAILED,
            self::OPERATOR,
            $now,
            ProvisioningFailureCode::AWAITING_METADATA
        );

        self::assertSame(1, CollectionRepository::withdrawPendingProvisioning($id));

        $n = $this->nullness($id);

        self::assertTrue($n['at'], 'withdrawal must clear the timestamp to SQL NULL');
        self::assertTrue($n['by'], 'withdrawal must clear the requester to SQL NULL');
        self::assertTrue($n['code'], 'withdrawal must clear the failure code to SQL NULL');

        $this->assertNotEmptyString($id, 'provisioning_requested_at');
        $this->assertNotEmptyString($id, 'provisioning_failure_code');
    }

    /**
     * And the compare-and-swap protection is NOT weakened by the null fix.
     *
     * The whole point of the guarded UPDATE is that a caller believing a
     * stale state loses the race. A rewrite that composed the WHERE clause
     * as literals instead of placeholders would still pass every test above
     * while opening an injection surface, so the guard is re-asserted here
     * alongside the null semantics it shares a statement with.
     */
    public function testTheCompareAndSwapGuardStillRefusesAStaleExpectedState(): void
    {
        $id  = $this->seedCollection();
        $now = gmdate('Y-m-d H:i:s');

        self::assertTrue(CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::NONE,
            ProvisioningState::REQUESTED,
            self::OPERATOR,
            $now,
            null
        ));

        // A second caller still believing the row is `none`.
        self::assertFalse(CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::NONE,
            ProvisioningState::REQUESTED,
            99,
            $now,
            null
        ));

        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();
        self::assertSame(
            (string) self::OPERATOR,
            $wpdb->get_var($wpdb->prepare(
                "SELECT provisioning_requested_by FROM `{$table}` WHERE id = %d",
                $id
            )),
            'the first writer must win'
        );
    }
}
