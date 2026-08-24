<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No active `$wpdb` format placeholder may sit inside a comment embedded in
 * SQL handed to `prepare()`.
 *
 * ## Why this needs a guard
 *
 * `wpdb::prepare()` counts placeholders **textually**. It does not parse SQL,
 * so it cannot tell `%f` in a value position from `%f` in a `/* … *` comment.
 * A documented constant written as `Floor (%f) = …` inside the query therefore
 * inflates the placeholder count by one. WordPress then reports
 * `_doing_it_wrong` and — for unnumbered placeholders — returns an empty
 * string, so the query never runs.
 *
 * That is not a loud failure. In `VoteRepository::getVoteAggregatesForPage()`
 * the empty result was absorbed by an `if (!$result)` guard meant for "no
 * votes yet", so every trust-score recalculation silently scored every page as
 * having zero votes while the only symptom was a notice in the log.
 *
 * ## Scope
 *
 * Only string literals passed as the FIRST argument to a `prepare()` call are
 * inspected, located with PHP's own tokenizer. PHPDoc and ordinary `//`
 * comments are separate token types and are never examined, so documenting a
 * method with `LIMIT %d` in its docblock stays legal — which matters, because
 * ten such docblocks exist in this repository today.
 */
final class PreparedSqlCommentPlaceholderTest extends TestCase
{
    /**
     * WordPress placeholder forms: %s %d %f %F %i, optionally numbered
     * (`%1$s`) and optionally carrying printf width/precision (`%.2f`).
     * `%%` is an escaped literal percent and is excluded before matching.
     */
    private const PLACEHOLDER = '/%(?:\d+\$)?(?:[-+ 0]|\'.)*\d*(?:\.\d+)?[sdfFi]/';

    /** SQL comment forms that can appear inside a query string. */
    private const SQL_COMMENTS = [
        '#/\*.*?\*/#s',      // /* block */
        '/--[^\n]*/',        // -- line
        '/(?<![\w\'"])#[^\n]*/', // # line
    ];

    /**
     * The second way an embedded comment breaks a query.
     *
     * `wpdb::query()` calls `get_table_from_query()`, which regex-matches the
     * first `FROM <word>` in the query text. It does not skip comments, so
     * prose reading "…are excluded from the mature_unique_voters count…"
     * resolved the table name to `the`. `check_safe_collation()` then returned
     * false and the query was rejected outright with "Could not perform query
     * because it contains invalid data".
     *
     * Same root cause as the placeholder miscount — comments living inside the
     * SQL string — so it is guarded in the same place.
     *
     * @return list<string> the offending phrases found in $sql's comments
     */
    public static function fromInCommentsIn(string $sql): array
    {
        $found = [];
        foreach (self::SQL_COMMENTS as $pattern) {
            if (!preg_match_all($pattern, $sql, $m)) {
                continue;
            }
            foreach ($m[0] as $comment) {
                if (preg_match_all('/\bfrom\s+\S+/i', $comment, $hits)) {
                    foreach ($hits[0] as $hit) {
                        $found[] = trim($hit);
                    }
                }
            }
        }

        return $found;
    }

    /** @return list<string> the offending placeholders found in $sql's comments */
    public static function offendersIn(string $sql): array
    {
        // `%%` is an escaped percent, never a placeholder. Blank it first so
        // `%%d` is not read as `%d`.
        $sql = str_replace('%%', '', $sql);

        $found = [];
        foreach (self::SQL_COMMENTS as $pattern) {
            if (!preg_match_all($pattern, $sql, $m)) {
                continue;
            }
            foreach ($m[0] as $comment) {
                if (preg_match_all(self::PLACEHOLDER, $comment, $hits)) {
                    foreach ($hits[0] as $hit) {
                        $found[] = $hit;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Extract the first-argument string literal of every `prepare(` call.
     *
     * Uses the tokenizer rather than a regex so that PHPDoc (T_DOC_COMMENT)
     * and `//` comments (T_COMMENT) are structurally excluded: only tokens
     * that are part of a string literal are ever concatenated.
     *
     * @return list<array{sql:string,line:int}>
     */
    private static function preparedSqlIn(string $code): array
    {
        $tokens = token_get_all($code);
        $out    = [];
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || strcasecmp($t[1], 'prepare') !== 0) {
                continue;
            }

            // Must be a method call: ->prepare( or ::prepare(
            $prev = $tokens[$i - 1] ?? null;
            if (!is_array($prev) || !in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            // Find the opening paren.
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }

            // Walk the first argument, collecting string content only.
            $depth = 0;
            $sql   = '';
            $line  = $t[2];
            for ($k = $j; $k < $count; $k++) {
                $tk = $tokens[$k];

                if ($tk === '(' || $tk === '[') {
                    $depth++;
                    continue;
                }
                if ($tk === ')' || $tk === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                    continue;
                }
                if ($tk === ',' && $depth === 1) {
                    break; // end of the first (SQL) argument
                }
                if (is_array($tk) && in_array($tk[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $sql .= $tk[1];
                }
            }

            if ($sql !== '') {
                $out[] = ['sql' => $sql, 'line' => $line];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function phpFiles(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = [];
        foreach ([$root . '/app', $root . '/includes'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
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

    public function testScannerSeesARealNonEmptyTree(): void
    {
        $files = self::phpFiles();
        self::assertGreaterThan(200, count($files), 'file walk found suspiciously few PHP files');

        $withPrepare = 0;
        foreach ($files as $f) {
            if (self::preparedSqlIn((string) file_get_contents($f)) !== []) {
                $withPrepare++;
            }
        }
        self::assertGreaterThan(30, $withPrepare, 'tokenizer extracted prepare() SQL from suspiciously few files');
    }

    public function testNoActivePlaceholderInsideAnEmbeddedSqlComment(): void
    {
        $root       = dirname(__DIR__, 2);
        $violations = [];

        foreach (self::phpFiles() as $file) {
            foreach (self::preparedSqlIn((string) file_get_contents($file)) as $call) {
                $offenders = self::offendersIn($call['sql']);
                if ($offenders !== []) {
                    $violations[] = sprintf(
                        '%s:%d — %s inside an SQL comment',
                        ltrim(str_replace($root, '', $file), '\\/'),
                        $call['line'],
                        implode(', ', $offenders)
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "A format placeholder inside an embedded SQL comment is counted by wpdb::prepare()\n"
            . "and will silently break the query. Reword the comment without a % specifier:\n\n"
            . implode("\n", $violations)
        );
    }

    // ── self-test: the detector must flag, and must not over-flag ────────

    /** @return array<string, array{0:string, 1:bool}> */
    public static function fixtures(): array
    {
        return [
            'block comment with %f'      => ['SELECT /* floor (%f) */ a FROM t WHERE id = %d', true],
            'block comment with %s'      => ['SELECT /* name %s */ a FROM t', true],
            'block comment with %i'      => ['SELECT /* ident %i */ a FROM t', true],
            'numbered placeholder'       => ['SELECT /* first %1$s */ a FROM t', true],
            'precision placeholder'      => ['SELECT /* pct %.2f */ a FROM t', true],
            'dash-dash line comment'     => ["SELECT a -- limit %d\n FROM t", true],
            'hash line comment'          => ["SELECT a # limit %d\n FROM t", true],
            'multiline block'            => ["SELECT /*\n * floor (%f) = X\n */ a FROM t", true],

            'placeholders in values only' => ['SELECT a FROM t WHERE id = %d AND n = %s', false],
            'comment with no placeholder' => ['SELECT /* bounded by LIMIT */ a FROM t WHERE id = %d', false],
            'escaped percent in comment'  => ['SELECT /* 50%% of rows */ a FROM t WHERE id = %d', false],
            'bare percent in comment'     => ['SELECT /* 100% covered */ a FROM t WHERE id = %d', false],
            'LIKE wildcard, no comment'   => ["SELECT a FROM t WHERE n LIKE '%%foo%%' AND id = %d", false],
            'no comment at all'           => ['SELECT a FROM t', false],
        ];
    }

    #[DataProvider('fixtures')]
    public function testDetectorFixtures(string $sql, bool $shouldFlag): void
    {
        $offenders = self::offendersIn($sql);
        if ($shouldFlag) {
            self::assertNotEmpty($offenders, 'detector missed a placeholder inside an SQL comment');
        } else {
            self::assertSame([], $offenders, 'detector flagged something it should not have');
        }
    }

    /**
     * The reason this guard is tokenizer-based. A docblock above a method is a
     * T_DOC_COMMENT, never part of a string literal, so it must be invisible
     * to the scan even when it documents `LIMIT %d`.
     */
    public function testPhpDocMentioningAPlaceholderIsNotExtracted(): void
    {
        $code = <<<'PHP'
        <?php
        class R {
            /**
             * Bounded (§4): `LIMIT %d`, unique-key meta filter.
             *
             * @return list<int>
             */
            public function ids(): array {
                // inline note about %s formatting
                return $this->db->prepare('SELECT id FROM t WHERE a = %d LIMIT 10');
            }
        }
        PHP;

        $calls = self::preparedSqlIn($code);
        self::assertCount(1, $calls, 'exactly one prepare() call should be found');
        self::assertStringNotContainsString('LIMIT %d`', $calls[0]['sql'], 'docblock text leaked into the extracted SQL');
        self::assertSame([], self::offendersIn($calls[0]['sql']));
    }

    /**
     * Mutation control: the real query must be reachable by the scanner, so
     * re-introducing the original defect is caught. Rebuilds the exact comment
     * that caused the outage and asserts the detector fires on it.
     */
    public function testTheOriginalDefectWouldBeCaught(): void
    {
        $sql = "SELECT\n"
             . "  /* ── retroactive fraud discount ──\n"
             . "   * Floor (%f) = BCC_TRUST_RETROACTIVE_FRAUD_FLOOR — must match PHP.\n"
             . "   */\n"
             . "  x FROM t WHERE page_id = %d";

        self::assertSame(['%f'], self::offendersIn($sql));
    }

    /** The shipped query must be clean — the fix, pinned. */
    public function testVoteAggregateQueryIsClean(): void
    {
        $file = dirname(__DIR__, 2) . '/app/Domain/Core/Repositories/VoteRepository.php';
        self::assertFileExists($file);

        $calls = self::preparedSqlIn((string) file_get_contents($file));
        self::assertNotEmpty($calls, 'no prepare() calls extracted from VoteRepository');

        foreach ($calls as $call) {
            self::assertSame([], self::offendersIn($call['sql']), "VoteRepository.php:{$call['line']}");
            self::assertSame([], self::fromInCommentsIn($call['sql']), "VoteRepository.php:{$call['line']}");
        }
    }

    // ── second hazard: a comment that fakes out get_table_from_query() ───

    public function testNoFromClauseWordingInsideAnEmbeddedSqlComment(): void
    {
        $root       = dirname(__DIR__, 2);
        $violations = [];

        foreach (self::phpFiles() as $file) {
            foreach (self::preparedSqlIn((string) file_get_contents($file)) as $call) {
                $offenders = self::fromInCommentsIn($call['sql']);
                if ($offenders !== []) {
                    $violations[] = sprintf(
                        '%s:%d — "%s" inside an SQL comment',
                        ltrim(str_replace($root, '', $file), '\\/'),
                        $call['line'],
                        implode('", "', $offenders)
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "wpdb::query() resolves the target table with a regex for the first \"FROM <word>\"\n"
            . "and does not skip comments, so prose containing \"from\" makes it pick the wrong\n"
            . "table, fail check_safe_collation() and reject the query as invalid data.\n"
            . "Move the prose out of the SQL string:\n\n"
            . implode("\n", $violations)
        );
    }

    /** @return array<string, array{0:string, 1:bool}> */
    public static function fromFixtures(): array
    {
        return [
            'prose "from the" in comment'  => ['SELECT a /* excluded from the count */ FROM t WHERE id = %d', true],
            'prose "FROM" uppercase'       => ['SELECT a /* copied FROM elsewhere */ FROM t WHERE id = %d', true],
            'dash comment with from'       => ["SELECT a -- derived from votes\n FROM t", true],
            'real FROM only'               => ['SELECT a FROM t WHERE id = %d', false],
            'comment without from'         => ['SELECT a /* bounded by LIMIT */ FROM t WHERE id = %d', false],
            'word formation is not from'   => ['SELECT a /* the formation rules */ FROM t', false],
        ];
    }

    #[DataProvider('fromFixtures')]
    public function testFromDetectorFixtures(string $sql, bool $shouldFlag): void
    {
        $offenders = self::fromInCommentsIn($sql);
        if ($shouldFlag) {
            self::assertNotEmpty($offenders, 'detector missed a FROM inside an SQL comment');
        } else {
            self::assertSame([], $offenders, 'detector flagged something it should not have');
        }
    }

    /** Mutation control for the second hazard: the exact comment that caused it. */
    public function testTheOriginalTableConfusionWouldBeCaught(): void
    {
        $sql = "SELECT x\n"
             . "  /* ── voter maturity ──\n"
             . "   * Fresh accounts (age < N days) are excluded from the\n"
             . "   * mature_unique_voters distinct count.\n"
             . "   */\n"
             . "  FROM t WHERE page_id = %d";

        self::assertSame(['from the'], self::fromInCommentsIn($sql));
    }
}
