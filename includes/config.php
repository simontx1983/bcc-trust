<?php
/**
 * BCC Trust Engine Configuration
 *
 * Split into logical modules for maintainability.
 * Each sub-file is self-contained and can be overridden independently.
 *
 * @package BCC\Trust\Core
 * @version 2.1.0
 */
if (!defined('ABSPATH')) exit;

$config_dir = __DIR__ . '/config/';

require_once $config_dir . 'trust-weights.php';
require_once $config_dir . 'fraud-detection.php';
require_once $config_dir . 'scoring.php';
require_once $config_dir . 'tiers.php';
require_once $config_dir . 'limits.php';
require_once $config_dir . 'behavioral.php';
require_once $config_dir . 'quest-rewards.php';
