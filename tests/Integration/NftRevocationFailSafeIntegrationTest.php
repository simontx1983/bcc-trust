<?php

declare(strict_types=1);

namespace {
    // bcc-core's PeepSoGroupWriter::leave() refuses to run unless PeepSo is
    // loaded, and calls do_action() afterwards. Neither exists in this
    // harness. The double below performs exactly PeepSo's member_leave(): a
    // DELETE of the (group, user) membership row — so removal, and the
    // offset shift it causes, happen in real MySQL.
    if (!function_exists('do_action')) {
        function do_action(string $hook, ...$args): void
        {
        }
    }

    if (!class_exists('PeepSoGroupUser', false)) {
        final class PeepSoGroupUser
        {
            public function __construct(private int $groupId, private int $userId)
            {
            }

            public function member_leave(): void
            {
                $wpdb = $GLOBALS['wpdb'];
                $wpdb->query($wpdb->prepare(
                    'DELETE FROM `' . $wpdb->prefix . 'peepso_group_members` WHERE gm_group_id = %d AND gm_user_id = %d',
                    $this->groupId,
                    $this->userId
                ));
            }
        }
    }
}

namespace BCC\Trust\Tests\Integration {

    use BCC\Core\Log\Logger;
    use BCC\Trust\Onchain\Contracts\CountsHoldingsWithCompleteness;
    use BCC\Trust\Onchain\Contracts\FetcherInterface;
    use BCC\Trust\Onchain\Factories\FetcherFactory;
    use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
    use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
    use BCC\Trust\Onchain\Repositories\ChainRepository;
    use BCC\Trust\Onchain\Repositories\CollectionRepository;
    use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
    use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
    use BCC\Trust\Onchain\Repositories\WalletRepository;
    use BCC\Trust\Onchain\Services\HoldingsService;
    use BCC\Trust\Onchain\Services\NftGroupRevokeService;
    use BCC\Trust\Onchain\Support\Bech32;
    use BCC\Trust\Onchain\ValueObjects\EligibilityVerdict;
    use BCC\Trust\Onchain\ValueObjects\HoldingsCount;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    /**
     * Scripted provider. Everything else in this suite is real: the
     * repositories and their SQL, the member table and bcc-core's paging
     * query and writer, the gate identity resolver, the audit log, and the
     * sweep's cursor option.
     */
    final class RevocationScriptedFetcher implements FetcherInterface, CountsHoldingsWithCompleteness
    {
        /** @var array<string, int|null> wallet address → exact count, or null = could not answer */
        public static array $answers = [];

        /** @var list<string> wallet addresses asked about, in order */
        public static array $asked = [];

        public static int $maxRequestsPerRead = 1;

        public function __construct(private object $chain)
        {
        }

        public static function reset(): void
        {
            self::$answers            = [];
            self::$asked              = [];
            self::$maxRequestsPerRead = 1;
        }

        public function count_holdings_evidence(string $wallet, string $contract): ?HoldingsCount
        {
            self::$asked[] = $wallet;
            $n = self::$answers[$wallet] ?? null;

            return $n === null ? null : HoldingsCount::exact($n);
        }

        public function max_requests_per_evidence_read(): int
        {
            return self::$maxRequestsPerRead;
        }

        public function count_holdings(string $wallet, string $contract): ?int
        {
            return $this->count_holdings_evidence($wallet, $contract)?->count;
        }

        public function supports_feature(string $feature): bool
        {
            return in_array($feature, ['holdings_count', 'holdings_list'], true);
        }

        public function list_holdings(string $wallet, ?string $cursor = null): array
        {
            return ['items' => [], 'truncated' => false, 'cursor' => null, 'complete' => false, 'served_from_cache' => false];
        }

        public function fetch_validator(string $address): array { return []; }
        public function fetch_all_validators(): array { return []; }
        public function enrich_validator(string $address, ?object $existingRow = null): array { return []; }
        public function fetch_delegations(string $delegatorAddress): array { return []; }
        public function fetch_collections(string $walletAddress, int $chainId = 0): array { return []; }
        public function fetch_top_collections(int $limit = 100): array { return []; }
        public function get_chain(): object { return $this->chain; }
        public function last_fetch_error(): ?string { return null; }
    }

    /**
     * PR 7.14 against real MySQL: a failed read is never a zero, and one
     * sweep visits every member exactly once while it removes them.
     *
     * The unit suite proves the decisions with doubles that SAY they failed.
     * This suite makes the database actually fail (`failQueriesMatching`) and
     * pages through a real `wp_peepso_group_members` with bcc-core's real
     * `listGroupMembers()` (ORDER BY role_rank, gm_joined DESC, gm_id DESC
     * LIMIT/OFFSET) while bcc-core's real `PeepSoGroupWriter::leave()` deletes
     * rows underneath it — the exact shape that made the old `$offset +=
     * count($members)` skip one member per removal.
     *
     * In the `mariadb` group: production runs MariaDB with READ-COMMITTED, and
     * paging a table while deleting from it is exactly the engine-shaped
     * behaviour that group exists to check.
     */
    #[Group('mariadb')]
    final class NftRevocationFailSafeIntegrationTest extends TestCase
    {
        private const GROUP      = 71400;
        private const OWNER      = 90000;
        private const FIRST_USER = 10000;
        private const COLLECTION = 71401;
        private const CONTRACT   = 'cosmos12gsv9tmjhhg86wg9fnd9cnju28jx3fxva9cn8dh9meketkfxxajqmg3exz';

        private const EVM_COLLECTION = 71402;
        private const EVM_CONTRACT   = '0x2222222222222222222222222222222222222222';
        private const EVM_WALLET     = '0x1111111111111111111111111111111111111111';

        /** @var array<string, string>|null */
        private static ?array $originalDrivers = null;

        protected function setUp(): void
        {
            parent::setUp();
            Logger::reset();
            RevocationScriptedFetcher::reset();
            $GLOBALS['__bcc_test_transients'] = [];
            unset($GLOBALS['__bcc_test_options']['bcc_gated_group_revoke_cursor']);

            $wpdb = $GLOBALS['wpdb'];
            $wpdb->clearFaultInjection();

            // Owned by this suite: dropped and recreated so the column set is
            // exactly what bcc-core's paging query reads, whatever shape an
            // earlier suite left behind.
            $wpdb->query('DROP TABLE IF EXISTS `' . $this->members() . '`');
            $created = $wpdb->query(
                'CREATE TABLE `' . $this->members() . '` (
                    gm_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    gm_user_id BIGINT UNSIGNED NOT NULL,
                    gm_group_id BIGINT UNSIGNED NOT NULL,
                    gm_user_status VARCHAR(32) NOT NULL,
                    gm_joined DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (gm_id),
                    KEY gm_user_id (gm_user_id),
                    KEY gm_group_id (gm_group_id)
                ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            self::assertNotFalse($created, 'members table: ' . $wpdb->last_error);

            $this->truncateFixtures();

            // Route the cosmos driver to the scripted provider; restored in tearDown.
            $drivers = new \ReflectionProperty(FetcherFactory::class, 'drivers');
            $drivers->setAccessible(true);
            /** @var array<string, string> $current */
            $current = $drivers->getValue();
            self::$originalDrivers ??= $current;
            $drivers->setValue(null, array_merge($current, ['cosmos' => RevocationScriptedFetcher::class]));
        }

        protected function tearDown(): void
        {
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->clearFaultInjection();
            $this->truncateFixtures();
            $wpdb->query('DROP TABLE IF EXISTS `' . $this->members() . '`');

            if (self::$originalDrivers !== null) {
                $drivers = new \ReflectionProperty(FetcherFactory::class, 'drivers');
                $drivers->setAccessible(true);
                $drivers->setValue(null, self::$originalDrivers);
            }
            parent::tearDown();
        }

        // ── fixtures ────────────────────────────────────────────────────

        private function members(): string
        {
            return $GLOBALS['wpdb']->prefix . 'peepso_group_members';
        }

        private function truncateFixtures(): void
        {
            $wpdb = $GLOBALS['wpdb'];
            foreach ([
                WalletRepository::table(),
                CollectionRepository::table(),
                NftHoldingsRepository::table(),
                ChainCheckpointRepository::table(),
                $wpdb->postmeta,
                $wpdb->posts,
                $wpdb->prefix . 'bcc_trust_activity',
            ] as $table) {
                $wpdb->query('DELETE FROM `' . $table . '`');
            }
        }

        private function chainId(string $slug): int
        {
            $id = ChainRepository::resolveIdAnyState($slug);
            self::assertIsInt($id, "fixture chain '{$slug}' must exist");

            return $id;
        }

        private static function cosmosAddress(int $i): string
        {
            return Bech32::encode('cosmos', (string) hex2bin(str_pad(dechex($i), 40, '0', STR_PAD_LEFT)));
        }

        private function insertCollection(int $id, int $chainId, string $contract, ?string $tokenStandard = null): void
        {
            $wpdb = $GLOBALS['wpdb'];
            $ok = $wpdb->query($wpdb->prepare(
                'INSERT INTO `' . CollectionRepository::table() . '`
                    (id, chain_id, contract_address, canonical_identifier, token_standard, is_verified, source, fetched_at, expires_at)
                 VALUES (%d, %d, %s, %s, ' . ($tokenStandard === null ? 'NULL' : '%s') . ", 1, 'toplist', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))",
                ...($tokenStandard === null
                    ? [$id, $chainId, $contract, strtolower($contract)]
                    : [$id, $chainId, $contract, strtolower($contract), $tokenStandard])
            ));
            self::assertNotFalse($ok, 'collection fixture: ' . $wpdb->last_error);
        }

        private function insertCosmosGate(): void
        {
            $wpdb    = $GLOBALS['wpdb'];
            $chainId = $this->chainId('cosmos');
            $this->insertCollection(self::COLLECTION, $chainId, self::CONTRACT);

            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$wpdb->posts}` (ID, post_title, post_name, post_type, post_status) VALUES (%d, 'Holders', 'holders', 'peepso-group', 'publish')",
                self::GROUP
            ));
            foreach ([
                GatedGroupRepository::META_KIND       => GatedGroupRepository::KIND_HOLDERS,
                GatedGroupRepository::META_CHAIN_ID   => (string) $chainId,
                GatedGroupRepository::META_CONTRACT   => self::CONTRACT,
                GatedGroupRepository::META_MIN_BAL    => '1',
                GatedGroupRepository::META_COLLECTION => (string) self::COLLECTION,
            ] as $key => $value) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                    self::GROUP,
                    $key,
                    $value
                ));
            }
        }

        private function insertWallet(int $userId, int $chainId, string $address): int
        {
            $wpdb = $GLOBALS['wpdb'];
            $ok = $wpdb->query($wpdb->prepare(
                'INSERT INTO `' . WalletRepository::table() . "` (user_id, post_id, wallet_address, chain_id, verified_at) VALUES (%d, 0, %s, %d, NOW())",
                $userId,
                $address,
                $chainId
            ));
            self::assertNotFalse($ok, 'wallet fixture: ' . $wpdb->last_error);

            return (int) $wpdb->insert_id;
        }

        private function insertMember(int $userId, string $status, int $secondsAgo): void
        {
            $wpdb = $GLOBALS['wpdb'];
            // A FIXED anchor, not NOW(): NOW() can tick between two inserts and
            // give neighbours the same gm_joined, which reorders them.
            $wpdb->query($wpdb->prepare(
                'INSERT INTO `' . $this->members() . "` (gm_user_id, gm_group_id, gm_user_status, gm_joined)
                 VALUES (%d, %d, %s, DATE_SUB('2026-09-01 00:00:00', INTERVAL %d SECOND))",
                $userId,
                self::GROUP,
                $status,
                $secondsAgo
            ));
        }

        /**
         * A gated group with an owner and $n members, each with one linked
         * Cosmos wallet whose scripted answer is a complete zero. Member $i
         * joined $i seconds after the previous one, so the paging order is
         * member 0, 1, 2, … (gm_joined DESC).
         *
         * @return list<string> member wallet addresses, in paging order
         */
        private function seedGroup(int $n): array
        {
            $this->insertCosmosGate();
            $chainId = $this->chainId('cosmos');

            $this->insertMember(self::OWNER, 'member_owner', 100000);
            $addresses = [];
            for ($i = 0; $i < $n; $i++) {
                $userId  = self::FIRST_USER + $i;
                $address = self::cosmosAddress($userId);
                $this->insertWallet($userId, $chainId, $address);
                // Newest first: member 0 has the most recent gm_joined.
                $this->insertMember($userId, 'member', $i + 1);
                RevocationScriptedFetcher::$answers[$address] = 0;
                $addresses[] = $address;
            }

            return $addresses;
        }

        /** @return list<int> user ids still in the group, owner excluded */
        private function remainingMembers(): array
        {
            $wpdb = $GLOBALS['wpdb'];
            $ids = $wpdb->get_col($wpdb->prepare(
                'SELECT gm_user_id FROM `' . $this->members() . "` WHERE gm_group_id = %d AND gm_user_status <> 'member_owner' ORDER BY gm_user_id",
                self::GROUP
            ));

            return array_map('intval', $ids);
        }

        private function revokeAuditRows(): int
        {
            $wpdb = $GLOBALS['wpdb'];

            return (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM `' . $wpdb->prefix . "bcc_trust_activity` WHERE action = 'holder_group_revoked'"
            );
        }

        private static function tableRegex(string $table): string
        {
            return '/' . preg_quote($table, '/') . '/';
        }

        // ════════════════════════════════════════════════════════════════
        // A failed read is never a zero
        // ════════════════════════════════════════════════════════════════

        public function testAFailedWalletQueryRemovesNobodyAndTheNextHealthyTickDoes(): void
        {
            $this->seedGroup(1);
            $GLOBALS['wpdb']->failQueriesMatching = self::tableRegex(WalletRepository::table());

            $stats = (new NftGroupRevokeService())->sweep();

            self::assertSame([self::FIRST_USER], $this->remainingMembers(), 'a failed wallet read removed a member');
            self::assertSame(0, $stats['revoked']);
            self::assertSame(['repository_read_failed' => 1], $stats['skipped_reasons']);
            self::assertSame([], RevocationScriptedFetcher::$asked, 'nothing to ask a provider about');
            self::assertSame(0, $this->revokeAuditRows());

            // Anti-vacuity: the same member, the same complete zero, a healthy database.
            $GLOBALS['wpdb']->clearFaultInjection();
            $stats = (new NftGroupRevokeService())->sweep();

            self::assertSame([], $this->remainingMembers());
            self::assertSame(1, $stats['revoked']);
            self::assertSame(1, $this->revokeAuditRows());
        }

        public function testAFailedTokenStandardQueryIsUnknown(): void
        {
            $this->seedGroup(1);
            $GLOBALS['wpdb']->failQueriesMatching = '/SELECT token_standard/';

            $verdict = HoldingsService::eligibilityVerdict(self::FIRST_USER, 'cosmos', self::CONTRACT, 1);

            self::assertTrue($verdict->isUnknown());
            self::assertSame(EligibilityVerdict::REASON_READ_FAILED, $verdict->reason);
            self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault was really injected');
        }

        public function testAFailedTransferIndexQueryIsUnknownAndAFreshPositiveCounts(): void
        {
            $wpdb    = $GLOBALS['wpdb'];
            $chainId = $this->chainId('ethereum');
            $this->insertCollection(self::EVM_COLLECTION, $chainId, self::EVM_CONTRACT, 'ERC-1155');
            $link = $this->insertWallet(self::FIRST_USER, $chainId, self::EVM_WALLET);
            $wpdb->query($wpdb->prepare(
                'INSERT INTO `' . NftHoldingsRepository::table() . "` (wallet_link_id, chain_id, contract_address, token_id, token_standard, balance, metadata_status, last_seen_block, confirmed_at)
                 VALUES (%d, %d, %s, '7', 'ERC-1155', 2, 1, 100, NOW())",
                $link,
                $chainId,
                self::EVM_CONTRACT
            ));
            $wpdb->query($wpdb->prepare(
                'INSERT INTO `' . ChainCheckpointRepository::table() . "` (chain_id, state, last_run_at) VALUES (%d, 'healthy', UTC_TIMESTAMP())",
                $chainId
            ));

            $wpdb->failQueriesMatching = self::tableRegex(NftHoldingsRepository::table());
            $failed = HoldingsService::eligibilityVerdict(self::FIRST_USER, 'ethereum', self::EVM_CONTRACT, 1);
            self::assertSame(EligibilityVerdict::REASON_READ_FAILED, $failed->reason);

            $wpdb->clearFaultInjection();
            self::assertTrue(
                HoldingsService::eligibilityVerdict(self::FIRST_USER, 'ethereum', self::EVM_CONTRACT, 1)->isEligible(),
                'a healthy index row under a checkpoint that ran just now is proof'
            );

            $wpdb->query($wpdb->prepare(
                'UPDATE `' . ChainCheckpointRepository::table() . '` SET last_run_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) WHERE chain_id = %d',
                $chainId
            ));
            self::assertSame(
                EligibilityVerdict::REASON_EVIDENCE_STALE,
                HoldingsService::eligibilityVerdict(self::FIRST_USER, 'ethereum', self::EVM_CONTRACT, 1)->reason
            );
        }

        public function testAFailedVerifiedCollectionQueryIsAnIncompleteWalk(): void
        {
            $chain = ChainRepository::getById($this->chainId('cosmos'));
            self::assertNotNull($chain);
            $fetcher = new CosmosFetcher((object) array_merge((array) $chain, ['rest_url' => 'http://127.0.0.1:1']));

            self::assertTrue($fetcher->list_holdings(self::cosmosAddress(1))['complete'], 'control: no verified collections is a real empty');

            $GLOBALS['wpdb']->failQueriesMatching = self::tableRegex(CollectionRepository::table());
            $walk = $fetcher->list_holdings(self::cosmosAddress(1));

            self::assertSame([], $walk['items']);
            self::assertFalse($walk['complete'], 'a failed query is not "no verified collections"');
        }

        // ════════════════════════════════════════════════════════════════
        // Pagination: every member exactly once
        // ════════════════════════════════════════════════════════════════

        public function testEveryMemberIsEvaluatedAndRemovedExactlyOnceAcrossPagesAndTicks(): void
        {
            // 250 complete zeros. Default tick cap 200, page size 100.
            $addresses = $this->seedGroup(250);

            $first = (new NftGroupRevokeService())->sweep();
            self::assertSame(200, $first['checked']);
            self::assertSame(200, $first['revoked']);
            self::assertSame(array_slice($addresses, 0, 200), RevocationScriptedFetcher::$asked, 'tick 1 took members in order with none skipped');
            self::assertCount(50, $this->remainingMembers());

            $second = (new NftGroupRevokeService())->sweep();
            self::assertSame(50, $second['revoked']);
            self::assertSame($addresses, RevocationScriptedFetcher::$asked, 'tick 2 resumed on the first unevaluated member');
            self::assertSame([], $this->remainingMembers());
            self::assertSame(250, $this->revokeAuditRows());

            $owner = (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM `' . $this->members() . "` WHERE gm_user_status = 'member_owner'");
            self::assertSame(1, $owner, 'the owner is never removed');
        }

        public function testKeptMembersBetweenRemovalsAreNeitherSkippedNorRevisited(): void
        {
            // Repeating pattern across pages: complete zero (removed), provider
            // could not answer (kept), proven holder (kept).
            $addresses = $this->seedGroup(230);
            $kept      = [];
            foreach ($addresses as $i => $address) {
                if ($i % 3 === 1) {
                    RevocationScriptedFetcher::$answers[$address] = null;
                    $kept[] = self::FIRST_USER + $i;
                } elseif ($i % 3 === 2) {
                    RevocationScriptedFetcher::$answers[$address] = 1;
                    $kept[] = self::FIRST_USER + $i;
                }
            }

            $first  = (new NftGroupRevokeService())->sweep();
            $second = (new NftGroupRevokeService())->sweep();

            self::assertSame(230, $first['checked'] + $second['checked']);
            self::assertSame(77, $first['revoked'] + $second['revoked']);
            self::assertSame(77, $first['skipped_unknown'] + $second['skipped_unknown']);
            self::assertSame($addresses, RevocationScriptedFetcher::$asked, 'each member asked about exactly once, in order');
            self::assertSame($kept, $this->remainingMembers(), 'exactly the unverifiable members and the holders were kept');
        }

        public function testATickThatRunsOutOfBudgetResumesOnTheFirstUnevaluatedMember(): void
        {
            // 300 units per tick, 100 per read: three members per tick.
            RevocationScriptedFetcher::$maxRequestsPerRead = 100;
            $addresses = $this->seedGroup(5);

            $first = (new NftGroupRevokeService())->sweep();
            self::assertTrue($first['stopped_on_budget']);
            self::assertSame(3, $first['revoked']);
            self::assertSame(array_slice($addresses, 0, 3), RevocationScriptedFetcher::$asked);

            $second = (new NftGroupRevokeService())->sweep();
            self::assertSame(2, $second['revoked']);
            self::assertSame($addresses, RevocationScriptedFetcher::$asked);
            self::assertSame([], $this->remainingMembers());
        }
    }
}
