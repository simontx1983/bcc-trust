<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns stored CW-721 classification evidence into a plain-language
 * sentence for the operator. PURE — zero I/O, zero WordPress, zero
 * $wpdb.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * {@see CosmwasmClassifier} stores evidence as bounded MACHINE tokens so
 * the columns stay small and the state machine stays comparable:
 *
 *     classification_reason  "num_tokens_only" / "checksum_twin:434"
 *     probes_ok              "num_tokens,contract_info"
 *     probes_failed          "contract_info:query_unsupported"
 *     last_error             a sanitized ≤255-char upstream excerpt
 *
 * Those are precise and unreadable. An admin queue that renders them raw
 * asks the operator to learn wasmd's error vocabulary before they can
 * approve a collection, so the approval decision gets made on the name
 * alone — which is exactly the decision the evidence exists to inform.
 *
 * ── WHAT IT REFUSES TO DO ───────────────────────────────────────────────
 * It reads ONLY the structured tokens. `last_error` is never an input:
 * upstream error text is an opaque blob (node hostnames, gas figures,
 * Rust type paths) that says nothing an operator can act on, and pasting
 * it into a sentence is the "dump the evidence blob" failure this
 * replaces. The admin page surfaces the sanitized excerpt separately,
 * behind a disclosure, clearly labelled as raw upstream text.
 *
 * An unrecognised token degrades to a neutral phrase rather than being
 * echoed: a future classifier version can add reasons, and a stale
 * narrator must not present a token it does not understand as if it
 * were prose.
 */
final class CosmwasmEvidenceNarrator
{
    /**
     * Human label for a classification value.
     */
    public static function classificationLabel(string $classification): string
    {
        switch ($classification) {
            case CosmwasmClassifier::CONFIRMED:
                return 'Confirmed NFT collection';
            case CosmwasmClassifier::PROBABLE:
                return 'Probable NFT collection';
            case CosmwasmClassifier::NOT_CW721:
                return 'Not an NFT collection';
            case CosmwasmClassifier::UNREACHABLE:
                return 'Could not be reached';
            case CosmwasmClassifier::INCONCLUSIVE:
                return 'Not yet decided';
            default:
                return 'Unknown';
        }
    }

    /**
     * Confidence word for a classification. Deliberately three coarse
     * buckets and NEVER a percentage — the classifier produces a verdict
     * from a fixed probe set, not a score, and inventing a number would
     * imply a precision the evidence does not carry.
     *
     * @return 'high'|'medium'|'low'|'none'
     */
    public static function confidence(string $classification): string
    {
        switch ($classification) {
            case CosmwasmClassifier::CONFIRMED:
            case CosmwasmClassifier::NOT_CW721:
                return 'high';
            case CosmwasmClassifier::PROBABLE:
                return 'medium';
            case CosmwasmClassifier::UNREACHABLE:
                return 'low';
            default:
                return 'none';
        }
    }

    /**
     * The full operator-facing sentence: what we concluded, why, and what
     * happens next.
     *
     * @param string $classification one of CosmwasmClassifier's states
     * @param string $reason         the stored `classification_reason`
     * @param string $probesOk       the stored `probes_ok`
     * @param string $probesFailed   the stored `probes_failed`
     */
    public static function describe(
        string $classification,
        string $reason,
        string $probesOk,
        string $probesFailed
    ): string {
        $parts = [self::reasonSentence($classification, $reason)];

        $probeSentence = self::probeSentence($probesOk, $probesFailed);
        if ($probeSentence !== '') {
            $parts[] = $probeSentence;
        }

        return implode(' ', $parts);
    }

    /**
     * The "why" clause, keyed off the stored reason token.
     *
     * The reason tokens come from three places and all three are handled:
     * the classifier's own decision rules, the family-level
     * `sampled:<n>` / `no_contracts` verdicts, and the zero-request
     * `checksum_twin:<code id>` inheritance.
     */
    public static function reasonSentence(string $classification, string $reason): string
    {
        $reason = trim($reason);

        // checksum_twin:<code id> — inherited, zero requests spent.
        if (strncmp($reason, 'checksum_twin:', 14) === 0) {
            $twin = (int) substr($reason, 14);

            return $twin > 0
                ? sprintf(
                    'Its stored wasm binary is byte-identical to code %d, which was already classified, so that verdict was inherited without spending any requests.',
                    $twin
                )
                : 'Its stored wasm binary matched an already-classified one, so that verdict was inherited without spending any requests.';
        }

        // sampled:<n> — a family verdict reduced from N probed instances.
        if (strncmp($reason, 'sampled:', 8) === 0) {
            $n = (int) substr($reason, 8);

            return $n > 0
                ? sprintf(
                    'Decided by probing %d contract%s built from this code, rather than every contract under it.',
                    $n,
                    $n === 1 ? '' : 's'
                )
                : 'Decided by probing a sample of the contracts built from this code.';
        }

        switch ($reason) {
            case 'num_tokens_and_info':
                return 'It counted its tokens and returned a collection name, which is what a working CW-721 collection does.';

            case 'num_tokens_only':
                return 'It counted its tokens, but refused both collection-info queries — so it holds NFTs even though it does not report a name the standard way.';

            case 'info_only':
                return 'It returned a collection name but cannot count tokens, which is also how a launchpad minter behaves — so it is a candidate, not a confirmed collection.';

            case 'no_cw721_queries':
                return 'It refused every CW-721 query, which is a definite answer from the contract itself, not a network problem.';

            case 'node_unreachable':
                return 'The chain node failed to answer, so nothing was learned about this contract. It will be tried again.';

            case 'partial_evidence_node_unreachable':
                return 'Part of the check succeeded and the rest failed on the node side. A half-answer is not treated as a verdict, so it will be tried again.';

            case 'contract_not_found':
                return 'No contract answered at this address.';

            case 'no_contracts':
                return 'Nothing has been built from this code yet, so there is nothing to test. It stays open in case something is deployed later.';

            case 'indeterminate':
                return 'The responses did not add up to an answer either way.';

            case '':
                return self::fallbackSentence($classification);

            default:
                // A reason token this build does not recognise — most
                // likely written by a NEWER classifier version. Say what
                // the verdict was; do not echo a token as prose.
                return self::fallbackSentence($classification);
        }
    }

    /**
     * The "which checks ran" clause. Empty string when no probe evidence
     * was recorded (checksum inheritance and the no-contracts verdict
     * both legitimately record none).
     */
    public static function probeSentence(string $probesOk, string $probesFailed): string
    {
        $ok     = self::splitTokens($probesOk);
        $failed = self::splitTokens($probesFailed);

        $clauses = [];

        if ($ok !== []) {
            $clauses[] = 'Answered: ' . self::humanList(array_map(
                static fn(string $probe): string => self::probeLabel($probe),
                $ok
            )) . '.';
        }

        if ($failed !== []) {
            $descriptions = [];
            foreach ($failed as $entry) {
                $probe = $entry;
                $kind  = '';
                $split = strpos($entry, ':');
                if ($split !== false) {
                    $probe = substr($entry, 0, $split);
                    $kind  = substr($entry, $split + 1);
                }
                $why = self::errorKindPhrase($kind);
                $descriptions[] = $why === ''
                    ? self::probeLabel($probe)
                    : self::probeLabel($probe) . ' (' . $why . ')';
            }
            $clauses[] = 'Did not answer: ' . self::humanList($descriptions) . '.';
        }

        return implode(' ', $clauses);
    }

    /**
     * What the operator can do about this row right now. Kept separate
     * from the evidence so a row's "why" and its "what next" can be
     * rendered in different places.
     */
    public static function nextStep(string $classification): string
    {
        switch ($classification) {
            case CosmwasmClassifier::CONFIRMED:
                return 'Ready for review — verify it to give its holders a community, or hide it.';
            case CosmwasmClassifier::PROBABLE:
                return 'Check it yourself before verifying — run Test CW-721, or hide it if it is a minter or junk.';
            case CosmwasmClassifier::NOT_CW721:
                return 'Settled. It is never re-checked automatically.';
            case CosmwasmClassifier::UNREACHABLE:
                return 'Waiting on a retry. Force Retry clears the wait if the node is back.';
            case CosmwasmClassifier::INCONCLUSIVE:
                return 'Waiting on a retry or on something being deployed. Force Retry looks again now.';
            default:
                return '';
        }
    }

    /** Verdict-only sentence for an absent or unrecognised reason token. */
    private static function fallbackSentence(string $classification): string
    {
        switch ($classification) {
            case CosmwasmClassifier::CONFIRMED:
                return 'The CW-721 checks passed.';
            case CosmwasmClassifier::PROBABLE:
                return 'Some of the CW-721 checks passed.';
            case CosmwasmClassifier::NOT_CW721:
                return 'The CW-721 checks were refused by the contract.';
            case CosmwasmClassifier::UNREACHABLE:
                return 'The checks could not be completed against the chain.';
            default:
                return 'No conclusion has been reached yet.';
        }
    }

    /** Operator-readable name for a probe. */
    private static function probeLabel(string $probe): string
    {
        switch ($probe) {
            case CosmwasmClassifier::PROBE_NUM_TOKENS:
                return 'token count';
            case CosmwasmClassifier::PROBE_CONTRACT_INFO:
                return 'collection name (classic)';
            case CosmwasmClassifier::PROBE_COLLECTION_INFO:
                return 'collection name (cw721 v0.19+)';
            default:
                return 'an additional check';
        }
    }

    /**
     * Why a probe failed, in operator language.
     *
     * The distinction that matters is the one the classifier is built
     * around: "the contract refused" is evidence about the contract,
     * everything else is evidence about the node or the wire.
     */
    private static function errorKindPhrase(string $kind): string
    {
        switch ($kind) {
            case CosmwasmClassifier::KIND_QUERY_UNSUPPORTED:
                return 'the contract does not implement it';
            case CosmwasmClassifier::KIND_NODE_ERROR:
                return 'the chain node errored';
            case CosmwasmClassifier::KIND_TRANSPORT:
                return 'the request never got through';
            case CosmwasmClassifier::KIND_HTTP_4XX:
                return 'the node rejected the request';
            case CosmwasmClassifier::KIND_NOT_FOUND:
                return 'nothing exists at that address';
            case CosmwasmClassifier::KIND_MALFORMED:
                return 'the reply could not be read';
            default:
                return '';
        }
    }

    /**
     * Split a stored comma-joined evidence field into its tokens.
     *
     * @return list<string>
     */
    private static function splitTokens(string $joined): array
    {
        $joined = trim($joined);
        if ($joined === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $joined) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $out[] = $token;
            }
        }

        return $out;
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param list<string> $items
     */
    private static function humanList(array $items): string
    {
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
