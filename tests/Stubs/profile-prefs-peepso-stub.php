<?php
/**
 * PeepSo, present. Records profile-row writes for MyProfilePrefsEndpointAtomicityTest.
 * Required from setUp()/a test of a separate-process class only.
 */

declare(strict_types=1);

if (!class_exists('PeepSoUser', false)) {
    class PeepSoUser
    {
        private function __construct(private int $userId)
        {
        }

        public static function get_instance(int $userId): self
        {
            return new self($userId);
        }

        /** @return array<string, mixed> */
        public function get_peepso_user(): array
        {
            return ['usr_id' => $this->userId, 'usr_profile_acc' => $GLOBALS['__bcc_prefs_profile_acc'] ?? 10];
        }

        /** @param array<string, mixed> $row */
        public function update_peepso_user(array $row): void
        {
            $GLOBALS['__bcc_prefs_writes'][]      = ['peepso', 'usr_profile_acc', $row['usr_profile_acc']];
            $GLOBALS['__bcc_prefs_profile_acc'] = $row['usr_profile_acc'];
        }

        /** @return int */
        public function get_profile_accessibility()
        {
            return $GLOBALS['__bcc_prefs_profile_acc'] ?? 10;
        }

        /** @return int */
        public function get_profile_post_accessibility()
        {
            return 20;
        }
    }
}
