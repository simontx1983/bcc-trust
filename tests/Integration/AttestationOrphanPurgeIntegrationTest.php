<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Repositories\AttestationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Attestation rows of deleted accounts (2026-11-18): two such rows survived
 * on production, so the account-deletion purge is hardened and the
 * survivors get a dry-run-first prune.
 *
 *   - deleteForUser() runs BOTH legs (rows cast, rows cast against the
 *     profile) and answers false, logged with the failing leg, when either
 *     did not run. A failure used to be ignored;
 *   - findOrphanedUserIds() / countOrphanedRows() find the accounts that no
 *     longer exist but still hold rows, cast or targeted, for the prune CLI.
 *
 * The attestation table is shared with sibling suites, so counts are read
 * as deltas and id lists are narrowed to this test's ids.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(AttestationRepository::class)]
final class AttestationOrphanPurgeIntegrationTest extends TestCase
{
    private const LIVE       = 986601;
    private const LIVE_OTHER = 986604;
    private const GONE_ATTESTOR = 986602; // cast rows, has no wp_users row
    private const GONE_TARGET   = 986603; // was vouched for, has no wp_users row
    private const MINE = [self::LIVE, self::LIVE_OTHER, self::GONE_ATTESTOR, self::GONE_TARGET];

    /** The attestor-keyed DELETE in deleteForUser(). */
    private const CAST_DELETE = '/^DELETE FROM\s+`?\S*bcc_trust_attestations`?\s+WHERE\s+`?attestor_user_id`?\s*=/';

    private AttestationRepository $repo;
    private bool $createdTable = false;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        Logger::reset();

        $att = TableRegistry::trustAttestations();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $att)) !== $att) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-trust-attestations.php';
            \bcc_trust_create_trust_attestations_table();
            $this->createdTable = true;
        }
        $this->repo = new AttestationRepository();
        $this->purge();

        foreach ([self::LIVE, self::LIVE_OTHER] as $id) {
            self::assertNotFalse($wpdb->insert($wpdb->users, ['ID' => $id, 'user_login' => 'aop_' . $id]), $wpdb->last_error);
        }
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->clearFaultInjection();
        $this->purge();
        if ($this->createdTable) {
            $wpdb->query('DROP TABLE IF EXISTS `' . TableRegistry::trustAttestations() . '`');
        }
    }

    private function purge(): void
    {
        global $wpdb;
        $att = TableRegistry::trustAttestations();
        foreach (self::MINE as $id) {
            $wpdb->delete($att, ['attestor_user_id' => $id]);
            $wpdb->delete($att, ['target_kind' => 'user_profile', 'target_id' => $id]);
            $wpdb->delete($wpdb->users, ['ID' => $id]);
        }
    }

    private function vouch(int $attestor, string $kind, int $target, bool $revoked = false): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(TableRegistry::trustAttestations(), [
            'attestor_user_id' => $attestor,
            'target_kind'      => $kind,
            'target_id'        => $target,
            'kind'             => 'vouch',
            'revoked_at'       => $revoked ? gmdate('Y-m-d H:i:s') : null,
        ]), $wpdb->last_error);
    }

    /** @return list<int> */
    private function myOrphans(): array
    {
        return array_values(array_intersect($this->repo->findOrphanedUserIds(), self::MINE));
    }

    private function rowsInvolving(int $userId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . TableRegistry::trustAttestations() . "` WHERE attestor_user_id = %d OR (target_kind = 'user_profile' AND target_id = %d)",
            $userId,
            $userId
        ));
    }

    public function testTheGoneAccountsAreFoundFromEitherSideAndCounted(): void
    {
        $before = $this->repo->countOrphanedRows();

        $this->vouch(self::GONE_ATTESTOR, 'user_profile', self::LIVE);        // cast by a gone account, active
        $this->vouch(self::GONE_ATTESTOR, 'validator_card', 986699, true);    // cast by a gone account, revoked
        $this->vouch(self::LIVE, 'user_profile', self::GONE_TARGET);          // against a gone profile, active
        $this->vouch(self::LIVE, 'user_profile', self::LIVE_OTHER);           // both alive: not an orphan

        self::assertSame([self::GONE_ATTESTOR, self::GONE_TARGET], $this->myOrphans(), 'ascending, live accounts excluded');

        $after = $this->repo->countOrphanedRows();
        self::assertSame(2, $after['cast'] - $before['cast'], 'rows cast by a gone account');
        self::assertSame(1, $after['targeted'] - $before['targeted'], 'rows cast against a gone profile');
        self::assertSame(2, $after['active'] - $before['active'], 'of which still active');
    }

    public function testThePurgeRemovesBothSidesAndTheOrphansAreGone(): void
    {
        $this->vouch(self::GONE_ATTESTOR, 'user_profile', self::LIVE);
        $this->vouch(self::LIVE, 'user_profile', self::GONE_TARGET);
        $this->vouch(self::LIVE, 'user_profile', self::LIVE_OTHER);

        self::assertTrue($this->repo->deleteForUser(self::GONE_ATTESTOR));
        self::assertTrue($this->repo->deleteForUser(self::GONE_TARGET));

        self::assertSame([], $this->myOrphans());
        self::assertSame(0, $this->rowsInvolving(self::GONE_ATTESTOR));
        self::assertSame(0, $this->rowsInvolving(self::GONE_TARGET));
        self::assertSame(1, $this->rowsInvolving(self::LIVE_OTHER), 'a live pair is untouched');
    }

    public function testAFailedLegAnswersFalseIsLoggedAndTheOtherLegStillRuns(): void
    {
        global $wpdb;
        $this->vouch(self::GONE_ATTESTOR, 'user_profile', self::LIVE);
        $this->vouch(self::LIVE, 'user_profile', self::GONE_ATTESTOR);

        $wpdb->failQueriesMatching = self::CAST_DELETE;
        try {
            $ok = $this->repo->deleteForUser(self::GONE_ATTESTOR);
        } finally {
            $hits = $wpdb->injectedFailures;
            $wpdb->clearFaultInjection();
        }

        self::assertSame(1, $hits, 'precondition: the cast DELETE was the query failed');
        self::assertFalse($ok, 'a delete that did not run is reported, never assumed');
        self::assertSame(1, $this->rowsInvolving(self::GONE_ATTESTOR), 'the targeted leg still ran; the cast row survived');

        $errors = array_values(array_filter(
            Logger::$lines,
            static fn (array $l): bool => $l['level'] === 'error' && $l['message'] === '[bcc-trust] attestation purge failed for a deleted account'
        ));
        self::assertCount(1, $errors);
        self::assertSame('cast', $errors[0]['context']['leg']);
    }
}
