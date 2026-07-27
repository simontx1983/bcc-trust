<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\ValueObjects\GroupType;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Delegator-community classification — GroupContextResolver.
 *
 * The resolver is the single seam every consumer asks "what kind of group
 * is this?", so the `delegators` arm is what routes a validator community
 * to its own join endpoint (MyGroupsEndpoint rejects the kind), renders
 * the DELEGATOR COMMUNITY kicker, and marks it on-chain verified. A
 * regression here silently downgrades a gated community to GroupType::User
 * — which is exactly the Halls split-brain bug class, where the plain
 * join door would accept a group whose gate lives elsewhere.
 *
 * ## Isolation
 * Runs in its own subprocess; setUp() pulls in tests/Stubs/halls-gate-stubs.php
 * (shared with HallsJoinGateTest) which defines the WordPress functions the
 * REAL GroupContextResolver + PeepSoPrivacy call, backed by a per-test
 * fixture. The main process is untouched.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorGroupResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/halls-gate-stubs.php';
        $GLOBALS['__bcc_halls_gate_fixture'] = [];
    }

    /**
     * @param array<string, string> $extraMeta
     */
    private function registerGroup(int $id, string $kind, int $privacy = 1, array $extraMeta = []): void
    {
        $GLOBALS['__bcc_halls_gate_fixture'][$id] = [
            'post_type' => 'peepso-group',
            'meta'      => array_merge([
                '_bcc_group_kind'      => $kind,
                'peepso_group_privacy' => (string) $privacy,
            ], $extraMeta),
        ];
    }

    public function testDelegatorsKindResolvesToValidatorType(): void
    {
        $this->registerGroup(3100, 'delegators');

        $ctx = (new GroupContextResolver())->forGroup(3100);

        self::assertNotNull($ctx);
        self::assertSame(GroupType::Validator, $ctx->type);
    }

    public function testValidatorGroupCarriesOnChainVerification(): void
    {
        $this->registerGroup(3101, 'delegators');

        $ctx = (new GroupContextResolver())->forGroup(3101);

        self::assertNotNull($ctx);
        self::assertTrue($ctx->isVerified());
        // Reuses the SAME badge as holder groups — the delegation gate is
        // on-chain proof, so the public vocabulary must not fork.
        self::assertSame('on_chain', $ctx->verification?->kind);
    }

    public function testValidatorGroupSourcePointsAtValidatorId(): void
    {
        $this->registerGroup(3102, 'delegators', 1, ['_bcc_gate_validator_id' => '4242']);

        $ctx = (new GroupContextResolver())->forGroup(3102);

        self::assertNotNull($ctx);
        self::assertSame('validator', $ctx->sourceKind);
        self::assertSame(4242, $ctx->sourceId);
    }

    public function testValidatorGroupWithoutValidatorMetaHasNullSourceId(): void
    {
        // Gate meta half-written (provisioning interrupted): the kind still
        // classifies, but the FK is absent rather than a bogus 0.
        $this->registerGroup(3103, 'delegators');

        $ctx = (new GroupContextResolver())->forGroup(3103);

        self::assertNotNull($ctx);
        self::assertSame('validator', $ctx->sourceKind);
        self::assertNull($ctx->sourceId);
    }

    public function testValidatorGroupIsNotClassifiedAsHolderGroup(): void
    {
        $this->registerGroup(3104, 'delegators');

        $ctx = (new GroupContextResolver())->forGroup(3104);

        self::assertNotNull($ctx);
        // isHolderGroup() gates NFT-specific enrichment paths; a delegator
        // community must not be dragged through the collection lookups.
        self::assertFalse($ctx->isHolderGroup());
    }

    public function testHolderAndPlainKindsAreUnaffected(): void
    {
        $this->registerGroup(3105, 'holders');
        $this->registerGroup(3106, '');

        $resolver = new GroupContextResolver();

        self::assertSame(GroupType::Nft, $resolver->forGroup(3105)?->type);
        self::assertSame(GroupType::User, $resolver->forGroup(3106)?->type);
        // A plain group must stay unverified — the new arm must not leak
        // the on-chain badge to non-gated kinds.
        self::assertFalse((bool) $resolver->forGroup(3106)?->isVerified());
    }
}
