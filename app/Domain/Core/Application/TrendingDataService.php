<?php

namespace BCC\Trust\Core\Application;

use BCC\Core\Contracts\TrendingDataInterface;
use BCC\Trust\Core\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Named implementation of TrendingDataInterface for ServiceLocator allowlist.
 *
 * Replaces the anonymous class previously registered in bootstrap.php.
 * The ServiceLocator requires exact class-name matching via $allowedProviders,
 * which anonymous classes can never satisfy.
 */
final class TrendingDataService implements TrendingDataInterface
{
    public function getTrendingPages(int $limit): array
    {
        return Plugin::instance()->readModelHealthRepository()->getTrendingPages($limit);
    }
}
