<?php
/**
 * Moderation integration stubs — the moderation suites ONLY
 * (ModerationRestoreIntegrationTest and its F-73 siblings).
 *
 * ⚠⚠ Loaded from setUp() of suites that run with
 * #[RunTestsInSeparateProcesses], NEVER from the shared integration
 * bootstrap. Other suites (DelegatorGroup, PagePosts, PageClaimBinding, …)
 * declare their OWN guarded `do_action` that records into their own
 * globals; a global recorder declared first hijacks theirs and silently
 * empties their event assertions. In a separate process nothing here can
 * leak into a sibling.
 *
 *   - do_action           records [hook, args] into $GLOBALS['__bcc_test_actions'];
 *                         no listeners run;
 *   - get_current_user_id 0 (AuditLogger's fallback actor);
 *   - the bcc_hidden_activities table (the moderation sidecar).
 */

declare(strict_types=1);

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        $GLOBALS['__bcc_test_actions'][] = [$hook, $args];
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 0;
    }
}

require_once dirname(__DIR__, 2) . '/includes/database/schema-hidden-activities.php';
bcc_trust_create_hidden_activities_table();
