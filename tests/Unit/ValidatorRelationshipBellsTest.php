<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Services\NotificationDispatcher;
use BCC\Trust\Core\Services\PageOwnerResolver;
use BCC\Trust\Core\Services\PushDispatcher;
use BCC\Trust\Core\Support\NotificationType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The bells for a committed validator relationship change: every
 * administrator gets the admin kind (from the acting staff member, linking
 * the page); the member gets their own kind as a self-notification; an
 * unrelated action rings nothing.
 */
#[CoversClass(NotificationDispatcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorRelationshipBellsTest extends TestCase
{
    private const STAFF  = 1;
    private const ADMIN2 = 2;
    private const MEMBER = 9;
    private const PAGE   = 501;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/mention-audience-stubs.php';
        if (!function_exists('get_users')) {
            eval('function get_users(array $args = []): array { return ($args["role"] ?? "") === "administrator" ? ["1", "2"] : []; }');
        }
        if (!function_exists('get_the_title')) {
            eval('function get_the_title(int $postId): string { return "Iqlusion"; }');
        }
        $GLOBALS['__bcc_mna'] = ['meta' => [], 'unmentionable' => [], 'bells' => [], 'pushes' => [], 'logs' => []];
        foreach ([self::STAFF, self::ADMIN2, self::MEMBER] as $id) {
            $GLOBALS['__bcc_mna']['meta'][$id] = ['bcc_handle' => 'member' . $id];
        }
    }

    private static function dispatcher(): NotificationDispatcher
    {
        $bare = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new NotificationDispatcher(
            $bare(PageOwnerResolver::class),
            new PushDispatcher($bare(PushSubscriptionRepository::class)),
            $bare(CommentRepository::class),
            static fn (int $actId, int $viewerId): bool => true
        );
    }

    /** @return list<array{int, int, string, int}> [to, from, type, external_id] */
    private static function bells(): array
    {
        return array_map(static fn (array $b): array => [$b['to'], $b['from'], $b['type'], $b['external_id']], $GLOBALS['__bcc_mna']['bells']);
    }

    public function testALinkRingsEveryAdminAndTheNewOwner(): void
    {
        self::dispatcher()->onValidatorRelationshipChanged('owner_linked', self::PAGE, self::MEMBER, self::STAFF, 77);

        self::assertSame([
            [self::STAFF, self::STAFF, NotificationType::VALIDATOR_OWNER_LINKED, self::PAGE],
            [self::ADMIN2, self::STAFF, NotificationType::VALIDATOR_OWNER_LINKED, self::PAGE],
            [self::MEMBER, self::MEMBER, NotificationType::VALIDATOR_OWNERSHIP_GRANTED, self::PAGE],
        ], self::bells());
    }

    public function testARevokeAndACancelTellTheMemberTheyAreNoLongerTheOwner(): void
    {
        self::dispatcher()->onValidatorRelationshipChanged('owner_revoked', self::PAGE, self::MEMBER, self::STAFF, 77);
        self::dispatcher()->onValidatorRelationshipChanged('owner_link_cancelled', self::PAGE, self::MEMBER, self::ADMIN2, 77);

        $types = array_column(self::bells(), 2);
        self::assertSame(2, count(array_keys($types, NotificationType::VALIDATOR_OWNER_REVOKED, true)));
        self::assertSame(2, count(array_keys($types, NotificationType::VALIDATOR_OWNER_LINK_CANCELLED, true)));
        self::assertSame(2, count(array_keys($types, NotificationType::VALIDATOR_OWNERSHIP_REMOVED, true)));
        foreach (self::bells() as [$to, $from, $type]) {
            if ($type === NotificationType::VALIDATOR_OWNERSHIP_REMOVED) {
                self::assertSame([self::MEMBER, self::MEMBER], [$to, $from], 'a self-notification: no staff member named as the sender');
            }
        }
    }

    /** A wallet proof replaced a staff-approved owner: no member is named as the actor. */
    public function testASupersedeTellsAdminsAndThePreviousOwnerWithoutNamingTheClaimer(): void
    {
        $newOwner = 4242;
        self::dispatcher()->onValidatorRelationshipChanged('owner_superseded', self::PAGE, self::MEMBER, $newOwner, 77);

        $bells = self::bells();
        self::assertSame(
            [[self::STAFF, NotificationType::VALIDATOR_OWNER_REVOKED], [self::ADMIN2, NotificationType::VALIDATOR_OWNER_REVOKED], [self::MEMBER, NotificationType::VALIDATOR_OWNERSHIP_REMOVED]],
            array_map(static fn (array $b): array => [$b[0], $b[2]], $bells)
        );
        foreach ($bells as [$to, $from]) {
            self::assertNotSame($newOwner, $from, 'the claimer is never the sender');
            self::assertNotSame($newOwner, $to, 'and gets no bell from this');
        }
    }

    /** The slugs the frontend (FE #400) types: a one-character drift would hide its toggles. */
    public function testTheBellSlugsAreExactlyTheOnesTheFrontendTypes(): void
    {
        self::assertSame(
            ['bcc_validator_owner_linked', 'bcc_validator_owner_revoked', 'bcc_validator_owner_link_cancelled', 'bcc_validator_ownership_granted', 'bcc_validator_ownership_removed'],
            [NotificationType::VALIDATOR_OWNER_LINKED, NotificationType::VALIDATOR_OWNER_REVOKED, NotificationType::VALIDATOR_OWNER_LINK_CANCELLED, NotificationType::VALIDATOR_OWNERSHIP_GRANTED, NotificationType::VALIDATOR_OWNERSHIP_REMOVED]
        );
    }

    public function testAnUnrelatedActionRingsNothing(): void
    {
        self::dispatcher()->onValidatorRelationshipChanged('owner_aligned', self::PAGE, self::MEMBER, null, 0);
        self::dispatcher()->onValidatorRelationshipChanged('owner_linked', 0, self::MEMBER, self::STAFF, 77);

        self::assertSame([], self::bells());
    }
}
