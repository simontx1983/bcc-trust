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
