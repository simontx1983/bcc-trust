<?php
/**
 * The one place that decides which administrator tab a collection belongs in.
 *
 * ── WHY ONE CLASS HOLDS BOTH THE PHP RULE AND THE SQL ───────────────────
 * The tabs need two things that must agree exactly: a set-based SQL
 * predicate (so a page of results and a total count are correct without an
 * N+1), and a PHP predicate (so a single row can be labelled, and so the
 * rules can be tested exhaustively without a database). Two copies of a
 * rule in two languages is a drift generator — the classic way a tab starts
 * showing a row its own count denies. They live here together, derived from
 * the same four named inputs, and an integration test cross-checks the SQL
 * classification against the PHP classification over a fixture set so a
 * divergence fails CI rather than misleading an operator.
 *
 * ── THE FOUR INPUTS ─────────────────────────────────────────────────────
 *   V  is_verified = 1
 *   K  canonical_identifier IS NOT NULL          (identity is resolved)
 *   C  a PUBLISHED peepso-group holders post points at this collection
 *   H  an operator DENY rule covers this contract
 *
 * Everything else — provisioning state, failure code, source, chain — is a
 * LABEL, never a tab predicate. Keeping the tab a function of (V,K,C,H)
 * alone is what makes exhaustiveness provable: sixteen combinations, each
 * landing in exactly one tab, asserted directly by a unit test.
 *
 * ── WHY TAB 3 IS `hidden_by_operator` AND NOT "flagged/scam" ────────────
 * The only persisted moderation state in this codebase is
 * `wp_bcc_nft_spam_contracts.rule = 'deny'`, and the Hide button IS what
 * writes it — VerifyCollectionsPage's own docblock says "Hide = RULE_DENY".
 * No column anywhere records a scam classification, and the heuristic spam
 * detection in the fetchers is never persisted per collection. Naming the
 * tab "scam" would assert something the database cannot support about a
 * third party. It is named for the operator act that actually happened.
 *
 * ── WHY A FOURTH TAB EXISTS ─────────────────────────────────────────────
 * Decoupling verification from provisioning CREATES the "verified, no
 * community" population — it is the resting state of every newly verified
 * collection awaiting a request. Neither honest tab describes it, and
 * dropping it would hide rows from every tab. `needs_attention` is bounded:
 * five named causes, each rendered with its cause, and nothing lands there
 * by default.
 *
 * @package BCC\Trust\Onchain\Services
 * @since PR 6 — collection administration and explicit provisioning
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;

if (!defined('ABSPATH')) {
    exit;
}

final class CollectionStateClassifier
{
    public const TAB_VERIFIED_WITH_COMMUNITY = 'verified_with_community';
    public const TAB_DISCOVERED_UNVERIFIED   = 'discovered_unverified';
    public const TAB_NEEDS_ATTENTION         = 'needs_attention';
    public const TAB_HIDDEN_BY_OPERATOR      = 'hidden_by_operator';

    /** Causes a row can land in `needs_attention` for. Bounded, and rendered. */
    public const CAUSE_VERIFIED_NO_COMMUNITY   = 'verified_no_community';
    public const CAUSE_REQUEST_PENDING         = 'request_pending';
    public const CAUSE_PROVISIONING_FAILED     = 'provisioning_failed';
    public const CAUSE_COMMUNITY_UNVERIFIED    = 'community_collection_unverified';
    public const CAUSE_IDENTITY_UNRESOLVED     = 'community_identity_unresolved';
    public const CAUSE_CONTRADICTORY_STATE     = 'contradictory_provisioning_state';

    /**
     * The four tabs in display order.
     *
     * NOTE this is display order, not precedence order. They differ, and
     * conflating them is how a precedence bug hides: see {@see precedence()}.
     *
     * @return list<string>
     */
    public static function tabs(): array
    {
        return [
            self::TAB_VERIFIED_WITH_COMMUNITY,
            self::TAB_DISCOVERED_UNVERIFIED,
            self::TAB_NEEDS_ATTENTION,
            self::TAB_HIDDEN_BY_OPERATOR,
        ];
    }

    /**
     * Evaluation order. FIRST MATCH WINS.
     *
     * Hidden outranks everything: a collection an operator has denied must
     * not surface in a working queue whatever its verification or community
     * state, or the operator's decision is quietly overridden by a tab.
     * Needs-attention outranks the two healthy tabs so a contradictory row
     * is never presented as healthy.
     *
     * @return list<string>
     */
    public static function precedence(): array
    {
        return [
            self::TAB_HIDDEN_BY_OPERATOR,
            self::TAB_NEEDS_ATTENTION,
            self::TAB_VERIFIED_WITH_COMMUNITY,
            self::TAB_DISCOVERED_UNVERIFIED,
        ];
    }

    public static function isTab(string $tab): bool
    {
        return in_array($tab, self::tabs(), true);
    }

    /**
     * PURE. Classify one collection from the four inputs.
     *
     * @param bool $verified        V
     * @param bool $identityResolved K
     * @param bool $hasCommunity    C
     * @param bool $hidden          H
     */
    public static function classify(
        bool $verified,
        bool $identityResolved,
        bool $hasCommunity,
        bool $hidden
    ): string {
        if ($hidden) {
            return self::TAB_HIDDEN_BY_OPERATOR;
        }

        if (self::needsAttention($verified, $identityResolved, $hasCommunity)) {
            return self::TAB_NEEDS_ATTENTION;
        }

        if ($verified && $hasCommunity && $identityResolved) {
            return self::TAB_VERIFIED_WITH_COMMUNITY;
        }

        // Everything remaining is (NOT V AND NOT C). Both K values land here,
        // which is correct: the 91 unresolved Solana aliases are unverified,
        // community-less, discovered rows and belong in the discovery queue.
        return self::TAB_DISCOVERED_UNVERIFIED;
    }

    /**
     * PURE. The needs-attention predicate, extracted so the SQL below and
     * {@see classify()} cannot drift apart by editing only one of them.
     */
    public static function needsAttention(
        bool $verified,
        bool $identityResolved,
        bool $hasCommunity
    ): bool {
        // verified but no community — incl. requested and failed
        if ($verified && !$hasCommunity) {
            return true;
        }

        // a community whose collection is not (or is no longer) verified
        if (!$verified && $hasCommunity) {
            return true;
        }

        // a live community gating on an identity that cannot be resolved
        if ($verified && $hasCommunity && !$identityResolved) {
            return true;
        }

        return false;
    }

    /**
     * PURE. Why this row needs attention, for rendering. Returns null when
     * the row does not need attention at all.
     *
     * The provisioning state is consulted HERE and only here — it refines
     * the cause shown to an operator, and is deliberately not part of the
     * tab predicate. A `failed` row is already `V AND NOT C`; the state
     * tells the operator which of the three verified-no-community cases it
     * is, so the retry affordance can be right.
     */
    public static function attentionCause(
        bool $verified,
        bool $identityResolved,
        bool $hasCommunity,
        string $provisioningState
    ): ?string {
        if (!self::needsAttention($verified, $identityResolved, $hasCommunity)) {
            return null;
        }

        if ($verified && $hasCommunity && !$identityResolved) {
            return self::CAUSE_IDENTITY_UNRESOLVED;
        }

        if (!$verified && $hasCommunity) {
            // A provisioned community whose collection was later unverified is
            // the expected shape. Anything else here is a state contradiction:
            // a community exists but the row does not say `provisioned`.
            return $provisioningState === ProvisioningState::PROVISIONED
                ? self::CAUSE_COMMUNITY_UNVERIFIED
                : self::CAUSE_CONTRADICTORY_STATE;
        }

        // Remaining: verified, no community.
        switch ($provisioningState) {
            case ProvisioningState::REQUESTED:
                return self::CAUSE_REQUEST_PENDING;
            case ProvisioningState::FAILED:
                return self::CAUSE_PROVISIONING_FAILED;
            case ProvisioningState::NONE:
                return self::CAUSE_VERIFIED_NO_COMMUNITY;
            default:
                // `provisioned` with no live community is a contradiction —
                // the community was trashed or deleted out from under the
                // row. Say so rather than showing "no community requested",
                // which would invite an operator to create a second one.
                return self::CAUSE_CONTRADICTORY_STATE;
        }
    }

    /** Operator-facing label for a tab. */
    public static function tabLabel(string $tab): string
    {
        switch ($tab) {
            case self::TAB_VERIFIED_WITH_COMMUNITY:
                return 'Verified with community';
            case self::TAB_DISCOVERED_UNVERIFIED:
                return 'Discovered / unverified';
            case self::TAB_NEEDS_ATTENTION:
                return 'Needs attention';
            case self::TAB_HIDDEN_BY_OPERATOR:
                return 'Hidden by operator';
            default:
                return 'Unrecognised tab';
        }
    }

    /** Operator-facing label for a needs-attention cause. */
    public static function causeLabel(string $cause): string
    {
        switch ($cause) {
            case self::CAUSE_VERIFIED_NO_COMMUNITY:
                return 'Verified — no community requested';
            case self::CAUSE_REQUEST_PENDING:
                return 'Community requested — not created yet';
            case self::CAUSE_PROVISIONING_FAILED:
                return 'Community creation failed — retryable';
            case self::CAUSE_COMMUNITY_UNVERIFIED:
                return 'Community exists — collection is not verified';
            case self::CAUSE_IDENTITY_UNRESOLVED:
                return 'Community exists — on-chain identity unresolved';
            case self::CAUSE_CONTRADICTORY_STATE:
                return 'Provisioning state contradicts the community that exists';
            default:
                return 'Unrecognised cause';
        }
    }

    // ── SQL ─────────────────────────────────────────────────────────────
    //
    // The four predicates above, expressed against a collections table
    // aliased `c`. Every fragment is a hardcoded string containing no
    // caller input — the meta keys, post type, post status and rule value
    // are compile-time constants of this system, not user data — so these
    // compose safely into a prepared statement whose placeholders carry the
    // caller's filters.
    //
    // ── WHY THE TABLE NAMES ARE PASSED IN ───────────────────────────────
    // This is a Service, and §1 puts raw `$wpdb` in Repositories only. The
    // two WordPress core table names come from the caller — which IS a
    // repository and legitimately holds the handle — so the RULE can stay
    // here, beside the PHP predicate it must agree with, without this file
    // reaching for a database handle it has no business owning.

    /**
     * SQL for `C`: a PUBLISHED peepso-group holders post points here.
     *
     * ── WHY post_status IS CHECKED ──────────────────────────────────────
     * A trashed or draft group is not a live community. Counting one would
     * put a collection in `verified_with_community` while nobody can reach
     * the community, and — worse — would suppress the "Request community"
     * affordance that would fix it.
     *
     * @param string $postmetaTable `$wpdb->postmeta`
     * @param string $postsTable    `$wpdb->posts`
     */
    public static function sqlHasCommunity(string $postmetaTable, string $postsTable): string
    {
        return "EXISTS (
            SELECT 1
              FROM {$postmetaTable} pm_coll
              INNER JOIN {$postmetaTable} pm_kind
                      ON pm_kind.post_id = pm_coll.post_id
                     AND pm_kind.meta_key = '" . GatedGroupRepository::META_KIND . "'
                     AND pm_kind.meta_value = '" . GatedGroupRepository::KIND_HOLDERS . "'
              INNER JOIN {$postsTable} p
                      ON p.ID = pm_coll.post_id
                     AND p.post_type = 'peepso-group'
                     AND p.post_status = 'publish'
             WHERE pm_coll.meta_key = '" . GatedGroupRepository::META_COLLECTION . "'
               AND pm_coll.meta_value = c.id
        )";
    }

    /**
     * SQL for `H`: an operator DENY rule covers this contract.
     *
     * ── WHY `LOWER(c.contract_address)` ─────────────────────────────────
     * `NftSpamContractRepository` lower-cases on write AND on read, and the
     * spam table's collation is case-insensitive. Lower-casing here
     * reproduces the EXISTING classification exactly — which is the point.
     * That folding diverges from the byte-exact canonical identity used
     * everywhere else, and on Solana that divergence is real; correcting it
     * changes WHICH rows are hidden, so it is deliberately out of scope for
     * PR 6 and belongs in its own change with its own data check.
     */
    public static function sqlIsHidden(): string
    {
        return 'EXISTS (
            SELECT 1
              FROM ' . NftSpamContractRepository::table() . ' s
             WHERE s.chain_id = c.chain_id
               AND s.contract_address = LOWER(c.contract_address)
               AND s.rule = \'' . NftSpamContractRepository::RULE_DENY . '\'
        )';
    }

    /**
     * The complete WHERE fragment for one tab, mirroring {@see classify()}
     * including its precedence. Contains no placeholders and no caller
     * input; the caller ANDs it with its own prepared filters.
     *
     * @throws \InvalidArgumentException on an unknown tab — an unrecognised
     *         tab must never silently degrade to "everything".
     */
    public static function sqlForTab(string $tab, string $postmetaTable, string $postsTable): string
    {
        // Validate BEFORE building anything: an unknown tab is a programming
        // error, and must never fall through to a fragment that matches
        // everything.
        if (!self::isTab($tab)) {
            throw new \InvalidArgumentException('Unknown collection state tab: ' . $tab);
        }

        $c = self::sqlHasCommunity($postmetaTable, $postsTable);
        $h = self::sqlIsHidden();

        // Precedence is expressed by negating the higher-ranked predicates,
        // which is what makes the four fragments provably disjoint: any row
        // matching an earlier tab is excluded from every later one.
        $notHidden = "NOT {$h}";

        // needs_attention, in SQL. Mirrors needsAttention() term for term.
        $attention = "(
               (c.is_verified = 1 AND NOT {$c})
            OR (c.is_verified = 0 AND {$c})
            OR (c.is_verified = 1 AND {$c} AND c.canonical_identifier IS NULL)
        )";

        switch ($tab) {
            case self::TAB_HIDDEN_BY_OPERATOR:
                return $h;

            case self::TAB_NEEDS_ATTENTION:
                return "{$notHidden} AND {$attention}";

            case self::TAB_VERIFIED_WITH_COMMUNITY:
                return "{$notHidden} AND NOT {$attention}
                        AND c.is_verified = 1 AND {$c} AND c.canonical_identifier IS NOT NULL";

            case self::TAB_DISCOVERED_UNVERIFIED:
            default:
                // `isTab()` above has already refused anything else, so the
                // default arm is the last real tab rather than a fallthrough
                // that could ever mean "match everything".
                return "{$notHidden} AND NOT {$attention}
                        AND c.is_verified = 0 AND NOT {$c}";
        }
    }
}
