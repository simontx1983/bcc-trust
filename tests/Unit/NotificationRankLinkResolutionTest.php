<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\NotificationViewService;
use BCC\Trust\Core\Support\NotificationType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the post-redesign deep-link repair: every rank-standing /
 * findings notification type resolves to `/me/progression` (the §N11
 * standing file — the member's own progression surface) instead of
 * falling to the `default => '/'` arm and dead-ending the bell row on
 * the floor. RANK_UP previously routed to `/u/<actor>` — for a system
 * event whose actor is the recipient (or nobody), the standing file is
 * where grade / recovery / findings detail actually renders.
 *
 * Seam note: `resolveLink` is a pure private static match — reflection
 * invocation exercises the REAL production arm with no WP, no DB. The
 * rank arms never touch $externalId/$actId/$actorHandle, which the
 * empty-actor case below proves (system dispatches carry no actor).
 */
final class NotificationRankLinkResolutionTest extends TestCase
{
    /** @return list<string> */
    private static function rankTypes(): array
    {
        return [
            NotificationType::RANK_UP,
            NotificationType::RANK_DEMOTED,
            NotificationType::RANK_RECOVERY_STARTED,
            NotificationType::RANK_RECOVERY_REMINDER,
            NotificationType::RANK_DECAY_WARNING,
            NotificationType::RANK_FINDING_ISSUED,
            NotificationType::RANK_APPEAL_OUTCOME,
            NotificationType::RANK_FINDING_REVERSED,
        ];
    }

    private static function resolve(string $type, int $externalId, int $actId, string $actorHandle): string
    {
        $m = new ReflectionMethod(NotificationViewService::class, 'resolveLink');
        $m->setAccessible(true);
        /** @var string $link */
        $link = $m->invoke(null, $type, $externalId, $actId, $actorHandle);
        return $link;
    }

    public function testEveryRankTypeLandsOnTheProgressionSurface(): void
    {
        foreach (self::rankTypes() as $type) {
            self::assertSame(
                '/me/progression',
                self::resolve($type, 0, 0, ''),
                "{$type} must deep-link to the member's own standing file even with no actor"
            );
        }
    }

    public function testRankLinksIgnoreActorAndRowIds(): void
    {
        // System dispatches sometimes DO carry an actor (RANK_UP's actor
        // is the recipient) or stray row ids — none of them may bend the
        // destination.
        foreach (self::rankTypes() as $type) {
            self::assertSame('/me/progression', self::resolve($type, 42, 99, 'phillipcosmos'));
        }
    }

    public function testNonRankArmsAreUntouched(): void
    {
        // Adjacent-arm sanity: the repair added arms, it did not reshape
        // the match. REACTION keeps its focus link; WELCOME keeps the
        // floor; unknown types keep the '/' default.
        self::assertSame('/?focus=15823', self::resolve(NotificationType::REACTION, 0, 15823, ''));
        self::assertSame('/', self::resolve(NotificationType::WELCOME, 0, 0, ''));
        self::assertSame('/', self::resolve('bcc_never_a_type', 1, 2, 'x'));
    }
}
