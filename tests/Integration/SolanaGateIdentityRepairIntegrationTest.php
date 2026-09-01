<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Onchain\Repair\SolanaGateIdentityManifest;
use BCC\Trust\Onchain\Repair\SolanaGateIdentityRepairService;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use PHPUnit\Framework\TestCase;

/**
 * The eight-row repair against a REAL MySQL.
 *
 * Everything here depends on behaviour the unit suite structurally cannot
 * observe — row locks, transaction rollback, unique-key enforcement, and
 * what is actually left in the table after a failure. The unit `$wpdb`
 * double returns queued fixtures regardless of query text, so "it rolled
 * back" is not a claim that suite can make.
 *
 * The fixtures reproduce the measured production shape: 28 holder gates,
 * 20 Cosmos + 8 Solana, every gate linked to exactly one collection whose
 * chain matches, no duplicate meta, one community each.
 */
final class SolanaGateIdentityRepairIntegrationTest extends TestCase
{
    private const OPERATOR_ID = 4242;

    /** A genuine 32-byte key, used as a Cosmos-side control's neighbour. */
    private const UNRELATED_MINT = 'So11111111111111111111111111111111111111112';

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateAll();
    }

    protected function tearDown(): void
    {
        $this->truncateAll();
        parent::tearDown();
    }

    private function truncateAll(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CollectionRepository::table() . '`');
        $wpdb->query('DELETE FROM `' . $wpdb->postmeta . '`');
        $wpdb->query('DELETE FROM `' . $wpdb->posts . '`');
        $wpdb->query('DELETE FROM `' . TableRegistry::activity() . '`');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function chainId(string $slug): int
    {
        $id = ChainRepository::resolveIdAnyState($slug);
        self::assertIsInt($id, "fixture chain '{$slug}' must exist");
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function insertCollection(
        int $id,
        int $chainId,
        string $contract,
        ?string $canonical,
        int $verified = 1,
        string $source = 'toplist'
    ): void {
        $wpdb = $GLOBALS['wpdb'];

        $ok = $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . CollectionRepository::table() . '`
                (id, chain_id, contract_address, canonical_identifier, is_verified, source, fetched_at, expires_at)
             VALUES (%d, %d, %s, ' . ($canonical === null ? 'NULL' : '%s') . ', %d, %s, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))',
            ...($canonical === null
                ? [$id, $chainId, $contract, $verified, $source]
                : [$id, $chainId, $contract, $canonical, $verified, $source])
        ));

        self::assertNotFalse($ok, 'collection fixture insert failed: ' . $wpdb->last_error);
    }

    private function insertGate(
        int $postId,
        int $chainId,
        int $collectionId,
        string $gateContract,
        int $minBalance = 1,
        string $postType = 'peepso-group',
        string $postStatus = 'publish'
    ): void {
        $wpdb = $GLOBALS['wpdb'];

        $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . $wpdb->posts . '` (ID, post_title, post_name, post_type, post_status)
             VALUES (%d, %s, %s, %s, %s)',
            $postId,
            'Group ' . $postId,
            'group-' . $postId,
            $postType,
            $postStatus
        ));

        $this->addMeta($postId, GatedGroupRepository::META_KIND, GatedGroupRepository::KIND_HOLDERS);
        $this->addMeta($postId, GatedGroupRepository::META_CHAIN_ID, (string) $chainId);
        $this->addMeta($postId, GatedGroupRepository::META_CONTRACT, $gateContract);
        $this->addMeta($postId, GatedGroupRepository::META_MIN_BAL, (string) $minBalance);
        $this->addMeta($postId, GatedGroupRepository::META_COLLECTION, (string) $collectionId);
    }

    private function addMeta(int $postId, string $key, string $value): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'INSERT INTO `' . $wpdb->postmeta . '` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)',
            $postId,
            $key,
            $value
        ));
    }

    /** Seed all eight manifest rows exactly as production has them. */
    private function seedTheEight(): int
    {
        $solana = $this->chainId('solana');

        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            $this->insertCollection(
                $entry['collection_id'],
                $solana,
                $entry['alias'],
                null,
                $entry['expected_is_verified'],
                $entry['expected_source']
            );
            $this->insertGate(
                $entry['post_id'],
                $solana,
                $entry['collection_id'],
                $entry['alias'],
                $entry['expected_gate_min_balance']
            );
        }

        return $solana;
    }

    /** @return array<string, mixed>|null */
    private function collectionRow(int $id): ?array
    {
        $wpdb = $GLOBALS['wpdb'];
        $row  = $wpdb->get_row($wpdb->prepare(
            'SELECT id, chain_id, contract_address, canonical_identifier, is_verified, source
               FROM `' . CollectionRepository::table() . '` WHERE id = %d',
            $id
        ));

        return $row === null ? null : (array) $row;
    }

    /** @return list<string> */
    private function metaValues(int $postId, string $key): array
    {
        $wpdb = $GLOBALS['wpdb'];

        return array_map('strval', $wpdb->get_col($wpdb->prepare(
            'SELECT meta_value FROM `' . $wpdb->postmeta . '` WHERE post_id = %d AND meta_key = %s ORDER BY meta_id',
            $postId,
            $key
        )));
    }

    private function auditRowCount(): int
    {
        $wpdb = $GLOBALS['wpdb'];

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . TableRegistry::activity() . '` WHERE action = %s',
            SolanaGateIdentityRepairService::AUDIT_ACTION
        ));
    }

    /** @return array<string, string> result keyed by alias */
    private function resultsByAlias(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $out[$r['alias']] = $r['result'];
        }

        return $out;
    }

    // ── dry run ─────────────────────────────────────────────────────────

    public function testDryRunPlansAllEightAndWritesNothing(): void
    {
        $this->seedTheEight();

        $before = $this->snapshot();

        $service = new SolanaGateIdentityRepairService();
        $results = $service->run(false, 0, 'run-dry');

        self::assertCount(8, $results);
        foreach ($results as $r) {
            self::assertSame(
                SolanaGateIdentityRepairService::RESULT_WOULD_REPAIR,
                $r['result'],
                "collection {$r['collection_id']}: {$r['detail']}"
            );
        }

        // Zero writes, zero audit rows.
        self::assertSame($before, $this->snapshot(), 'dry run modified the database');
        self::assertSame(0, $this->auditRowCount(), 'dry run created an audit row');
    }

    // ── apply ───────────────────────────────────────────────────────────

    public function testApplyRepairsAllEightExactly(): void
    {
        $this->seedTheEight();

        $service = new SolanaGateIdentityRepairService();
        $results = $service->run(true, self::OPERATOR_ID, 'run-apply');

        foreach ($results as $r) {
            self::assertSame(
                SolanaGateIdentityRepairService::RESULT_REPAIRED,
                $r['result'],
                "collection {$r['collection_id']}: {$r['detail']}"
            );
        }

        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            $row = $this->collectionRow($entry['collection_id']);
            self::assertNotNull($row);

            // Written, byte-exact, case preserved.
            self::assertSame($entry['new_canonical_identifier'], $row['canonical_identifier']);

            // The legacy alias is UNTOUCHED — the repair adds an identity,
            // it does not rewrite history.
            self::assertSame($entry['alias'], $row['contract_address']);

            // Preserved.
            self::assertSame((string) $entry['expected_is_verified'], (string) $row['is_verified']);
            self::assertSame($entry['expected_source'], $row['source']);

            // Gate meta updated for compatibility; everything else preserved.
            self::assertSame(
                [$entry['new_canonical_identifier']],
                $this->metaValues($entry['post_id'], GatedGroupRepository::META_CONTRACT)
            );
            self::assertSame(
                [(string) $entry['collection_id']],
                $this->metaValues($entry['post_id'], GatedGroupRepository::META_COLLECTION)
            );
            self::assertSame(
                ['1'],
                $this->metaValues($entry['post_id'], GatedGroupRepository::META_MIN_BAL)
            );
        }

        self::assertSame(8, $this->auditRowCount(), 'one audit row per mapping, no more, no fewer');
    }

    public function testCasePreservedThroughStorageAndRetrieval(): void
    {
        $this->seedTheEight();
        (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'run-case');

        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            $expected = $entry['new_canonical_identifier'];

            // Round-trips through the gate config reader unfolded.
            $config = GatedGroupRepository::getGateConfig($entry['post_id']);
            self::assertNotNull($config);
            self::assertSame($expected, $config->contractAddress);

            // And the reverse lookup finds it by the exact identity...
            self::assertSame(
                $entry['post_id'],
                GatedGroupRepository::findGroupForCollection($config->chainId, $expected)
            );

            // ...but NOT by a case-folded copy, which is a different key.
            $folded = strtolower($expected);
            if ($folded !== $expected) {
                self::assertNull(
                    GatedGroupRepository::findGroupForCollection($config->chainId, $folded),
                    'a case-folded mint must not resolve to the gate'
                );
            }
        }
    }

    // ── idempotence ─────────────────────────────────────────────────────

    public function testSecondRunReportsAlreadyAppliedAndWritesNothing(): void
    {
        $this->seedTheEight();

        $service = new SolanaGateIdentityRepairService();
        $service->run(true, self::OPERATOR_ID, 'run-1');

        $afterFirst = $this->snapshot();
        self::assertSame(8, $this->auditRowCount());

        $results = $service->run(true, self::OPERATOR_ID, 'run-2');

        foreach ($results as $r) {
            self::assertSame(SolanaGateIdentityRepairService::RESULT_ALREADY_APPLIED, $r['result']);
        }

        self::assertSame($afterFirst, $this->snapshot(), 'rerun modified the database');
        self::assertSame(8, $this->auditRowCount(), 'rerun created a duplicate audit row');
    }

    // ── refusals ────────────────────────────────────────────────────────

    /**
     * A half-applied row is NOT "already applied". It is a state no
     * successful run can produce, so reporting it as finished would hide
     * the only evidence that something went wrong.
     */
    public function testPartiallyAppliedRowIsRefusedNotTreatedAsApplied(): void
    {
        $solana  = $this->seedTheEight();
        $entries = SolanaGateIdentityManifest::entries();
        $victim  = $entries[0];

        // Canonical written, gate meta still the alias.
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . CollectionRepository::table() . '` SET canonical_identifier = %s WHERE id = %d',
            $victim['new_canonical_identifier'],
            $victim['collection_id']
        ));

        $results = (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'run-partial');
        $byAlias = $this->resultsByAlias($results);

        self::assertSame(
            SolanaGateIdentityRepairService::RESULT_REFUSED_PRECONDITION,
            $byAlias[$victim['alias']]
        );

        // The other seven are unaffected — one mapping's refusal must not
        // corrupt another's transaction.
        foreach (array_slice($entries, 1) as $entry) {
            self::assertSame(
                SolanaGateIdentityRepairService::RESULT_REPAIRED,
                $byAlias[$entry['alias']]
            );
        }
        self::assertSame(7, $this->auditRowCount());
        self::assertSame($solana, $this->chainId('solana'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mutatedFixtureProvider')]
    public function testMismatchedFixtureIsRefused(string $case): void
    {
        $solana  = $this->seedTheEight();
        $entries = SolanaGateIdentityManifest::entries();
        $victim  = $entries[0];
        $wpdb    = $GLOBALS['wpdb'];

        switch ($case) {
            case 'wrong_alias':
                $wpdb->query($wpdb->prepare(
                    'UPDATE `' . CollectionRepository::table() . '` SET contract_address = %s WHERE id = %d',
                    'not_the_reviewed_alias',
                    $victim['collection_id']
                ));
                break;

            case 'wrong_gate_alias':
                $wpdb->query($wpdb->prepare(
                    'UPDATE `' . $wpdb->postmeta . '` SET meta_value = %s WHERE post_id = %d AND meta_key = %s',
                    'something_else',
                    $victim['post_id'],
                    GatedGroupRepository::META_CONTRACT
                ));
                break;

            case 'wrong_back_reference':
                $wpdb->query($wpdb->prepare(
                    'UPDATE `' . $wpdb->postmeta . '` SET meta_value = %s WHERE post_id = %d AND meta_key = %s',
                    '99999',
                    $victim['post_id'],
                    GatedGroupRepository::META_COLLECTION
                ));
                break;

            case 'missing_back_reference':
                $wpdb->query($wpdb->prepare(
                    'DELETE FROM `' . $wpdb->postmeta . '` WHERE post_id = %d AND meta_key = %s',
                    $victim['post_id'],
                    GatedGroupRepository::META_COLLECTION
                ));
                break;

            case 'duplicate_meta':
                $this->addMeta($victim['post_id'], GatedGroupRepository::META_CONTRACT, $victim['alias']);
                break;

            case 'wrong_chain':
                $wpdb->query($wpdb->prepare(
                    'UPDATE `' . $wpdb->postmeta . '` SET meta_value = %s WHERE post_id = %d AND meta_key = %s',
                    (string) $this->chainId('ethereum'),
                    $victim['post_id'],
                    GatedGroupRepository::META_CHAIN_ID
                ));
                break;

            case 'collection_chain_mismatch':
                $wpdb->query($wpdb->prepare(
                    'UPDATE `' . CollectionRepository::table() . '` SET chain_id = %d WHERE id = %d',
                    $this->chainId('ethereum'),
                    $victim['collection_id']
                ));
                break;

            case 'wrong_min_balance':
                $wpdb->query($wpdb->prepare(
                    'UPDATE `' . $wpdb->postmeta . '` SET meta_value = %s WHERE post_id = %d AND meta_key = %s',
                    '5',
                    $victim['post_id'],
                    GatedGroupRepository::META_MIN_BAL
                ));
                break;

            case 'wrong_verification_state':
                $wpdb->query($wpdb->prepare(
                    'UPDATE `' . CollectionRepository::table() . '` SET is_verified = 0 WHERE id = %d',
                    $victim['collection_id']
                ));
                break;

            case 'wrong_source':
                $wpdb->query($wpdb->prepare(
                    'UPDATE `' . CollectionRepository::table() . '` SET source = %s WHERE id = %d',
                    'manual',
                    $victim['collection_id']
                ));
                break;

            case 'wrong_post_type':
                $wpdb->query($wpdb->prepare(
                    'UPDATE `' . $wpdb->posts . '` SET post_type = %s WHERE ID = %d',
                    'post',
                    $victim['post_id']
                ));
                break;

            case 'wrong_post_status':
                $wpdb->query($wpdb->prepare(
                    'UPDATE `' . $wpdb->posts . '` SET post_status = %s WHERE ID = %d',
                    'draft',
                    $victim['post_id']
                ));
                break;

            case 'missing_post':
                $wpdb->query($wpdb->prepare(
                    'DELETE FROM `' . $wpdb->posts . '` WHERE ID = %d',
                    $victim['post_id']
                ));
                break;

            case 'missing_collection':
                $wpdb->query($wpdb->prepare(
                    'DELETE FROM `' . CollectionRepository::table() . '` WHERE id = %d',
                    $victim['collection_id']
                ));
                break;

            default:
                self::fail('unknown case ' . $case);
        }

        $results = (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'run-' . $case);
        $byAlias = $this->resultsByAlias($results);

        self::assertSame(
            SolanaGateIdentityRepairService::RESULT_REFUSED_PRECONDITION,
            $byAlias[$victim['alias']],
            "case '{$case}' should have been refused"
        );

        // The victim's canonical identity must still be NULL — refusing
        // means writing NOTHING, not writing and reporting a refusal.
        $row = $this->collectionRow($victim['collection_id']);
        if ($row !== null) {
            self::assertNull($row['canonical_identifier'], "case '{$case}' wrote despite refusing");
        }

        // Seven independent transactions still succeeded.
        self::assertSame(7, $this->auditRowCount(), "case '{$case}' leaked across mappings");
        self::assertSame($solana, $this->chainId('solana'));
    }

    /** @return list<array{string}> */
    public static function mutatedFixtureProvider(): array
    {
        return [
            ['wrong_alias'],
            ['wrong_gate_alias'],
            ['wrong_back_reference'],
            ['missing_back_reference'],
            ['duplicate_meta'],
            ['wrong_chain'],
            ['collection_chain_mismatch'],
            ['wrong_min_balance'],
            ['wrong_verification_state'],
            ['wrong_source'],
            ['wrong_post_type'],
            ['wrong_post_status'],
            ['missing_post'],
            ['missing_collection'],
        ];
    }

    // ── audit fidelity ──────────────────────────────────────────────────

    public function testAuditRowRecordsExactBeforeAndAfter(): void
    {
        $solana = $this->seedTheEight();
        (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'run-audit');

        $wpdb = $GLOBALS['wpdb'];

        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT user_id, target_type, target_id, meta FROM `' . TableRegistry::activity() . '`
                  WHERE action = %s AND target_id = %d',
                SolanaGateIdentityRepairService::AUDIT_ACTION,
                $entry['collection_id']
            ));

            self::assertNotNull($row, "no audit row for collection {$entry['collection_id']}");
            self::assertSame((string) self::OPERATOR_ID, (string) $row->user_id);
            self::assertSame('collection', (string) $row->target_type);

            $meta = json_decode((string) $row->meta, true);
            self::assertIsArray($meta);

            self::assertSame('run-audit', $meta['run_id']);
            self::assertSame(1, $meta['manifest_version']);
            self::assertSame('solana', $meta['chain_slug']);
            self::assertSame($solana, $meta['chain_id']);
            self::assertSame($entry['collection_id'], $meta['collection_id']);
            self::assertSame($entry['post_id'], $meta['post_id']);
            self::assertSame('canonical_identifier', $meta['field']);

            // Before/after survive byte-exact, case included — the property
            // AuditMetaRepairFidelityTest pins at the unit layer, proven
            // here on a STORED row.
            self::assertNull($meta['before']);
            self::assertSame($entry['new_canonical_identifier'], $meta['after']);
            self::assertSame($entry['alias'], $meta['gate_meta_before']);
            self::assertSame($entry['new_canonical_identifier'], $meta['gate_meta_after']);
            self::assertSame($entry['alias'], $meta['contract_address']);
            self::assertSame(self::OPERATOR_ID, $meta['operator_user_id']);
        }
    }

    /**
     * The audit row must carry no wallet address, email, credential or
     * free-text prose. Checked against the STORED row, because redaction
     * happens on the way in.
     */
    public function testAuditRowLeaksNothingSensitive(): void
    {
        $this->seedTheEight();
        (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'run-leak');

        $wpdb = $GLOBALS['wpdb'];
        $all  = $wpdb->get_col($wpdb->prepare(
            'SELECT meta FROM `' . TableRegistry::activity() . '` WHERE action = %s',
            SolanaGateIdentityRepairService::AUDIT_ACTION
        ));

        self::assertNotEmpty($all);

        foreach ($all as $json) {
            $decoded = json_decode((string) $json, true);
            self::assertIsArray($decoded);

            foreach (['ip', 'client_ip', 'wallet', 'wallet_address', 'email', 'token',
                      'exception', 'message', 'note', 'trace', 'query'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $decoded, "audit meta carries '{$forbidden}'");
            }

            self::assertStringNotContainsStringIgnoringCase('@', (string) $json);
            self::assertStringNotContainsStringIgnoringCase('bearer', (string) $json);
        }
    }

    // ── the 28-gate production shape ────────────────────────────────────

    /**
     * Twenty Cosmos gates alongside the eight Solana ones. The repair must
     * touch NONE of them: it iterates the manifest, not the gate table.
     */
    public function testTwentyCosmosGatesAreUntouched(): void
    {
        $this->seedTheEight();

        $cosmos       = $this->chainId('cosmos');
        $cosmosBefore = [];

        for ($i = 0; $i < 20; $i++) {
            $collectionId = 500 + $i;
            $postId       = 7000 + $i;
            // A valid, canonical bech32 identity — Cosmos rows are NOT
            // alias-backed, which is why they were never broken.
            $addr = $this->cosmosAddress($i);

            $this->insertCollection($collectionId, $cosmos, $addr, $addr);
            $this->insertGate($postId, $cosmos, $collectionId, $addr);

            $cosmosBefore[$collectionId] = $this->collectionRow($collectionId);
        }

        // 28 gates total, matching the measured production shape.
        self::assertCount(28, GatedGroupRepository::listAllGatedGroupIds());

        (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'run-cosmos');

        foreach ($cosmosBefore as $collectionId => $before) {
            self::assertSame(
                $before,
                $this->collectionRow($collectionId),
                "cosmos collection {$collectionId} was modified"
            );
        }

        // And exactly eight audit rows — one per manifest entry, none for
        // the twenty Cosmos gates.
        self::assertSame(8, $this->auditRowCount());
    }

    /**
     * Every Cosmos gate still resolves to a usable identity after the
     * change — the property that would break if the comparison had been
     * made case-SENSITIVE instead of chain-AWARE.
     */
    public function testCosmosGatesStillResolveTheirIdentity(): void
    {
        $cosmos = $this->chainId('cosmos');

        for ($i = 0; $i < 20; $i++) {
            $collectionId = 600 + $i;
            $postId       = 7100 + $i;
            $addr         = $this->cosmosAddress($i);

            $this->insertCollection($collectionId, $cosmos, $addr, $addr);
            $this->insertGate($postId, $cosmos, $collectionId, $addr);

            $config = GatedGroupRepository::getGateConfig($postId);
            self::assertNotNull($config);

            $identity = \BCC\Trust\Onchain\Services\GateIdentityResolver::resolve($config);
            self::assertTrue(
                $identity->isResolved(),
                "cosmos gate {$postId} no longer resolves: " . $identity->reason()
            );
            self::assertSame($addr, $identity->canonical());
            self::assertSame('cosmos', $identity->chainFamily());
        }
    }

    /**
     * Before the repair the eight Solana gates must report UNRESOLVED —
     * which is what stops any provider being called for them.
     */
    public function testTheEightSolanaGatesAreUnresolvedBeforeRepairAndResolvedAfter(): void
    {
        $this->seedTheEight();

        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            $config = GatedGroupRepository::getGateConfig($entry['post_id']);
            self::assertNotNull($config);

            $identity = \BCC\Trust\Onchain\Services\GateIdentityResolver::resolve($config);
            self::assertFalse($identity->isResolved(), 'alias-backed gate must not resolve');
            self::assertSame('collection_identity_unresolved', $identity->reason());
        }

        (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'run-after');

        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            $config = GatedGroupRepository::getGateConfig($entry['post_id']);
            self::assertNotNull($config);

            $identity = \BCC\Trust\Onchain\Services\GateIdentityResolver::resolve($config);
            self::assertTrue($identity->isResolved(), 'repaired gate must resolve: ' . $identity->reason());
            self::assertSame($entry['new_canonical_identifier'], $identity->canonical());
            self::assertSame('solana', $identity->chainFamily());
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * A valid bech32 `cosmos1…` address. Built by canonicalising a known
     * good one and varying it only where the checksum still holds — so the
     * fixtures are real addresses, not shape-alikes.
     */
    private function cosmosAddress(int $i): string
    {
        // 20 distinct, checksum-valid cosmos addresses generated from a
        // fixed 20-byte payload that varies by index.
        $data = str_pad((string) $i, 40, '0', STR_PAD_LEFT);
        $addr = \BCC\Trust\Onchain\Support\Bech32::encode('cosmos', hex2bin($data));

        self::assertIsString($addr, 'fixture bech32 encode failed');

        return $addr;
    }

    /**
     * A whole-of-state fingerprint — the collections table plus the gate
     * postmeta. Used to prove "nothing was written".
     *
     * @return array{collections: list<array<string, mixed>>, postmeta: list<array<string, mixed>>}
     */
    private function snapshot(): array
    {
        $wpdb = $GLOBALS['wpdb'];

        $collections = $wpdb->get_results(
            'SELECT id, chain_id, contract_address, canonical_identifier, is_verified, source
               FROM `' . CollectionRepository::table() . '` ORDER BY id'
        );
        $postmeta = $wpdb->get_results(
            'SELECT meta_id, post_id, meta_key, meta_value FROM `' . $wpdb->postmeta . '` ORDER BY meta_id'
        );

        return [
            'collections' => array_map(static fn(object $r): array => (array) $r, $collections),
            'postmeta'    => array_map(static fn(object $r): array => (array) $r, $postmeta),
        ];
    }
}
