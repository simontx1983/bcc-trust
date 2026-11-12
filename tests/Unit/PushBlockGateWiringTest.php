<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F-74 wiring: every push with a person behind it hands that person to
 * PushDispatcher::enqueue(), which is what lets the block gate run. A call
 * site that forgets the actor silently skips the gate, so the mapping is
 * pinned here, event by event, against NotificationDispatcher's source.
 */
final class PushBlockGateWiringTest extends TestCase
{
    /** event type (as written at the call site) => the actor variable it must pass */
    private const ACTORS = [
        "'review'"                 => 'authorId',
        "'mention'"                => 'authorId',
        "'hall_post'"              => 'authorId',
        "'comment_received'"       => 'commenterId',
        '$pushEvent'               => 'attestorId',
        "'attestation_revoked'"    => 'attestorId',
        "'attestation_reaffirmed'" => 'attestorId',
    ];

    /** System events: no person, so no actor and no block lookup. */
    private const SYSTEM = ["'divergence_state_warning'", "'holder_community_live'"];

    public function testEveryPersonDrivenPushPassesItsActor(): void
    {
        $calls = $this->enqueueCalls();
        self::assertCount(count(self::ACTORS) + count(self::SYSTEM), $calls, 'a new enqueue site must be classified here');

        foreach (self::ACTORS as $event => $actor) {
            self::assertArrayHasKey($event, $calls, $event . ' enqueue not found');
            self::assertMatchesRegularExpression(
                '/,\s*\$' . $actor . '\)\s*;$/',
                $calls[$event],
                $event . ' must pass $' . $actor . ' so the block gate runs'
            );
        }
        foreach (self::SYSTEM as $event) {
            self::assertArrayHasKey($event, $calls);
            self::assertMatchesRegularExpression('/\]\)\s*;$/', $calls[$event], $event . ' is a system event: no actor');
        }
    }

    /** @return array<string, string> event expression => the whole enqueue(...) statement */
    private function enqueueCalls(): array
    {
        $source = file_get_contents(__DIR__ . '/../../app/Domain/Core/Services/NotificationDispatcher.php');
        self::assertIsString($source);

        preg_match_all('/\$this->pushDispatcher->enqueue\(\s*\$\w+,\s*([^,]+),.*?\)\s*;/s', $source, $m, PREG_SET_ORDER);
        $calls = [];
        foreach ($m as $match) {
            $calls[trim($match[1])] = $match[0];
        }
        return $calls;
    }
}
