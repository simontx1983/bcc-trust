<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The request budget is a NEUTRAL primitive: it must not depend on the
 * full-chain scanner.
 *
 * ── WHY THIS GUARD EXISTS ───────────────────────────────────────────────
 * The type began life as `CosmwasmTickBudget` and read both of its ceilings
 * from `CosmwasmDiscoveryGate`. Every ownership surface that bounds provider
 * work — join, stance, the eligible-group listing, the profile badge,
 * reconciliation and the revoke sweep — therefore loaded a scanner class to
 * get a number that has nothing to do with scanning. With the scanner frozen
 * (and, later, retired) that coupling is what would break membership
 * verification, so it is asserted structurally rather than left to review.
 *
 * The strongest form of "no dependency" is the one asserted here: in
 * EXECUTABLE code the file names no other class AT ALL. A file that
 * references nothing cannot fail to load because something else is absent.
 * Prose in the docblock may still discuss the scanner — that is
 * documentation, not a dependency, which is why the scan reads tokens and
 * skips comments.
 */
#[Group('unit')]
final class ProviderRequestBudgetIsNeutralTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../app/Domain/Onchain/Support/ProviderRequestBudget.php';

    /** Class-like names referenced by executable tokens, excluding the file's own declaration. */
    private static function referencedClassNames(string $source): array
    {
        $tokens = token_get_all($source);
        $names  = [];
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            // Skip the file's own declaration: `final class X` / `namespace N;`.
            if ($t[0] === T_CLASS || $t[0] === T_NAMESPACE) {
                while ($i < $count && !(is_string($tokens[$i]) && ($tokens[$i] === '{' || $tokens[$i] === ';'))) {
                    $i++;
                }
                continue;
            }
            if ($t[0] === T_USE) {
                // An import at file level is a dependency by definition.
                $j = $i + 1;
                $import = '';
                while ($j < $count && !(is_string($tokens[$j]) && $tokens[$j] === ';')) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $import .= $tokens[$j][1];
                    }
                    $j++;
                }
                if ($import !== '') {
                    $names[] = $import;
                }
                $i = $j;
                continue;
            }
            if (!in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            // A class reference is followed by `::` or preceded by `new` / `instanceof`.
            $next = $tokens[$i + 1] ?? null;
            $prevIdx = $i - 1;
            while ($prevIdx > 0 && is_array($tokens[$prevIdx]) && $tokens[$prevIdx][0] === T_WHITESPACE) {
                $prevIdx--;
            }
            $prev = $tokens[$prevIdx] ?? null;
            $isStatic = is_array($next) && $next[0] === T_DOUBLE_COLON;
            $isNew    = is_array($prev) && in_array($prev[0], [T_NEW, T_INSTANCEOF], true);
            if ($isStatic || $isNew) {
                $names[] = $t[1];
            }
        }

        // `self::` is the file's own class, not a dependency.
        return array_values(array_filter(array_unique($names), static fn(string $n): bool => !in_array(strtolower($n), ['self', 'static', 'parent'], true)));
    }

    public function testTheBudgetReferencesNoOtherClassInExecutableCode(): void
    {
        $source = (string) file_get_contents(self::SOURCE);
        self::assertNotSame('', $source, 'the budget source must be readable');

        self::assertSame(
            [],
            self::referencedClassNames($source),
            'ProviderRequestBudget must name no other class in executable code'
        );
    }

    public function testNoScannerIdentifierAppearsInExecutableCode(): void
    {
        $source = (string) file_get_contents(self::SOURCE);
        $offenders = [];

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue; // prose may discuss the scanner; only code may not depend on it
            }
            if (preg_match('/cosmwasm|discovery|scanner|discoverygate/i', (string) $token[1])) {
                $offenders[] = $token[1] . ' @ line ' . $token[2];
            }
        }

        self::assertSame([], $offenders, 'no scanner identifier may appear in executable code');
    }

    /** The scan must be able to SEE a violation, or its silence proves nothing. */
    public function testTheScanDetectsAPlantedDependencyAndIgnoresProse(): void
    {
        $violating = "<?php\nnamespace X;\nfinal class B {\n public function __construct() { \$this->n = CosmwasmDiscoveryGate::requestBudget(); }\n}\n";
        self::assertSame(['CosmwasmDiscoveryGate'], self::referencedClassNames($violating), 'a real dependency is caught');

        $importing = "<?php\nnamespace X;\nuse BCC\\Trust\\Onchain\\Support\\CosmwasmDiscoveryGate;\nfinal class B {}\n";
        self::assertNotSame([], self::referencedClassNames($importing), 'an import alone is caught');

        $prose = "<?php\nnamespace X;\n/** Ceilings come from CosmwasmDiscoveryGate in the caller. */\nfinal class B {\n public function n(): int { return 1; }\n}\n";
        self::assertSame([], self::referencedClassNames($prose), 'prose about the scanner is not a dependency');
    }

    /** Both ceilings are the caller's to state — a default could only come from one subsystem. */
    public function testBothCeilingsAreRequiredConstructorArguments(): void
    {
        $ctor = (new \ReflectionClass(ProviderRequestBudget::class))->getConstructor();
        self::assertNotNull($ctor);
        self::assertSame(2, $ctor->getNumberOfParameters());
        self::assertSame(2, $ctor->getNumberOfRequiredParameters(), 'neither ceiling may have a default');
    }

    public function testTheBudgetStillCountsAndStopsExactlyAsBefore(): void
    {
        $budget = new ProviderRequestBudget(25, 20);
        self::assertSame(25, $budget->remaining());
        self::assertSame(0, $budget->spent());
        self::assertTrue($budget->canSpend(25));
        self::assertFalse($budget->canSpend(26));

        $budget->spend(5);
        self::assertSame(20, $budget->remaining());
        self::assertSame(5, $budget->spent());

        $budget->reserve(18);
        self::assertSame(2, $budget->available(), 'a reserve only ever restricts');
        self::assertFalse($budget->canSpend(3));
        $budget->reserve(0);
        self::assertSame(20, $budget->available());

        $budget->spend(20);
        self::assertTrue($budget->exhausted());
    }

    /** The wall clock still wins over the request budget. */
    public function testAnExpiredClockStopsTheBudgetEvenWithRequestsLeft(): void
    {
        $budget = new ProviderRequestBudget(1000, 0); // max(1, 0) = 1 second
        self::assertSame(1000, $budget->remaining());
        usleep(1_100_000);
        self::assertTrue($budget->timedOut());
        self::assertTrue($budget->exhausted(), 'the clock stops the tick even with requests left');
        self::assertFalse($budget->canSpend(1));
    }

    /**
     * The real isolation proof: load the file in a FRESH PHP process where no
     * scanner class, no autoloader and no WordPress exist, and use it.
     */
    public function testItLoadsAndWorksInAProcessWithNothingElseLoaded(): void
    {
        $php = PHP_BINARY;
        self::assertNotSame('', $php, 'PHP_BINARY must be known for the isolation proof to mean anything');

        $script = <<<'PHP'
<?php
define('ABSPATH', __DIR__);
require getenv('BCC_BUDGET_FILE');
$declared = array_filter(get_declared_classes(), static fn(string $c): bool => stripos($c, 'BCC\\') === 0);
$b = new BCC\Trust\Onchain\Support\ProviderRequestBudget(7, 20);
$b->spend(3);
echo json_encode(['classes' => array_values($declared), 'remaining' => $b->remaining(), 'spent' => $b->spent(), 'exhausted' => $b->exhausted()]);
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'bccbudget') . '.php';
        file_put_contents($tmp, $script);

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($tmp);
        $env = 'BCC_BUDGET_FILE=' . realpath(self::SOURCE);
        putenv($env);
        $output = (string) shell_exec($cmd);
        @unlink($tmp);

        $decoded = json_decode(trim($output), true);
        self::assertIsArray($decoded, 'the isolated process must run the budget: ' . $output);
        self::assertSame(['BCC\\Trust\\Onchain\\Support\\ProviderRequestBudget'], $decoded['classes'], 'only the budget class loads');
        self::assertSame(4, $decoded['remaining']);
        self::assertSame(3, $decoded['spent']);
        self::assertFalse($decoded['exhausted']);
    }
}
