<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-143 (bug-finder part) + the F-101-class counts cache, against the REAL
 * tables.
 *
 *  - ValidatorRepository::getForProject: rows tied on the sort key had no
 *    unique tiebreak, so an OFFSET page could repeat one and skip another;
 *    and the `chain_name` sort ordered by `v.chain_name`, a column that does
 *    not exist on validators — an SQL error that came back as an empty page.
 *  - ValidatorRepository / CollectionRepository::getCountsByChain cached the
 *    EMPTY map for an hour when the read failed.
 *  - AttestationRepository::listByTarget (decayed_weight): no `id` tiebreak.
 */
#[Group('integration')]
#[Group('mariadb')]
final class PagedListTiebreakAndCountsCacheIntegrationTest extends TestCase
{
    private const POST = 770001;

    private int $chainId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $wpdb  = $GLOBALS['wpdb'];
        $table = TableRegistry::trustAttestations();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';
            bcc_trust_create_trust_attestations_table();
        }
        self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $chainId = ChainRepository::resolveIdAnyState('cosmos');
        self::assertIsInt($chainId, 'the chain registry must be seeded');
        $this->chainId = $chainId;

        $this->purge();
        unset($GLOBALS['__bcc_test_object_cache']['bcc_onchain_validators']['counts_by_chain']);
        unset($GLOBALS['__bcc_test_object_cache']['bcc_onchain']['collection_counts_by_chain']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query($wpdb->prepare('DELETE FROM `' . WalletRepository::table() . '` WHERE post_id = %d', self::POST));
        $wpdb->query("DELETE FROM `" . ValidatorRepository::table() . "` WHERE operator_address LIKE 'f143val%'");
        $wpdb->query('DELETE FROM `' . TableRegistry::trustAttestations() . "` WHERE target_kind = 'validator_card' AND target_id = 880001");
    }

    /** Seeds $n validators linked to POST, all with the SAME stake; returns their ids in insert order. */
    private function seedTiedValidators(int $n): array
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->insert(WalletRepository::table(), [
            'user_id' => 770001, 'post_id' => self::POST, 'wallet_address' => 'cosmos1f143wallet', 'chain_id' => $this->chainId,
        ]);
        self::assertSame('', $wpdb->last_error, 'fixture: wallet link');
        $linkId = (int) $wpdb->insert_id;

        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO `' . ValidatorRepository::table() . '` (wallet_link_id, operator_address, chain_id, total_stake, expires_at)'
                . ' VALUES (%d, %s, %d, 1000, UTC_TIMESTAMP())',
                $linkId,
                'f143val' . $i,
                $this->chainId
            ));
            self::assertSame('', $wpdb->last_error, 'fixture: validator');
            $ids[] = (int) $wpdb->insert_id;
        }
        return $ids;
    }

    /** @return list<int> */
    private static function ids(array $result): array
    {
        return array_map(static fn (object $r): int => (int) $r->id, $result['items']);
    }

    // ── getForProject ───────────────────────────────────────────────────

    public function testTiedStakesPageWithoutRepeatsOrGapsInIdDescOrder(): void
    {
        $ids = $this->seedTiedValidators(5);

        $p1 = self::ids(ValidatorRepository::getForProject(self::POST, 1, 2));
        $p2 = self::ids(ValidatorRepository::getForProject(self::POST, 2, 2));
        $p3 = self::ids(ValidatorRepository::getForProject(self::POST, 3, 2));

        self::assertSame(array_reverse($ids), array_merge($p1, $p2, $p3), 'every tied validator exactly once, in id DESC order (the tiebreak)');
    }

    public function testTheChainNameSortReturnsRows(): void
    {
        $this->seedTiedValidators(3);

        $result = ValidatorRepository::getForProject(self::POST, 1, 8, 'chain_name');

        self::assertSame('', (string) $GLOBALS['wpdb']->last_error, 'the chain_name sort must be valid SQL');
        self::assertCount(3, $result['items'], 'it used to error on v.chain_name and return an empty page');
    }

    // ── getCountsByChain: never cache a failed read ─────────────────────

    public function testAFailedValidatorCountsReadIsNotCached(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $this->seedTiedValidators(2);

        $wpdb->failQueriesMatching = '/SELECT chain_id, COUNT\(\*\) AS cnt/i';
        self::assertSame([], ValidatorRepository::getCountsByChain());
        self::assertGreaterThan(0, $wpdb->injectedFailures, 'the fault must actually have fired');
        $wpdb->clearFaultInjection();

        self::assertArrayNotHasKey(
            'counts_by_chain',
            $GLOBALS['__bcc_test_object_cache']['bcc_onchain_validators'] ?? [],
            'a failed read must not be cached'
        );
        $counts = ValidatorRepository::getCountsByChain();
        self::assertGreaterThanOrEqual(2, (int) ($counts[$this->chainId]->cnt ?? 0), 'the next read answers for real');
    }

    public function testAFailedCollectionCountsReadIsNotCached(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $wpdb->failQueriesMatching = '/SELECT chain_id, COUNT\(\*\) AS cnt/i';
        self::assertSame([], CollectionRepository::getCountsByChain());
        self::assertGreaterThan(0, $wpdb->injectedFailures);
        $wpdb->clearFaultInjection();

        self::assertArrayNotHasKey(
            'collection_counts_by_chain',
            $GLOBALS['__bcc_test_object_cache']['bcc_onchain'] ?? [],
            'a failed read must not be cached'
        );
    }

    public function testAHealthyCountsReadIsStillCached(): void
    {
        ValidatorRepository::getCountsByChain();

        self::assertArrayHasKey('counts_by_chain', $GLOBALS['__bcc_test_object_cache']['bcc_onchain_validators'] ?? []);
    }

    // ── listByTarget (decayed_weight) ───────────────────────────────────

    public function testTiedAttestationsPageWithoutRepeatsOrGapsInIdDescOrder(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = TableRegistry::trustAttestations();
        $ids   = [];
        for ($i = 1; $i <= 5; $i++) {
            $wpdb->insert($table, [
                'attestor_user_id' => 880100 + $i, 'target_kind' => 'validator_card', 'target_id' => 880001,
                'kind' => 'vouch', 'weight_at_time' => 1.0, 'created_at' => '2026-11-01 12:00:00',
            ]);
            self::assertSame('', $wpdb->last_error, 'fixture: attestation');
            $ids[] = (int) $wpdb->insert_id;
        }

        $repo = new AttestationRepository();
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            foreach ($repo->listByTarget('validator_card', 880001, ['page' => $page, 'per_page' => 2]) as $row) {
                $seen[] = (int) $row->id;
            }
        }

        self::assertSame(array_reverse($ids), $seen, 'every tied attestation exactly once, in id DESC order');
    }
}
