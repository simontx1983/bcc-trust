<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security {
    // Namespace-scoped so it binds ONLY for AuditLogger and cannot collide
    // with a global definition another suite installs. AuditLogger calls
    // get_current_user_id() unqualified, so PHP resolves this first.
    if (!function_exists(__NAMESPACE__ . '\\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return $GLOBALS['bcc_itest_current_user'] ?? 0;
        }
    }
}

namespace BCC\Trust\Tests\Integration {

    use BCC\Core\Observability\DegradationMetrics;
    use BCC\Trust\Core\Database\TableRegistry;
    use BCC\Trust\Core\Security\AuditLogger;
    use PHPUnit\Framework\TestCase;

    /**
     * AuditLogger's write path end to end, against a REAL MySQL and the REAL
     * bcc-core DegradationMetrics.
     *
     * The headline requirement:
     * {@see testEncodingFailureStillWritesTheBaseRowAndEmitsTheMetric}.
     * §VIII.30 says an audit-log problem must never break the mutation that
     * has already committed. Persisting metadata adds a NEW way for the audit
     * write to go wrong — an unserialisable payload — and the answer must be
     * "keep the accountability row, lose only the context, and say so out
     * loud". Accountability is never traded for context.
     */
    final class AuditLoggerMetaWriteIntegrationTest extends TestCase
    {
        private const SUBSYSTEM = 'audit_log_swallow';
        private const EVENT     = 'meta_encode_failed';

        protected function setUp(): void
        {
            $GLOBALS['bcc_itest_current_user'] = 4242;
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['bcc_itest_current_user']);
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->query("DELETE FROM `" . TableRegistry::activity() . "` WHERE action LIKE 'itlog\\_%'");
        }

        public function testOrdinaryMetadataIsStoredAndRaisesNoDegradation(): void
        {
            $before = DegradationMetrics::readEvent(self::SUBSYSTEM, self::EVENT, time());

            AuditLogger::log('itlog_ok', 77, ['chain_slug' => 'solana', 'collection_id' => 100], 'onchain_collection');

            $row = $this->fetch('itlog_ok');
            self::assertNotNull($row, 'the audit row must exist');
            self::assertNotNull($row->meta, 'metadata must be stored');

            $decoded = json_decode((string) $row->meta, true);
            self::assertSame('solana', $decoded['chain_slug']);
            self::assertSame(100, $decoded['collection_id']);

            self::assertSame(
                $before,
                DegradationMetrics::readEvent(self::SUBSYSTEM, self::EVENT, time()),
                'a healthy write must not raise the failure metric'
            );
        }

        public function testEncodingFailureStillWritesTheBaseRowAndEmitsTheMetric(): void
        {
            $before = DegradationMetrics::readEvent(self::SUBSYSTEM, self::EVENT, time());

            // INF cannot be represented in JSON and defeats wp_json_encode
            // (its sanity check only repairs invalid UTF-8).
            AuditLogger::log('itlog_encfail', 99, ['ratio' => INF, 'note' => 'kept?'], 'user');

            $row = $this->fetch('itlog_encfail');

            // 1. The ordinary base row survives, fully populated.
            self::assertNotNull($row, 'THE BASE AUDIT ROW MUST SURVIVE an unencodable payload');
            self::assertSame(4242, (int) $row->user_id, 'actor attribution is preserved');
            self::assertSame('itlog_encfail', $row->action);
            self::assertSame('user', $row->target_type);
            self::assertSame(99, (int) $row->target_id);
            self::assertNotSame('', (string) $row->created_at);

            // 2. Only the context is lost, and it is lost as NULL.
            self::assertNull($row->meta, 'unencodable metadata is stored as NULL');

            // 3. And the loss is announced rather than silent.
            self::assertSame(
                $before + 1,
                DegradationMetrics::readEvent(self::SUBSYSTEM, self::EVENT, time()),
                'audit_log_swallow.meta_encode_failed must be recorded exactly once'
            );
        }

        public function testEmptyMetadataIsNullAndIsNotAnEncodingFailure(): void
        {
            $before = DegradationMetrics::readEvent(self::SUBSYSTEM, self::EVENT, time());

            AuditLogger::log('itlog_empty', 5, [], 'user');

            $row = $this->fetch('itlog_empty');
            self::assertNotNull($row);
            self::assertNull($row->meta, '"no metadata" is NULL');

            self::assertSame(
                $before,
                DegradationMetrics::readEvent(self::SUBSYSTEM, self::EVENT, time()),
                'passing no metadata is normal, not a failure'
            );
        }

        public function testRedactionAppliesOnTheRealWritePath(): void
        {
            // Proves the policy is not merely unit-testable but actually
            // reaches the stored row — the RateLimiter shape, end to end.
            AuditLogger::log('itlog_redact', 1, [
                'action'  => 'vote',
                'ip'      => '203.0.113.42',
                'count'   => 91,
            ], 'system');

            $row = $this->fetch('itlog_redact');
            self::assertNotNull($row);
            self::assertIsString($row->meta);

            self::assertStringNotContainsString('203.0.113.42', $row->meta, 'a raw IP must never reach the stored row');
            self::assertStringContainsString('203.0.113.***', $row->meta);
            self::assertStringContainsString('"count":91', $row->meta, 'non-sensitive context still survives');
        }

        public function testLogCheckedReturnsTheIdOfTheRowItWrote(): void
        {
            $id = AuditLogger::logChecked('itlog_checked', 8, ['before' => 'bozosgroup'], 'onchain_collection');

            self::assertIsInt($id);
            self::assertGreaterThan(0, $id);

            $wpdb = $GLOBALS['wpdb'];
            $row  = $wpdb->get_row($wpdb->prepare(
                'SELECT action, meta FROM `' . TableRegistry::activity() . '` WHERE id = %d',
                $id
            ));

            self::assertNotNull($row, 'the returned id must point at a real row');
            self::assertSame('itlog_checked', $row->action);
            self::assertStringContainsString('bozosgroup', (string) $row->meta);
        }

        // ===============================================================
        // The two paths must DIVERGE on identical input
        // ===============================================================

        public function testLogCheckedWritesNoRowAtAllWhenRequiredMetadataCannotBeEncoded(): void
        {
            $before = DegradationMetrics::readEvent(self::SUBSYSTEM, self::EVENT, time());

            $id = AuditLogger::logChecked('itlog_reqfail', 11, ['ratio' => INF, 'before' => 'bozosgroup'], 'onchain_collection');

            self::assertNull($id, 'logChecked() must REPORT FAILURE when required metadata is lost');

            // And crucially: no half-record. A base-only row for an action
            // named like a repair reads during forensics as "the repair
            // happened, details unavailable" — indistinguishable from success.
            self::assertSame(
                0,
                $this->countRows('itlog_reqfail'),
                'a base-only row must not be left behind to pass for a confirmed repair audit'
            );

            // The loss is still announced.
            self::assertSame(
                $before + 1,
                DegradationMetrics::readEvent(self::SUBSYSTEM, self::EVENT, time()),
                'meta_encode_failed is still recorded even though no row was written'
            );
        }

        public function testTheOrdinaryAndCheckedPathsDivergeOnIdenticalInput(): void
        {
            $payload = ['ratio' => INF, 'chain_slug' => 'solana'];

            // Ordinary path: §VIII.30 — the mutation already committed, so the
            // accountability row survives and only the context is lost.
            AuditLogger::log('itlog_divergea', 21, $payload, 'user');

            $ordinary = $this->fetch('itlog_divergea');
            self::assertNotNull($ordinary, 'log() keeps the base row');
            self::assertNull($ordinary->meta, 'log() degrades metadata to NULL');

            // Checked path: same payload, opposite outcome.
            $id = AuditLogger::logChecked('itlog_divergeb', 21, $payload, 'user');

            self::assertNull($id, 'logChecked() reports failure');
            self::assertSame(0, $this->countRows('itlog_divergeb'), 'logChecked() writes nothing');
        }

        public function testLogCheckedStillSucceedsWhenNoMetadataWasRequired(): void
        {
            // Empty metadata means "this action genuinely carries no context",
            // which is different from "the context was lost". It must not be
            // treated as a failure, or every context-free checked call breaks.
            $id = AuditLogger::logChecked('itlog_reqempty', 3, [], 'user');

            self::assertIsInt($id);
            self::assertGreaterThan(0, $id);
            self::assertSame(1, $this->countRows('itlog_reqempty'));
        }

        // ===============================================================
        // Stored-row negatives — what must NEVER reach the column
        // ===============================================================

        public function testSqlFragmentsAndFilePathsNeverReachTheStoredRow(): void
        {
            // The exact shape CronService passes: substr($e->getMessage(), …).
            AuditLogger::log('itlog_negsql', 12, [
                'failures'   => 3,
                'last_error' => 'SQLSTATE[42S02]: Base table not found: SELECT bcc_handle FROM wp_bcc_trust_votes '
                              . 'WHERE voter_user_id = 7 in /var/www/html/wp-content/plugins/bcc-trust/app/Domain/Core/Repositories/VoteRepository.php:412',
            ], 'system');

            $row = $this->fetch('itlog_negsql');
            self::assertNotNull($row);
            self::assertIsString($row->meta);

            foreach ([
                'SQLSTATE', 'SELECT', 'FROM wp_bcc', 'wp_bcc_trust_votes', 'bcc_handle',
                '/var/www/', 'wp-content', 'VoteRepository.php', ':412',
            ] as $fragment) {
                self::assertStringNotContainsString(
                    $fragment,
                    $row->meta,
                    "`{$fragment}` must never be durable in wp_bcc_trust_activity.meta"
                );
            }

            // Non-sensitive sibling context still survives, so the row is not
            // simply emptied.
            self::assertStringContainsString('"failures":3', $row->meta);
            self::assertStringContainsString('free_text', $row->meta);
        }

        public function testCredentialShapedStringsNeverReachTheStoredRow(): void
        {
            AuditLogger::log('itlog_negcred', 13, [
                'dsn'        => 'mysql://root:tr0ub4dor3@10.0.0.5:3306/bcc_prod',
                'header'     => 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig',
                'diagnostic' => 'connect failed api_key=AKIAIOSFODNN7EXAMPLE',
            ], 'system');

            $row = $this->fetch('itlog_negcred');
            self::assertNotNull($row);
            self::assertIsString($row->meta);

            foreach (['tr0ub4dor3', 'eyJhbGciOiJIUzI1NiJ9.payload.sig', 'AKIAIOSFODNN7EXAMPLE'] as $secret) {
                self::assertStringNotContainsString($secret, $row->meta, 'a credential must never be durable');
            }
        }

        public function testPrivateModerationTextNeverReachesTheStoredRow(): void
        {
            // Operator prose about a member, which may also name third parties.
            AuditLogger::log('itlog_negmod', 14, [
                'report_id'       => 55,
                'prior_reason_code' => 'harassment',
                'moderation_note' => 'Suspended after Dave reported him; he threatened to dox @sarah_h and emailed sarah@example.org',
            ], 'user');

            $row = $this->fetch('itlog_negmod');
            self::assertNotNull($row);
            self::assertIsString($row->meta);

            foreach (['Suspended after', 'Dave', 'threatened', 'dox', '@sarah_h', 'sarah@example.org'] as $fragment) {
                self::assertStringNotContainsString($fragment, $row->meta, 'private moderation prose must not be durable');
            }

            // The structured facts an audit legitimately needs DO survive.
            self::assertStringContainsString('"report_id":55', $row->meta);
            self::assertStringContainsString('harassment', $row->meta, 'a bounded reason CODE is not free text');
            self::assertStringContainsString('free_text', $row->meta);
        }

        private function countRows(string $action): int
        {
            $wpdb = $GLOBALS['wpdb'];

            return (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM `' . TableRegistry::activity() . '` WHERE action = %s',
                $action
            ));
        }

        private function fetch(string $action): ?object
        {
            $wpdb = $GLOBALS['wpdb'];

            return $wpdb->get_row($wpdb->prepare(
                'SELECT user_id, action, target_type, target_id, created_at, meta
                   FROM `' . TableRegistry::activity() . '`
                  WHERE action = %s ORDER BY id DESC LIMIT 1',
                $action
            ));
        }
    }
}
