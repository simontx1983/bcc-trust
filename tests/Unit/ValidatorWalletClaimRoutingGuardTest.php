<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

/**
 * A VALIDATOR operator claim made with a wallet goes through
 * ValidatorRelationshipService::claimByWallet() (the relationship lock order,
 * the log rows, the supersede of a staff-approved owner, and the page mirror
 * in the same run), never straight to createExclusiveClaim(). And the page
 * route hands it the page, and never writes a validator's page mirror itself.
 *
 * Source pin (comments removed): the behaviour of claimByWallet() is proved
 * on MySQL in ValidatorRelationshipServiceIntegrationTest; this pins that the
 * claim flow actually reaches it.
 */
final class ValidatorWalletClaimRoutingGuardTest extends TestCase
{
    public function testAValidatorOperatorClaimGoesThroughTheRelationshipService(): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Onchain/Services/ClaimService.php'), 'claim');

        self::assertMatchesRegularExpression(
            "/\\\$entityType === 'validator' && \\\$match\['role'\] === 'operator'\)\s*\?\s*ValidatorRelationshipService::claimByWallet\(/",
            $body,
            'a validator operator claim must be routed to claimByWallet()'
        );
        self::assertMatchesRegularExpression('/claimByWallet\([^;]*\$pageId\)/', $body, 'with the page it is mirrored onto');
    }

    public function testThePageRoutePassesThePageAndLeavesValidatorMirrorsToTheService(): void
    {
        $code = GateOrder::code('app/Domain/Core/REST/PagesEndpoint.php');

        self::assertStringContainsString('ClaimService::claim($userId, $entityType, $entityId, $pageId)', $code);
        self::assertMatchesRegularExpression(
            "/\\\$underlying = \\\$entityType === 'validator'\s*\?\s*null/",
            $code,
            'a validator\'s page mirror is never written by the route'
        );
    }
}
