<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\AdminValidatorWriteEndpoint;
use BCC\Trust\Core\Support\BearerAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminValidatorWriteEndpoint::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminValidatorWriteEndpointTest extends TestCase
{
    private const STEP = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/admin-validator-write-stubs.php';
        require_once __DIR__ . '/../../app/Domain/Core/REST/AdminValidatorWriteEndpoint.php';
        $GLOBALS['validator_write'] = [
            'actor' => 7, 'admin' => true, 'routes' => [], 'starts' => [], 'resends' => [], 'writes' => [], 'read_calls' => [],
            'read_failure' => false, 'fail_after_write' => false,
            'projection' => ['owner' => ['link_id' => 88, 'user' => ['user_id' => 22], 'locked_for_staff' => false]],
            'step' => (object) ['action' => 'link_owner', 'page_id' => '10', 'target_user_id' => '22', 'role' => 'owner', 'used_at' => null, 'attempts' => '0', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 600)],
            'write_result' => ['ok' => true, 'link_id' => 88],
            'step_result' => ['ok' => true, 'step_up_id' => self::STEP, 'expires_in' => 600, 'resend_after' => 60],
        ];
    }

    private function request(array $body = [], array $url = []): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST');
        $request->json = $body;
        $request->url = $url + ['pageId' => '10', 'linkId' => '88', 'stepUpId' => self::STEP];
        return $request;
    }

    private function body(): array
    {
        return ['user_id' => 22, 'role' => 'owner', 'verification_method' => 'website', 'evidence_note' => 'Ownership reviewed', 'evidence_ref' => 'https://example.test/proof', 'step_up_id' => self::STEP, 'code' => '123456'];
    }

    private function assertError(\WP_REST_Response $response, int $status, string $code, ?string $reason = null): void
    {
        self::assertSame($status, $response->get_status());
        self::assertSame($code, $response->get_data()['error']['code']);
        self::assertSame('no-store', $response->get_headers()['Cache-Control']);
        if ($reason !== null) {
            self::assertSame($reason, $response->get_data()['error']['data']['reason']);
        }
    }

    public function testRoutesAndHandlerGatesApplyBeforeReadsOrWrites(): void
    {
        AdminValidatorWriteEndpoint::register();
        self::assertCount(5, $GLOBALS['validator_write']['routes']);
        foreach ($GLOBALS['validator_write']['routes'] as $options) {
            self::assertSame([BearerAuth::class, 'requireAdmin'], $options['permission_callback']);
        }
        $endpoint = new AdminValidatorWriteEndpoint();
        foreach (['start', 'resend', 'link', 'revoke', 'cancel'] as $handler) {
            $GLOBALS['validator_write']['actor'] = 0;
            $this->assertError($endpoint->$handler($this->request()), 401, 'bcc_unauthorized');
            $GLOBALS['validator_write']['actor'] = 7;
            $GLOBALS['validator_write']['admin'] = false;
            $this->assertError($endpoint->$handler($this->request()), 403, 'bcc_forbidden');
        }
        self::assertSame([], $GLOBALS['validator_write']['read_calls']);
        self::assertSame([], $GLOBALS['validator_write']['writes']);
    }

    public function testStartUsesOnlyJsonPasswordAndRoutePageWithOwnerRole(): void
    {
        $request = $this->request(['action' => 'link_owner', 'target_user_id' => 22, 'current_password' => 'json password', 'wallet_address' => 'ignored']);
        $request->query = ['pageId' => 999, 'current_password' => 'query password', 'wallet_address' => 'query wallet'];
        $response = (new AdminValidatorWriteEndpoint())->start($request);
        self::assertSame(200, $response->get_status());
        self::assertSame([['json password', null, 7, 'link_owner', 10, 22, 'owner']], $GLOBALS['validator_write']['starts']);
        self::assertSame('no-store', $response->get_headers()['Cache-Control']);
    }

    public function testMailRejectionReturnsAnExplicitRetryableError(): void
    {
        $GLOBALS['validator_write']['step_result'] = ['ok' => false, 'error' => 'mail_unavailable', 'status' => 503];
        $endpoint = new AdminValidatorWriteEndpoint();

        $start = $endpoint->start($this->request(['action' => 'link_owner', 'target_user_id' => 22, 'current_password' => 'password']));
        $this->assertError($start, 503, 'bcc_unavailable', 'mail_unavailable');
        $this->assertError($endpoint->resend($this->request()), 503, 'bcc_unavailable', 'mail_unavailable');
    }

    public function testQueryPasswordCannotSupplyMissingJsonCredential(): void
    {
        $request = $this->request(['action' => 'link_owner', 'target_user_id' => 22]);
        $request->query = ['current_password' => 'query password'];
        $this->assertError((new AdminValidatorWriteEndpoint())->start($request), 403, 'bcc_step_up_failed', 'password');
        self::assertSame([], $GLOBALS['validator_write']['starts']);
    }

    public function testLinkValidatesStrictIdsAndEvidenceBeforeService(): void
    {
        $endpoint = new AdminValidatorWriteEndpoint();
        foreach (['0', '-10', '10.0', '010', str_repeat('9', 40)] as $id) {
            $this->assertError($endpoint->link($this->request($this->body(), ['pageId' => $id])), 422, 'bcc_invalid_request');
        }
        foreach (['user_id' => '22', 'role' => 'admin', 'verification_method' => 'wallet', 'evidence_note' => str_repeat('a', 2001), 'evidence_ref' => str_repeat('a', 501)] as $field => $value) {
            $body = $this->body();
            $body[$field] = $value;
            $response = $endpoint->link($this->request($body));
            $this->assertError($response, 422, 'bcc_invalid_request');
            self::assertSame($field, $response->get_data()['error']['data']['field']);
        }
        self::assertSame([], $GLOBALS['validator_write']['writes']);
    }

    public function testLinkReturnsCanonicalClaimAndOwnerProjection(): void
    {
        $response = (new AdminValidatorWriteEndpoint())->link($this->request($this->body()));
        self::assertSame(201, $response->get_status());
        self::assertSame(88, $response->get_data()['data']['link_id']);
        self::assertSame($GLOBALS['validator_write']['projection']['owner'], $response->get_data()['data']['owner']);
        self::assertSame('no-store', $response->get_headers()['Cache-Control']);
        self::assertCount(1, $GLOBALS['validator_write']['writes']);
    }

    public function testRevokeAndCancelBindToCurrentOwnerAndClaim(): void
    {
        $endpoint = new AdminValidatorWriteEndpoint();
        foreach (['revoke' => 'revoke_owner', 'cancel' => 'cancel_link'] as $handler => $action) {
            $GLOBALS['validator_write']['step']->action = $action;
            $response = $endpoint->$handler($this->request(['reason' => 'linked_in_error', 'note' => 'Correcting mistaken link', 'step_up_id' => self::STEP, 'code' => '123456']));
            self::assertSame(200, $response->get_status());
            if ($handler === 'cancel') {
                self::assertSame(['cancelled' => true, 'restored_owner' => null], $response->get_data()['data']);
            }
        }
        self::assertSame(88, $GLOBALS['validator_write']['writes'][0][3]);
        $GLOBALS['validator_write']['step']->target_user_id = '23';
        $this->assertError($endpoint->cancel($this->request(['note' => 'Correcting mistaken link', 'step_up_id' => self::STEP, 'code' => '123456'])), 403, 'bcc_step_up_failed', 'mismatch');
        self::assertCount(2, $GLOBALS['validator_write']['writes']);
    }

    public function testStepUpStateAndBindingsFailClosed(): void
    {
        $endpoint = new AdminValidatorWriteEndpoint();
        foreach (['action' => 'cancel_link', 'page_id' => '11', 'target_user_id' => '23', 'role' => 'team'] as $field => $value) {
            $old = $GLOBALS['validator_write']['step']->$field;
            $GLOBALS['validator_write']['step']->$field = $value;
            $this->assertError($endpoint->link($this->request($this->body())), 403, 'bcc_step_up_failed', 'mismatch');
            $GLOBALS['validator_write']['step']->$field = $old;
        }
        $GLOBALS['validator_write']['step']->used_at = gmdate('Y-m-d H:i:s');
        $this->assertError($endpoint->link($this->request($this->body())), 403, 'bcc_step_up_failed', 'used');
        $GLOBALS['validator_write']['step']->used_at = null;
        $GLOBALS['validator_write']['step']->expires_at = gmdate('Y-m-d H:i:s', time() - 1);
        $this->assertError($endpoint->link($this->request($this->body())), 403, 'bcc_step_up_failed', 'expired');
        self::assertSame([], $GLOBALS['validator_write']['writes']);
    }

    public function testRevokeAndCancelAllowMissingOrBlankNotesButValidateProvidedText(): void
    {
        $endpoint = new AdminValidatorWriteEndpoint();
        foreach (['revoke' => 'revoke_owner', 'cancel' => 'cancel_link'] as $handler => $action) {
            $GLOBALS['validator_write']['step']->action = $action;
            $body = ['reason' => 'linked_in_error', 'step_up_id' => self::STEP, 'code' => '123456'];
            self::assertSame(200, $endpoint->$handler($this->request($body))->get_status());
            self::assertSame(200, $endpoint->$handler($this->request($body + ['note' => '']))->get_status());
            foreach ([null, [], "\xff", str_repeat('a', 2001)] as $invalid) {
                $this->assertError($endpoint->$handler($this->request($body + ['note' => $invalid])), 422, 'bcc_invalid_request');
            }
        }
        self::assertCount(4, $GLOBALS['validator_write']['writes']);
    }

    public function testProofOwnerIsProtectedBeforeWriteOrStart(): void
    {
        $GLOBALS['validator_write']['projection']['owner']['locked_for_staff'] = true;
        $endpoint = new AdminValidatorWriteEndpoint();
        $this->assertError($endpoint->start($this->request(['action' => 'cancel_link', 'target_user_id' => 22, 'current_password' => 'password'])), 409, 'bcc_conflict', 'wallet_owner_present');
        $this->assertError($endpoint->cancel($this->request(['note' => 'Correction', 'step_up_id' => self::STEP, 'code' => '123456'])), 409, 'bcc_conflict', 'wallet_owner_present');
        self::assertSame([], $GLOBALS['validator_write']['writes']);
        self::assertSame([], $GLOBALS['validator_write']['starts']);
    }

    public function testServiceErrorsUseCanonicalCodesAndRetryAfter(): void
    {
        $endpoint = new AdminValidatorWriteEndpoint();
        $GLOBALS['validator_write']['write_result'] = ['ok' => false, 'reason' => 'target_not_eligible', 'status' => 422];
        $this->assertError($endpoint->link($this->request($this->body())), 422, 'bcc_invalid_request', 'target_not_eligible');
        $GLOBALS['validator_write']['step_result'] = ['ok' => false, 'error' => 'rate_limited', 'status' => 429, 'retry_after' => 31];
        $response = $endpoint->resend($this->request());
        $this->assertError($response, 429, 'bcc_rate_limited');
        self::assertSame('31', $response->get_headers()['Retry-After']);
    }

    public function testRepositoryFailureBeforeWriteReturnsUnavailableWithoutDetail(): void
    {
        $GLOBALS['validator_write']['read_failure'] = true;
        $response = (new AdminValidatorWriteEndpoint())->link($this->request($this->body()));
        $this->assertError($response, 503, 'bcc_unavailable');
        self::assertStringNotContainsString('private repository detail', json_encode($response->get_data()));
        self::assertSame([], $GLOBALS['validator_write']['writes']);
    }

    public function testProjectionFailureAfterSavedWriteMarksCommittedOutcome(): void
    {
        $GLOBALS['validator_write']['fail_after_write'] = true;
        $response = (new AdminValidatorWriteEndpoint())->link($this->request($this->body()));
        $this->assertError($response, 503, 'bcc_unavailable', 'result_unavailable');
        self::assertTrue($response->get_data()['error']['data']['committed']);
        self::assertStringContainsString('saved', $response->get_data()['error']['message']);
        self::assertCount(1, $GLOBALS['validator_write']['writes']);
    }
}
