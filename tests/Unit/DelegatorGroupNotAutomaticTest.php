<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A delegator community exists because its page's operator asked for one.
 *
 * ── WHAT WAS RETIRED ────────────────────────────────────────────────────
 * Three things created them unasked: a `bcc_page_claimed` subscriber, a backfill
 * folded into the daily `bcc_gated_group_provision` handler, and an admin
 * "Provision now" button, all taking the minimum from a filter.
 *
 * DelegatorGroupIntegrationTest fires the real event and the real cron handler
 * from bcc-trust.php. This covers what firing can't reach without loading all of
 * WordPress: the other subscribers registered from Plugin.php, and any new caller
 * added anywhere in the tree.
 *
 * ⚠ COMMENTS ARE STRIPPED BEFORE ASSERTING ABSENCE, because the notes left behind
 * deliberately name what was removed.
 */
#[Group('unit')]
final class DelegatorGroupNotAutomaticTest extends TestCase
{
    /** Every PHP file the plugin ships, with comments removed. */
    private static function shippedCode(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = [$root . '/bcc-trust.php'];
        foreach (['/app', '/includes'] as $dir) {
            $walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($walker as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $out = [];
        foreach ($files as $path) {
            $code = '';
            foreach (token_get_all((string) file_get_contents($path)) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($t) ? $t[1] : $t;
            }
            $out[str_replace('\\', '/', substr($path, strlen($root) + 1))] = $code;
        }

        return $out;
    }

    public function testTheOnlyCallersOfTheWritesAreTheOperatorRoute(): void
    {
        foreach (['->createForPage(', '->updateMinStake('] as $call) {
            $callers = [];
            foreach (self::shippedCode() as $path => $code) {
                if (str_contains($code, $call)) {
                    $callers[] = $path;
                }
            }
            self::assertSame(['app/Domain/Onchain/REST/PageDelegatorGroupEndpoint.php'], $callers, $call);
        }
    }

    public function testTheRetiredEntryPointsAreGone(): void
    {
        foreach (self::shippedCode() as $path => $code) {
            foreach ([
                'provisionForClaim',
                'bcc_validator_group_min_stake',
                'bcc_validator_groups_provision',
                'handle_provision_validator_groups',
                'listVerifiedValidatorOperatorClaims',
            ] as $retired) {
                self::assertStringNotContainsString($retired, $code, $path);
            }
        }
    }

    public function testNothingOnTheClaimEventReachesTheProvisioningService(): void
    {
        foreach (self::shippedCode() as $path => $code) {
            if (!str_contains($code, "add_action('bcc_page_claimed'")) {
                continue;
            }
            self::assertStringNotContainsString('validatorGroupProvisioningService', $code, $path);
        }
    }

    public function testTheDailyHookStillServesTheNftHolderSweep(): void
    {
        $code = self::shippedCode()['bcc-trust.php'];

        $start = strpos($code, "add_action('bcc_gated_group_provision'");
        self::assertNotFalse($start);
        $handler = substr($code, $start, (int) strpos($code, '});', $start) - $start);

        self::assertStringContainsString('->gatedGroupProvisioningService()', $handler);
        self::assertStringContainsString('->processRequested()', $handler);
        self::assertStringNotContainsString('validatorGroupProvisioningService', $handler);
    }

    /** The real sweep keeps its schedule: the hook is shared, not retired. */
    public function testTheDailyHookStaysScheduled(): void
    {
        $code = self::shippedCode()['bcc-trust.php'];

        self::assertSame(
            2,
            preg_match_all("/wp_schedule_event\\([^;]*'bcc_gated_group_provision'/", $code),
            'activation and the self-heal both schedule it'
        );
        self::assertArrayHasKey('includes/cron-hooks.php', self::shippedCode());
        self::assertStringContainsString("'bcc_gated_group_provision'", self::shippedCode()['includes/cron-hooks.php']);
    }

    public function testTheCardCarriesTheBlockFromTheSharedDecisionAndTheBundle(): void
    {
        $code = self::shippedCode()['app/Domain/Core/Services/CardViewService.php'];

        self::assertStringContainsString("\$card['delegator_group'] = DelegatorGroupView::forCard(", $code);
        self::assertStringContainsString("\$prefetched['delegator_groups'] ?? null", $code);
        self::assertSame(
            1,
            substr_count($code, 'ValidatorPageOperatorAuthority::decide('),
            'one decision per card, shared, or the list surface pays for it twice'
        );

        $bundle = self::shippedCode()['app/Domain/Core/Support/PageCardPrefetcher.php'];
        self::assertStringContainsString('DelegatorGroupView::groupsForPages($validatorRows)', $bundle);
    }

    /** Bell only, by requirement. */
    public function testTheRemovalBellSendsNoPushAndNoMail(): void
    {
        $code  = self::shippedCode()['app/Domain/Core/Services/NotificationDispatcher.php'];
        $start = strpos($code, 'function onDelegatorGroupMemberRemoved');
        self::assertNotFalse($start);
        $end    = strpos($code, 'private function dispatch(', $start);
        $method = substr($code, $start, (int) $end - $start);

        self::assertStringContainsString('NotificationType::DELEGATOR_GROUP_REMOVED', $method);
        self::assertStringNotContainsString('pushDispatcher', $method);
        self::assertStringNotContainsString('wp_mail', $method);

        self::assertNotContains('delegator_group_removed', \BCC\Trust\Core\Support\NotificationPrefs::PUSH_TYPES);
    }

    /** The one caller of the bell is the one line after a real removal. */
    public function testTheBellHasOneCallerInTheSweep(): void
    {
        $callers = [];
        foreach (self::shippedCode() as $path => $code) {
            if (str_contains($code, '->onDelegatorGroupMemberRemoved(')) {
                $callers[] = $path;
            }
        }
        self::assertSame(['app/Domain/Onchain/Services/ValidatorGroupRevokeService.php'], $callers);
    }

    public function testTheGroupDetailPassesTheCurrentMinimumToTheJoinHint(): void
    {
        $code = self::shippedCode()['app/Domain/Core/Services/GroupsService.php'];

        self::assertStringContainsString("\$validatorStats['min_stake_display']", $code);
    }
}
