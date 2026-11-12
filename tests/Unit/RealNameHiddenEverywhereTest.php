<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\PublicIdentity;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-28: "Hide my real name" (§K2 real_name_hidden) used to apply only on
 * the profile. PublicIdentity::visibleName() is now the one rule: when the
 * member hid their name, everyone but the member sees '@handle'. Every
 * surface that renders a member's name routes through it (pinned below
 * at source level), and /members search no longer matches a hidden
 * member by name.
 */
#[CoversMethod(PublicIdentity::class, 'visibleName')]
#[CoversMethod(PublicIdentity::class, 'forUser')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RealNameHiddenEverywhereTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('get_user_meta')) {
            eval('function get_user_meta(int $id, string $key = "", bool $single = false) { return $GLOBALS["__rn"][$id][$key] ?? ""; }');
        }
        $GLOBALS['__rn'] = [
            7 => ['bcc_handle' => 'alice', 'bcc_privacy_real_name_hidden' => '1'],
            8 => ['bcc_handle' => 'bob',   'bcc_privacy_real_name_hidden' => '0'],
            9 => ['bcc_handle' => 'carol'],
        ];
    }

    public function testHiddenNameIsMaskedForOthers(): void
    {
        self::assertSame('@alice', PublicIdentity::visibleName(7, 'Alice Smith', 'u_alice', 'alice', 99));
    }

    public function testHiddenNameIsMaskedWhenTheViewerIsUnknown(): void
    {
        self::assertSame('@alice', PublicIdentity::visibleName(7, 'Alice Smith', 'u_alice', 'alice'));
    }

    public function testTheMemberStillSeesTheirOwnName(): void
    {
        self::assertSame('Alice Smith', PublicIdentity::visibleName(7, 'Alice Smith', 'u_alice', 'alice', 7));
    }

    public function testNotHiddenIsUnchanged(): void
    {
        self::assertSame('Bob Jones', PublicIdentity::visibleName(8, 'Bob Jones', 'u_bob', 'bob', 99));
        self::assertSame('Carol', PublicIdentity::visibleName(9, 'Carol', 'u_carol', 'carol', 99));
    }

    public function testMaskingNeverFallsBackToTheLogin(): void
    {
        self::assertSame('@member-7', PublicIdentity::visibleName(7, 'Alice Smith', 'u_alice', '', 99));
    }

    public function testForUserAppliesTheRule(): void
    {
        $u = (object) ['ID' => 7, 'display_name' => 'Alice Smith', 'user_login' => 'u_alice'];
        self::assertSame(['handle' => 'alice', 'display_name' => '@alice'], PublicIdentity::forUser($u));
        self::assertSame(['handle' => 'alice', 'display_name' => 'Alice Smith'], PublicIdentity::forUser($u, 7));
    }

    /** @return array<string, array{string, string}> */
    public static function surfaces(): array
    {
        return [
            'member card'        => ['app/Domain/Core/Services/CardViewService.php', 'visibleName('],
            'operator mini'      => ['app/Domain/Core/Services/CardViewService.php', 'forUser($user)'],
            'profile + summary'  => ['app/Domain/Core/Services/UserViewService.php', 'visibleName('],
            'feed reactors'      => ['app/Domain/Core/Services/Feed/FeedHydrationPipeline.php', 'visibleName('],
            'blog author'        => ['app/Domain/Core/Services/BlogService.php', 'visibleName('],
            'comments'           => ['app/Domain/Core/Services/CommentService.php', 'visibleName('],
            'messages'           => ['app/Domain/Core/Services/MessagesService.php', 'visibleName('],
            'blocks list'        => ['app/Domain/Core/Services/MyBlocksService.php', 'visibleName('],
            'notifications'      => ['app/Domain/Core/Services/NotificationViewService.php', 'forUser($user)'],
            'mentions overlay'   => ['app/Domain/Core/Services/Mentions/MentionOverlayService.php', 'forUser($user)'],
            'mention picker'     => ['app/Domain/Core/Services/Mentions/MentionSearchService.php', 'forUser($user)'],
            'attestations'       => ['app/Domain/Core/Services/AttestationService.php', 'forUser($user)'],
            'card search member' => ['app/Domain/Core/REST/CardsSearchEndpoint.php', 'visibleName('],
        ];
    }

    #[DataProvider('surfaces')]
    public function testEverySurfaceRoutesThroughTheRule(string $rel, string $needle): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        self::assertStringContainsString('PublicIdentity::' . $needle, $src);
    }

    public function testNoPublicSurfaceStillCallsTheUnmaskedDisplayName(): void
    {
        // displayName() ignores the hide flag; only the admin read model
        // (UserSyncService mirror) and moderation may use it directly.
        $allowed = [
            'app/Domain/Core/Support/PublicIdentity.php',
            'app/Domain/Core/Services/UserSyncService.php',
            'app/Domain/Core/Services/ModerationQueueService.php',
        ];
        $root = dirname(__DIR__, 2);
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app'));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($rel, $allowed, true)) {
                continue;
            }
            self::assertStringNotContainsString(
                'PublicIdentity::displayName(',
                (string) file_get_contents($file->getPathname()),
                "$rel renders a name without the hide-real-name rule"
            );
        }
    }

    public function testMembersSearchExcludesHiddenNamesFromTheNameMatch(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Support/MemberSearchCandidates.php');
        self::assertMatchesRegularExpression(
            "/'search_columns' => \['display_name'\],.*?'meta_query'\s*=>\s*\[.*?bcc_privacy_real_name_hidden.*?NOT EXISTS.*?bcc_privacy_real_name_hidden.*?'!='/s",
            $src
        );
    }
}
