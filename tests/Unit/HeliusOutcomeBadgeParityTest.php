<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

/**
 * The Helius delivery-log outcome set is closed, and the Webhooks admin page
 * mirrors it for badge colouring. An outcome added to HeliusDeliveryLog but
 * not to WebhooksPage::outcomeBadge() still renders, as a grey "muted" badge
 * that looks routine, which is how a failure outcome would slip past an
 * operator. This pins that every OUTCOME_* constant has its own badge arm.
 *
 * Reads both files as source (comments removed): the Helius unit tests load
 * a stub HeliusDeliveryLog at the same FQN, so reflection could see the
 * stub's constants instead of the real ones.
 */
final class HeliusOutcomeBadgeParityTest extends TestCase
{
    public function testEveryDeliveryOutcomeHasItsOwnAdminBadge(): void
    {
        $log = GateOrder::code('app/Domain/Onchain/Services/HeliusDeliveryLog.php');
        preg_match_all('/const\s+(OUTCOME_[A-Z_]+)\s*=/', $log, $m);
        $outcomes = $m[1];
        self::assertContains('OUTCOME_DEDUPE_FAILED', $outcomes, 'the constant scan found the outcome set');
        self::assertGreaterThanOrEqual(5, count($outcomes));

        $badge = GateOrder::body(
            GateOrder::code('app/Domain/Onchain/Admin/WebhooksPage.php'),
            'outcomeBadge'
        );
        foreach ($outcomes as $const) {
            self::assertMatchesRegularExpression(
                '/HeliusDeliveryLog::' . $const . '\s*=>/',
                $badge,
                "WebhooksPage::outcomeBadge() has no arm for HeliusDeliveryLog::{$const}; it would render as a muted badge"
            );
        }
    }

    public function testADedupeFailureIsShownAsAFailure(): void
    {
        $badge = GateOrder::body(
            GateOrder::code('app/Domain/Onchain/Admin/WebhooksPage.php'),
            'outcomeBadge'
        );
        self::assertMatchesRegularExpression(
            "/HeliusDeliveryLog::OUTCOME_DEDUPE_FAILED\s*=>\s*AdminUi::badge\('dedupe_failed',\s*AdminUi::BAD\)/",
            $badge
        );
    }
}
