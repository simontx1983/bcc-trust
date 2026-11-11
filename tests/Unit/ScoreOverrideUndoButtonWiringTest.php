<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\Admin\ModerationService;
use PHPUnit\Framework\TestCase;

/**
 * F-113: the admin "Undo Last Override" button is wired end to end.
 *
 * ⚠ includes/admin/moderation.php and moderation-user.php are in
 * phpstan.neon's `scanFiles`, not its `paths`: PHPStan never analyses them,
 * so a misspelt method name there would only surface as a fatal on the
 * admin page. This pins the wiring instead.
 */
final class ScoreOverrideUndoButtonWiringTest extends TestCase
{
    public function testTheActionMapRoutesTheButtonToTheUndo(): void
    {
        $source = $this->read('moderation.php');

        self::assertMatchesRegularExpression(
            "/'undo_score_override'\s*=>\s*static function \(\) use \( \\\$mod \) \{\s*"
            . "\\\$mod->undoScoreOverride\( absint\( \\\$_POST\['user_id'\] \?\? 0 \) \);/",
            $source
        );
        self::assertTrue(method_exists(ModerationService::class, 'undoScoreOverride'));
    }

    public function testTheUserPageShowsTheButtonOnlyWhenThereIsSomethingToUndo(): void
    {
        $source = $this->read('moderation-user.php');

        self::assertStringContainsString('->latestUndoableOverride( (int) $user_id )', $source);
        self::assertTrue(method_exists(ModerationService::class, 'latestUndoableOverride'));
        self::assertMatchesRegularExpression(
            '/<\?php if \( \$bcc_undoable !== null \) : \?>\s*<form method="post"[^>]*>\s*'
            . "<\?php wp_nonce_field\( 'bcc_trust_moderation' \); \?>"
            . '[\s\S]*?<button name="undo_score_override"/',
            $source,
            'the button sits inside the null guard, in a form carrying the moderation nonce the dispatcher checks'
        );

        // The summary is escaped: the reason is admin free text.
        self::assertMatchesRegularExpression(
            "/echo esc_html\( sprintf\(\s*'Override to %d on %s UTC\. Reason: %s'/",
            $source
        );
    }

    private function read(string $file): string
    {
        $source = file_get_contents(__DIR__ . '/../../includes/admin/' . $file);
        self::assertIsString($source);
        return $source;
    }
}
