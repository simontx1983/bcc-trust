<?php
/**
 * Stubs for ValidatorMintOffsetProgressTest.
 *
 * mintMissing() re-checks each row with
 * ValidatorRepository::findPlaceholderPageId() BEFORE attempting a mint,
 * and that call sits OUTSIDE the per-row try/catch — so without a $wpdb
 * double the loop dies on the first row and the offset behaviour under
 * test is never reached. The double answers "no existing page" (null →
 * (int) 0), which is the state a mint candidate is in by definition.
 *
 * ⚠ Global $wpdb is unavoidable here (the repository reads `global $wpdb`),
 * which is exactly why the consuming test runs in separate processes: a
 * $wpdb this thin would otherwise be visible to every other suite in the
 * process.
 *
 * @package BCC\Trust\Tests\Stubs
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Stubs {

    final class MintOffsetWpdb
    {
        public string $prefix   = 'wp_';
        public string $postmeta = 'wp_postmeta';
        public string $posts    = 'wp_posts';
        public string $last_error = '';

        /** @var list<string> */
        public array $queries = [];

        public function prepare(string $sql, mixed ...$args): string
        {
            return $sql;
        }

        /** Always "this validator has no page yet". */
        public function get_var(string $sql): ?string
        {
            $this->queries[] = $sql;

            return null;
        }

        /** @return list<object> */
        public function get_results(string $sql, mixed $output = null): array
        {
            $this->queries[] = $sql;

            return [];
        }

        public function query(string $sql): int
        {
            $this->queries[] = $sql;

            return 0;
        }
    }
}

namespace {
    if (!isset($GLOBALS['wpdb']) || !($GLOBALS['wpdb'] instanceof \BCC\Trust\Tests\Stubs\MintOffsetWpdb)) {
        $GLOBALS['wpdb'] = new \BCC\Trust\Tests\Stubs\MintOffsetWpdb();
    }
}
