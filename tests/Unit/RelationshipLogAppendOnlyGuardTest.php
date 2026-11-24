<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

/**
 * The validator relationship log is append-only. Source guard over the
 * whole plugin (bcc-trust.php, app/, includes/), comments removed:
 *  - only the schema file, TableRegistry and the repository name the table;
 *  - the repository writes only through $wpdb->insert(), never update,
 *    delete, replace or a raw UPDATE / DELETE / REPLACE / TRUNCATE / DROP /
 *    ALTER, and never names `->query(` on the table.
 */
final class RelationshipLogAppendOnlyGuardTest extends TestCase
{
    private const REPOSITORY = 'app/Domain/Onchain/Repositories/ValidatorRelationshipLogRepository.php';

    private const ALLOWED = [
        'app/Domain/Core/Database/TableRegistry.php',
        'includes/database/schema-validator-relationship-log.php',
        self::REPOSITORY,
    ];

    public function testOnlyTheRepositoryReachesTheTable(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = ['bcc-trust.php'];
        foreach (['app', 'includes'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        self::assertGreaterThan(100, count($files), 'the scan covers the plugin tree');

        $reaching = [];
        foreach ($files as $relative) {
            $code = GateOrder::code($relative);
            if (str_contains($code, 'bcc_validator_relationship_log')
                || str_contains($code, 'validatorRelationshipLog(')
                || str_contains($code, 'ValidatorRelationshipLogRepository::table(')
            ) {
                $reaching[] = $relative;
            }
        }
        sort($reaching);
        $allowed = self::ALLOWED;
        sort($allowed);
        self::assertSame($allowed, $reaching, 'nothing else may reach the log table');
    }

    public function testTheRepositoryOnlyEverInserts(): void
    {
        $code = GateOrder::code(self::REPOSITORY);

        self::assertSame(1, substr_count($code, '$wpdb->insert('), 'one insert, in append()');
        foreach (['$wpdb->update(', '$wpdb->delete(', '$wpdb->replace(', '$wpdb->query('] as $call) {
            self::assertStringNotContainsString($call, $code, $call);
        }
        foreach (['UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'DROP', 'ALTER', 'INSERT'] as $verb) {
            self::assertDoesNotMatchRegularExpression('/\b' . $verb . '\b/', $code, "no raw {$verb}");
        }
        self::assertStringContainsString('self::lockHeldHere()', GateOrder::body($code, 'append'), 'append checks the lock');
    }
}
