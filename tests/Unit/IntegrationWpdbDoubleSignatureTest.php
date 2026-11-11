<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Integration\MysqliWpdb;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

require_once __DIR__ . '/../Integration/MysqliWpdb.php';

/**
 * The integration `$wpdb` double must be callable in every way real
 * `wpdb` is callable.
 *
 * ⚠⚠ THIS SUITE EXISTS BECAUSE THE SAME DEFECT HAS NOW HAPPENED TWICE,
 * and both times it was invisible until something unrelated broke.
 *
 *   1. `get_results()` took only `$sql`. A dozen production repositories
 *      pass `ARRAY_A`, and PHP SILENTLY IGNORES a surplus argument to a
 *      user-defined method — so those calls got objects in the harness
 *      and arrays in production, and any test over them asserted against
 *      a shape production never returns. Fixed during S9b.
 *   2. `get_col()` took `string $sql`, non-nullable. bcc-core's
 *      `PeepSoGroupRepository` uses real `wpdb`'s documented
 *      `query($sql)` then `get_col(null)` idiom — read the already
 *      fetched `last_result` without re-querying — at two sites. That is
 *      a hard `TypeError` against the old signature, and it red-lit
 *      bcc-trust's integration suite on EVERY branch, including `main`,
 *      for bcc-core code that is correct in production.
 *
 * The second failed loudly. The first did not, which is the real
 * hazard: a double that is merely NARROWER than the thing it doubles
 * reports a pass for a call production makes differently. So the
 * signatures are now asserted directly, in the UNIT job, needing no
 * database — a reflection test cannot be skipped by a missing engine,
 * and it fails on the PR that narrows a signature rather than on an
 * unrelated PR weeks later.
 *
 * ⚠ The expected shapes are real WordPress's, read from
 * `wp-includes/class-wpdb.php` at WP 7.0.2 (the version this project
 * runs) rather than recalled:
 *
 *     :3027  get_var( $query = null, $x = 0, $y = 0 )
 *     :3061  get_row( $query = null, $output = OBJECT, $y = 0 )
 *     :3105  get_col( $query = null, $x = 0 )
 *     :3142  get_results( $query = null, $output = OBJECT )
 *
 * They are hardcoded rather than derived from the stubs because loading
 * `wordpress-stubs.php` into a unit process declares the whole of
 * WordPress and would collide with the guarded stubs these suites rely
 * on. The provenance above is the substitute for deriving them.
 */
#[CoversNothing]
final class IntegrationWpdbDoubleSignatureTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: list<string>}>
     *         method => [method, minimum parameter count, params that must accept null]
     */
    public static function readerProvider(): array
    {
        return [
            // get_var( $query = null, $x = 0, $y = 0 )
            'get_var'     => ['get_var', 3, ['sql']],
            // get_row( $query = null, $output = OBJECT, $y = 0 )
            'get_row'     => ['get_row', 3, ['sql']],
            // get_col( $query = null, $x = 0 )
            'get_col'     => ['get_col', 2, ['sql']],
            // get_results( $query = null, $output = OBJECT )
            'get_results' => ['get_results', 2, ['sql']],
        ];
    }

    /**
     * @param list<string> $nullable
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('readerProvider')]
    public function testEachReaderAcceptsEveryArgumentRealWpdbAccepts(
        string $method,
        int $minParams,
        array $nullable
    ): void {
        $ref = (new ReflectionClass(MysqliWpdb::class))->getMethod($method);

        $this->assertGreaterThanOrEqual(
            $minParams,
            $ref->getNumberOfParameters(),
            sprintf(
                'wpdb::%s() accepts %d arguments. A narrower double silently ignores the surplus '
                . 'ones, so a call that works in production is tested against a different shape.',
                $method,
                $minParams
            )
        );

        $this->assertSame(
            0,
            $ref->getNumberOfRequiredParameters(),
            sprintf('wpdb::%s() can be called with no arguments at all.', $method)
        );

        $params = [];
        foreach ($ref->getParameters() as $p) {
            $params[$p->getName()] = $p;
        }

        foreach ($nullable as $name) {
            $this->assertArrayHasKey($name, $params, "{$method}() must still have a \${$name} parameter");
            $type = $params[$name]->getType();
            $this->assertTrue(
                $type === null || $type->allowsNull(),
                sprintf(
                    '%s::$%s must accept null — real wpdb defaults it to null and the "read '
                    . 'last_result without re-querying" idiom passes null explicitly.',
                    $method,
                    $name
                )
            );
            $this->assertTrue(
                $params[$name]->isDefaultValueAvailable() && $params[$name]->getDefaultValue() === null,
                sprintf('%s::$%s must default to null, as real wpdb does.', $method, $name)
            );
        }
    }

    /**
     * ⚠ Anti-vacuity. Every assertion above is about ABSENCE of a
     * restriction, and a provider whose rows never reached a real method
     * would pass all of them. This pins that the subject is the class
     * under test and that the provider is not empty.
     */
    public function testTheProviderActuallyNamesMethodsOnTheDouble(): void
    {
        $rows = self::readerProvider();
        $this->assertCount(4, $rows);

        $ref = new ReflectionClass(MysqliWpdb::class);
        foreach ($rows as $label => $row) {
            $this->assertTrue($ref->hasMethod($row[0]), "{$label}: {$row[0]} is not a method of the double");
        }
    }

    /**
     * `last_result` is the state the null form reads. Real
     * `wpdb::$last_result` is public, and a private one here would make
     * the idiom untestable and unreadable from a test.
     */
    public function testLastResultIsPublicStateLikeRealWpdb(): void
    {
        $ref = new ReflectionClass(MysqliWpdb::class);

        $this->assertTrue($ref->hasProperty('last_result'), 'The null-query form has nothing to read without it.');
        $prop = $ref->getProperty('last_result');
        $this->assertTrue($prop->isPublic(), 'real wpdb::$last_result is public');

        $type = $prop->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame('array', $type->getName());
    }
}
