<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\Admin\ModerationService;
use PHPUnit\Framework\TestCase;

/**
 * F-114 wiring in admin files PHPStan never analyses (phpstan.neon
 * `scanFiles`, not `paths`), so a misspelt method there would only surface
 * as a fatal on the admin page.
 */
final class SuspendSafeguardWiringTest extends TestCase
{
    public function testTheRingFormsReasonReachesTheService(): void
    {
        $source = $this->read('moderation.php');

        self::assertMatchesRegularExpression(
            '/->handleRingAction\(\s*\$resolved\[\'user_ids\'\],\s*\$resolved\[\'action\'\],\s*'
            . 'sanitize_textarea_field\( wp_unslash\( \(string\) \( \$_POST\[\'reason\'\] \?\? \'\' \) \) \)\s*\);/',
            $source
        );
        $params = (new \ReflectionMethod(ModerationService::class, 'handleRingAction'))->getParameters();
        self::assertSame('note', $params[2]->getName() ?? null);
    }

    public function testTheBulkListNamesWhoWasSkipped(): void
    {
        $source = $this->read('moderation-list.php');

        self::assertStringContainsString("intval( \$_GET['bulk_skipped'] )", $source);
        self::assertStringContainsString('->moderationService()->takeSuspendSkips()', $source);
        self::assertTrue(method_exists(ModerationService::class, 'takeSuspendSkips'));
        // Names are user logins: escaped.
        self::assertMatchesRegularExpression("/esc_html\( \\\$bulk_skip_lines !== \[\] \? implode\(/", $source);
    }

    private function read(string $file): string
    {
        $source = file_get_contents(__DIR__ . '/../../includes/admin/' . $file);
        self::assertIsString($source);
        return $source;
    }
}
