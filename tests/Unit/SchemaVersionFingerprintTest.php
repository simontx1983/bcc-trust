<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F-151a — BCC_TRUST_SCHEMA_VERSION ignores comment and whitespace edits,
 * and still moves on every change that could alter the installed schema.
 *
 * The version used to be an md5 of raw file bytes, so a docblock edit to any
 * schema-*.php fired the full dbDelta installer on every environment. The
 * dangerous direction is the opposite one — a real schema change that does
 * NOT move the version never installs — so most cases below are that.
 */
final class SchemaVersionFingerprintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../includes/database/compute-schema-version.php';
    }

    private const BASE = <<<'SRC'
<?php
/** Creates the widgets table. */
function bcc_x_install(): void {
    global $wpdb;
    // The table.
    $sql = "CREATE TABLE {$wpdb->prefix}widgets (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY  (id)
    )";
    dbDelta($sql); # install
}
SRC;

    private function fp(string $src): string
    {
        return bcc_trust_schema_source_fingerprint($src);
    }

    // ── does NOT move ──────────────────────────────────────────────────

    public function testACommentOnlyEditDoesNotMoveTheFingerprint(): void
    {
        $edited = str_replace(
            ['/** Creates the widgets table. */', '// The table.', '# install'],
            ["/**\n * Creates the widgets table.\n * Rewritten docblock, longer.\n */", '// A different comment.', '# reworded'],
            self::BASE
        );

        self::assertSame($this->fp(self::BASE), $this->fp($edited));
    }

    public function testCodeWhitespaceOutsideStringsDoesNotMoveTheFingerprint(): void
    {
        $edited = str_replace(
            ["function bcc_x_install(): void {\n    global \$wpdb;", "dbDelta(\$sql);"],
            ["function   bcc_x_install()  :  void\n{\n\n        global   \$wpdb;", "dbDelta( \$sql );"],
            self::BASE
        );

        self::assertSame($this->fp(self::BASE), $this->fp($edited));
    }

    public function testCrlfLineEndingsOutsideStringsDoNotMoveTheFingerprint(): void
    {
        $noStrings = "<?php\nfunction a(): int {\n    return 1;\n}\n";

        self::assertSame($this->fp($noStrings), $this->fp(str_replace("\n", "\r\n", $noStrings)));
    }

    // ── MUST move ──────────────────────────────────────────────────────

    public function testAColumnChangeInsideTheSqlStringMovesTheFingerprint(): void
    {
        self::assertNotSame(
            $this->fp(self::BASE),
            $this->fp(str_replace('VARCHAR(64)', 'VARCHAR(128)', self::BASE))
        );
    }

    public function testWhitespaceInsideTheSqlStringMovesTheFingerprint(): void
    {
        // dbDelta is whitespace-sensitive (it needs two spaces after
        // PRIMARY KEY), so string contents are hashed verbatim.
        self::assertNotSame(
            $this->fp(self::BASE),
            $this->fp(str_replace('PRIMARY KEY  (id)', 'PRIMARY KEY (id)', self::BASE))
        );
    }

    public function testHeredocAndNowdocBodiesAreHashedVerbatim(): void
    {
        $heredoc = "<?php\n\$s = <<<SQL\nALTER TABLE t ADD c INT\nSQL;\n";
        $nowdoc  = "<?php\n\$s = <<<'SQL'\nALTER TABLE t ADD c INT\nSQL;\n";

        self::assertNotSame($this->fp($heredoc), $this->fp(str_replace('ADD c INT', 'ADD  c INT', $heredoc)));
        self::assertNotSame($this->fp($nowdoc), $this->fp(str_replace('ADD c INT', 'ADD  c INT', $nowdoc)));
    }

    public function testACodeChangeMovesTheFingerprint(): void
    {
        self::assertNotSame(
            $this->fp(self::BASE),
            $this->fp(str_replace('dbDelta($sql);', 'dbDelta($sql); bcc_x_extra();', self::BASE))
        );
    }

    public function testRemovingWhitespaceThatSeparatesTokensMovesTheFingerprint(): void
    {
        // Token boundaries are hashed, so dropping whitespace can never make
        // two different programs alike: `new Foo` vs `newFoo`, `- -` vs `--`.
        self::assertNotSame(
            $this->fp("<?php\n\$a = new Foo;"),
            $this->fp("<?php\n\$a = newFoo;")
        );
        self::assertNotSame(
            $this->fp("<?php\n\$a = \$b - -\$c;"),
            $this->fp("<?php\n\$a = \$b --\$c;")
        );
        // A comment between tokens is a separator too, equal to a space.
        self::assertSame(
            $this->fp("<?php\n\$a = new Foo;"),
            $this->fp("<?php\n\$a = new/* x */Foo;")
        );
    }

    // ── the real tree ──────────────────────────────────────────────────

    public function testTheRealVersionIsTenHexCharsAndStable(): void
    {
        $dir = dirname(__DIR__, 2);
        $v   = bcc_trust_compute_schema_version($dir);

        self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $v);
        self::assertSame($v, bcc_trust_compute_schema_version($dir));
    }

    public function testTheComputerIsNotItsOwnInput(): void
    {
        // Named compute-*.php, not schema-*.php, so editing how the hash is
        // computed is not itself mistaken for a schema change by the glob.
        self::assertStringStartsNotWith(
            'schema-',
            basename(dirname(__DIR__, 2) . '/includes/database/compute-schema-version.php')
        );
        self::assertNotContains(
            dirname(__DIR__, 2) . '/includes/database/compute-schema-version.php',
            glob(dirname(__DIR__, 2) . '/includes/database/schema-*.php') ?: []
        );
    }
}
