<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRelationshipLogRepository as Log;
use BCC\Trust\Onchain\Repositories\ValidatorStepUpRepository as StepUps;
use BCC\Trust\Onchain\Services\PageOwnershipAligner;
use BCC\Trust\Onchain\Services\ValidatorRelationshipService as Service;
use BCC\Trust\Onchain\Services\ValidatorStepUpService;
use BCC\Trust\Onchain\Support\RelationshipLogAnchor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The staff owner link / revoke / cancel on a real engine: the step-up is
 * spent, the claim rows and the log row commit together (or not at all), D3
 * refuses any change against an owner proven by a wallet, and the page is
 * queued for the ownership aligner.
 *
 * Separate processes: the test defines BCC_ENCRYPTION_KEY and WordPress
 * hook shims that must not leak.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(Service::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorRelationshipServiceIntegrationTest extends TestCase
{
    private const STAFF = 971001;
    private const OWNER = 971002;
    private const OTHER = 971003;
    private const PAGE  = 971101;
    private const CODE  = '424242';

    private int $validatorId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BCC_ENCRYPTION_KEY')) {
            define('BCC_ENCRYPTION_KEY', 'relationship-service-test-key');
        }
        if (!function_exists('do_action')) {
            eval('function do_action(string $hook, ...$args): void { $GLOBALS["__actions"][] = [$hook, ...$args]; }');
        }
        if (!function_exists('wp_next_scheduled')) {
            eval('function wp_next_scheduled(string $hook, array $args = []): int|false { return false; }');
        }
        $GLOBALS['__actions']      = [];
        $GLOBALS['bcc_scheduled']  = [];
        $GLOBALS['__bcc_ineligible_targets'] = [];
        require_once dirname(__DIR__) . '/Stubs/validator-link-target-stub.php';
        $this->cleanUp();

        global $wpdb;
        $wpdb->insert(ChainRepository::table(), ['slug' => 'vrs-chain', 'name' => 'VRS', 'chain_type' => 'cosmos']);
        $chainId = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id' => null, 'operator_address' => 'cosmosvaloper1vrs' . uniqid(), 'chain_id' => $chainId,
            'moniker' => 'VRS Fixture', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        $this->validatorId = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->postmeta, ['post_id' => self::PAGE, 'meta_key' => '_bcc_onchain_validator_id', 'meta_value' => (string) $this->validatorId]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM `' . ClaimRepository::table() . '` WHERE user_id IN (%d, %d)', self::OWNER, self::OTHER));
        $wpdb->query($wpdb->prepare('DELETE FROM `' . StepUps::table() . '` WHERE user_id = %d', self::STAFF));
        $wpdb->query('DELETE FROM `' . Log::table() . '`');
        $wpdb->query("DELETE FROM `{$wpdb->prefix}bcc_onchain_validators` WHERE moniker = 'VRS Fixture'");
        $wpdb->query("DELETE FROM `" . ChainRepository::table() . "` WHERE slug = 'vrs-chain'");
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d AND meta_key = '_bcc_onchain_validator_id'", self::PAGE));
        delete_option(RelationshipLogAnchor::OPTION);
        delete_option(PageOwnershipAligner::PENDING);
    }

    private static function stepUp(string $action, int $targetUserId): string
    {
        $id = bin2hex(random_bytes(16));
        StepUps::create([
            'id' => $id, 'user_id' => self::STAFF, 'action' => $action, 'page_id' => self::PAGE,
            'target_user_id' => $targetUserId, 'role' => 'owner', 'code_hash' => ValidatorStepUpService::codeHash($id, self::CODE),
            'created_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 600), 'resend_after' => null,
        ]);

        return $id;
    }

    private function seedOwner(int $userId, string $method): int
    {
        global $wpdb;
        foreach ([['validator', $this->validatorId], ['page', self::PAGE]] as [$type, $entity]) {
            $wpdb->insert(ClaimRepository::table(), [
                'user_id' => $userId, 'entity_type' => $type, 'entity_id' => $entity,
                'wallet_address' => $method === 'staff' ? '' : 'cosmos1own', 'chain_id' => $method === 'staff' ? 0 : 7,
                'claim_role' => 'operator', 'status' => 'verified', 'verification_method' => $method,
                'verified_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if ($type === 'validator') {
                $claimId = (int) $wpdb->insert_id;
            }
        }

        return $claimId ?? 0;
    }

    /** @return array<string, mixed> */
    private function link(int $userId, ?string $stepUpId = null, string $code = self::CODE, ?string $ref = 'https://operator.example/proof'): array
    {
        return Service::linkOwnerByStaff(self::STAFF, self::PAGE, $userId, 'website', 'Confirmed on the operator site.', $ref, $stepUpId ?? self::stepUp('link_owner', $userId), $code);
    }

    /** @return list<string> */
    private static function logActions(): array
    {
        return array_map(static fn (object $r): string => (string) $r->action, Log::rowsAfter(0, 50));
    }

    public function testAStaffLinkWritesTheOwnerAndTheLogTogether(): void
    {
        $stepUp = self::stepUp('link_owner', self::OWNER);

        $result = $this->link(self::OWNER, $stepUp);

        self::assertTrue($result['ok']);
        self::assertSame(['state' => 'RESOLVED', 'user_id' => self::OWNER], ClaimRepository::resolveVerifiedOperatorForPageStrict(self::PAGE));
        self::assertSame([['claim_id' => $result['link_id'], 'user_id' => self::OWNER, 'verification_method' => 'staff']], ClaimRepository::ownerClaimsForPageStrict(self::PAGE));
        self::assertSame(['owner_linked'], self::logActions());
        $row = Log::rowsAfter(0, 1)[0];
        self::assertSame(['staff', (string) self::STAFF, (string) self::OWNER], [$row->actor_kind, (string) $row->actor_user_id, (string) $row->subject_user_id]);
        self::assertSame(['seq' => 1, 'row_hash' => $row->row_hash], RelationshipLogAnchor::get(), 'the anchor follows the head');
        self::assertNotNull(StepUps::find($stepUp, self::STAFF)?->used_at, 'the step-up is spent');
        self::assertContains(['hook' => PageOwnershipAligner::HOOK, 'args' => [self::PAGE]], array_map(static fn (array $s): array => ['hook' => $s['hook'], 'args' => $s['args']], $GLOBALS['bcc_scheduled']));
        self::assertContains('bcc_validator_relationship_changed', array_column($GLOBALS['__actions'], 0));
    }

    public function testAWalletProvenOwnerIsLockedForStaff(): void
    {
        $this->seedOwner(self::OTHER, 'wallet');
        $stepUp = self::stepUp('link_owner', self::OWNER);

        self::assertSame(['ok' => false, 'reason' => 'wallet_owner_present', 'status' => 409], $this->link(self::OWNER, $stepUp));
        self::assertSame([], self::logActions(), 'nothing written');
        self::assertNull(StepUps::find($stepUp, self::STAFF)?->used_at, 'the step-up spend rolled back with the change');
        self::assertSame(self::OTHER, ClaimRepository::resolveVerifiedOperatorForPageStrict(self::PAGE)['user_id']);
    }

    public function testAnUnknownMethodIsLockedForStaffToo(): void
    {
        $this->seedOwner(self::OTHER, 'mystery');

        self::assertSame('wallet_owner_present', $this->link(self::OWNER)['reason']);
    }

    /** Exclusivity reads the page's own owner rows, not only the claims the resolvers see. */
    public function testAProofOwnerRowOnThePageAloneIsLockedForStaff(): void
    {
        global $wpdb;
        $wpdb->insert(ClaimRepository::table(), [
            'user_id' => self::OTHER, 'entity_type' => 'page', 'entity_id' => self::PAGE,
            'wallet_address' => 'cosmos1own', 'chain_id' => 7, 'claim_role' => 'operator',
            'status' => 'verified', 'verification_method' => 'wallet', 'verified_at' => gmdate('Y-m-d H:i:s'),
        ]);

        self::assertSame('wallet_owner_present', $this->link(self::OWNER)['reason']);
        self::assertSame([], self::logActions());
    }

    /** ...and every OTHER page bound to the validator. */
    public function testAnOwnerRowOnAnotherBoundPageIsAnIncumbentToo(): void
    {
        global $wpdb;
        $other = self::PAGE + 1;
        $wpdb->insert($wpdb->postmeta, ['post_id' => $other, 'meta_key' => '_bcc_onchain_validator_id', 'meta_value' => (string) $this->validatorId]);
        $wpdb->insert(ClaimRepository::table(), [
            'user_id' => self::OTHER, 'entity_type' => 'page', 'entity_id' => $other,
            'wallet_address' => '', 'chain_id' => 0, 'claim_role' => 'operator',
            'status' => 'verified', 'verification_method' => 'staff', 'verified_by' => self::STAFF, 'verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
        try {
            self::assertSame('owner_exists', $this->link(self::OWNER)['reason']);
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d AND meta_key = '_bcc_onchain_validator_id'", $other));
        }
    }

    // ── a wallet proof claims the validator (the page-claim flow) ──────

    /** @return array<string, mixed> */
    private function walletClaim(int $userId): array
    {
        return Service::claimByWallet($userId, $this->validatorId, 'cosmos1proof' . $userId, 7, self::PAGE);
    }

    /** @return array{status: string, method: string}|null */
    private function row(int $userId, string $type, int $entity): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            'SELECT status, verification_method, claim_role FROM `' . ClaimRepository::table() . '` WHERE user_id = %d AND entity_type = %s AND entity_id = %d',
            $userId,
            $type,
            $entity
        ));

        return $r === null ? null : ['status' => (string) $r->status, 'method' => (string) $r->verification_method, 'role' => (string) $r->claim_role];
    }

    /** Phillip's precedence: a wallet proof supersedes a staff approval, in one run. */
    public function testAWalletProofSupersedesAStaffApprovedOwner(): void
    {
        $this->seedOwner(self::OTHER, 'staff');

        $result = $this->walletClaim(self::OWNER);

        self::assertTrue($result['success']);
        self::assertSame([self::OTHER], $result['superseded_user_ids']);
        self::assertTrue($result['mirror_written']);
        self::assertSame('revoked', $this->row(self::OTHER, 'validator', $this->validatorId)['status'] ?? null);
        self::assertSame('revoked', $this->row(self::OTHER, 'page', self::PAGE)['status'] ?? null, 'the staff owner\'s page mirror too');
        self::assertSame(['status' => 'verified', 'method' => 'wallet', 'role' => 'operator'], $this->row(self::OWNER, 'validator', $this->validatorId));
        self::assertSame(['status' => 'verified', 'method' => 'wallet', 'role' => 'operator'], $this->row(self::OWNER, 'page', self::PAGE), 'the mirror is a wallet claim like the claim');
        self::assertSame(['state' => 'RESOLVED', 'user_id' => self::OWNER], ClaimRepository::resolveVerifiedOperatorForPageStrict(self::PAGE));
        self::assertSame(['owner_superseded', 'owner_linked'], self::logActions());
        $rows = Log::rowsAfter(0, 5);
        self::assertSame([(string) self::OTHER, 'member', (string) self::OWNER], [(string) $rows[0]->subject_user_id, $rows[0]->actor_kind, (string) $rows[0]->actor_user_id]);
        self::assertSame(['seq' => 2, 'row_hash' => $rows[1]->row_hash], RelationshipLogAnchor::get(), 'anchored after the commit');
        self::assertContains(['hook' => PageOwnershipAligner::HOOK, 'args' => [self::PAGE]], array_map(static fn (array $s): array => ['hook' => $s['hook'], 'args' => $s['args']], $GLOBALS['bcc_scheduled']), 'the page swap is left to the aligner');
        $changed = array_values(array_filter($GLOBALS['__actions'], static fn (array $a): bool => $a[0] === 'bcc_validator_relationship_changed'));
        self::assertSame('owner_superseded', $changed[0][1] ?? null);
        self::assertSame(self::OTHER, $changed[0][3] ?? null, 'the previous owner is the subject');
    }

    public function testAWalletProofNeverSupersedesAProofOwner(): void
    {
        $this->seedOwner(self::OTHER, 'wallet');

        self::assertSame(['success' => false, 'error' => 'already_claimed', 'message' => 'This project already has a verified Operator.'], $this->walletClaim(self::OWNER));
        self::assertSame('verified', $this->row(self::OTHER, 'validator', $this->validatorId)['status'] ?? null);
        self::assertNull($this->row(self::OWNER, 'validator', $this->validatorId));
        self::assertSame([], self::logActions(), 'nothing written');
    }

    public function testAnOwnerOfUnknownMethodIsNeverSupersededEither(): void
    {
        $this->seedOwner(self::OTHER, 'mystery');

        self::assertSame('already_claimed', $this->walletClaim(self::OWNER)['error'] ?? null);
        self::assertSame([], self::logActions());
    }

    public function testAFirstWalletClaimIsLinkedAndMirrored(): void
    {
        $result = $this->walletClaim(self::OWNER);

        self::assertSame([true, [], true], [$result['success'], $result['superseded_user_ids'], $result['mirror_written']]);
        self::assertSame(['owner_linked'], self::logActions());
        self::assertSame('wallet', $this->row(self::OWNER, 'page', self::PAGE)['method'] ?? null);
    }

    public function testAReClaimBySameUserIsIdempotent(): void
    {
        $first  = $this->walletClaim(self::OWNER);
        $second = $this->walletClaim(self::OWNER);

        self::assertSame($first['claim_id'], $second['claim_id']);
        self::assertSame(['owner_linked'], self::logActions(), 'one record, not two');
    }

    /** C-a+: a team member's own wallet proof ends their membership on the record. */
    public function testATeamMembersWalletClaimEndsTheirMembershipOnTheRecord(): void
    {
        global $wpdb;
        $wpdb->insert(ClaimRepository::table(), [
            'user_id' => self::OWNER, 'entity_type' => 'page', 'entity_id' => self::PAGE,
            'wallet_address' => '', 'chain_id' => 0, 'claim_role' => 'representative',
            'status' => 'active', 'verification_method' => 'staff', 'verified_by' => self::STAFF,
        ]);

        self::assertTrue($this->walletClaim(self::OWNER)['success']);

        self::assertSame(['team_member_removed', 'owner_linked'], self::logActions());
        self::assertSame(['status' => 'verified', 'method' => 'wallet', 'role' => 'operator'], $this->row(self::OWNER, 'page', self::PAGE));
        self::assertEquals(['role' => 'representative', 'reason' => 'became_owner'], json_decode((string) Log::rowsAfter(0, 1)[0]->payload, true));
    }

    public function testAnotherUsersOwnerRowOnThePageSkipsOnlyTheMirror(): void
    {
        global $wpdb;
        $wpdb->insert(ClaimRepository::table(), [
            'user_id' => self::OTHER, 'entity_type' => 'page', 'entity_id' => self::PAGE,
            'wallet_address' => 'cosmos1x', 'chain_id' => 7, 'claim_role' => 'operator',
            'status' => 'verified', 'verification_method' => 'wallet', 'verified_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = $this->walletClaim(self::OWNER);

        self::assertSame([true, false], [$result['success'], $result['mirror_written']]);
        self::assertSame('verified', $this->row(self::OWNER, 'validator', $this->validatorId)['status'] ?? null, 'the claim stands');
        self::assertNull($this->row(self::OWNER, 'page', self::PAGE));
    }

    /** Security #881: a link to a member who is not eligible is refused INSIDE the run. */
    public function testALinkToAnIneligibleMemberIsRefusedAndNothingIsWritten(): void
    {
        $GLOBALS['__bcc_ineligible_targets'] = [self::OWNER];
        $stepUp = self::stepUp('link_owner', self::OWNER);

        self::assertSame(['ok' => false, 'reason' => 'target_not_eligible', 'status' => 422], $this->link(self::OWNER, $stepUp));
        self::assertNull(ClaimRepository::getUserClaim(self::OWNER, 'validator', $this->validatorId, ['operator']));
        self::assertSame([], self::logActions());
        self::assertNull(StepUps::find($stepUp, self::STAFF)?->used_at, 'the step-up is not spent');
    }

    /** Security #881 belt: a page that is not bound to the validator is never mirrored. */
    public function testAWalletClaimNeverMirrorsAnUnboundPage(): void
    {
        $result = Service::claimByWallet(self::OWNER, $this->validatorId, 'cosmos1proof', 7, self::PAGE + 50);

        self::assertSame([true, false], [$result['success'], $result['mirror_written']]);
        self::assertSame('verified', $this->row(self::OWNER, 'validator', $this->validatorId)['status'] ?? null);
        self::assertNull($this->row(self::OWNER, 'page', self::PAGE + 50));
        self::assertSame('0', (string) Log::rowsAfter(0, 1)[0]->page_id, 'and never named in the log');
    }

    /** Review #881: a validator's page mirror is written under the PAGE lock a collection mirror takes. */
    public function testAWalletClaimWaitsForThePageLock(): void
    {
        $other = new \mysqli(
            getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
            getenv('BCC_TEST_DB_USER') ?: 'root',
            getenv('BCC_TEST_DB_PASS') ?: 'root',
            getenv('BCC_TEST_DB_NAME') ?: 'bcc_test',
            (int) (getenv('BCC_TEST_DB_PORT') ?: 10005)
        );
        $held = $other->query("SELECT GET_LOCK('bcc_claim:page:" . self::PAGE . ":operator', 0) AS got")->fetch_assoc();
        self::assertSame('1', (string) ($held['got'] ?? ''), 'control: the other connection holds the page lock');
        try {
            $result = $this->walletClaim(self::OWNER);
        } finally {
            $other->query("SELECT RELEASE_LOCK('bcc_claim:page:" . self::PAGE . ":operator')");
            $other->close();
        }

        self::assertSame(['success' => false, 'error' => 'transient'], array_intersect_key($result, ['success' => 1, 'error' => 1]));
        self::assertNull($this->row(self::OWNER, 'validator', $this->validatorId), 'nothing written without the page lock');
        self::assertSame([], self::logActions());
    }

    // ── member-side and system ends (C1b-2) ────────────────────────────

    /** @return list<object> */
    private static function revokedRows(): array
    {
        return array_values(array_filter(Log::rowsAfter(0, 50), static fn (object $r): bool => (string) $r->action === 'owner_revoked'));
    }

    public function testTheWeeklySweepEndsAnOwnerClaimOnTheRecordAsTheSystem(): void
    {
        $claimId = $this->seedOwner(self::OWNER, 'wallet');

        self::assertTrue(\BCC\Trust\Onchain\Services\ClaimService::revokeClaim($claimId, 'wallet_link_gone', 'system', null));

        self::assertSame('revoked', $this->row(self::OWNER, 'validator', $this->validatorId)['status'] ?? null);
        $rows = self::revokedRows();
        self::assertCount(1, $rows);
        self::assertSame(['system', '', (string) self::OWNER], [$rows[0]->actor_kind, (string) $rows[0]->actor_user_id, (string) $rows[0]->subject_user_id]);
        $payload = json_decode((string) $rows[0]->payload, true);
        self::assertSame(['wallet_link_gone', 'wallet'], [$payload['reason'] ?? null, $payload['prev']['method'] ?? null]);
        self::assertSame(['seq' => (int) $rows[0]->seq, 'row_hash' => $rows[0]->row_hash], RelationshipLogAnchor::get(), 'anchored after the commit');
        self::assertContains(['hook' => PageOwnershipAligner::HOOK, 'args' => [self::PAGE]], array_map(static fn (array $s): array => ['hook' => $s['hook'], 'args' => $s['args']], $GLOBALS['bcc_scheduled']), 'the aligner retries the page release');
    }

    public function testAWalletUnlinkAndADeletionNameTheirInitiator(): void
    {
        $this->seedOwner(self::OWNER, 'wallet');
        self::assertSame(1, \BCC\Trust\Onchain\Services\ClaimService::revokeAllForUser(self::OWNER, 'wallet_unlinked', 'cosmos1own', 7, 'member', self::OWNER));
        $rows = self::revokedRows();
        self::assertSame(['member', (string) self::OWNER], [$rows[0]->actor_kind, (string) $rows[0]->actor_user_id]);

        $this->seedOtherValidatorOwner();
        \BCC\Trust\Onchain\Services\ClaimService::purgeForDeletedUser(self::OTHER, 'staff', self::STAFF);
        $rows = self::revokedRows();
        self::assertSame(['staff', (string) self::STAFF, (string) self::OTHER], [$rows[1]->actor_kind, (string) $rows[1]->actor_user_id, (string) $rows[1]->subject_user_id]);
        self::assertNull($this->row(self::OTHER, 'validator', $this->validatorId), 'and the rows are purged, as before');
    }

    public function testNothingToEndWritesNoRecord(): void
    {
        $claimId = $this->seedOwner(self::OWNER, 'wallet');
        self::assertTrue(\BCC\Trust\Onchain\Services\ClaimService::revokeClaim($claimId, 'wallet_link_gone'));

        self::assertFalse(\BCC\Trust\Onchain\Services\ClaimService::revokeClaim($claimId, 'wallet_link_gone'), 'already revoked');
        self::assertCount(1, self::revokedRows(), 'owner_revoked only when something was revoked');
    }

    public function testAnEndThatCannotTakeTheLogLockThrowsAndWritesNothing(): void
    {
        $claimId = $this->seedOwner(self::OWNER, 'wallet');
        $other   = new \mysqli(
            getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
            getenv('BCC_TEST_DB_USER') ?: 'root',
            getenv('BCC_TEST_DB_PASS') ?: 'root',
            getenv('BCC_TEST_DB_NAME') ?: 'bcc_test',
            (int) (getenv('BCC_TEST_DB_PORT') ?: 10005)
        );
        $other->query("SELECT GET_LOCK('" . Log::LOCK_NAME . "', 0)");
        try {
            \BCC\Trust\Onchain\Services\ClaimService::revokeClaim($claimId, 'wallet_link_gone');
            self::fail('a revoke without the log lock must throw (the caller counts it as failed)');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('busy', $e->getMessage());
        } finally {
            $other->query("SELECT RELEASE_LOCK('" . Log::LOCK_NAME . "')");
            $other->close();
        }

        self::assertSame('verified', $this->row(self::OWNER, 'validator', $this->validatorId)['status'] ?? null);
        self::assertSame([], self::revokedRows());
    }

    public function testTheBreakGlassPutsTheApproverAndEvidenceOnTheRecord(): void
    {
        $this->seedOwner(self::OWNER, 'wallet');

        self::assertSame(1, \BCC\Trust\Onchain\Services\ClaimService::revokeBatchForUser(self::OWNER, 'ownership_disputed', 10, null, null, 'system', null, ['approver' => 'Approver', 'via' => 'cli'], 'Lost key; written approval on file.'));

        $row     = self::revokedRows()[0];
        $payload = json_decode((string) $row->payload, true);
        self::assertSame(['Approver', 'cli', 'ownership_disputed'], [$payload['approver'] ?? null, $payload['via'] ?? null, $payload['reason'] ?? null]);
        self::assertSame('Lost key; written approval on file.', (string) $row->evidence_note);
        self::assertTrue(\BCC\Trust\Onchain\Repositories\ClaimRepository::verifiedValidatorClaimExists(self::OWNER) === false);
    }

    public function testAValidatorClaimArrivingAfterCliPreflightStillRequiresApproval(): void
    {
        self::assertFalse(ClaimRepository::verifiedValidatorClaimExists(self::OWNER));
        $this->seedOwner(self::OWNER, 'wallet');

        foreach ([[[], null], [['approver' => 'Approver'], null], [[], 'Approval recorded'], [['approver' => '  '], 'Approval recorded']] as [$extra, $evidence]) {
            try {
                \BCC\Trust\Onchain\Services\ClaimService::revokeBatchForUser(self::OWNER, 'ownership_disputed', 10, null, null, 'system', null, $extra + ['via' => 'cli'], $evidence);
                self::fail('A fresh validator claim must not bypass CLI approval.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('cli_approval_required', $error->getMessage());
            }
            self::assertSame('verified', $this->row(self::OWNER, 'validator', $this->validatorId)['status'] ?? null);
            self::assertSame([], self::revokedRows());
        }
    }

    /**
     * A claim that moves between the unlocked read (which names the locks)
     * and the locked revoke is not revoked under the wrong validator's
     * locks: the run is abandoned and retried, and the record names the
     * validator the claim is actually on. The move is made from a second
     * connection right after the first read, as a concurrent writer would.
     */
    public function testAClaimThatMovesBeforeTheLocksIsRetriedUnderTheRightLocks(): void
    {
        global $wpdb;
        $claimId = $this->seedOwner(self::OWNER, 'wallet');
        $chainId = (int) $wpdb->get_var("SELECT id FROM `" . ChainRepository::table() . "` WHERE slug = 'vrs-chain'");
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id' => null, 'operator_address' => 'cosmosvaloper1vrs' . uniqid(), 'chain_id' => $chainId,
            'moniker' => 'VRS Fixture', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        $second = (int) $wpdb->insert_id;
        $page2  = self::PAGE + 1;
        $wpdb->insert($wpdb->postmeta, ['post_id' => $page2, 'meta_key' => '_bcc_onchain_validator_id', 'meta_value' => (string) $second]);

        $other = new \mysqli(
            getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
            getenv('BCC_TEST_DB_USER') ?: 'root',
            getenv('BCC_TEST_DB_PASS') ?: 'root',
            getenv('BCC_TEST_DB_NAME') ?: 'bcc_test',
            (int) (getenv('BCC_TEST_DB_PORT') ?: 10005)
        );
        $moved = false;
        $real  = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class ($real, static function (string $sql) use (&$moved, $other, $claimId, $second): void {
            if (!$moved && str_contains($sql, 'SELECT user_id, entity_type, entity_id, claim_role, status, verification_method')) {
                $moved = true;
                $other->query('UPDATE `' . ClaimRepository::table() . '` SET entity_id = ' . $second . ' WHERE id = ' . $claimId);
            }
        }) {
            public function __construct(private object $inner, private \Closure $afterQuery)
            {
            }

            public function __get(string $name): mixed
            {
                return $this->inner->$name;
            }

            public function __set(string $name, mixed $value): void
            {
                $this->inner->$name = $value;
            }

            public function __isset(string $name): bool
            {
                return isset($this->inner->$name);
            }

            /** @param array<int, mixed> $args */
            public function __call(string $method, array $args): mixed
            {
                return $this->inner->$method(...$args);
            }

            public function query(string $sql): mixed
            {
                $result = $this->inner->query($sql);
                ($this->afterQuery)($sql);

                return $result;
            }
        };
        try {
            self::assertTrue(Service::endOwnerClaim($claimId, 'wallet_link_gone', 'system', null));
        } finally {
            $GLOBALS['wpdb'] = $real;
            $other->close();
            $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d AND meta_key = '_bcc_onchain_validator_id'", $page2));
        }

        self::assertTrue($moved, 'the claim moved after the first read');
        self::assertSame('revoked', $this->row(self::OWNER, 'validator', $second)['status'] ?? null);
        $rows = self::revokedRows();
        self::assertCount(1, $rows);
        $payload = json_decode((string) $rows[0]->payload, true);
        self::assertSame($second, $payload['validator_id'] ?? null, 'revoked under the locks of the validator it is on');
        self::assertSame($page2, (int) $rows[0]->page_id);
    }

    /** Only an owner-role validator claim ends through the relationship service; any other keeps the old path. */
    public function testANonOwnerValidatorClaimKeepsTheOldRevokePath(): void
    {
        global $wpdb;
        $wpdb->insert(ClaimRepository::table(), [
            'user_id' => self::OWNER, 'entity_type' => 'validator', 'entity_id' => $this->validatorId,
            'wallet_address' => 'cosmos1own', 'chain_id' => 7, 'claim_role' => 'holder',
            'status' => 'verified', 'verification_method' => 'wallet', 'verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $claimId = (int) $wpdb->insert_id;

        self::assertTrue(\BCC\Trust\Onchain\Services\ClaimService::revokeClaim($claimId, 'wallet_link_gone'), 'revoked, never silently skipped');
        self::assertSame('revoked', (string) $wpdb->get_var($wpdb->prepare('SELECT status FROM `' . ClaimRepository::table() . '` WHERE id = %d', $claimId)));
        self::assertSame([], self::revokedRows(), 'no owner record for a non-owner claim');
    }

    private function seedOtherValidatorOwner(): void
    {
        global $wpdb;
        $wpdb->insert(ClaimRepository::table(), [
            'user_id' => self::OTHER, 'entity_type' => 'validator', 'entity_id' => $this->validatorId,
            'wallet_address' => 'cosmos1oth', 'chain_id' => 7, 'claim_role' => 'operator',
            'status' => 'verified', 'verification_method' => 'wallet', 'verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testAStaffApprovedOwnerMustBeRevokedFirst(): void
    {
        $this->seedOwner(self::OTHER, 'staff');

        self::assertSame(['ok' => false, 'reason' => 'owner_exists', 'status' => 409], $this->link(self::OWNER));
    }

    public function testAWrongCodeIsRefusedAndNothingIsWritten(): void
    {
        $stepUp = self::stepUp('link_owner', self::OWNER);

        self::assertSame(['ok' => false, 'reason' => 'code_invalid', 'status' => 403], $this->link(self::OWNER, $stepUp, '000000'));
        self::assertSame([], ClaimRepository::ownerClaimsForPageStrict(self::PAGE));
        self::assertSame('1', (string) StepUps::find($stepUp, self::STAFF)?->attempts, 'the attempt counted');
    }

    public function testAStepUpForAnotherActionOrTargetIsRefused(): void
    {
        self::assertSame('step_up_invalid', $this->link(self::OWNER, self::stepUp('revoke_owner', self::OWNER))['reason']);
        self::assertSame('step_up_invalid', $this->link(self::OWNER, self::stepUp('link_owner', self::OTHER))['reason']);
        self::assertSame([], ClaimRepository::ownerClaimsForPageStrict(self::PAGE));
        self::assertSame([], self::logActions());
    }

    public function testRevokingAStaffOwnerEndsTheLinkOnTheRecord(): void
    {
        $linkId = $this->link(self::OWNER)['link_id'];

        $result = Service::revokeOwnerByStaff(self::STAFF, self::PAGE, $linkId, 'evidence_withdrawn', 'The proof page is gone.', self::stepUp('revoke_owner', self::OWNER), self::CODE);

        self::assertSame(['ok' => true], $result);
        self::assertSame([], ClaimRepository::ownerClaimsForPageStrict(self::PAGE));
        self::assertSame(['owner_linked', 'owner_revoked'], self::logActions());
        self::assertSame('UNCLAIMED', ClaimRepository::resolveVerifiedOperatorForPageStrict(self::PAGE)['state']);
    }

    public function testARevokeSpendsAStepUpBoundToThatRevoke(): void
    {
        $linkId = $this->link(self::OWNER)['link_id'];

        foreach ([self::stepUp('cancel_link', self::OWNER), self::stepUp('revoke_owner', self::OTHER)] as $wrong) {
            self::assertSame('step_up_invalid', Service::revokeOwnerByStaff(self::STAFF, self::PAGE, $linkId, 'linked_in_error', '', $wrong, self::CODE)['reason']);
        }
        self::assertSame(self::OWNER, ClaimRepository::resolveVerifiedOperatorForPageStrict(self::PAGE)['user_id'], 'still the owner');

        $right = self::stepUp('revoke_owner', self::OWNER);
        self::assertSame(['ok' => true], Service::revokeOwnerByStaff(self::STAFF, self::PAGE, $linkId, 'linked_in_error', '', $right, self::CODE));
        self::assertNotNull(StepUps::find($right, self::STAFF)?->used_at, 'the step-up is spent');
    }

    public function testStaffCannotRevokeOrCancelAWalletOwner(): void
    {
        $claimId = $this->seedOwner(self::OTHER, 'wallet');

        self::assertSame('wallet_owner_present', Service::revokeOwnerByStaff(self::STAFF, self::PAGE, $claimId, 'ownership_disputed', '', self::stepUp('revoke_owner', self::OTHER), self::CODE)['reason']);
        self::assertSame('wallet_owner_present', Service::cancelLinkByStaff(self::STAFF, self::PAGE, $claimId, '', self::stepUp('cancel_link', self::OTHER), self::CODE)['reason']);
        self::assertSame(self::OTHER, ClaimRepository::resolveVerifiedOperatorForPageStrict(self::PAGE)['user_id']);
    }

    public function testACancelledLinkCannotBeCancelledAgain(): void
    {
        $linkId = $this->link(self::OWNER)['link_id'];

        self::assertSame(['ok' => true], Service::cancelLinkByStaff(self::STAFF, self::PAGE, $linkId, 'Linked by mistake.', self::stepUp('cancel_link', self::OWNER), self::CODE));
        self::assertSame(['ok' => false, 'reason' => 'already_cancelled', 'status' => 409], Service::cancelLinkByStaff(self::STAFF, self::PAGE, $linkId, '', self::stepUp('cancel_link', self::OWNER), self::CODE));
        self::assertSame(['owner_linked', 'owner_link_cancelled'], self::logActions());
    }

    public function testEvidenceIsBoundedTextAndAReferenceIsAnHttpLink(): void
    {
        self::assertSame('invalid_evidence', $this->link(self::OWNER, null, self::CODE, 'javascript:alert(1)')['reason']);
        self::assertSame('invalid_evidence', $this->link(self::OWNER, null, self::CODE, 'ftp://operator.example')['reason']);
        self::assertSame('invalid_evidence', Service::linkOwnerByStaff(self::STAFF, self::PAGE, self::OWNER, 'website', str_repeat('x', 2001), null, self::stepUp('link_owner', self::OWNER), self::CODE)['reason']);
        self::assertSame('invalid_evidence', Service::linkOwnerByStaff(self::STAFF, self::PAGE, self::OWNER, 'rumour', 'note', null, self::stepUp('link_owner', self::OWNER), self::CODE)['reason']);
        self::assertSame([], ClaimRepository::ownerClaimsForPageStrict(self::PAGE));
    }

    public function testAPageWithNoValidatorIsNotFound(): void
    {
        self::assertSame(['ok' => false, 'reason' => 'not_a_validator_page', 'status' => 404], Service::linkOwnerByStaff(self::STAFF, 999999, self::OWNER, 'website', 'note', null, self::stepUp('link_owner', self::OWNER), self::CODE));
    }
}
