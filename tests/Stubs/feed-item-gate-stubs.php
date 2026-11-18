<?php

declare(strict_types=1);

/**
 * FeedItemGateTest doubles: the activity module lookup and the REST response.
 * The feed allowlist itself is bcc-core's REAL FeedItemNormalizer (the test
 * loads it from the sibling checkout). Load only in a separate process.
 */

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoActivityRepository', false)) {
        class PeepSoActivityRepository
        {
            public static function getModuleIdByActId(int $actId): ?string
            {
                $map = $GLOBALS['__bcc_fig_module_by_act'] ?? [];
                return $map[$actId] ?? null;
            }
        }
    }
}

namespace {
    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @param mixed $data */
            public function __construct(public $data = null, public int $status = 200)
            {
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }
        }
    }
}
