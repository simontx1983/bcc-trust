<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No executable SQL in the plugin may use the `FOR SHARE` locking clause.
 *
 * ## Why this needs a guard
 *
 * `FOR SHARE` is MySQL 8 syntax. Production runs MariaDB 11.8, which rejects it
 * with a parse error (1064). `PageReadModelRepository::syncPage()` used it for
 * both score reads, `get_row()` returned null for the rejected statement — the
 * same value as "this page has no score" — and every page's read model was
 * overwritten with neutral defaults every day: 5,688 SQL errors per run.
 *
 * The integration suite could not see it because it ran on MySQL 8, which
 * accepts the clause. `LOCK IN SHARE MODE` takes the same shared lock and is
 * accepted by both engines.
 *
 * ## Scope
 *
 * Only string-literal tokens (single-quoted, double-quoted parts, heredoc and
 * nowdoc bodies) are inspected, located with PHP's own tokenizer. Comments and
 * docblocks are separate token types and are never examined, so explaining the
 * defect in prose — as this file and the repository do — stays legal.
 */
final class NoForShareLockingClauseTest extends TestCase
{
    /** Word-bounded, whitespace-tolerant, case-insensitive. */
    private const FOR_SHARE = '/\bFOR\s+SHARE\b/i';

    /**
     * @return list<array{line: int, text: string}> string tokens in $code that
     *         contain the clause
     */
    public static function offendersIn(string $code): array
    {
        $found = [];
        foreach (token_get_all($code) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if (preg_match(self::FOR_SHARE, $token[1]) === 1) {
                $found[] = ['line' => $token[2], 'text' => trim((string) preg_replace('/\s+/', ' ', $token[1]))];
            }
        }

        return $found;
    }

    /** @return list<string> */
    private static function executableFiles(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = [$root . '/bcc-trust.php'];
        foreach ([$root . '/app', $root . '/includes'] as $dir) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    // ── the guard ────────────────────────────────────────────────────────

    public function testNoExecutableSqlUsesForShare(): void
    {
        $offenders = [];
        foreach (self::executableFiles() as $file) {
            foreach (self::offendersIn((string) file_get_contents($file)) as $hit) {
                $offenders[] = basename($file) . ':' . $hit['line'] . ' — ' . substr($hit['text'], -80);
            }
        }

        self::assertSame([], $offenders, "MariaDB rejects FOR SHARE (errno 1064); use LOCK IN SHARE MODE:\n" . implode("\n", $offenders));
    }

    public function testScannerSeesARealNonEmptyTree(): void
    {
        $files = self::executableFiles();
        self::assertGreaterThan(200, count($files), 'file walk found suspiciously few PHP files');

        $sqlStrings = 0;
        foreach ($files as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token)
                    && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match('/\bSELECT\b.*\bFROM\b/is', $token[1]) === 1
                ) {
                    $sqlStrings++;
                }
            }
        }
        self::assertGreaterThan(100, $sqlStrings, 'tokenizer extracted suspiciously few SQL string tokens — the guard would pass over nothing');
    }

    public function testTheRepositoryThatShippedTheDefectIsInsideTheScan(): void
    {
        $normalise  = static fn (string $path): string => str_replace('\\', '/', $path);
        $repository = $normalise(dirname(__DIR__, 2) . '/app/Domain/Core/Repositories/PageReadModelRepository.php');
        self::assertFileExists($repository);
        self::assertContains($repository, array_map($normalise, self::executableFiles()), 'the file that caused the outage must be scanned');
    }

    // ── the detector ─────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: int}> */
    public static function fixtures(): array
    {
        return [
            'single-quoted literal'           => ["<?php \$q = 'SELECT 1 FROM t FOR SHARE';", 1],
            'double-quoted with interpolation'=> ["<?php \$q = \"SELECT 1 FROM {\$t} WHERE id = %d FOR SHARE\";", 1],
            'clause split across lines'       => ["<?php \$q = \"SELECT 1 FROM t\n  FOR\n  SHARE\";", 1],
            'lower case'                      => ["<?php \$q = 'select 1 from t for share';", 1],
            'heredoc body'                    => ["<?php \$q = <<<SQL\nSELECT 1 FROM t FOR SHARE\nSQL;\n", 1],
            'nowdoc body'                     => ["<?php \$q = <<<'SQL'\nSELECT 1 FROM t FOR SHARE\nSQL;\n", 1],
            'portable clause is allowed'      => ["<?php \$q = 'SELECT 1 FROM t LOCK IN SHARE MODE';", 0],
            'FOR UPDATE is allowed'           => ["<?php \$q = 'SELECT 1 FROM t FOR UPDATE';", 0],
            'line comment is not SQL'         => ["<?php // FOR SHARE is rejected by MariaDB\n\$q = 'SELECT 1';", 0],
            'block comment is not SQL'        => ["<?php /* FOR SHARE */ \$q = 'SELECT 1';", 0],
            'docblock is not SQL'             => ["<?php /** Uses FOR SHARE historically. */ function f(): void {}", 0],
            'word boundary respected'         => ["<?php \$q = 'SELECT shareholder FROM t WHERE x = \\'FORSHARE\\'';", 0],
        ];
    }

    #[DataProvider('fixtures')]
    public function testDetector(string $code, int $expectedHits): void
    {
        self::assertCount($expectedHits, self::offendersIn($code), 'detector miscounted executable FOR SHARE occurrences');
    }
}
