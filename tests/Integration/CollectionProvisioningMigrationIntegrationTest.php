<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\TestCase;

/**
 * The PR 6 provisioning-intent migration, against a REAL MySQL.
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * Every claim here is about what the SERVER does: whether a column really
 * got the default it was declared with, whether the composite index is
 * actually used by the queue query, whether a VARCHAR(32) truncates a code,
 * and whether the backfill's join finds the gates it is supposed to. The
 * unit suite's `$wpdb` double answers from a queue regardless of the SQL, so
 * none of those are observable there.
 */
final class CollectionProvisioningMigrationIntegrationTest extends TestCase
{
    private const COLUMNS = [
        'provisioning_state',
        'provisioning_requested_at',
        'provisioning_requested_by',
        'provisioning_failure_code',
    ];

    private const INDEX = 'idx_provisioning_state_id';

    protected function tearDown(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query("DELETE FROM `{$table}`");
        $wpdb->query("DELETE FROM `{$wpdb->postmeta}`");
        $wpdb->query("DELETE FROM `{$wpdb->posts}`");

        parent::tearDown();
    }

    private function column(string $name): ?object
    {
        $wpdb = $GLOBALS['wpdb'];

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            CollectionRepository::table(),
            $name
        ));

        return $row;
    }

    // ── Shape ───────────────────────────────────────────────────────────

    public function testAllFourColumnsExist(): void
    {
        foreach (self::COLUMNS as $name) {
            self::assertNotNull($this->column($name), $name . ' is missing');
        }
    }

    /**
     * `provisioning_state` is NOT NULL with a `'none'` default, so a row
     * inserted by any writer that does not name the column still lands in a
     * legal state rather than NULL — which would satisfy no predicate and
     * fall out of every tab.
     */
    public function testTheStateColumnIsNotNullAndDefaultsToNone(): void
    {
        $col = $this->column('provisioning_state');

        self::assertNotNull($col);
        self::assertSame('NO', $col->IS_NULLABLE);
        self::assertSame('none', $col->COLUMN_DEFAULT);
        self::assertSame(20, (int) $col->CHARACTER_MAXIMUM_LENGTH);
    }

    /**
     * ⚠ The default is verified by INSERTING, not only by reading
     * INFORMATION_SCHEMA.
     *
     * `COLUMN_DEFAULT` is a string column: a genuinely NULL default comes
     * back as the four-character string "NULL", which is indistinguishable
     * from a default OF the literal text. Watching a real insert land is the
     * only reading that cannot be misread.
     */
    public function testAnInsertThatNamesNoProvisioningColumnsLandsInNone(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (contract_address, chain_id, fetched_at, expires_at)
             VALUES (%s, %d, NOW(), NOW())",
            '0x1111111111111111111111111111111111111111',
            1
        ));

        $state = $wpdb->get_var("SELECT provisioning_state FROM `{$table}` ORDER BY id DESC LIMIT 1");

        self::assertSame(ProvisioningState::NONE, $state);
        self::assertTrue(ProvisioningState::isValid((string) $state));
    }

    public function testTheThreeOptionalColumnsAreNullable(): void
    {
        foreach (['provisioning_requested_at', 'provisioning_requested_by', 'provisioning_failure_code'] as $name) {
            $col = $this->column($name);
            self::assertNotNull($col, $name);
            self::assertSame('YES', $col->IS_NULLABLE, $name . ' must be nullable');
        }
    }

    /**
     * The failure column has to hold the LONGEST code in the vocabulary. A
     * code that does not fit would be silently truncated by MySQL into a
     * value the vocabulary does not contain.
     */
    public function testTheFailureColumnFitsEveryCodeInTheVocabulary(): void
    {
        $col = $this->column('provisioning_failure_code');
        self::assertNotNull($col);

        $width = (int) $col->CHARACTER_MAXIMUM_LENGTH;
        self::assertSame(32, $width);
        self::assertLessThanOrEqual($width, ProvisioningFailureCode::maxLength());

        // And prove it by round-tripping the longest one through the server.
        $wpdb    = $GLOBALS['wpdb'];
        $table   = CollectionRepository::table();
        $longest = '';
        foreach (ProvisioningFailureCode::all() as $code) {
            if (strlen($code) > strlen($longest)) {
                $longest = $code;
            }
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (contract_address, chain_id, fetched_at, expires_at, provisioning_failure_code)
             VALUES (%s, %d, NOW(), NOW(), %s)",
            '0x2222222222222222222222222222222222222222',
            1,
            $longest
        ));

        self::assertSame(
            $longest,
            $wpdb->get_var("SELECT provisioning_failure_code FROM `{$table}` ORDER BY id DESC LIMIT 1"),
            'the longest code must survive the column width intact'
        );
    }

    // ── The index, and proof it is the right one ────────────────────────

    public function testTheCompositeIndexExistsInTheDeclaredColumnOrder(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, SEQ_IN_INDEX
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              ORDER BY SEQ_IN_INDEX',
            CollectionRepository::table(),
            self::INDEX
        ));

        self::assertIsArray($rows);
        self::assertCount(2, $rows, self::INDEX . ' must be a two-column index');
        self::assertSame('provisioning_state', $rows[0]->COLUMN_NAME);
        self::assertSame('id', $rows[1]->COLUMN_NAME, 'the cursor column must come SECOND');
    }

    /**
     * ⚠ The index is justified by the QUERY, not by looking plausible.
     *
     * `listRequested()` runs
     *   WHERE provisioning_state = ? AND id > ? ORDER BY id ASC LIMIT ?
     * and `(provisioning_state, id)` serves the equality, the cursor range
     * AND the ordering from one structure. EXPLAIN is asserted rather than
     * assumed: a state-only index would satisfy the equality and then have
     * to sort, and nothing else in the test suite would notice.
     */
    public function testTheQueueQueryActuallyUsesTheCompositeIndex(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        // Enough rows that the optimiser prefers the index over a scan.
        for ($i = 1; $i <= 200; $i++) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}` (contract_address, chain_id, fetched_at, expires_at, provisioning_state)
                 VALUES (%s, %d, NOW(), NOW(), %s)",
                sprintf('0x%040d', $i),
                1,
                $i % 4 === 0 ? ProvisioningState::REQUESTED : ProvisioningState::NONE
            ));
        }
        $wpdb->query("ANALYZE TABLE `{$table}`");

        $plan = $wpdb->get_row($wpdb->prepare(
            "EXPLAIN SELECT id FROM `{$table}`
              WHERE provisioning_state = %s AND id > %d
              ORDER BY id ASC LIMIT %d",
            ProvisioningState::REQUESTED,
            0,
            50
        ));

        self::assertNotNull($plan);
        self::assertSame(
            self::INDEX,
            $plan->key ?? null,
            'the queue query must be served by the composite index'
        );
        self::assertStringNotContainsString(
            'filesort',
            (string) ($plan->Extra ?? ''),
            'the index must supply the ordering, not just the filter'
        );
    }

    // ── Idempotency ─────────────────────────────────────────────────────

    /**
     * The migration has already run once in the bootstrap. Running it again
     * is the path EVERY existing install takes on every schema-version bump,
     * and it must be a clean no-op — not an error, and not a second index.
     */
    public function testRunningTheMigrationAgainChangesNothing(): void
    {
        $before = $this->schemaFingerprint();

        bcc_onchain_add_collections_provisioning_state();
        bcc_onchain_add_collections_provisioning_state();

        self::assertSame($before, $this->schemaFingerprint());
    }

    private function schemaFingerprint(): string
    {
        $wpdb = $GLOBALS['wpdb'];

        $cols = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
              ORDER BY COLUMN_NAME',
            CollectionRepository::table()
        ));

        $idx = $wpdb->get_results($wpdb->prepare(
            'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
              ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            CollectionRepository::table()
        ));

        return (string) json_encode(['columns' => $cols, 'indexes' => $idx]);
    }

    /**
     * The migration must not disturb what PR 5a installed. `uq_chain_contract`
     * staying is deliberate — dropping it is blocked until every legacy alias
     * resolves — and `canonical_identifier` keeps its BINARY collation, which
     * is the whole reason Solana identities survive.
     */
    public function testTheMigrationLeavesTheCanonicalIdentityWorkAlone(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $collation = $wpdb->get_var($wpdb->prepare(
            'SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            CollectionRepository::table(),
            'canonical_identifier'
        ));
        self::assertSame('utf8mb4_bin', $collation);

        foreach (['uq_chain_contract', 'uq_chain_canonical'] as $key) {
            self::assertSame(
                1,
                (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(DISTINCT INDEX_NAME) FROM INFORMATION_SCHEMA.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
                    CollectionRepository::table(),
                    $key
                )),
                $key . ' must survive the PR 6 migration'
            );
        }
    }

    // ── Backfill ────────────────────────────────────────────────────────

    /**
     * Seed a published holder community pointing at a collection.
     *
     * @return int the collection id
     */
    private function seedCollectionWithCommunity(int $postId, string $contract, int $verified = 1): int
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (contract_address, chain_id, fetched_at, expires_at, is_verified)
             VALUES (%s, %d, NOW(), NOW(), %d)",
            $contract,
            1,
            $verified
        ));
        $collectionId = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->posts}` (ID, post_title, post_type, post_status)
             VALUES (%d, %s, %s, %s)",
            $postId,
            'Holders: ' . $contract,
            'peepso-group',
            'publish'
        ));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
            $postId,
            '_bcc_group_kind',
            'holders'
        ));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
            $postId,
            '_bcc_gate_collection_id',
            (string) $collectionId
        ));

        return $collectionId;
    }

    public function testTheBackfillMarksExactlyTheCollectionsThatHaveALiveCommunity(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $withCommunity = $this->seedCollectionWithCommunity(6001, '0x3333333333333333333333333333333333333333');

        // A VERIFIED collection with no community. This is the row the old
        // behaviour would have auto-provisioned, and it must stay `none`.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (contract_address, chain_id, fetched_at, expires_at, is_verified)
             VALUES (%s, %d, NOW(), NOW(), 1)",
            '0x4444444444444444444444444444444444444444',
            1
        ));
        $verifiedNoCommunity = (int) $wpdb->insert_id;

        self::assertTrue(bcc_onchain_backfill_collections_provisioning_state());

        self::assertSame(
            ProvisioningState::PROVISIONED,
            $wpdb->get_var($wpdb->prepare("SELECT provisioning_state FROM `{$table}` WHERE id = %d", $withCommunity))
        );
        self::assertSame(
            ProvisioningState::NONE,
            $wpdb->get_var($wpdb->prepare("SELECT provisioning_state FROM `{$table}` WHERE id = %d", $verifiedNoCommunity)),
            'pre-existing verification must NOT be retro-read as authorization'
        );
    }

    /**
     * The postcondition is a RELATIONSHIP, not a census. Asserting "28" would
     * make the migration wrong the first time anyone adds a community.
     */
    public function testTheBackfillPostconditionIsAShapeNotACount(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $this->seedCollectionWithCommunity(6002, '0x5555555555555555555555555555555555555555');
        $this->seedCollectionWithCommunity(6003, '0x6666666666666666666666666666666666666666');

        self::assertTrue(bcc_onchain_backfill_collections_provisioning_state());

        $provisioned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE provisioning_state = %s",
            ProvisioningState::PROVISIONED
        ));
        $gated = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.meta_value) FROM `{$wpdb->postmeta}` pm
              INNER JOIN `{$wpdb->postmeta}` k ON k.post_id = pm.post_id AND k.meta_key = %s AND k.meta_value = %s
              INNER JOIN `{$wpdb->posts}` p ON p.ID = pm.post_id AND p.post_type = %s AND p.post_status = %s
             WHERE pm.meta_key = %s",
            '_bcc_group_kind',
            'holders',
            'peepso-group',
            'publish',
            '_bcc_gate_collection_id'
        ));

        self::assertSame($gated, $provisioned);
        self::assertGreaterThan(0, $gated, 'the fixture must actually exercise the relationship');
    }

    /** A DRAFT or TRASHED group is not a live community and must not backfill. */
    public function testAnUnpublishedGroupDoesNotCountAsACommunity(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $collectionId = $this->seedCollectionWithCommunity(6004, '0x7777777777777777777777777777777777777777');
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->posts}` SET post_status = %s WHERE ID = %d",
            'trash',
            6004
        ));

        self::assertTrue(bcc_onchain_backfill_collections_provisioning_state());

        self::assertSame(
            ProvisioningState::NONE,
            $wpdb->get_var($wpdb->prepare("SELECT provisioning_state FROM `{$table}` WHERE id = %d", $collectionId)),
            'a trashed group is not a community anyone can reach'
        );
    }

    /** Re-running the backfill is a no-op, not a second pass of writes. */
    public function testTheBackfillIsIdempotent(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $collectionId = $this->seedCollectionWithCommunity(6005, '0x8888888888888888888888888888888888888888');

        self::assertTrue(bcc_onchain_backfill_collections_provisioning_state());

        // An operator then moves the row on. A second backfill pass must not
        // drag it back: the guarded UPDATE only touches rows still on the
        // column default.
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET provisioning_state = %s, provisioning_requested_at = NOW(),
                    provisioning_requested_by = %d, provisioning_failure_code = %s
              WHERE id = %d",
            ProvisioningState::FAILED,
            2,
            ProvisioningFailureCode::GROUP_CREATE_FAILED,
            $collectionId
        ));

        // The postcondition legitimately fails now (a provisioned-marked row
        // is gone), so the return value is not what is asserted — the STATE is.
        bcc_onchain_backfill_collections_provisioning_state();

        self::assertSame(
            ProvisioningState::FAILED,
            $wpdb->get_var($wpdb->prepare("SELECT provisioning_state FROM `{$table}` WHERE id = %d", $collectionId)),
            'a rerun must not overwrite operator state'
        );
    }

    /**
     * A gate whose `_bcc_gate_collection_id` names no collection is an
     * ORPHAN. The backfill must skip it rather than fail or invent a row.
     */
    public function testAnOrphanGateIsSkippedWithoutBreakingTheBackfill(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $real = $this->seedCollectionWithCommunity(6006, '0x9999999999999999999999999999999999999999');

        // A gate pointing at a collection id that does not exist.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->posts}` (ID, post_title, post_type, post_status) VALUES (%d, %s, %s, %s)",
            6007,
            'Orphan',
            'peepso-group',
            'publish'
        ));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
            6007,
            '_bcc_group_kind',
            'holders'
        ));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
            6007,
            '_bcc_gate_collection_id',
            '999999'
        ));

        self::assertTrue(bcc_onchain_backfill_collections_provisioning_state());

        $table = CollectionRepository::table();
        self::assertSame(
            ProvisioningState::PROVISIONED,
            $wpdb->get_var($wpdb->prepare("SELECT provisioning_state FROM `{$table}` WHERE id = %d", $real)),
            'the real gate is still processed'
        );
    }

    // ── The state write, against a real server ──────────────────────────

    /**
     * `setProvisioningState()` puts the expected current state in the WHERE
     * clause, so two operators racing cannot both win. Proven against the
     * server because it is the DATABASE that arbitrates, not PHP.
     */
    public function testAGuardedStateWriteRefusesWhenTheRowHasAlreadyMoved(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (contract_address, chain_id, fetched_at, expires_at, is_verified)
             VALUES (%s, %d, NOW(), NOW(), 1)",
            '0xabcabcabcabcabcabcabcabcabcabcabcabcabca',
            1
        ));
        $id = (int) $wpdb->insert_id;

        self::assertTrue(CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::NONE,
            ProvisioningState::REQUESTED,
            2,
            gmdate('Y-m-d H:i:s')
        ));

        // A second caller still believing the row is `none`.
        self::assertFalse(
            CollectionRepository::setProvisioningState(
                $id,
                ProvisioningState::NONE,
                ProvisioningState::REQUESTED,
                3,
                gmdate('Y-m-d H:i:s')
            ),
            'the loser of a race must be told it lost, not silently overwrite'
        );

        self::assertSame(
            '2',
            $wpdb->get_var($wpdb->prepare("SELECT provisioning_requested_by FROM `{$table}` WHERE id = %d", $id))
        );
    }

    /** An illegal transition is refused before it reaches the server. */
    public function testAnIllegalTransitionIsNeverWritten(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (contract_address, chain_id, fetched_at, expires_at, provisioning_state)
             VALUES (%s, %d, NOW(), NOW(), %s)",
            '0xbcdbcdbcdbcdbcdbcdbcdbcdbcdbcdbcdbcdbcdb',
            1,
            ProvisioningState::PROVISIONED
        ));
        $id = (int) $wpdb->insert_id;

        self::assertFalse(CollectionRepository::setProvisioningState(
            $id,
            ProvisioningState::PROVISIONED,
            ProvisioningState::NONE
        ));

        self::assertSame(
            ProvisioningState::PROVISIONED,
            $wpdb->get_var($wpdb->prepare("SELECT provisioning_state FROM `{$table}` WHERE id = %d", $id))
        );
    }

    /**
     * `withdrawPendingProvisioning()` names the two withdrawable states in
     * its WHERE clause, which is what makes `provisioned` unreachable from
     * it no matter what a caller passes.
     */
    public function testWithdrawalCannotReachAProvisionedRowAtTheSqlLevel(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (contract_address, chain_id, fetched_at, expires_at, provisioning_state)
             VALUES (%s, %d, NOW(), NOW(), %s)",
            '0xcdecdecdecdecdecdecdecdecdecdecdecdecdec',
            1,
            ProvisioningState::PROVISIONED
        ));
        $id = (int) $wpdb->insert_id;

        self::assertSame(0, CollectionRepository::withdrawPendingProvisioning($id));
        self::assertSame(
            ProvisioningState::PROVISIONED,
            $wpdb->get_var($wpdb->prepare("SELECT provisioning_state FROM `{$table}` WHERE id = %d", $id))
        );
    }
}
