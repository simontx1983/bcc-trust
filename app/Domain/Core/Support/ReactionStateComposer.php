<?php
/**
 * Reaction State Composer — builds the grammar-aware `reactions` block
 * (api-contract-v1.md §2.11) for one activity + viewer.
 *
 * Extracted from ReactionsEndpoint::buildStateResponse (§11 reuse) so
 * StokeEndpoint can return the identical kind_grammar/counts/
 * viewer_reaction trio alongside its own heat_stage/viewer_stoke_count
 * fields, without re-implementing the count/viewer-reaction lookup.
 *
 * @package BCC\Trust\Core\Support
 */

namespace BCC\Trust\Core\Support;

use BCC\Core\Feed\ReactionGrammarMap;
use BCC\Trust\Core\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

final class ReactionStateComposer
{
    /**
     * @return array{kind_grammar: string, counts: array<string, int>, viewer_reaction: ?string}
     */
    public static function compose(int $actId, int $viewerId, string $grammar): array
    {
        $repo = Plugin::instance()->peepSoReactionRepository();

        // Build kind => type_id for THIS grammar's kinds only. Includes
        // both BCC-seeded (trust three + Fire) and PeepSo-default
        // (Like/Love/Haha/Wow) lookups via ReactionGrammarRegistry.
        $kindToTypeId = [];
        foreach (ReactionGrammarMap::kindsFor($grammar) as $kind) {
            $typeId = ReactionGrammarRegistry::idFor($kind);
            if ($typeId !== null) {
                $kindToTypeId[$kind] = $typeId;
            }
        }
        $typeIdToKind = array_flip($kindToTypeId);

        // Zero-fill the contract-required kinds for this grammar; any
        // kind whose ID isn't resolvable just stays at 0 (degraded but
        // still contract-correct shape).
        $counts = ReactionGrammarMap::emptyCountsFor($grammar);

        $rawCounts = $repo->countsByActId($actId);
        foreach ($rawCounts as $typeId => $count) {
            if (isset($typeIdToKind[$typeId])) {
                $counts[$typeIdToKind[$typeId]] = $count;
            }
        }

        // Cross-grammar viewer guard: ignore reactions that don't
        // belong to this post's grammar (only possible via stale
        // pre-validation rows or a direct DB write).
        $viewerTypeId   = $repo->viewerReactionForActId($actId, $viewerId);
        $viewerReaction = $viewerTypeId !== null && isset($typeIdToKind[$viewerTypeId])
            ? $typeIdToKind[$viewerTypeId]
            : null;

        return [
            'kind_grammar'    => $grammar,
            'counts'          => $counts,
            'viewer_reaction' => $viewerReaction,
        ];
    }
}
