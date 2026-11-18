<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\MyProfileEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-294 (account Lows): PATCH /me/profile's bio limit said "500 characters"
 * but counted BYTES, so a bio in a non-Latin script was refused at a
 * fraction of 500. Against the real MyProfileEndpoint::patch().
 *
 * The success path goes on to build the profile view-model, which needs far
 * more of WordPress than this harness has; the test only needs to see the
 * write reach wp_update_user, so it stops there.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BioLengthInCharactersTest extends TestCase
{
    private const USER = 42;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('wp_update_user')) {
            eval(<<<'PHP'
                function wp_update_user(array $data)
                {
                    $GLOBALS['__bcc_ph']['updates'][] = $data;
                    throw new \RuntimeException('stop after the write: the view-model is out of scope');
                }
                function sanitize_textarea_field(string $s): string { return trim($s); }
                PHP);
        }
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = ['users' => [], 'meta' => [], 'updates' => [], 'current_user' => self::USER];
    }

    private static function patchBio(string $bio): ?\WP_REST_Response
    {
        try {
            return (new MyProfileEndpoint())->patch(new \WP_REST_Request(['bio' => $bio]));
        } catch (\RuntimeException $e) {
            return null; // reached wp_update_user
        }
    }

    public function testFiveHundredNonLatinCharactersAreAccepted(): void
    {
        $bio = str_repeat('é', 250) . str_repeat('日', 250);   // 500 chars, 1250 bytes

        self::assertNull(self::patchBio($bio), 'must not be refused as too long');
        self::assertSame($bio, $GLOBALS['__bcc_ph']['updates'][0]['description'] ?? null);
    }

    public function testFiveHundredAndOneCharactersAreRefused(): void
    {
        $resp = self::patchBio(str_repeat('日', 501));

        self::assertNotNull($resp);
        self::assertSame(422, $resp->get_status());
        self::assertStringContainsString('500 characters', (string) ($resp->get_data()['error']['message'] ?? ''));
        self::assertSame([], $GLOBALS['__bcc_ph']['updates']);
    }
}
