<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Services\ProfileVouchLineResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-340: the profile trust header's vouch line, on real rows.
 *
 *   - Signed in: "Vouched for by N people you watch" — N is exact: active
 *     `vouch` attestations on the owner's profile whose giver the viewer
 *     follows (watching a person = following them).
 *   - Signed out: "Top vouched by" — the eligible vouchers, highest trust
 *     score first, at most 3.
 *
 * Every row below is a voucher the line must (or must not) count. The
 * eligible set is a strict subset of what the public roster shows: vouch
 * only, not revoked, attestor exists, not suspended; signed in, also not
 * mutually blocked with the viewer. A hidden real name is NOT hidden
 * participation: that voucher counts, as on the roster.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(AttestationRepository::class)]
#[CoversClass(ProfileVouchLineResolver::class)]
final class ProfileVouchLineIntegrationTest extends TestCase
{
    private const OWNER  = 934001;
    private const VIEWER = 934002;

    private const WATCHED_A   = 934011; // followed, vouches          → counts
    private const WATCHED_B   = 934012; // followed, vouches          → counts
    private const WATCHED_C   = 934013; // followed, vouches          → counts
    private const UNWATCHED   = 934014; // NOT followed, vouches      → only "top"
    private const SUSPENDED   = 934015; // followed, vouches, suspended → never
    private const NAME_HIDDEN = 934016; // followed, vouches, real name hidden → COUNTS (as the roster shows them)
    private const BLOCKED     = 934017; // followed, vouches, blocked by the viewer → not counted (signed in)
    private const BACKER      = 934018; // followed, stand_behind only → never ("Vouched")
    private const REVOKED     = 934019; // followed, vouch revoked    → never

    private const ALL_USERS = [
        self::OWNER, self::VIEWER, self::WATCHED_A, self::WATCHED_B, self::WATCHED_C, self::UNWATCHED,
        self::SUSPENDED, self::NAME_HIDDEN, self::BLOCKED, self::BACKER, self::REVOKED,
    ];

    /** @var list<string> */
    private array $createdTables = [];

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->ensureTables();
        $this->purge();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->purge();
        global $wpdb;
        foreach ($this->createdTables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function testTheSignedInCountIsExactlyTheWatchedEligibleVouchers(): void
    {
        $line = $this->resolver()->forProfile(self::OWNER, self::VIEWER);

        self::assertSame(4, $line['vouched_by_watched_count'], 'A, B, C and the name-hidden voucher; not the unwatched, suspended, blocked, backer or revoked rows');
        self::assertSame([], $line['top_vouchers'], 'the avatar list is for signed-out viewers only');
    }

    public function testThereIsNoLineOnYourOwnProfile(): void
    {
        self::assertNull($this->resolver()->forProfile(self::OWNER, self::OWNER)['vouched_by_watched_count']);
    }

    public function testAViewerWhoWatchesNoVoucherGetsZeroWhichHidesTheLine(): void
    {
        self::assertSame(0, $this->resolver()->forProfile(self::OWNER, self::UNWATCHED)['vouched_by_watched_count']);
    }

    public function testSignedOutTopVouchersAreTheEligibleOnesByScore(): void
    {
        // Eligible for a signed-out view: A, B, C, UNWATCHED, BLOCKED (a
        // block is between two signed-in members) and NAME_HIDDEN. Scores:
        // A 90, UNWATCHED 80, C 70, BLOCKED 60; B and NAME_HIDDEN have no
        // row (neutral 50), so the newer vouch (NAME_HIDDEN) comes first.
        self::assertSame(
            [self::WATCHED_A, self::UNWATCHED, self::WATCHED_C],
            (new AttestationRepository())->topProfileVoucherIds(self::OWNER, 3)
        );
        self::assertSame(
            [self::WATCHED_A, self::UNWATCHED, self::WATCHED_C, self::BLOCKED, self::NAME_HIDDEN, self::WATCHED_B],
            (new AttestationRepository())->topProfileVoucherIds(self::OWNER, 10),
            'and nobody ineligible ever appears'
        );
    }

    public function testAFailedReadIsUnknownNeverAWrongNumber(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/bcc_trust_attestations/';

        $line = $this->resolver()->forProfile(self::OWNER, self::VIEWER);

        self::assertNull($line['vouched_by_watched_count'], 'unknown, not 0');
        self::assertSame([], $line['top_vouchers']);
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault must actually have fired');
    }

    // ── fixture ─────────────────────────────────────────────────────────

    private function resolver(): ProfileVouchLineResolver
    {
        return new ProfileVouchLineResolver(new AttestationRepository());
    }

    private function seed(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach (self::ALL_USERS as $id) {
            self::assertNotFalse($wpdb->insert($wpdb->users, ['ID' => $id, 'user_login' => 'f340_' . $id]), $wpdb->last_error);
        }

        // The viewer follows every voucher except UNWATCHED.
        foreach ([self::WATCHED_A, self::WATCHED_B, self::WATCHED_C, self::SUSPENDED, self::NAME_HIDDEN, self::BLOCKED, self::BACKER, self::REVOKED] as $id) {
            self::assertNotFalse($wpdb->insert($p . 'peepso_user_followers', [
                'uf_active_user_id' => self::VIEWER, 'uf_passive_user_id' => $id, 'uf_follow' => 1,
            ]), $wpdb->last_error);
        }

        $n = 0;
        foreach ([self::WATCHED_A, self::WATCHED_B, self::WATCHED_C, self::UNWATCHED, self::SUSPENDED, self::NAME_HIDDEN, self::BLOCKED, self::REVOKED] as $id) {
            $this->attest($id, 'vouch', $id === self::REVOKED ? '2026-11-02 00:00:00' : null, ++$n);
        }
        $this->attest(self::BACKER, 'stand_behind', null, ++$n);

        self::assertNotFalse($wpdb->insert($p . 'bcc_trust_user_info', [
            'user_id' => self::SUSPENDED, 'user_login' => 'f340_s', 'user_email' => 's@example.test', 'display_name' => 'S', 'is_suspended' => 1,
        ]), $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($wpdb->usermeta, [
            'user_id' => self::NAME_HIDDEN, 'meta_key' => 'bcc_privacy_real_name_hidden', 'meta_value' => '1',
        ]), $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($p . 'peepso_blocks', [
            'blk_user_id' => self::VIEWER, 'blk_blocked_id' => self::BLOCKED,
        ]), $wpdb->last_error);

        foreach ([self::WATCHED_A => 90, self::UNWATCHED => 80, self::WATCHED_C => 70, self::BLOCKED => 60] as $id => $score) {
            self::assertNotFalse($wpdb->insert($p . 'bcc_trust_page_scores', [
                'page_id'        => \BCC\Trust\Core\Services\MemberSelfPageService::ID_BASE + $id,
                'category_id'    => 0,
                'page_owner_id'  => $id,
                'total_score'    => $score,
            ]), $wpdb->last_error);
        }
    }

    private function attest(int $attestor, string $kind, ?string $revokedAt, int $n): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(\BCC\Trust\Core\Database\TableRegistry::trustAttestations(), [
            'attestor_user_id' => $attestor,
            'target_kind'      => 'user_profile',
            'target_id'        => self::OWNER,
            'kind'             => $kind,
            'weight_at_time'   => 1.0,
            'attestation_order_in_target' => $n,
            'created_at'       => sprintf('2026-11-01 00:%02d:00', $n),
            'revoked_at'       => $revokedAt,
        ]), $wpdb->last_error);
    }

    private function purge(): void
    {
        global $wpdb;
        $p   = $wpdb->prefix;
        $ids = implode(',', array_map('intval', self::ALL_USERS));
        $wpdb->query("DELETE FROM `" . \BCC\Trust\Core\Database\TableRegistry::trustAttestations() . "` WHERE target_id = " . self::OWNER . " AND target_kind = 'user_profile'");
        $wpdb->query("DELETE FROM `{$p}peepso_user_followers` WHERE uf_active_user_id = " . self::VIEWER);
        $wpdb->query("DELETE FROM `{$p}peepso_blocks` WHERE blk_user_id IN ({$ids})");
        $wpdb->query("DELETE FROM `{$p}bcc_trust_user_info` WHERE user_id IN ({$ids})");
        $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE user_id IN ({$ids})");
        $wpdb->query("DELETE FROM `{$p}bcc_trust_page_scores` WHERE page_owner_id IN ({$ids}) AND page_id > " . \BCC\Trust\Core\Services\MemberSelfPageService::ID_BASE);
        $wpdb->query("DELETE FROM {$wpdb->users} WHERE ID IN ({$ids})");
    }

    /**
     * Created only when absent (and dropped again); widened only with the
     * columns read here, because other suites leave stunted copies behind.
     */
    private function ensureTables(): void
    {
        global $wpdb;
        $p   = $wpdb->prefix;
        $att = \BCC\Trust\Core\Database\TableRegistry::trustAttestations();

        $tables = [
            $p . 'peepso_user_followers' => "uf_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, uf_passive_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, uf_active_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, uf_follow TINYINT(1) NOT NULL DEFAULT 1",
            $p . 'peepso_blocks'         => 'blk_user_id BIGINT UNSIGNED NOT NULL, blk_blocked_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (blk_user_id, blk_blocked_id)',
            $wpdb->usermeta              => 'umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL',
        ];
        foreach ($tables as $table => $columns) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                $this->createdTables[] = $table;
                self::assertNotFalse($wpdb->query("CREATE TABLE `{$table}` ({$columns})"), $wpdb->last_error);
            }
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $att)) !== $att) {
            $this->createdTables[] = $att;
            require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';
            \bcc_trust_create_trust_attestations_table();
        }

        foreach ([
            $p . 'peepso_user_followers' => ['uf_active_user_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0', 'uf_follow' => 'TINYINT(1) NOT NULL DEFAULT 1'],
            $att => [
                'attestor_user_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'target_kind'      => "VARCHAR(32) NOT NULL DEFAULT ''",
                'target_id'        => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'kind'             => "VARCHAR(32) NOT NULL DEFAULT 'vouch'",
                'weight_at_time'   => 'DECIMAL(10,4) NOT NULL DEFAULT 0',
                'attestation_order_in_target' => 'INT NOT NULL DEFAULT 0',
                'created_at'       => 'DATETIME NULL',
                'revoked_at'       => 'DATETIME NULL',
            ],
        ] as $table => $wanted) {
            $existing = (array) $wpdb->get_col($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));
            foreach ($wanted as $column => $definition) {
                if (!in_array($column, $existing, true)) {
                    self::assertNotFalse($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}"), $wpdb->last_error);
                }
            }
        }
    }
}
