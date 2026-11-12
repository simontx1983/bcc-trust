<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Support;

/**
 * F-96: every engagement write handler, and the write call(s) each makes.
 *
 * One table for the two gates that guard these routes, so
 * GroupInteractionGateTest and NoReactionsGateTest pin their gate against
 * the SAME list of writes instead of two copies that drift apart.
 *
 * `noReactions` is false for the comment-stoke route only: it is gated on
 * the parent post's group (GroupInteractionGate::checkPost) and does not
 * consult NoReactionsGate, which the caller-count inventory in
 * NoReactionsGateTest also records.
 */
final class EngagementWriteRoutes
{
    /**
     * @var list<array{file: string, method: string, writes: list<string>, noReactions: bool}>
     */
    public const HANDLERS = [
        ['file' => 'ReactionsEndpoint.php',    'method' => 'setReaction',    'writes' => ['PeepSoReactionWriter::setReaction('],    'noReactions' => true],
        ['file' => 'ReactionsEndpoint.php',    'method' => 'removeReaction', 'writes' => ['PeepSoReactionWriter::removeReaction('], 'noReactions' => true],
        ['file' => 'StokeEndpoint.php',        'method' => 'addStoke',       'writes' => ['->addStoke('],                           'noReactions' => true],
        ['file' => 'StokeEndpoint.php',        'method' => 'removeStoke',    'writes' => ['->removeStoke('],                        'noReactions' => true],
        ['file' => 'HelpfulMarkEndpoint.php',  'method' => 'handle',         'writes' => ['->addMark(', '->removeMark('],           'noReactions' => true],
        ['file' => 'CommentStokeEndpoint.php', 'method' => 'handle',         'writes' => ['->addStoke(', '->removeStoke('],         'noReactions' => false],
    ];

    public const REST_DIR = 'app/Domain/Core/REST/';
}
