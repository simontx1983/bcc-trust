<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use PHPUnit\Framework\TestCase;

/**
 * PR 5a canonical collection identity, against a REAL MySQL.
 *
 * Everything here depends on behaviour the unit suite structurally cannot
 * observe: collation, unique-key enforcement, and what MySQL does with
 * NULLs in a unique index. The unit suite's `$wpdb` double returns queued
 * fixtures regardless of the query text, so a claim about a collation is
 * meaningless there.
 *
 * NOTE ON THE TRANSITIONAL SHAPE: this PR does NOT drop
 * `uq_chain_contract`. The case-distinct Solana pair therefore still
 * cannot coexist in the production table — see
 * {@see testTheTransitionalTableStillBlocksCaseDistinctSolanaPairs}, which
 * pins that as the INTENDED result rather than a missing feature.
 */
final class CollectionCanonicalIdentityIntegrationTest extends TestCase
{
    private const COLUMN     = 'canonical_identifier';
    private const NEW_KEY    = 'uq_chain_canonical';
    private const OLD_KEY    = 'uq_chain_contract';

    // Two valid Solana mints differing ONLY by trailing case.
    private const SOL_UPPER = 'J1S9H3QjnRtBbbuD4HjPV6RpRhwuk4zKbxsnCHuTgh9w';
    private const SOL_LOWER = 'J1S9H3QjnRtBbbuD4HjPV6RpRhwuk4zKbxsnCHuTGH9W';

    private const EVM_CHECKSUMMED = '0x6e60bCdF52078A250932CF9FeC174c5F67348845';

    /** @var array<string, int> observed once, before any test mutates state */
    private static array $asInstalled = [];

    public static function setUpBeforeClass(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        self::$asInstalled = [
            'collections' => (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . CollectionRepository::table() . '`'),
            'verified'    => (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM `' . CollectionRepository::table() . '` WHERE is_verified = 1'
            ),
        ];
    }

    protected function tearDown(): void
    {
        // Each test seeds its own rows; the throwaway DB persists across the
        // whole run, so clean up rather than leak into the next test.
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CollectionRepository::table() . '`');
        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function chainId(string $slug): int
    {
        $id = ChainRepository::resolveIdAnyState($slug);
        self::assertIsInt($id, "fixture chain '{$slug}' must exist");
        self::assertGreaterThan(0, $id);

        return $id;
    }

    /** Raw INSERT that bypasses the repository — used to stage legacy rows. */
    private function insertRaw(int $chainId, string $contract, ?string $canonical, int $verified = 0): bool
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $canonSql = $canonical === null ? 'NULL' : $wpdb->prepare('%s', $canonical);

        $wpdb->suppress_errors(true);
        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (wallet_link_id, contract_address, canonical_identifier, chain_id,
                 is_verified, show_on_profile, source, fetched_at, expires_at)
             VALUES (NULL, %s, {$canonSql}, %d, %d, 1, 'toplist', %s, %s)",
            $contract,
            $chainId,
            $verified,
            '2026-08-30 00:00:00',
            '2027-08-30 00:00:00'
        ));
        $wpdb->suppress_errors(false);

        return $ok !== false;
    }

    /** @return list<string> ordered column names of an index */
    private function indexColumns(string $indexName): array
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        /** @var list<object{Key_name: string, Column_name: string, Seq_in_index: string}> $rows */
        $rows = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");

        $ordered = [];
        foreach ($rows as $row) {
            $ordered[(int) $row->Seq_in_index] = (string) $row->Column_name;
        }
        ksort($ordered);

        return array_values($ordered);
    }

    // ── (1) schema shape ────────────────────────────────────────────────

    /**
     * The collation is the entire mechanism. The throwaway test database is
     * created `utf8mb4_unicode_ci` and the table default is likewise
     * case-insensitive, so a column that INHERITED its collation would look
     * present and correct while still folding case. It must be DECLARED.
     */
    public function testTheCanonicalColumnIsBinaryCollated(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        /** @var object{DATA_TYPE: string, IS_NULLABLE: string, COLLATION_NAME: string|null, CHARACTER_MAXIMUM_LENGTH: string|null}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT DATA_TYPE, IS_NULLABLE, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
              LIMIT 1',
            CollectionRepository::table(),
            self::COLUMN
        ));

        self::assertNotNull($row, 'canonical_identifier must exist after the migration');
        self::assertSame('varchar', strtolower((string) $row->DATA_TYPE));
        self::assertSame(128, (int) $row->CHARACTER_MAXIMUM_LENGTH);
        self::assertSame(
            'utf8mb4_bin',
            (string) $row->COLLATION_NAME,
            'an inherited _ci collation would silently case-fold Solana mints'
        );
        self::assertSame(
            'YES',
            strtoupper((string) $row->IS_NULLABLE),
            'NULL is how the 99 legacy alias rows survive PR 5a; NOT NULL is PR 5b'
        );
    }

    public function testTheNewUniqueKeyHasExactlyTheIntendedColumnsInOrder(): void
    {
        self::assertSame(['chain_id', self::COLUMN], $this->indexColumns(self::NEW_KEY));

        $wpdb     = $GLOBALS['wpdb'];
        $nonUnique = $wpdb->get_var(
            "SHOW INDEX FROM `" . CollectionRepository::table() . "` WHERE Key_name = '" . self::NEW_KEY . "'"
        );
        // Non_unique is column 2 of SHOW INDEX; assert via information_schema
        // instead so the assertion does not depend on column ordering.
        $isUnique = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              LIMIT 1',
            CollectionRepository::table(),
            self::NEW_KEY
        ));
        self::assertSame(0, $isUnique, 'uq_chain_canonical must be UNIQUE');
        self::assertNotNull($nonUnique);
    }

    /**
     * PR 5a deliberately keeps the old key. Losing it here would remove the
     * only uniqueness constraint covering the NULL-canonical legacy rows,
     * and would strand the four ON DUPLICATE KEY UPDATE writers.
     */
    public function testTheOldCaseInsensitiveUniqueKeyIsStillPresent(): void
    {
        self::assertSame(['chain_id', 'contract_address'], $this->indexColumns(self::OLD_KEY));
    }

    // ── (2) the binary key really is case-sensitive ─────────────────────

    /**
     * The headline mechanism, proven on the real key in isolation from
     * `uq_chain_contract`: a UNIQUE index over a utf8mb4_bin column accepts
     * two values differing only by case.
     *
     * This is what PR 5b unlocks once the old key can be dropped.
     */
    public function testTheBinaryCanonicalKeyAcceptsTwoCaseDistinctSolanaMints(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $probe = 'wp_bcc_canonical_key_probe';

        $wpdb->query("DROP TABLE IF EXISTS `{$probe}`");
        $wpdb->query(
            "CREATE TABLE `{$probe}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                chain_id BIGINT UNSIGNED NOT NULL,
                canonical_identifier VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_chain_canonical (chain_id, canonical_identifier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci"
        );

        try {
            $first = $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$probe}` (chain_id, canonical_identifier) VALUES (%d, %s)",
                13,
                self::SOL_UPPER
            ));
            self::assertNotFalse($first);

            $wpdb->suppress_errors(true);
            $second = $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$probe}` (chain_id, canonical_identifier) VALUES (%d, %s)",
                13,
                self::SOL_LOWER
            ));
            $wpdb->suppress_errors(false);

            self::assertNotFalse(
                $second,
                'a binary-collated unique key MUST accept two mints differing only by case'
            );
            self::assertSame(
                2,
                (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$probe}`"),
                'both mints must coexist as two rows'
            );

            // And the stored bytes survive exactly.
            $stored = $wpdb->get_col("SELECT canonical_identifier FROM `{$probe}` ORDER BY id");
            self::assertSame([self::SOL_UPPER, self::SOL_LOWER], $stored);
        } finally {
            $wpdb->query("DROP TABLE IF EXISTS `{$probe}`");
        }
    }

    /**
     * The transitional truth, pinned so it cannot be mistaken for a bug or
     * silently "fixed" without the legacy data work.
     *
     * `uq_chain_contract` is case-INSENSITIVE, so in the real table the
     * second mint is still rejected. That is EXPECTED in PR 5a. PR 5b drops
     * the old key once the 99 legacy aliases are resolved, at which point
     * the probe above becomes the production behaviour.
     */
    public function testTheTransitionalTableStillBlocksCaseDistinctSolanaPairs(): void
    {
        $chainId = $this->chainId('solana');

        self::assertTrue(
            $this->insertRaw($chainId, self::SOL_UPPER, self::SOL_UPPER),
            'the first mint must insert'
        );

        $second = $this->insertRaw($chainId, self::SOL_LOWER, self::SOL_LOWER);

        self::assertFalse(
            $second,
            'INTENDED transitional result: uq_chain_contract (case-insensitive) still rejects the pair. '
            . 'PR 5b drops that key; PR 5a only installs and proves the mechanism.'
        );
        self::assertSame(
            1,
            (int) $GLOBALS['wpdb']->get_var(
                'SELECT COUNT(*) FROM `' . CollectionRepository::table() . '`'
            )
        );
    }

    // ── (3) exact Solana case survives write -> read ────────────────────

    public function testSolanaCaseSurvivesAWriteAndRead(): void
    {
        $chainId = $this->chainId('solana');

        $result = CollectionRepository::upsert(
            ['chain_id' => $chainId, 'contract_address' => self::SOL_UPPER],
            0
        );

        self::assertSame('created', $result['status']);

        $row = CollectionRepository::findByChainContract($chainId, self::SOL_UPPER);
        self::assertNotNull($row, 'the mint must be findable by its exact case');
        self::assertSame(self::SOL_UPPER, (string) $row->contract_address, 'display value preserved verbatim');
        self::assertSame(self::SOL_UPPER, (string) $row->canonical_identifier, 'canonical preserved byte-for-byte');
    }

    // ── (4) EVM / Cosmos variants converge ──────────────────────────────

    /**
     * The one live EVM row is checksummed, so this is not hypothetical:
     * `contract_address` keeps the mixed case a human recognises, while
     * `canonical_identifier` carries the lowercase form used to compare.
     */
    public function testEvmCaseVariantsConvergeAndDisplayValueIsPreserved(): void
    {
        $chainId = $this->chainId('ethereum');

        $created = CollectionRepository::upsert(
            ['chain_id' => $chainId, 'contract_address' => self::EVM_CHECKSUMMED],
            0
        );
        self::assertSame('created', $created['status']);

        // A second write with a different case must UPDATE, not insert.
        $again = CollectionRepository::upsert(
            ['chain_id' => $chainId, 'contract_address' => strtolower(self::EVM_CHECKSUMMED)],
            0
        );
        self::assertSame('updated', $again['status']);
        self::assertSame($created['id'], $again['id'], 'both case variants are one collection');

        self::assertSame(
            1,
            (int) $GLOBALS['wpdb']->get_var(
                'SELECT COUNT(*) FROM `' . CollectionRepository::table() . '`'
            ),
            'EVM case variants must never become two rows'
        );

        $row = CollectionRepository::findByChainContract($chainId, self::EVM_CHECKSUMMED);
        self::assertNotNull($row);
        self::assertSame(self::EVM_CHECKSUMMED, (string) $row->contract_address, 'original text preserved');
        self::assertSame(strtolower(self::EVM_CHECKSUMMED), (string) $row->canonical_identifier);

        // ...and the lowercase spelling resolves to the same row.
        $viaLower = CollectionRepository::findByChainContract($chainId, strtolower(self::EVM_CHECKSUMMED));
        self::assertNotNull($viaLower);
        self::assertSame((int) $row->id, (int) $viaLower->id);
    }

    public function testCosmosCaseVariantsConvergeToOneCanonicalIdentity(): void
    {
        $chainId  = $this->chainId('cosmos');
        $bech32   = 'cosmos1qypqxpq9qcrsszg2pvxq6rs0zqg3yyc5lzv7xu';

        $created = CollectionRepository::addManual([
            'chain_id'         => $chainId,
            'contract_address' => $bech32,
        ]);
        self::assertIsInt($created);

        // bech32 permits an all-uppercase encoding of the same address.
        $again = CollectionRepository::addManual([
            'chain_id'         => $chainId,
            'contract_address' => strtoupper($bech32),
        ]);
        self::assertIsInt($again);

        self::assertSame(
            1,
            (int) $GLOBALS['wpdb']->get_var(
                'SELECT COUNT(*) FROM `' . CollectionRepository::table() . '`'
            ),
            'bech32 case variants must never become two rows'
        );

        $row = CollectionRepository::findByChainContract($chainId, $bech32);
        self::assertNotNull($row);
        self::assertSame($bech32, (string) $row->canonical_identifier);
    }

    // ── (5) same identifier on two chains ───────────────────────────────

    public function testTheSameCanonicalIdentifierCanExistOnDifferentChains(): void
    {
        $eth  = $this->chainId('ethereum');
        $poly = $this->chainId('polygon');

        self::assertNotSame($eth, $poly);

        $a = CollectionRepository::upsert(['chain_id' => $eth, 'contract_address' => self::EVM_CHECKSUMMED], 0);
        $b = CollectionRepository::upsert(['chain_id' => $poly, 'contract_address' => self::EVM_CHECKSUMMED], 0);

        self::assertSame('created', $a['status']);
        self::assertSame('created', $b['status'], 'chain is part of identity — the same contract on two chains is two rows');
        self::assertNotSame($a['id'], $b['id']);
        self::assertSame(
            2,
            (int) $GLOBALS['wpdb']->get_var(
                'SELECT COUNT(*) FROM `' . CollectionRepository::table() . '`'
            )
        );
    }

    // ── (6) marketplace aliases cannot become identity ──────────────────

    public function testAMagicEdenSymbolCannotCreateACollection(): void
    {
        $chainId = $this->chainId('solana');

        $viaUpsert = CollectionRepository::upsert(
            ['chain_id' => $chainId, 'contract_address' => 'mad_lads'],
            0
        );
        self::assertSame('failed', $viaUpsert['status']);

        $viaManual = CollectionRepository::addManual([
            'chain_id'         => $chainId,
            'contract_address' => 'okay_bears',
        ]);
        self::assertFalse($viaManual);

        $viaBulk = CollectionRepository::bulkUpsert([
            ['chain_id' => $chainId, 'contract_address' => 'froganas'],
            ['chain_id' => $chainId, 'contract_address' => 'smb_gen3'],
        ]);
        self::assertSame(0, $viaBulk, 'a curated feed of symbols must write nothing');

        self::assertSame(
            0,
            (int) $GLOBALS['wpdb']->get_var(
                'SELECT COUNT(*) FROM `' . CollectionRepository::table() . '`'
            ),
            'not one alias row may be created'
        );
    }

    /**
     * A feed carrying one bad entry must not discard the good ones — the
     * refusal is per row, not per batch.
     */
    public function testBulkUpsertSkipsRefusedRowsAndStillWritesTheValidOnes(): void
    {
        $solana = $this->chainId('solana');

        $written = CollectionRepository::bulkUpsert([
            ['chain_id' => $solana, 'contract_address' => 'mad_lads'],      // alias
            ['chain_id' => $solana, 'contract_address' => self::SOL_UPPER], // real mint
        ]);

        self::assertSame(1, $written);
        self::assertSame(
            1,
            (int) $GLOBALS['wpdb']->get_var(
                'SELECT COUNT(*) FROM `' . CollectionRepository::table() . '`'
            )
        );
    }

    public function testNoWriterCanInsertANullCanonicalIdentity(): void
    {
        $solana = $this->chainId('solana');
        $table  = CollectionRepository::table();

        CollectionRepository::upsert(['chain_id' => $solana, 'contract_address' => 'mad_lads'], 0);
        CollectionRepository::bulkUpsert([['chain_id' => $solana, 'contract_address' => 'mad_lads']]);
        CollectionRepository::addManual(['chain_id' => $solana, 'contract_address' => 'mad_lads']);
        CollectionRepository::ensureExistsBatch([
            ['chain_id' => $solana, 'contract_address' => 'mad_lads', 'token_standard' => 'Metaplex'],
        ]);

        self::assertSame(
            0,
            (int) $GLOBALS['wpdb']->get_var(
                "SELECT COUNT(*) FROM `{$table}` WHERE canonical_identifier IS NULL"
            ),
            'all four ON DUPLICATE KEY UPDATE writers must refuse a NULL canonical identity'
        );
    }

    // ── (7) legacy rows survive, and stay reachable by name ─────────────

    /**
     * The 99 pre-PR-5a alias rows, in miniature: present, verified,
     * NULL-canonical, invisible to strict lookup, reachable only through
     * the explicitly-named legacy method.
     */
    public function testLegacyAliasRowsSurviveAndAreReachableOnlyByTheLegacyMethod(): void
    {
        $chainId = $this->chainId('solana');

        self::assertTrue($this->insertRaw($chainId, 'smb_gen3', null, 1), 'stage a verified legacy alias');

        // Still there, still verified, still NULL-canonical.
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"));
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE is_verified = 1"));
        self::assertSame(
            1,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE canonical_identifier IS NULL")
        );

        // Strict lookup must NOT find it — an alias is not a mint.
        self::assertNull(
            CollectionRepository::findByChainContract($chainId, 'smb_gen3'),
            'canonical lookup must never resolve a marketplace alias'
        );

        // The named legacy path still reaches it, so nothing disappears.
        // (Its return shape is CollectionWithChain, which carries no
        // is_verified — verification is asserted against the table above.)
        $legacy = CollectionRepository::findLegacyByChainContractInsensitive($chainId, 'smb_gen3');
        self::assertNotNull($legacy, 'verified legacy rows must remain reachable');
        self::assertSame('smb_gen3', (string) $legacy->contract_address);
        self::assertNull($legacy->canonical_identifier, 'a legacy alias has no canonical identity');
    }

    /** ID- and list-based surfaces are untouched by canonical semantics. */
    public function testLegacyRowsRemainVisibleThroughIdAndListPaths(): void
    {
        $chainId = $this->chainId('solana');
        $wpdb    = $GLOBALS['wpdb'];

        self::assertTrue($this->insertRaw($chainId, 'aurory', null, 1));
        $id = (int) $wpdb->get_var(
            'SELECT id FROM `' . CollectionRepository::table() . '` WHERE contract_address = "aurory" LIMIT 1'
        );
        self::assertGreaterThan(0, $id);

        self::assertNotEmpty(CollectionRepository::findManyByIds([$id]), 'ID lookup must still work');
        self::assertNotNull(CollectionRepository::getByIdWithChain($id), 'ID+chain lookup must still work');

        $verified = CollectionRepository::listVerified(50);
        self::assertNotEmpty($verified, 'a verified legacy row must still appear in the verified list');

        $counts = CollectionRepository::countByVerification();
        self::assertSame(1, $counts['verified']);
    }

    // ── (8) migration re-run is idempotent ──────────────────────────────

    /**
     * Idempotency is asserted on the Logger, not `$wpdb->last_error`: that
     * property is overwritten by every subsequent query — including the
     * INFORMATION_SCHEMA probes the migration itself runs at the end — so
     * asserting it is empty afterwards would pass no matter what happened.
     */
    public function testRerunningTheMigrationIsIdempotentAndPreservesRows(): void
    {
        $chainId = $this->chainId('solana');
        self::assertTrue($this->insertRaw($chainId, 'legacy_alias', null, 1));
        self::assertTrue($this->insertRaw($chainId, self::SOL_UPPER, self::SOL_UPPER, 0));

        $wpdb   = $GLOBALS['wpdb'];
        $table  = CollectionRepository::table();
        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $beforeVerified = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE is_verified = 1");

        \BCC\Core\Log\Logger::reset();
        bcc_onchain_add_collections_canonical_identifier();
        bcc_onchain_add_collections_canonical_identifier();

        $errors = array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $l): bool => $l['level'] === 'error'
        ));
        self::assertSame([], $errors, 'a re-run must log no error: ' . json_encode($errors));

        self::assertSame($before, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"), 'no row lost or added');
        self::assertSame(
            $beforeVerified,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE is_verified = 1"),
            'verification state must be untouched'
        );

        // The alias stayed NULL; the mint kept its exact case.
        self::assertSame(
            1,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE canonical_identifier IS NULL")
        );
        self::assertSame(
            self::SOL_UPPER,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT canonical_identifier FROM `{$table}` WHERE contract_address = %s",
                self::SOL_UPPER
            ))
        );

        // Both keys still present after a re-run.
        self::assertSame(['chain_id', self::COLUMN], $this->indexColumns(self::NEW_KEY));
        self::assertSame(['chain_id', 'contract_address'], $this->indexColumns(self::OLD_KEY));
    }

    /**
     * Stage the genuine pre-PR-5a shape — column absent — and prove the
     * migration adds it back, backfills what it can, preserves every row,
     * and leaves the refused ones alone.
     */
    public function testMigratingFromThePrePrFiveSchemaPreservesEveryRow(): void
    {
        $wpdb   = $GLOBALS['wpdb'];
        $table  = CollectionRepository::table();
        $solana = $this->chainId('solana');
        $eth    = $this->chainId('ethereum');

        self::assertTrue($this->insertRaw($solana, 'legacy_alias', null, 1));
        self::assertTrue($this->insertRaw($solana, self::SOL_UPPER, null, 0));
        self::assertTrue($this->insertRaw($eth, self::EVM_CHECKSUMMED, null, 0));

        $rowsBefore     = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $verifiedBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE is_verified = 1");

        // Reproduce the pre-migration shape.
        $wpdb->query("ALTER TABLE `{$table}` DROP INDEX " . self::NEW_KEY);
        $wpdb->query("ALTER TABLE `{$table}` DROP COLUMN " . self::COLUMN);

        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                self::COLUMN
            )),
            'precondition: the column is gone'
        );

        \BCC\Core\Log\Logger::reset();
        bcc_onchain_add_collections_canonical_identifier();

        $errors = array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $l): bool => $l['level'] === 'error'
        ));
        self::assertSame([], $errors, 'migration logged an error: ' . json_encode($errors));

        // Every row preserved, verification untouched.
        self::assertSame($rowsBefore, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"));
        self::assertSame(
            $verifiedBefore,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE is_verified = 1")
        );

        // Backfill outcome: the two real identifiers filled, the alias NULL.
        self::assertSame(
            2,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE canonical_identifier IS NOT NULL")
        );
        self::assertSame(
            1,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE canonical_identifier IS NULL")
        );

        // EVM lowercased, Solana byte-exact, display values untouched.
        self::assertSame(
            strtolower(self::EVM_CHECKSUMMED),
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT canonical_identifier FROM `{$table}` WHERE contract_address = %s",
                self::EVM_CHECKSUMMED
            ))
        );
        self::assertSame(
            self::SOL_UPPER,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT canonical_identifier FROM `{$table}` WHERE contract_address = %s",
                self::SOL_UPPER
            ))
        );
        self::assertSame(
            self::EVM_CHECKSUMMED,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT contract_address FROM `{$table}` WHERE chain_id = %d LIMIT 1",
                $eth
            )),
            'the original/display identifier is never rewritten'
        );

        // Fresh-install and upgrade converge: both keys present either way.
        self::assertSame(['chain_id', self::COLUMN], $this->indexColumns(self::NEW_KEY));
        self::assertSame(['chain_id', 'contract_address'], $this->indexColumns(self::OLD_KEY));
    }

    // ── (9) nothing else moved ──────────────────────────────────────────

    /**
     * The migration is a schema change and must have no side effects on the
     * capability control plane — no row seeded, nothing enabled.
     */
    public function testTheMigrationEnablesNoCapabilityAndSeedsNothing(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $capabilityRows = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM `' . \BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository::table() . '`'
        );

        \BCC\Core\Log\Logger::reset();
        bcc_onchain_add_collections_canonical_identifier();

        self::assertSame(
            $capabilityRows,
            (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM `' . \BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository::table() . '`'
            ),
            'the identity migration must not touch the capability table'
        );

        $chains = ChainRepository::table();
        self::assertSame(
            0,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$chains}` WHERE bcc_supports_nft_collections = 1
                                    OR manual_collection_discovery_enabled = 1
                                    OR cosmwasm_nft_discovery_enabled = 1"),
            'no discovery flag may be turned on'
        );
    }

    public function testTheFixtureDatabaseStartedEmptyOfCollections(): void
    {
        // Documents the baseline the other assertions are measured against.
        self::assertSame(0, self::$asInstalled['collections']);
        self::assertSame(0, self::$asInstalled['verified']);
    }
}
