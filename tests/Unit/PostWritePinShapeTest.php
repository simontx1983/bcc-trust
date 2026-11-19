<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-361 — `pinOnly()` neutralises the request for each distinct WRITE
 * SHAPE used in this plugin.
 *
 * ── WHY SHAPES AND NOT ONE TEST PER SITE ────────────────────────────
 *
 * A per-site behavioural pin test proves two things:
 *
 *   (a) the site is wrapped, and
 *   (b) `pinOnly` actually neutralises the request.
 *
 * ⭐ (a) is now proved MECHANICALLY FOR ALL 19 SITES by
 * {@see PostWritePinInventoryTest} — strictly better than a test per
 * site, because it also catches sites nobody thought to write a test
 * for, which is how F-361's first pass missed five.
 *
 * (b) is a property of the GUARD, and it is identical at every site:
 * the same `pinRequest()` / `finally restoreRequest()` runs whatever is
 * inside the closure. #772's `ActivityStreamWriterRequestPinTest` already
 * proves it end-to-end on a real member path.
 *
 * So what is left worth testing is each distinct SHAPE of thing being
 * wrapped, because the shapes differ in ways the guard cannot see:
 *
 *   1. a plain function call        — `wp_insert_post([...])`
 *   2. a 2-argument CONSTRUCTOR    — `new \PeepSoGroup(null, $data)`
 *   3. a WordPress wrapper         — `wp_save_post_revision($id)`
 *
 * ⚠ Four of the member-reachable sites also have full behavioural tests
 * through their own service harnesses; these three shapes cover the
 * sites whose services have no harness, rather than standing up two
 * heavyweight ones to re-prove the mechanism. That split is stated in
 * the PR rather than implied.
 *
 * ── WHAT IS REAL HERE AND WHAT IS DOUBLED ───────────────────────────
 *
 * ⭐ The GUARD is real — `BCC\Core\PeepSo\PeepSoAddPostGuard` loaded
 * from the sibling bcc-core checkout. A stub `pinOnly()` that merely
 * invoked its callable would make every assertion below vacuous.
 *
 * The things being WRAPPED are doubled, because that is what lets the
 * fixture observe the request AT the write.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PostWritePinShapeTest extends TestCase
{
    /** A request shaped the way an attacker would shape it. */
    private const HOSTILE = [
        'mood'         => '7',
        'location'     => 'somewhere else',
        'anon_id'      => '41',
        'group_id'     => '900',
        'page_id'      => '55',
        'module_id'    => '8',
        'post_as_page' => '1',
        'type'         => 'photo',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }

        $GLOBALS['__bcc_shape'] = [];

        require_once __DIR__ . '/../Stubs/pin-shape-stubs.php';
        require_once __DIR__ . '/../Stubs/peepso-add-post-guard-real.php';

        $_GET     = self::HOSTILE;
        $_POST    = self::HOSTILE;
        $_REQUEST = self::HOSTILE;
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];
        unset($GLOBALS['__bcc_shape']);
        parent::tearDown();
    }

    /** @return array{fn: string, args: array<string, mixed>, get: array<string, mixed>, post: array<string, mixed>, request: array<string, mixed>} */
    private static function onlyWrite(): array
    {
        /** @var list<array{fn: string, args: array<string, mixed>, get: array<string, mixed>, post: array<string, mixed>, request: array<string, mixed>}> $writes */
        $writes = $GLOBALS['__bcc_shape'];

        self::assertCount(1, $writes, 'exactly one write should have been recorded');

        return $writes[0];
    }

    // ── the three shapes, each wrapped the way production wraps it ───

    /** SHAPE 1 — a plain function call. */
    private static function shapeFunctionCall(): void
    {
        \BCC\Core\PeepSo\PeepSoAddPostGuard::pinOnly(
            [],
            static fn (): int => \BCC\Trust\Tests\Fixtures\wp_insert_post(['post_title' => 'x'])
        );
    }

    /** SHAPE 2 — a 2-argument constructor (the PeepSo model write). */
    private static function shapeConstructor(): void
    {
        \BCC\Core\PeepSo\PeepSoAddPostGuard::pinOnly(
            [],
            static fn (): \PeepSoGroup => new \PeepSoGroup(null, ['name' => 'a community'])
        );
    }

    /** SHAPE 3 — a WordPress wrapper that inserts a revision. */
    private static function shapeRevision(): void
    {
        \BCC\Core\PeepSo\PeepSoAddPostGuard::pinOnly(
            [],
            static fn () => \BCC\Trust\Tests\Fixtures\wp_save_post_revision(901)
        );
    }

    /** @return array<string, array{0: string}> */
    public static function shapeProvider(): array
    {
        return [
            'a plain function call'      => ['shapeFunctionCall'],
            'a 2-argument constructor'   => ['shapeConstructor'],
            'a WordPress revision write' => ['shapeRevision'],
        ];
    }

    /**
     * ⭐ THE CORE PROPERTY, for every shape: at the moment the write
     * runs, every key those three listeners read is neutral and the
     * query string — which `PeepSoInput` consults FIRST — is empty.
     */
    #[DataProvider('shapeProvider')]
    public function testTheRequestIsNeutralisedDuringEveryWriteShape(string $shape): void
    {
        self::assertSame(self::HOSTILE, $_POST, 'precondition: the request is hostile going in');

        self::{$shape}();

        $write = self::onlyWrite();

        self::assertSame([], $write['get'], '$_GET must be empty: PeepSoInput reads it FIRST');
        self::assertSame([], $write['request'], '$_REQUEST must be empty too');

        // ⚠ PINNED, NOT UNSET. `PeepSoInput::int()` falls back to the RAW
        // REQUEST BODY for a key absent from $_GET and $_POST, so
        // unsetting `mood` or `anon_id` would OPEN that leg rather than
        // close it. Only a present key at a neutral value shuts it.
        foreach (['mood' => 0, 'anon_id' => 0, 'group_id' => 0, 'page_id' => 0] as $key => $neutral) {
            self::assertArrayHasKey($key, $write['post'], "{$key} must be PINNED, not merely unset");
            self::assertSame($neutral, $write['post'][$key], "{$key} must be neutral during the write");
        }
        self::assertSame('', $write['post']['location'], 'location must be neutral during the write');
    }

    /**
     * ⚠ ANTI-VACUITY. Without this, every assertion above would also pass
     * against a shape helper that never wrote anything: the fixture has to
     * prove the hostile request was really present AND really restored.
     */
    #[DataProvider('shapeProvider')]
    public function testTheRequestIsRestoredAfterEveryWriteShape(string $shape): void
    {
        self::{$shape}();

        self::assertSame(self::HOSTILE, $_GET, '$_GET restored');
        self::assertSame(self::HOSTILE, $_POST, '$_POST restored');
        self::assertSame(self::HOSTILE, $_REQUEST, '$_REQUEST restored');
    }

    /**
     * ⚠ The restore sits in a `finally`, so it survives a write that
     * throws. Without that, one failed write would leave the rest of the
     * request running against an emptied `$_POST` — worse than the bug.
     */
    public function testTheRequestIsRestoredWhenAWriteThrows(): void
    {
        try {
            \BCC\Core\PeepSo\PeepSoAddPostGuard::pinOnly(
                [],
                static function (): void {
                    throw new \RuntimeException('the write blew up');
                }
            );
            self::fail('the closure was supposed to throw');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(self::HOSTILE, $_POST, '$_POST restored through the finally');
        self::assertSame(self::HOSTILE, $_GET, '$_GET restored through the finally');
    }

    /**
     * ⚠⚠ THE SHAPE-2 DISTINCTION, asserted rather than assumed: a
     * ONE-argument `new PeepSoGroup($id)` only LOADS and reaches no
     * write, which is why the guard must not demand a pin around it and
     * why the three provisioning services leave their read calls alone.
     *
     * If this ever starts recording a write, the guard's arg-count rule
     * is wrong and the provisioning services have unpinned writes.
     */
    public function testAOneArgumentGroupConstructorIsNotAWrite(): void
    {
        new \PeepSoGroup(4242);

        self::assertSame([], $GLOBALS['__bcc_shape'], 'loading a group must write nothing');
    }

    /**
     * ⚠ And the write really is observable without a pin — proving the
     * fixture can tell the two apart, so the assertions above are not
     * passing because the snapshot is always empty.
     */
    public function testAnUnpinnedWriteSeesTheHostileRequest(): void
    {
        \BCC\Trust\Tests\Fixtures\wp_insert_post(['post_title' => 'unpinned']);

        $write = self::onlyWrite();

        self::assertSame('7', $write['post']['mood'], 'an UNPINNED write sees the hostile value');
        self::assertSame(self::HOSTILE, $write['get'], 'and the hostile query string');
    }
}
