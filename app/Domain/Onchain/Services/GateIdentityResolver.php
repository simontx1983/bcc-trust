<?php
/**
 * Resolves the authoritative on-chain identity for a holder gate.
 *
 * ── THE COLLECTION ROW IS THE AUTHORITY ─────────────────────────────────
 * `_bcc_gate_collection_id` is the identity link. `_bcc_gate_contract_address`
 * is kept for backward compatibility and display and is NEVER consulted
 * here — on the eight production Solana gates it holds a Magic Eden symbol,
 * and trusting it is the defect PR 5b exists to remove.
 *
 * ── WHY THIS RUNS BEFORE HoldingsService, NOT INSIDE IT ─────────────────
 * The requirement is "an unresolved identity makes ZERO provider calls."
 * That could be written as a check inside the holdings loop, but then the
 * guarantee would rest on every future caller remembering to honour it.
 * Resolving FIRST — and refusing to produce a value a provider could be
 * called with — makes the guarantee structural: there is no identifier to
 * pass, so there is no call to make. A test asserts the fetcher is never
 * constructed, and that test can only pass because of this ordering.
 *
 * ── FAIL CLOSED, AND SAY SO SPECIFICALLY ────────────────────────────────
 * Every failure returns UNRESOLVED, never a zero and never a guess. The
 * caller turns that into UNKNOWN with the reason
 * `collection_identity_unresolved` — deliberately distinct from provider
 * downtime, because the two need opposite operator responses: one clears
 * itself, the other needs a repair run.
 *
 * The public reason is single and coarse (see {@see GateIdentity}); the
 * specific cause is logged for an operator, never returned to a caller.
 *
 * @package BCC\Trust\Onchain\Services
 * @since PR 5b — Solana holder-gate identity repair
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;
use BCC\Trust\Onchain\ValueObjects\GateIdentity;

if (!defined('ABSPATH')) {
    exit;
}

final class GateIdentityResolver
{
    /**
     * Resolve the identity a gate config actually gates on.
     *
     * The order below is the order the checks must happen in — each one
     * is a precondition for the next being meaningful.
     */
    public static function resolve(GatedGroupConfig $config): GateIdentity
    {
        // 1. Exactly one numeric collection link. `getGateConfig()` has
        //    already refused a duplicated meta row, so reaching here with
        //    a null means the key is absent or non-numeric.
        $collectionId = $config->collectionId;
        if ($collectionId === null || $collectionId <= 0) {
            return self::refuse($config->groupId, 'no_collection_link');
        }

        // 2. Load that collection row, joined to its chain. One read gives
        //    the row, its chain id, and the chain's slug + family — all
        //    from canonical repository data. The join is INNER, so a row
        //    whose chain has vanished is absent rather than half-resolved.
        $rows = CollectionRepository::findManyByIds([$collectionId]);
        $row  = $rows[$collectionId] ?? null;
        if ($row === null) {
            return self::refuse($config->groupId, 'collection_or_chain_row_missing');
        }

        // 3. Exactly one valid chain id on the gate.
        $gateChainId = $config->chainId;
        if ($gateChainId <= 0) {
            return self::refuse($config->groupId, 'no_chain_id');
        }

        // 4. The collection's chain must BE the gate's chain. A mismatch is
        //    contradictory metadata, not something to reconcile by
        //    preferring one side — either could be the corrupted half, and
        //    guessing would point a real provider query at the wrong chain.
        $collectionChainId = (int) ($row->chain_id ?? 0);
        if ($collectionChainId !== $gateChainId) {
            return self::refuse($config->groupId, 'chain_mismatch');
        }

        // 5. The chain, resolved by the join above — never by a hardcoded
        //    numeric id. `solana` is 20 in production and has been 13
        //    locally; neither number appears anywhere in this code path.
        $chainSlug   = (string) ($row->chain_slug ?? '');
        $chainFamily = (string) ($row->chain_type ?? '');
        if ($chainSlug === '' || $chainFamily === '') {
            return self::refuse($config->groupId, 'chain_incomplete');
        }

        // 6. Read the row's canonical identifier. NULL is the documented
        //    "legacy identity unresolved" state — precisely the eight rows
        //    this PR's manifest repairs, plus the 91 still awaiting one.
        $canonical = $row->canonical_identifier ?? null;
        if (!is_string($canonical) || $canonical === '') {
            return self::refuse($config->groupId, 'canonical_identifier_null');
        }

        // 7. Validate it FOR THIS CHAIN. A stored value is not trusted just
        //    because it is non-null: the column is written by code, and code
        //    changes. Re-validating here means a bad write can never become
        //    a bad provider query.
        $identity = NftCollectionIdentifier::canonicalize($chainFamily, $canonical);
        if (!$identity->isAccepted()) {
            return self::refuse(
                $config->groupId,
                'canonical_identifier_invalid:' . $identity->reason()
            );
        }

        // 8. Only the validated identity travels onward.
        return GateIdentity::resolved(
            $identity->canonical(),
            $chainSlug,
            $chainFamily,
            $collectionId
        );
    }

    /**
     * Resolve straight from a group id, for callers that do not already
     * hold a config. Returns UNRESOLVED for a non-holder group too — such
     * a group has no gate identity, which is the same answer.
     */
    public static function resolveForGroup(int $groupId): GateIdentity
    {
        $config = GatedGroupRepository::getGateConfig($groupId);

        if ($config === null) {
            return GateIdentity::unresolved();
        }

        return self::resolve($config);
    }

    /**
     * Log the specific cause for an operator and return the coarse public
     * refusal.
     *
     * The detail is deliberately log-only: which particular way a gate is
     * broken is operator information, and narrating it on the wire would
     * describe the site's internal state to anyone who can hit a join
     * endpoint. `$detail` is a fixed vocabulary of code-chosen tokens —
     * never user input, never a row value.
     */
    private static function refuse(int $groupId, string $detail): GateIdentity
    {
        \BCC\Core\Log\Logger::warning(
            '[bcc-trust] holder gate identity unresolved; no provider call was made',
            [
                'group_id' => $groupId,
                'detail'   => $detail,
            ]
        );

        return GateIdentity::unresolved();
    }
}
