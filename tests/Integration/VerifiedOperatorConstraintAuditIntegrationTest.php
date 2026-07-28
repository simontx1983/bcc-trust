<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins scripts/claims-verified-operator-constraint.php — the hand-run
 * pre-rollout audit + migration that guards "at most one verified operator
 * per (entity_type, entity_id)" — against a real MySQL.
 *
 * The load-bearing assertion (C1 hardening): the duplicate audit counts
 * verified-operator claim ROWS (`COUNT(*)`), which is exactly what the
 * UNIQUE key on the generated `verified_operator_slot` rejects. Counting
 * rows — not `COUNT(DISTINCT user_id)` — means the audit catches even two
 * verified-operator rows for the SAME user+entity, so it no longer depends
 * on the separate `uq_user_entity` key existing. This test drops
 * `uq_user_entity` in one case to prove that independence directly.
 *
 * Each test drives the real script via `include` (the same top-level
 * `$args`/`$wpdb`/ABSPATH contract as `wp eval-file`) and asserts on both
 * its printed report and the resulting schema/rows.
 */
#[Group('integration')]
final class VerifiedOperatorConstraintAuditIntegrationTest extends TestCase
{
    private const CHAIN_ID = 8;
    private const USER_A   = 240;
    private const USER_B   = 241;
    private const USER_C   = 242;

    private const SCRIPT = __DIR__ . '/../../scripts/claims-verified-operator-constraint.php';

    protected function setUp(): void
    {
        global $wpdb;
        $claims = $wpdb->prefix . 'bcc_onchain_claims';

        $wpdb->query('TRUNCATE TABLE `' . $claims . '`');
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_onchain_validators`');

        // Reset the constraint the `apply` path adds, so tests are hermetic
        // regardless of order (the throwaway DB persists across a run).
        if ($this->constraintColumnExists()) {
            $wpdb->query(
                'ALTER TABLE `' . $claims . '`'
                . ' DROP INDEX uq_verified_operator, DROP COLUMN verified_operator_slot'
            );
        }
        // Restore uq_user_entity if a prior case dropped it.
        if (!$this->indexExists('uq_user_entity')) {
            $wpdb->query(
                'ALTER TABLE `' . $claims . '`'
                . ' ADD UNIQUE KEY uq_user_entity (user_id, entity_type, entity_id)'
            );
        }
    }

    // ── Cases ────────────────────────────────────────────────────────────

    /** Two verified operators, DIFFERENT users, same entity → dirty + apply refused. */
    public function testTwoVerifiedOperatorsDifferentUsersSameEntityIsDirtyAndRefusesApply(): void
    {
        $vId = $this->insertValidator();
        $this->insertClaim($vId, 'operator', 'verified', self::USER_A);
        $this->insertClaim($vId, 'operator', 'verified', self::USER_B);

        $audit = $this->runScript(false);
        self::assertStringContainsString('DUPLICATES (1)', $audit);
        self::assertStringContainsString('2 verified operator claim rows', $audit);

        $apply = $this->runScript(true);
        self::assertStringContainsString('REFUSED', $apply);
        self::assertFalse($this->constraintColumnExists(), 'apply must not add the column on a dirty audit');
    }

    /**
     * Two verified-operator ROWS, SAME user+entity → dirty + apply refused.
     * This is the row set the old `COUNT(DISTINCT user_id)` audit MISSED.
     * uq_user_entity normally forbids it, so we drop that key first — which
     * also demonstrates the audit no longer leans on it.
     */
    public function testTwoVerifiedOperatorRowsSameUserSameEntityIsDirtyAndRefusesApply(): void
    {
        global $wpdb;
        $claims = $wpdb->prefix . 'bcc_onchain_claims';

        $vId = $this->insertValidator();
        $wpdb->query('ALTER TABLE `' . $claims . '` DROP INDEX uq_user_entity');
        $this->insertClaim($vId, 'operator', 'verified', self::USER_A);
        $this->insertClaim($vId, 'operator', 'verified', self::USER_A);

        // Sanity: the OLD audit (COUNT DISTINCT user_id) would have called this CLEAN.
        $distinct = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM `{$claims}`"
            . " WHERE status='verified' AND claim_role='operator'"
            . ' GROUP BY entity_type, entity_id'
        );
        self::assertSame(1, $distinct, 'precondition: distinct-user count is 1, which the old audit would pass');

        $audit = $this->runScript(false);
        self::assertStringContainsString('DUPLICATES (1)', $audit);
        self::assertStringContainsString('2 verified operator claim rows', $audit);

        $apply = $this->runScript(true);
        self::assertStringContainsString('REFUSED', $apply);
        self::assertFalse($this->constraintColumnExists(), 'apply must not add the column on a dirty audit');
    }

    /** Non-verified status and non-operator role are not counted → clean + apply succeeds. */
    public function testNonVerifiedAndNonOperatorRowsAreNotCountedAndApplySucceeds(): void
    {
        $vId = $this->insertValidator();
        // The single legitimate verified operator.
        $this->insertClaim($vId, 'operator', 'verified', self::USER_A);
        // Noise that must NOT count as a duplicate:
        $this->insertClaim($vId, 'operator', 'pending', self::USER_B);   // not verified
        $this->insertClaim($vId, 'creator', 'verified', self::USER_C);   // not operator

        $audit = $this->runScript(false);
        self::assertStringContainsString('Duplicate audit: CLEAN', $audit);

        $apply = $this->runScript(true);
        self::assertStringContainsString('APPLIED', $apply);
        self::assertTrue($this->constraintColumnExists(), 'clean apply must add the constraint column');
    }

    /** Clean apply → the constraint then rejects a second verified operator; NULL-slot rows still insert. */
    public function testCleanApplyThenConstraintRejectsSecondVerifiedOperator(): void
    {
        global $wpdb;

        $vId = $this->insertValidator();
        $this->insertClaim($vId, 'operator', 'verified', self::USER_A);

        $apply = $this->runScript(true);
        self::assertStringContainsString('APPLIED', $apply);

        // A 2nd verified operator (different user, so uq_user_entity allows it)
        // must now be rejected by uq_verified_operator.
        $second = $this->insertClaim($vId, 'operator', 'verified', self::USER_B);
        self::assertFalse($second, 'second verified operator must be rejected by the constraint');
        self::assertStringContainsStringIgnoringCase('duplicate', (string) $wpdb->last_error);

        // A non-operator verified row has a NULL slot and must still insert.
        $creator = $this->insertClaim($vId, 'creator', 'verified', self::USER_C);
        self::assertNotFalse($creator, 'NULL-slot (non-operator) rows must not collide');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Drives the real script with the given `apply` flag; returns its printed report. */
    private function runScript(bool $apply): string
    {
        // Consumed by the script via `isset($args) && in_array('apply', ...)`.
        $args = $apply ? ['apply'] : []; // phpcs:ignore
        ob_start();
        include self::SCRIPT;
        return (string) ob_get_clean();
    }

    private function insertValidator(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id'   => null,
            'operator_address' => 'cosmosvaloper1fixture' . uniqid(),
            'chain_id'         => self::CHAIN_ID,
            'moniker'          => 'Fixture Validator',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * Direct insert (verified rows carry an explicit verified_at so we skip
     * upsert()'s NULL-verified_at strict-mode issue). Returns the new id, or
     * false when the DB rejects the row (e.g. a unique-key violation).
     *
     * @return int|false
     */
    private function insertClaim(int $entityId, string $role, string $status, int $userId)
    {
        global $wpdb;
        $data = [
            'user_id'        => $userId,
            'entity_type'    => 'validator',
            'entity_id'      => $entityId,
            'wallet_address' => 'cosmos1' . $userId . '_' . $entityId . '_' . $role,
            'chain_id'       => self::CHAIN_ID,
            'claim_role'     => $role,
            'status'         => $status,
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ];
        if ($status === 'verified') {
            $data['verified_at'] = gmdate('Y-m-d H:i:s');
        }
        $r = $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', $data);
        return $r === false ? false : (int) $wpdb->insert_id;
    }

    private function constraintColumnExists(): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                AND COLUMN_NAME = 'verified_operator_slot'",
            $wpdb->prefix . 'bcc_onchain_claims'
        )) > 0;
    }

    private function indexExists(string $indexName): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                AND INDEX_NAME = %s",
            $wpdb->prefix . 'bcc_onchain_claims',
            $indexName
        )) > 0;
    }
}
