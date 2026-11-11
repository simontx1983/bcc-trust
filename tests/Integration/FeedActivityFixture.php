<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

/**
 * One feed activity + its backing wp_post, seeded into the shared harness
 * tables WITHOUT touching anything the test did not create.
 *
 *   - `peepso_activities` is created only when absent and dropped only when
 *     this fixture created it;
 *   - `wp_posts` is the SHARED harness table — only columns it lacks are
 *     added (other suites widen it too);
 *   - an id that is already taken SKIPS the test rather than overwriting a
 *     real row; teardown deletes only the ids seeded here.
 *
 * Shared by the F-73 moderation suites (restore cooldown, credible
 * reporters, report visibility).
 */
trait FeedActivityFixture
{
    private bool $activitiesWasOurs = false;

    /** @var list<array{0: int, 1: int}> [actId, postId] pairs seeded here */
    private array $seededActivities = [];

    private function seedFeedActivity(int $actId, int $postId, string $moduleId = '1', int $author = 960001): void
    {
        global $wpdb;

        $acts   = $wpdb->prefix . 'peepso_activities';
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $acts)) === $acts;
        if (!$exists) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE {$acts} (
                    act_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    act_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    act_module_id VARCHAR(20) NOT NULL DEFAULT '0',
                    act_external_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    act_access INT NOT NULL DEFAULT 10,
                    PRIMARY KEY (act_id)
                ) DEFAULT CHARSET=utf8mb4"
            ), 'create activities: ' . $wpdb->last_error);
            $this->activitiesWasOurs = true;
        }

        $wanted = [
            'post_author'   => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_date_gmt' => "DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'",
        ];
        $existing = (array) $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $wpdb->posts
        ));
        $adds = [];
        foreach ($wanted as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $adds[] = "ADD COLUMN {$column} {$definition}";
            }
        }
        if ($adds !== []) {
            self::assertNotFalse($wpdb->query("ALTER TABLE {$wpdb->posts} " . implode(', ', $adds)), $wpdb->last_error);
        }

        foreach ([[$acts, 'act_id', $actId], [$wpdb->posts, 'ID', $postId]] as [$table, $col, $id]) {
            $taken = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = %d", $id));
            if ($taken > 0) {
                self::markTestSkipped("{$table}.{$col}={$id} already exists; refusing to overwrite a real row");
            }
        }

        self::assertNotFalse($wpdb->insert($wpdb->posts, [
            'ID' => $postId, 'post_type' => 'peepso-post', 'post_status' => 'publish', 'post_author' => $author,
        ]), $wpdb->last_error);
        self::assertNotFalse($wpdb->insert($acts, [
            'act_id' => $actId, 'act_module_id' => $moduleId, 'act_external_id' => $postId, 'act_owner_id' => $author,
        ]), $wpdb->last_error);
        $this->seededActivities[] = [$actId, $postId];
    }

    private function removeFeedActivities(): void
    {
        global $wpdb;
        foreach ($this->seededActivities as [$actId, $postId]) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $postId));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE ID = %d", $postId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'peepso_activities WHERE act_id = %d', $actId));
        }
        $this->seededActivities = [];
        if ($this->activitiesWasOurs) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'peepso_activities');
            $this->activitiesWasOurs = false;
        }
    }
}
