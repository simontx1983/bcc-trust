<?php
/**
 * ScoreOverrideUndoIntegrationTest stubs. Loaded ONLY from that suite's
 * setUp() under #[RunTestsInSeparateProcesses], never from the shared
 * bootstrap, so nothing here can reach a sibling suite (see
 * moderation-integration-stubs.php for why that matters).
 */

declare(strict_types=1);

/** The acting admin. Non-zero: a manual Elite pin always names its admin. */
const BCC_TEST_SCORE_OVERRIDE_ADMIN = 940001;

// Declared BEFORE the shared moderation stubs, whose guarded
// get_current_user_id() returns 0.
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return BCC_TEST_SCORE_OVERRIDE_ADMIN;
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        return (object) ['ID' => BCC_TEST_SCORE_OVERRIDE_ADMIN, 'display_name' => 'Test Admin'];
    }
}

require_once __DIR__ . '/moderation-integration-stubs.php'; // do_action + hidden_activities

// EliteEligibilityService::resolveSubject() reads the page's post date for
// tenure. No date means tenure 0, so the evaluated verdict is "not eligible"
// and only the pin can keep the page elite. Namespaced, so it shadows nothing
// global.
eval('namespace BCC\\Trust\\Core\\Services; if (!\\function_exists(__NAMESPACE__ . "\\\\get_post_field")) { function get_post_field($field, $post = null, $context = "display") { return ""; } }');
