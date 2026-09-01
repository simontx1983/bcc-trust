<?php

declare(strict_types=1);

/**
 * The PeepSo classes, in their own file.
 *
 * ── WHY SEPARATE ────────────────────────────────────────────────────────
 * `class_exists('\PeepSoGroup')` is the check the provisioning service makes
 * to decide whether PeepSo Groups is installed at all, and a class cannot be
 * un-declared once it exists. The only way to test the "PeepSo is absent"
 * branch honestly is therefore to NOT declare it — which is possible only
 * because the tests run in separate processes and can skip this require.
 *
 * These are deliberately THIN. They are not a model of PeepSo; they are the
 * three call sites the provisioning and compensation paths actually use, and
 * a counter behind each so a test can assert that a group was created, that
 * it was removed again, and that its owner membership went with it.
 */

if (!class_exists('PeepSoGroup')) {
    final class PeepSoGroup
    {
        private int $id = 0;

        /** @param array<string, mixed>|null $data */
        public function __construct($id = null, ?array $data = null)
        {
            if ($id === null && is_array($data)) {
                BccPeepSoSpy::$created++;
                BccPeepSoSpy::$lastOwnerId = (int) ($data['owner_id'] ?? 0);

                // A 0-id group is the shape the real constructor produces when
                // `wp_insert_post()` fails — the service treats it as a
                // creation failure rather than a usable group.
                $this->id = BccPeepSoSpy::$createReturnsZero
                    ? 0
                    : 9000 + BccPeepSoSpy::$created;

                BccPeepSoSpy::$lastGroupId = $this->id;
                return;
            }

            $this->id = (int) $id;
        }

        /** @return mixed */
        public function get(string $key)
        {
            return $key === 'id' ? $this->id : null;
        }

        public function get_image_dir(): string
        {
            return '/tmp/peepso/groups/' . $this->id;
        }
    }
}

if (!class_exists('PeepSoGroupUser')) {
    final class PeepSoGroupUser
    {
        public function __construct(private int $groupId, private ?int $userId = null) {}

        public function member_leave(): bool
        {
            BccPeepSoSpy::$memberLeaves++;
            return true;
        }
    }
}

if (!class_exists('PeepSo')) {
    final class PeepSo
    {
        /**
         * @param mixed $default
         * @return mixed
         */
        public static function get_option(string $key, $default = null)
        {
            // The admin-notification option is off in the fixture, so the
            // compensation audit records `admin_email_sent = no`. A test that
            // wanted the other branch would flip this.
            return $default;
        }
    }
}
