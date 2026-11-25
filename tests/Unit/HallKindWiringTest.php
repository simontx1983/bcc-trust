<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

/**
 * Topic Halls, the read side: GET /halls `kind` reaches bcc-core's
 * Hall-kind filter on both the page and its total, and both Hall payloads
 * carry `hall_kind` / `topic`. Source-level, comments removed;
 * HallKindFilterIntegrationTest proves the reads and the fields on a real
 * server.
 */
final class HallKindWiringTest extends TestCase
{
    public function testTheEndpointHandsTheKindToTheService(): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Core/REST/HallsEndpoint.php'), 'handle');

        self::assertMatchesRegularExpression(
            '/->getHalls\(\s*\$viewerId,\s*\$page,\s*\$pageSize,\s*\$chain,\s*\$query,\s*\$kind\s*\)/',
            $body
        );
        GateOrder::assertOrder($body, [
            'self::hallKind(',
            "'kind must be one of: '",
            '->getHalls(',
        ], 'an unknown kind is refused before the read');
    }

    public function testThePageAndItsTotalTakeTheSameKind(): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Core/Services/HallsService.php'), 'getHalls');

        self::assertStringContainsString('PeepSoGroupRepository::listHalls($chainId, $offset, $pageSize, $query, $hallKind)', $body);
        self::assertStringContainsString('PeepSoGroupRepository::countHalls($chainId, $query, $hallKind)', $body);
    }

    /** @return array<string, array{string}> */
    public static function hallPayloads(): array
    {
        return ['the list' => ['getHalls'], 'the detail' => ['getHall']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hallPayloads')]
    public function testEachHallPayloadCarriesKindAndTopic(string $method): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Core/Services/HallsService.php'), $method);

        self::assertStringContainsString("'hall_kind'         => \$kindFacts[\$groupId]['hall_kind'] ?? null,", $body);
        self::assertStringContainsString("'topic'             => \$kindFacts[\$groupId]['topic'] ?? null,", $body);
        self::assertStringContainsString('self::loadKindFacts(', $body);
    }

    public function testTheColdStartReadsItsHallsThroughTheTopUp(): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Core/Services/FeedColdStartService.php'), 'composeHalls');

        self::assertStringContainsString('self::hallRows($chainId, self::HALLS_LIMIT)', $body);
        self::assertStringNotContainsString('listHalls(', $body, 'one path to the Hall rows');
    }
}
