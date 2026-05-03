<?php
/**
 * Trust Engine Admin Actions
 *
 * All logic lives in BCC\Trust\Core\Services\Admin\RepairService.
 *
 * @package BCC\Trust\Core
 */

if (!defined('ABSPATH')) {
    exit;
}

// Register admin_post actions
\BCC\Trust\Core\Services\Admin\RepairService::registerActions();

function bcc_trust_show_repair_diagnostics() {
    return \BCC\Trust\Core\Plugin::instance()->repairService()->getDiagnostics();
}
