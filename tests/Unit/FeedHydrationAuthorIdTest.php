<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression: feed hydration must read the author user-id from
 * `author.id` (what ActivityFeedService emits), NOT `author.user_id`.
 *
 * The `user_id` key never exists on the wire, so reading it silently
 * yielded 0 — killing the is_operator badge and forcing can_report to
 * `false` (dead Report affordance / moderation gap). This pins the
 * `id` contract on the pure, no-DB `hydrateViewerPermissions` seam so
 * the key can't drift back.
 */
final class FeedHydrationAuthorIdTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $items, int $viewerId): array
    {
        $m = new ReflectionMethod(FeedHydrationPipeline::class, 'hydrateViewerPermissions');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke(null, $items, $viewerId);
        return $out;
    }

    private function canReport(array $item): bool
    {
        return (bool) ($item['permissions']['can_report']['allowed'] ?? false);
    }

    public function testAuthorIdDrivesCanReportForOtherAuthedViewer(): void
    {
        $out = $this->hydrate([['author' => ['id' => 5], 'permissions' => []]], 9);
        self::assertTrue($this->canReport($out[0]), 'authed viewer can report another author (keyed on author.id)');
    }

    public function testSelfAuthoredIsNotReportable(): void
    {
        $out = $this->hydrate([['author' => ['id' => 9], 'permissions' => []]], 9);
        self::assertFalse($this->canReport($out[0]), 'a viewer cannot report their own post');
    }

    /**
     * The bug shape: only `author.user_id` present, no `author.id`.
     * Reading `id` (correct) yields 0 → not reportable. A regression to
     * `user_id` would flip this to true and fail the test.
     */
    public function testLegacyUserIdKeyAloneDoesNotDriveCanReport(): void
    {
        $out = $this->hydrate([['author' => ['user_id' => 5], 'permissions' => []]], 9);
        self::assertFalse(
            $this->canReport($out[0]),
            'author.user_id must NOT be read — only author.id drives can_report'
        );
    }

    public function testAnonymousViewerCannotReportAndGetsSignInHint(): void
    {
        $out = $this->hydrate([['author' => ['id' => 5], 'permissions' => []]], 0);
        self::assertFalse($this->canReport($out[0]));
        self::assertSame('Sign in to report.', $out[0]['permissions']['can_report']['unlock_hint']);
    }
}
