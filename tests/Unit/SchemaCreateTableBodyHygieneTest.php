<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * No blank line may appear inside a `CREATE TABLE ( ... )` column list.
 *
 * ── WHY THIS IS A GUARD AND NOT A UNIT TEST ─────────────────────────────
 * `dbDelta()` splits the CREATE TABLE body on newlines and treats every line
 * as a column or key definition. A blank line becomes an EMPTY definition:
 * its index regex finds nothing, `$index_column_matches['column_name']` is
 * unset, and WordPress emits
 *
 *     ALTER TABLE wp_bcc_chains ADD  `` (``)
 *
 * which fails with a syntax error on EVERY schema pass — four PHP warnings
 * from wp-admin/includes/upgrade.php (3019, 3025, 3028, 3057) plus one
 * WordPress database error, forever.
 *
 * ⚠ No index is created or lost. The index set is byte-identical either way,
 * which is precisely why this survived: it costs log noise and a wasted
 * round-trip and nothing an operator would notice. Measured in a container:
 * nine warning/error lines per pass before, zero after, with
 * `chain_type, is_active, PRIMARY, slug` both times.
 *
 * The trigger is invisible in review — a blank line is the most
 * unremarkable thing in a file — so it needs a guard rather than care.
 */
#[Group('unit')]
final class SchemaCreateTableBodyHygieneTest extends TestCase
{
    /**
     * Blank lines inside a CREATE TABLE column list, as [line => table].
     *
     * ⚠ LINE-BASED, DELIBERATELY. The first version of this scan was a regex
     * from `CREATE TABLE` to a terminator alternation that included `;`. It
     * ran straight past the closing paren and reported 99 hits across seven
     * files, nearly all of them inside docblocks and function bodies. The
     * real count was ONE. A scan that walks lines and tracks whether it is
     * inside the parens cannot make that mistake.
     *
     * The opening line must also END with `(` and must not be a comment, or a
     * docblock sentence mentioning "the CREATE TABLE's set" opens a phantom
     * body — that was the source of ten of the eleven false positives, and
     * `CREATE TABLE IF NOT EXISTS … LIKE …` (no body at all) was the
     * eleventh.
     *
     * @return list<array{file: string, line: int}>
     */
    private static function blankLinesInCreateTableBodies(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $hits   = [];
        $inBody = false;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if (!$inBody) {
                $isComment = $trimmed === ''
                    || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*')
                    || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '#');
                if (!$isComment
                    && stripos($trimmed, 'CREATE TABLE') !== false
                    && str_ends_with($trimmed, '(')) {
                    $inBody = true;
                }
                continue;
            }

            if (str_starts_with($trimmed, ')')) {
                $inBody = false;
                continue;
            }

            if ($trimmed === '') {
                $hits[] = ['file' => $file, 'line' => $i + 1];
            }
        }

        return $hits;
    }

    /** @return list<string> */
    private static function schemaFiles(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = glob($root . '/includes/database/*.php') ?: [];
        sort($files);

        return $files;
    }

    public function testNoSchemaFileHasABlankLineInsideACreateTableBody(): void
    {
        $offenders = [];
        foreach (self::schemaFiles() as $file) {
            foreach (self::blankLinesInCreateTableBodies($file) as $hit) {
                $offenders[] = basename($hit['file']) . ':' . $hit['line'];
            }
        }

        self::assertSame(
            [],
            $offenders,
            "A blank line inside a CREATE TABLE column list makes dbDelta emit "
            . "ALTER TABLE ... ADD `` (``) on every schema pass. Delete the blank "
            . "line; put any explanation outside the SQL string, because a comment "
            . "line goes through the same parser. Offenders: " . implode(', ', $offenders)
        );
    }

    public function testTheScanActuallyLooksAtSomething(): void
    {
        // ⚠ Anti-vacuity. A guard whose glob silently returns nothing passes
        // forever. `includes/database/` holds dozens of files and at least one
        // real CREATE TABLE body.
        $files = self::schemaFiles();
        self::assertGreaterThan(40, count($files), 'the schema glob must find the schema files');

        $withBodies = 0;
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            foreach (explode("\n", $src) as $line) {
                $t = trim($line);
                if (stripos($t, 'CREATE TABLE') !== false
                    && str_ends_with($t, '(')
                    && !str_starts_with($t, '*')
                    && !str_starts_with($t, '//')) {
                    $withBodies++;
                    break;
                }
            }
        }
        self::assertGreaterThan(20, $withBodies, 'the scan must find real CREATE TABLE bodies to inspect');
    }

    public function testThePlantedOffenderIsFound(): void
    {
        // ⚠ THE PLANTED POSITIVE, in the guard itself. Without it, a scanner
        // that quietly matched nothing would report a clean tree forever —
        // which is exactly how the first version of this scan looked right
        // while being wrong.
        $tmp = tempnam(sys_get_temp_dir(), 'bcc-schema-guard') . '.php';
        file_put_contents($tmp, <<<'PHPX'
<?php
// a blank line out here must NOT be reported

$sql = "CREATE TABLE {$table} (
    id BIGINT UNSIGNED NOT NULL,

    slug VARCHAR(50) NOT NULL,
    PRIMARY KEY (id)
) {$charset};";

function unrelated() {

    return 1;
}
PHPX);

        $hits = self::blankLinesInCreateTableBodies($tmp);
        unlink($tmp);

        self::assertCount(1, $hits, 'exactly the planted blank line must be reported');
        self::assertSame(6, $hits[0]['line'], 'and at the line it was planted on');
    }

    public function testADocblockMentionDoesNotOpenAPhantomBody(): void
    {
        // The false-positive class that made the first scan report 99.
        $tmp = tempnam(sys_get_temp_dir(), 'bcc-schema-guard') . '.php';
        file_put_contents($tmp, <<<'PHPX'
<?php
/**
 * An ALTER-declared key merges into the CREATE TABLE (
 * ...set instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

$wpdb->query("CREATE TABLE IF NOT EXISTS $archive LIKE $main");

PHPX);

        $hits = self::blankLinesInCreateTableBodies($tmp);
        unlink($tmp);

        self::assertSame([], $hits, 'prose and LIKE-form statements must not open a body');
    }
}
