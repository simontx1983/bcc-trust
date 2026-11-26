<?php

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Services\AdminValidatorReadService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Onchain\Repositories\ValidatorStepUpRepository;
use BCC\Trust\Onchain\Services\ValidatorRelationshipService;
use BCC\Trust\Onchain\Services\ValidatorStepUpService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-only transport for the existing owner relationship services.
 * @phpstan-import-type StepUpRow from ValidatorStepUpRepository
 */
final class AdminValidatorWriteEndpoint
{
    private const ACTIONS = ['link_owner', 'revoke_owner', 'cancel_link'];

    public static function register(): void
    {
        $instance = new self();

        register_rest_route('bcc/v1', '/admin/validator-pages/(?P<pageId>\d+)/step-up', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$instance, 'start'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);

        register_rest_route('bcc/v1', '/admin/validator-pages/step-up/(?P<stepUpId>[0-9a-f]{32})/resend', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$instance, 'resend'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);

        register_rest_route('bcc/v1', '/admin/validator-pages/(?P<pageId>\d+)/relationships', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$instance, 'link'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);

        register_rest_route('bcc/v1', '/admin/validator-pages/(?P<pageId>\d+)/relationships/(?P<linkId>\d+)/revoke', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$instance, 'revoke'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);

        register_rest_route('bcc/v1', '/admin/validator-pages/(?P<pageId>\d+)/relationships/(?P<linkId>\d+)/cancel', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$instance, 'cancel'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);
    }

    public function start(WP_REST_Request $request): WP_REST_Response
    {
        if (($gate = self::adminGate()) !== null) {
            return $gate;
        }
        $body = $request->get_json_params();
        $pageId = self::routeId($request, 'pageId');
        if ($pageId === null || !is_array($body)) {
            return self::invalid('pageId');
        }
        $action = $body['action'] ?? null;
        $target = $body['target_user_id'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            return self::invalid('action');
        }
        if (!is_int($target) || $target <= 0) {
            return self::invalid('target_user_id');
        }
        $password = $body['current_password'] ?? null;
        if (!is_string($password) || $password === '' || !mb_check_encoding($password, 'UTF-8')) {
            return self::error('bcc_step_up_failed', 403, ['reason' => 'password']);
        }
        try {
            $projection = AdminValidatorReadService::relationships($pageId);
            if ($projection === null) {
                return self::notFound();
            }
            if ($action !== 'link_owner') {
                $owner = self::ownerContext($projection);
                if ($owner === null || $owner['user_id'] !== $target) {
                    return self::error('bcc_step_up_failed', 403, ['reason' => 'mismatch']);
                }
                if ($owner['locked']) {
                    return self::error('bcc_conflict', 409, ['reason' => 'wallet_owner_present']);
                }
            }
            // Credential checking receives only the JSON password, never merged query fields.
            $credentialRequest = new WP_REST_Request('POST');
            $credentialRequest->set_header('Content-Type', 'application/json');
            $credentialRequest->set_body(json_encode(['current_password' => $password], JSON_THROW_ON_ERROR));
            $result = ValidatorStepUpService::start($credentialRequest, get_current_user_id(), $action, $pageId, $target, 'owner');
            if (!$result['ok']) {
                return self::failure($result);
            }
            return self::ok(['step_up_id' => $result['step_up_id'], 'expires_in' => $result['expires_in'], 'resend_after' => $result['resend_after']]);
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    public function resend(WP_REST_Request $request): WP_REST_Response
    {
        if (($gate = self::adminGate()) !== null) {
            return $gate;
        }
        $params = $request->get_url_params();
        $id = $params['stepUpId'] ?? null;
        if (!is_string($id) || !self::validStepId($id)) {
            return self::error('bcc_step_up_failed', 403, ['reason' => 'mismatch']);
        }
        try {
            $row = ValidatorStepUpRepository::find($id, get_current_user_id());
            if ($row === null || !in_array($row->action, self::ACTIONS, true) || $row->role !== 'owner') {
                return self::error('bcc_step_up_failed', 403, ['reason' => 'mismatch']);
            }
            $result = ValidatorStepUpService::resend(get_current_user_id(), $id);
            if (!$result['ok']) {
                return self::failure($result, $id);
            }
            return self::ok(['expires_in' => $result['expires_in'], 'resend_after' => $result['resend_after']]);
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    public function link(WP_REST_Request $request): WP_REST_Response
    {
        return $this->write($request, 'link_owner');
    }

    public function revoke(WP_REST_Request $request): WP_REST_Response
    {
        return $this->write($request, 'revoke_owner');
    }

    public function cancel(WP_REST_Request $request): WP_REST_Response
    {
        return $this->write($request, 'cancel_link');
    }

    private function write(WP_REST_Request $request, string $action): WP_REST_Response
    {
        if (($gate = self::adminGate()) !== null) {
            return $gate;
        }
        $pageId = self::routeId($request, 'pageId');
        $body = $request->get_json_params();
        if ($pageId === null || !is_array($body)) {
            return self::invalid('pageId');
        }
        $linkId = $action === 'link_owner' ? null : self::routeId($request, 'linkId');
        if ($action !== 'link_owner' && $linkId === null) {
            return self::invalid('linkId');
        }
        $stepId = $body['step_up_id'] ?? null;
        $code = $body['code'] ?? null;
        if ($stepId === null || $stepId === '') {
            return self::error('bcc_step_up_required', 403);
        }
        if (!is_string($stepId) || !self::validStepId($stepId)) {
            return self::error('bcc_step_up_failed', 403, ['reason' => 'mismatch']);
        }
        if (!is_string($code) || preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            return self::error('bcc_step_up_failed', 403, ['reason' => 'code']);
        }
        $noteField = $action === 'link_owner' ? 'evidence_note' : 'note';
        $note = array_key_exists($noteField, $body) ? $body[$noteField] : ($action === 'link_owner' ? null : '');
        if ($action === 'link_owner' ? !self::text($note, 2000) : (!is_string($note) || !mb_check_encoding($note, 'UTF-8') || mb_strlen($note, 'UTF-8') > 2000)) {
            return self::invalid($noteField);
        }
        $target = $body['user_id'] ?? null;
        $type = $body['verification_method'] ?? null;
        $ref = $body['evidence_ref'] ?? null;
        $reason = $body['reason'] ?? null;
        if ($action === 'link_owner') {
            if (!is_int($target) || $target <= 0) {
                return self::invalid('user_id');
            }
            if (($body['role'] ?? null) !== 'owner') {
                return self::invalid('role');
            }
            if (!is_string($type) || !in_array($type, ['website', 'keybase', 'manual'], true)) {
                return self::invalid('verification_method');
            }
            if (!self::text($ref, 500) || !is_string($ref) || !self::validReference($ref)) {
                return self::invalid('evidence_ref');
            }
        } elseif ($action === 'revoke_owner' && (!is_string($reason) || !in_array($reason, ['linked_in_error', 'evidence_withdrawn', 'owner_requested', 'ownership_disputed'], true))) {
            return self::invalid('reason');
        }
        try {
            $projection = AdminValidatorReadService::relationships($pageId);
            if ($projection === null) {
                return self::notFound();
            }
            if ($action !== 'link_owner') {
                $owner = self::ownerContext($projection);
                if ($owner === null || $owner['link_id'] !== $linkId) {
                    return self::notFound();
                }
                if ($owner['locked']) {
                    return self::error('bcc_conflict', 409, ['reason' => 'wallet_owner_present']);
                }
                $target = $owner['user_id'];
            }
            if (!is_int($target) || $target <= 0) {
                return self::unavailable();
            }
            $bindingError = self::bindingError($stepId, $action, $pageId, $target);
            if ($bindingError !== null) {
                return $bindingError;
            }
            $savedLinkId = null;
            if ($action === 'link_owner' && is_string($type) && is_string($ref) && is_string($note)) {
                $result = ValidatorRelationshipService::linkOwnerByStaff(get_current_user_id(), $pageId, $target, $type, $note, $ref, $stepId, $code);
                if ($result['ok']) {
                    $savedLinkId = $result['link_id'];
                }
            } elseif ($action === 'revoke_owner' && $linkId !== null && is_string($reason) && is_string($note)) {
                $result = ValidatorRelationshipService::revokeOwnerByStaff(get_current_user_id(), $pageId, $linkId, $reason, $note, $stepId, $code);
            } elseif ($action === 'cancel_link' && $linkId !== null && is_string($note)) {
                $result = ValidatorRelationshipService::cancelLinkByStaff(get_current_user_id(), $pageId, $linkId, $note, $stepId, $code);
            } else {
                return self::unavailable();
            }
            if (!$result['ok']) {
                return self::failure($result, $stepId);
            }
            if ($action === 'link_owner') {
                try {
                    $updated = AdminValidatorReadService::relationships($pageId);
                    $updatedOwner = $updated === null ? null : self::ownerContext($updated);
                    if ($updated === null || $updatedOwner === null || $updatedOwner['link_id'] !== $savedLinkId || $updatedOwner['user_id'] !== $target) {
                        return self::savedResultUnavailable();
                    }
                    return self::ok(['link_id' => $savedLinkId, 'owner' => $updated['owner']], 201);
                } catch (\Throwable) {
                    return self::savedResultUnavailable();
                }
            }
            return self::ok($action === 'revoke_owner' ? ['revoked' => true] : ['cancelled' => true, 'restored_owner' => null]);
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    private static function bindingError(string $id, string $action, int $pageId, int $target): ?WP_REST_Response
    {
        $row = ValidatorStepUpRepository::find($id, get_current_user_id());
        if ($row === null || $row->action !== $action || (int) $row->page_id !== $pageId || (int) $row->target_user_id !== $target || $row->role !== 'owner') {
            return self::error('bcc_step_up_failed', 403, ['reason' => 'mismatch']);
        }
        return self::stepStateError($row);
    }

    /** @param StepUpRow $row */
    private static function stepStateError(object $row): ?WP_REST_Response
    {
        if ($row->used_at !== null || (int) $row->attempts >= ValidatorStepUpRepository::MAX_ATTEMPTS) {
            return self::error('bcc_step_up_failed', 403, ['reason' => 'used']);
        }
        if ((string) $row->expires_at <= gmdate('Y-m-d H:i:s')) {
            return self::error('bcc_step_up_failed', 403, ['reason' => 'expired']);
        }
        return null;
    }

    /** @param array<string, mixed> $result */
    private static function failure(array $result, ?string $stepId = null): WP_REST_Response
    {
        $reason = $result['reason'] ?? $result['error'] ?? 'unavailable';
        if ($reason === 'mail_unavailable') {
            return self::error('bcc_unavailable', 503, ['reason' => 'mail_unavailable']);
        }
        if ($reason === 'rate_limited' || ($result['status'] ?? 0) === 429) {
            $retry = $result['retry_after'] ?? 60;
            $retry = is_int($retry) ? max(1, $retry) : 60;
            $response = self::error('bcc_rate_limited', 429, ['retry_after' => $retry]);
            $response->header('Retry-After', (string) $retry);
            return $response;
        }
        if (in_array($reason, ['credential_invalid', 'password_required'], true)) {
            return self::error('bcc_step_up_failed', 403, ['reason' => 'password']);
        }
        if (in_array($reason, ['step_up_invalid', 'step_up_expired', 'code_invalid'], true)) {
            $publicReason = $reason === 'code_invalid' ? 'code' : ($reason === 'step_up_expired' ? 'expired' : 'mismatch');
            if ($reason === 'step_up_invalid' && $stepId !== null) {
                $row = ValidatorStepUpRepository::find($stepId, get_current_user_id());
                if ($row !== null && ($state = self::stepStateError($row)) !== null) {
                    return $state;
                }
            }
            return self::error('bcc_step_up_failed', 403, ['reason' => $publicReason]);
        }
        if (in_array($reason, ['wallet_owner_present', 'owner_exists', 'validator_has_operator', 'already_cancelled', 'not_cancellable'], true)) {
            return self::error('bcc_conflict', 409, ['reason' => $reason]);
        }
        if ($reason === 'target_not_eligible') {
            return self::error('bcc_invalid_request', 422, ['reason' => $reason, 'field' => 'user_id']);
        }
        if (in_array($reason, ['link_not_found', 'not_a_validator_page'], true)) {
            return self::notFound();
        }
        if ($reason === 'resend_not_allowed') {
            if ($stepId !== null) {
                $row = ValidatorStepUpRepository::find($stepId, get_current_user_id());
                if ($row !== null && ($state = self::stepStateError($row)) !== null) {
                    return $state;
                }
            }
            $response = self::error('bcc_rate_limited', 429, ['retry_after' => 60]);
            $response->header('Retry-After', '60');
            return $response;
        }
        return self::unavailable();
    }

    private static function routeId(WP_REST_Request $request, string $field): ?int
    {
        $params = $request->get_url_params();
        $value = $params[$field] ?? null;
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($parsed) ? $parsed : null;
    }

    private static function validStepId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/D', $id) === 1;
    }

    /**
     * Validate the authorization fields in a read projection before using them.
     * @param array<string, mixed> $projection
     * @return array{link_id:int,user_id:int,locked:bool}|null
     */
    private static function ownerContext(array $projection): ?array
    {
        if (!array_key_exists('owner', $projection)) {
            throw new \RuntimeException('Owner projection unavailable.');
        }
        $owner = $projection['owner'];
        if ($owner === null) {
            return null;
        }
        if (!is_array($owner) || !isset($owner['link_id'], $owner['user'], $owner['locked_for_staff']) || !is_int($owner['link_id']) || $owner['link_id'] <= 0 || !is_array($owner['user']) || !is_int($owner['user']['user_id'] ?? null) || $owner['user']['user_id'] <= 0 || !is_bool($owner['locked_for_staff'])) {
            throw new \RuntimeException('Owner projection unavailable.');
        }
        return ['link_id' => $owner['link_id'], 'user_id' => $owner['user']['user_id'], 'locked' => $owner['locked_for_staff']];
    }

    private static function text(mixed $value, int $limit): bool
    {
        return is_string($value) && trim($value) !== '' && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= $limit;
    }

    private static function validReference(string $ref): bool
    {
        $parts = parse_url($ref);
        return is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true) && $parts['host'] !== '';
    }

    private static function adminGate(): ?WP_REST_Response
    {
        if (get_current_user_id() <= 0) {
            return self::error('bcc_unauthorized', 401);
        }
        return BearerAuth::requireAdmin() ? null : self::error('bcc_forbidden', 403);
    }

    /** @param array<string, mixed> $data */
    private static function error(string $code, int $status, array $data = []): WP_REST_Response
    {
        $response = ApiResponse::error($code, 'The request could not be completed.', $status, $data);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /** @param array<string, mixed> $data */
    private static function ok(array $data, int $status = 200): WP_REST_Response
    {
        $response = ApiResponse::ok($data, $status);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private static function invalid(string $field): WP_REST_Response
    {
        return self::error('bcc_invalid_request', 422, ['field' => $field]);
    }

    private static function notFound(): WP_REST_Response
    {
        return self::error('bcc_not_found', 404);
    }

    private static function unavailable(): WP_REST_Response
    {
        return self::error('bcc_unavailable', 503);
    }

    private static function savedResultUnavailable(): WP_REST_Response
    {
        $response = ApiResponse::error('bcc_unavailable', 'The change was saved, but its latest result could not be read. Refresh before trying again.', 503, ['reason' => 'result_unavailable', 'committed' => true]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
