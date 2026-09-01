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
 * Rollback, proven against a real transactional MySQL.
 *
 * ── WHY THESE ARE THE HARDEST TESTS TO GET HONEST ───────────────────────
 * "It rolls back" is the easiest claim in the codebase to assert falsely.
 * A test that never actually opens a transaction, or runs against a
 * non-transactional engine, will pass every rollback assertion while
 * proving nothing — the write simply never happened for an unrelated
 * reason.
 *
 * So each test here does two things: it forces a REAL failure at a chosen
 * point AFTER a successful write in the same transaction, and it first
 * proves the write would otherwise have landed. Without the second half,
 * a green result is indistinguishable from "the code never got that far".
 *
 * The failure is induced by breaking the audit table — the one dependency
 * the repair has that can be made to fail without touching the repair's
 * own code. That is deliberate: injecting a flag into the service would
 * test the flag, not the transaction.
 */
final class SolanaGateIdentityRepairRollbackIntegrationTest extends TestCase
{
    private const OPERATOR_ID = 4242;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateAll();
        $this->seedTheEight();
    }

    protected function tearDown(): void
    {
        $this->restoreAuditTable();
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

    private function seedTheEight(): void
    {
        $wpdb   = $GLOBALS['wpdb'];
        $solana = ChainRepository::resolveIdAnyState('solana');
        self::assertIsInt($solana);

        foreach (SolanaGateIdentityManifest::entries() as $entry) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO `' . CollectionRepository::table() . '`
                    (id, chain_id, contract_address, canonical_identifier, is_verified, source, fetched_at, expires_at)
                 VALUES (%d, %d, %s, NULL, %d, %s, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))',
                $entry['collection_id'],
                $solana,
                $entry['alias'],
                $entry['expected_is_verified'],
                $entry['expected_source']
            ));

            $wpdb->query($wpdb->prepare(
                'INSERT INTO `' . $wpdb->posts . '` (ID, post_title, post_name, post_type, post_status)
                 VALUES (%d, %s, %s, %s, %s)',
                $entry['post_id'],
                'Group',
                'g' . $entry['post_id'],
                'peepso-group',
                'publish'
            ));

            foreach ([
                GatedGroupRepository::META_KIND       => GatedGroupRepository::KIND_HOLDERS,
                GatedGroupRepository::META_CHAIN_ID   => (string) $solana,
                GatedGroupRepository::META_CONTRACT   => $entry['alias'],
                GatedGroupRepository::META_MIN_BAL    => '1',
                GatedGroupRepository::META_COLLECTION => (string) $entry['collection_id'],
            ] as $key => $value) {
                $wpdb->query($wpdb->prepare(
                    'INSERT INTO `' . $wpdb->postmeta . '` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)',
                    $entry['post_id'],
                    $key,
                    $value
                ));
            }
        }
    }

    // ── audit-table sabotage ────────────────────────────────────────────

    private function renameAuditTableAway(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = TableRegistry::activity();
        $wpdb->query('DROP TABLE IF EXISTS `' . $table . '__stash`');
        $wpdb->query('RENAME TABLE `' . $table . '` TO `' . $table . '__stash`');
    }

    private function narrowAuditMetaColumn(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        // LONGTEXT -> a column far too small for the repair payload, in
        // STRICT mode, so the INSERT errors rather than truncating.
        $wpdb->query('ALTER TABLE `' . TableRegistry::activity() . '` MODIFY COLUMN meta VARCHAR(8) NULL');
    }

    private function restoreAuditTable(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = TableRegistry::activity();

        $stashed = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table . '__stash'));
        if ($stashed !== null) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`');
            $wpdb->query('RENAME TABLE `' . $table . '__stash` TO `' . $table . '`');
        }

        $wpdb->query('ALTER TABLE `' . $table . '` MODIFY COLUMN meta LONGTEXT NULL');
    }

    private function canonicalOf(int $collectionId): ?string
    {
        $wpdb  = $GLOBALS['wpdb'];
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT canonical_identifier FROM `' . CollectionRepository::table() . '` WHERE id = %d',
            $collectionId
        ));

        return $value === null ? null : (string) $value;
    }

    private function gateMetaOf(int $postId): string
    {
        $wpdb = $GLOBALS['wpdb'];

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT meta_value FROM `' . $wpdb->postmeta . '` WHERE post_id = %d AND meta_key = %s',
            $postId,
            GatedGroupRepository::META_CONTRACT
        ));
    }

    // ── the control ─────────────────────────────────────────────────────

    /**
     * Proves the writes DO land when nothing is sabotaged.
     *
     * Without this, every rollback assertion below would also pass if the
     * repair simply never wrote anything at all.
     */
    public function testControlTheRepairActuallyWritesWhenNothingFails(): void
    {
        $entry = SolanaGateIdentityManifest::entries()[0];

        self::assertNull($this->canonicalOf($entry['collection_id']));

        (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'control');

        self::assertSame(
            $entry['new_canonical_identifier'],
            $this->canonicalOf($entry['collection_id']),
            'the control must prove a write lands, or the rollback tests prove nothing'
        );
        self::assertSame($entry['new_canonical_identifier'], $this->gateMetaOf($entry['post_id']));
    }

    // ── rollback on a failed audit INSERT ───────────────────────────────

    /**
     * The audit table is gone entirely. `AuditLogger` reports failure, the
     * service throws, and BOTH writes must be undone.
     */
    public function testAuditInsertFailureRollsBackBothWrites(): void
    {
        $entries = SolanaGateIdentityManifest::entries();

        $this->renameAuditTableAway();

        $results = (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'audit-insert-fail');

        foreach ($results as $r) {
            self::assertSame(
                SolanaGateIdentityRepairService::RESULT_FAILED_ROLLED_BACK,
                $r['result'],
                "collection {$r['collection_id']}: {$r['detail']}"
            );
        }

        $this->restoreAuditTable();

        // Nothing written, for any of the eight.
        foreach ($entries as $entry) {
            self::assertNull(
                $this->canonicalOf($entry['collection_id']),
                "collection {$entry['collection_id']} kept a canonical identifier after rollback"
            );
            self::assertSame(
                $entry['alias'],
                $this->gateMetaOf($entry['post_id']),
                "post {$entry['post_id']} kept a rewritten gate meta after rollback"
            );
        }
    }

    /**
     * The audit row can be inserted but its metadata cannot be stored.
     * `logChecked()` is REQUIRED-metadata, so it must write no row and
     * return null — and the repair must roll back rather than record a
     * repair it cannot describe.
     */
    public function testAuditMetadataFailureRollsBackTheRepair(): void
    {
        $entries = SolanaGateIdentityManifest::entries();

        $this->narrowAuditMetaColumn();

        $results = (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'audit-meta-fail');

        foreach ($results as $r) {
            self::assertSame(SolanaGateIdentityRepairService::RESULT_FAILED_ROLLED_BACK, $r['result']);
        }

        $this->restoreAuditTable();

        foreach ($entries as $entry) {
            self::assertNull($this->canonicalOf($entry['collection_id']));
            self::assertSame($entry['alias'], $this->gateMetaOf($entry['post_id']));
        }

        // And no audit row survived either.
        $wpdb  = $GLOBALS['wpdb'];
        $count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . TableRegistry::activity() . '` WHERE action = %s',
            SolanaGateIdentityRepairService::AUDIT_ACTION
        ));
        self::assertSame(0, $count);
    }

    /**
     * A rollback must not invalidate caches — the state it would be
     * publishing never became true.
     */
    public function testNoCacheInvalidationAfterRollback(): void
    {
        $entry = SolanaGateIdentityManifest::entries()[0];

        // Warm a sentinel into the cache key the repair would drop.
        wp_cache_set($entry['post_id'], ['sentinel'], 'post_meta');
        wp_cache_set('collection_counts_by_chain', ['sentinel'], 'bcc_onchain');

        $this->renameAuditTableAway();
        (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'no-invalidate');
        $this->restoreAuditTable();

        self::assertSame(
            ['sentinel'],
            wp_cache_get($entry['post_id'], 'post_meta'),
            'a rolled-back repair invalidated a cache for a change that never happened'
        );
        self::assertSame(['sentinel'], wp_cache_get('collection_counts_by_chain', 'bcc_onchain'));
    }

    /**
     * A successful repair DOES invalidate, and only after the commit. The
     * pairing with the test above is what makes either meaningful.
     */
    public function testCacheIsInvalidatedAfterASuccessfulCommit(): void
    {
        $entry = SolanaGateIdentityManifest::entries()[0];

        wp_cache_set($entry['post_id'], ['sentinel'], 'post_meta');
        wp_cache_set('collection_counts_by_chain', ['sentinel'], 'bcc_onchain');

        (new SolanaGateIdentityRepairService())->run(true, self::OPERATOR_ID, 'invalidate');

        self::assertFalse(wp_cache_get($entry['post_id'], 'post_meta'));
        self::assertFalse(wp_cache_get('collection_counts_by_chain', 'bcc_onchain'));
    }

    /**
     * A dry run must not invalidate anything either — it changed nothing,
     * so there is nothing to publish.
     */
    public function testDryRunInvalidatesNoCache(): void
    {
        $entry = SolanaGateIdentityManifest::entries()[0];

        wp_cache_set($entry['post_id'], ['sentinel'], 'post_meta');
        wp_cache_set('collection_counts_by_chain', ['sentinel'], 'bcc_onchain');

        (new SolanaGateIdentityRepairService())->run(false, 0, 'dry-no-invalidate');

        self::assertSame(['sentinel'], wp_cache_get($entry['post_id'], 'post_meta'));
        self::assertSame(['sentinel'], wp_cache_get('collection_counts_by_chain', 'bcc_onchain'));
    }

    /**
     * A locking read outside a transaction is refused loudly rather than
     * silently taking no lock. This is the guard that stops a future caller
     * from "simplifying away" TransactionManager and getting a repair that
     * looks atomic and is not.
     */
    public function testLockingReadsRefuseToRunOutsideATransaction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires TransactionManager::run/');

        \BCC\Trust\Onchain\Repositories\GateIdentityRepairRepository::lockCollection(79);
    }

    // ── the postcondition check, isolated ───────────────────────────────

    /**
     * `verifyPostconditions()` is belt-and-braces: in a healthy run every
     * value it re-reads is one the same transaction just wrote, so removing
     * it changes no observable outcome — which means no end-to-end test can
     * prove it is load-bearing, and a mutation control found exactly that.
     *
     * So it is exercised directly, against state that violates each
     * postcondition in turn. This is what makes "postcondition failure
     * rolls back" a claim with evidence behind it rather than a comment.
     */
    public function testPostconditionVerificationRejectsAnUnwrittenCanonicalIdentifier(): void
    {
        $entry = SolanaGateIdentityManifest::entries()[0];

        // Nothing has been repaired, so canonical_identifier is still NULL.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/canonical_identifier not written/');

        $this->callVerifyPostconditions(
            $entry['collection_id'],
            $entry['post_id'],
            $entry['new_canonical_identifier'],
            $entry['alias']
        );
    }

    /**
     * The guarantee that the repair ADDS an identity rather than rewriting
     * history: if `contract_address` ever differs from the reviewed alias,
     * the mapping must not commit.
     */
    public function testPostconditionVerificationRejectsAModifiedContractAddress(): void
    {
        $entry = SolanaGateIdentityManifest::entries()[0];

        // Make the row otherwise fully repaired...
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . CollectionRepository::table() . '` SET canonical_identifier = %s WHERE id = %d',
            $entry['new_canonical_identifier'],
            $entry['collection_id']
        ));
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . $wpdb->postmeta . '` SET meta_value = %s WHERE post_id = %d AND meta_key = %s',
            $entry['new_canonical_identifier'],
            $entry['post_id'],
            GatedGroupRepository::META_CONTRACT
        ));

        // ...then assert against an alias the row does NOT have.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/contract_address was modified/');

        $this->callVerifyPostconditions(
            $entry['collection_id'],
            $entry['post_id'],
            $entry['new_canonical_identifier'],
            'an_alias_this_row_does_not_have'
        );
    }

    /**
     * Invoke the private postcondition check inside a real transaction —
     * required, because its reads are `FOR UPDATE` and the repository
     * refuses those outside one.
     */
    private function callVerifyPostconditions(
        int $collectionId,
        int $postId,
        string $expectedCanonical,
        string $expectedContractAddress
    ): void {
        $service = new SolanaGateIdentityRepairService();

        $method = new \ReflectionMethod($service, 'verifyPostconditions');
        $method->setAccessible(true);

        try {
            \BCC\Trust\Core\Security\TransactionManager::run(
                static function () use ($method, $service, $collectionId, $postId, $expectedCanonical, $expectedContractAddress): array {
                    $method->invoke($service, $collectionId, $postId, $expectedCanonical, $expectedContractAddress);

                    return ['ok' => true];
                }
            );
        } catch (\Throwable $e) {
            // TransactionManager rethrows after rolling back; unwrap so the
            // test asserts on the postcondition's own message.
            throw $e;
        }
    }
}
