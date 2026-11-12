<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Services\HallsService;
use BCC\Trust\Onchain\Services\ValidatorLogoService;
use BCC\Trust\Onchain\Support\LocalUploadUrl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Validator logos stored with another host baked in (the 2026-11-12 staging-
 * restore incident: 73 `stage.` logo URLs on production, every one a 404,
 * none self-healing).
 *
 *   - rebase(): a sibling-host upload URL is served from this site's uploads.
 *   - needsResideload(): a stored logo on another host, or with its file
 *     gone, is re-downloaded even when the Keybase source is unchanged.
 */
#[CoversClass(LocalUploadUrl::class)]
#[CoversClass(ValidatorLogoService::class)]
#[CoversClass(HallsService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LocalUploadUrlTest extends TestCase
{
    private const CMS = 'https://cms.bluecollarcrypto.io/wp-content/uploads';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/bcc-uploads-' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/2026/06', 0777, true);
        file_put_contents($this->dir . '/2026/06/validator-128-logo.jpg', 'x');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/2026/06/validator-128-logo.jpg');
        @rmdir($this->dir . '/2026/06');
        @rmdir($this->dir . '/2026');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{baseurl: string, basedir: string} */
    private function uploads(): array
    {
        return ['baseurl' => self::CMS, 'basedir' => $this->dir];
    }

    // ── rebase ───────────────────────────────────────────────────────────

    public function testASiblingHostUploadIsRebasedOntoThisSite(): void
    {
        $want = self::CMS . '/2026/06/validator-128-logo.jpg';
        foreach ([
            'https://stage.bluecollarcrypto.io/wp-content/uploads/2026/06/validator-128-logo.jpg',
            'https://bluecollarcrypto.io/wp-content/uploads/2026/06/validator-128-logo.jpg',
            'http://stage.bluecollarcrypto.io/wp-content/uploads/2026/06/validator-128-logo.jpg',
        ] as $stored) {
            self::assertSame($want, LocalUploadUrl::rebase($stored, $this->uploads()), $stored);
        }
    }

    public function testEverythingElseIsLeftAlone(): void
    {
        foreach ([
            self::CMS . '/2026/06/validator-128-logo.jpg',                       // already here
            'https://evil.example/wp-content/uploads/2026/06/a.jpg',             // another domain
            'https://bluecollarcrypto.io.evil.example/wp-content/uploads/a.jpg', // look-alike
            'https://stage.bluecollarcrypto.io/other/2026/06/a.jpg',             // not an upload
            'https://stage.bluecollarcrypto.io/x/wp-content/uploads/2026/06/a.jpg', // uploads not at the path root
            'https://stage.bluecollarcrypto.io/wp-content/uploads/../wp-config.php',
        ] as $stored) {
            self::assertSame($stored, LocalUploadUrl::rebase($stored, $this->uploads()), $stored);
        }
        self::assertNull(LocalUploadUrl::rebase(null, $this->uploads()));
        self::assertSame('', LocalUploadUrl::rebase('', $this->uploads()));
    }

    // ── isCurrentAndPresent ──────────────────────────────────────────────

    public function testOnlyAnExistingFileOnThisSitesUploadsCounts(): void
    {
        $u = $this->uploads();
        self::assertTrue(LocalUploadUrl::isCurrentAndPresent(self::CMS . '/2026/06/validator-128-logo.jpg', $u));
        self::assertFalse(LocalUploadUrl::isCurrentAndPresent('https://stage.bluecollarcrypto.io/wp-content/uploads/2026/06/validator-128-logo.jpg', $u), 'wrong host');
        // Same-length host, so the path after the base lines up with a real file:
        // only the host comparison can reject it.
        self::assertFalse(LocalUploadUrl::isCurrentAndPresent('https://xyz.bluecollarcrypto.io/wp-content/uploads/2026/06/validator-128-logo.jpg', $u), 'wrong host, real file');
        self::assertFalse(LocalUploadUrl::isCurrentAndPresent(self::CMS . '/2026/06/validator-129-logo.jpg', $u), 'file missing');
        self::assertFalse(LocalUploadUrl::isCurrentAndPresent(self::CMS . '/2026/../2026/06/validator-128-logo.jpg', $u), 'traversal');
        self::assertFalse(LocalUploadUrl::isCurrentAndPresent(null, $u));
    }

    // ── the self-healing refresh ─────────────────────────────────────────

    public function testTheRefreshReDownloadsAWrongHostOrMissingLogo(): void
    {
        $u   = $this->uploads();
        $src = 'https://s3.keybase/abc.jpg';

        self::assertFalse(
            ValidatorLogoService::needsResideload(self::CMS . '/2026/06/validator-128-logo.jpg', $src, $src, $u),
            'a working local copy with an unchanged source is kept'
        );
        self::assertTrue(
            ValidatorLogoService::needsResideload('https://stage.bluecollarcrypto.io/wp-content/uploads/2026/06/validator-128-logo.jpg', $src, $src, $u),
            'the incident case: same source, logo stored on another host'
        );
        self::assertTrue(
            ValidatorLogoService::needsResideload(self::CMS . '/2026/06/validator-129-logo.jpg', $src, $src, $u),
            'same source, file gone'
        );
        self::assertTrue(ValidatorLogoService::needsResideload(self::CMS . '/2026/06/validator-128-logo.jpg', $src, 'https://s3.keybase/new.jpg', $u), 'source changed');
        self::assertTrue(ValidatorLogoService::needsResideload(null, null, $src, $u), 'no logo yet');
    }

    /**
     * run() resolves Keybase and sideloads through private statics with no
     * seam; pin that its skip decision IS needsResideload().
     */
    public function testTheRefreshSkipsOnlyThroughNeedsResideload(): void
    {
        $m     = new \ReflectionMethod(ValidatorLogoService::class, 'run');
        $lines = file((string) $m->getFileName());
        self::assertIsArray($lines);
        $body  = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

        self::assertMatchesRegularExpression('/if \(!self::needsResideload\(\$storedUrl, \$sourceRef, \$remoteUrl\)\) \{\s*ValidatorRepository::markLogoChecked\(\$validatorId\);\s*return;/', $body);
        self::assertStringNotContainsString('$haveLocal', $body, 'the old any-logo-at-all short-circuit is gone');
    }

    // ── the consumers ────────────────────────────────────────────────────

    public function testTheHallPreviewServesARebasedLogo(): void
    {
        $uploads = $this->uploads();
        if (!function_exists('wp_upload_dir')) {
            $GLOBALS['__bcc_upload_dir'] = $uploads;
            eval('function wp_upload_dir($time = null, $create = true) { return $GLOBALS["__bcc_upload_dir"]; }');
        }

        $out = HallsService::previewFromRows([
            (object) ['id' => 128, 'moniker' => 'iqlusion', 'logo_url' => 'https://stage.bluecollarcrypto.io/wp-content/uploads/2026/06/validator-128-logo.jpg'],
        ], []);

        self::assertSame(self::CMS . '/2026/06/validator-128-logo.jpg', $out['validators'][0]['logo_url']);
    }

    /**
     * resolvePageAvatarUrl() reaches PeepSo, post thumbnails and the
     * operator-profile store before the validator branch; pin its wiring.
     */
    public function testTheCrestValidatorBranchRebases(): void
    {
        $m     = new \ReflectionMethod(CardViewService::class, 'resolvePageAvatarUrl');
        $lines = file((string) $m->getFileName());
        self::assertIsArray($lines);
        $body  = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

        self::assertMatchesRegularExpression('/return \(string\) \\\\BCC\\\\Trust\\\\Onchain\\\\Support\\\\LocalUploadUrl::rebase\(\$logo\);/', $body);
    }
}
