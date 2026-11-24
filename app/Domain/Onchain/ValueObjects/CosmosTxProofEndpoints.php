<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE RULES FOR THE NODES THAT CONFIRM A COSMOS TRANSACTION FOR A VALIDATOR
 * MEMO CLAIM. The list itself is a decision, made in one place:
 * {@see CosmosEndpointPolicy::proofEndpoints()} (`PROOF_ENDPOINTS`).
 *
 * The memo claim proves key control by verifying the signature LOCALLY over
 * hash-matching bytes (bcc-core `CosmosTxVerifier`), so these endpoints are
 * trusted for nothing an endpoint could forge into a pass. They establish
 * inclusion (code 0 at a committed height), the block time, the account
 * number (a lie only makes verification fail) and the validator's
 * `security_contact` (where a lie could redirect a warning email, which is
 * why it needs two operators that agree).
 *
 * ── THE RULES THIS MAP MUST KEEP (pinned by CosmosTxProofEndpointsTest) ──
 *   - At least TWO entries per slug, run by DIFFERENT operators, on
 *     different registrable domains. Two nodes of one operator are one
 *     witness. A proxy that fans out to many backends (cosmos.directory)
 *     is no single operator and is never listed.
 *   - Public, keyless https endpoints, already normalized
 *     ({@see CosmosEndpointPolicy::normalize()}). No credentials, no query.
 *   - Every listed slug has a pinned network
 *     ({@see CosmosEndpointPolicy::pinnedNetwork()}), each node is checked
 *     against it before it is believed, and a pinned account prefix
 *     ({@see CosmosEndpointPolicy::pinnedAccountPrefix()}).
 *   - Each entry's `rest` is the same operator's LCD (account number and
 *     `security_contact`); `rpc` is its CometBFT RPC (`/status`, `/tx`,
 *     `/commit`).
 *
 * A slug whose entries break any rule above is UNSUPPORTED at read time,
 * so a bad edit refuses claims rather than trusting one operator.
 */
final class CosmosTxProofEndpoints
{
    /** The fewest independent operators a slug needs. */
    public const MIN_OPERATORS = 2;

    /** @return list<string> every slug listed (supported or not), for tests and admin display. */
    public static function listedSlugs(): array
    {
        return CosmosEndpointPolicy::proofSlugs();
    }

    /** Can a memo claim be proven on $slug? */
    public static function isSupported(string $slug): bool
    {
        return self::forSlug($slug) !== [];
    }

    /**
     * The endpoints for $slug, or [] when the slug is not listed or its
     * entries break a rule (fail closed: never fewer than two operators).
     *
     * @return list<array{operator: string, rpc: string, rest: string}>
     */
    public static function forSlug(string $slug): array
    {
        $entries = CosmosEndpointPolicy::proofEndpoints($slug);

        return self::problems($slug, $entries) === [] ? $entries : [];
    }

    /**
     * Every rule $entries break for $slug (empty = valid). PURE.
     *
     * @param list<array{operator: string, rpc: string, rest: string}> $entries
     * @return list<string>
     */
    public static function problems(string $slug, array $entries): array
    {
        $problems = [];
        if (CosmosEndpointPolicy::pinnedNetwork($slug) === null) {
            $problems[] = 'no pinned network';
        }
        if (CosmosEndpointPolicy::pinnedAccountPrefix($slug) === null) {
            $problems[] = 'no pinned account prefix';
        }
        if (count($entries) < self::MIN_OPERATORS) {
            $problems[] = 'fewer than ' . self::MIN_OPERATORS . ' operators';
        }

        $operators = [];
        $domains   = [];
        foreach ($entries as $i => $entry) {
            $operator = strtolower(trim($entry['operator']));
            if ($operator === '') {
                $problems[] = "entry {$i}: no operator";
            }
            $operators[] = $operator;

            $entryDomains = [];
            foreach (['rpc', 'rest'] as $kind) {
                $url = $entry[$kind];
                if (CosmosEndpointPolicy::normalize($url) !== $url) {
                    $problems[] = "entry {$i}: {$kind} is not a normalized https url";
                    continue;
                }
                $entryDomains[] = self::registrableDomain((string) parse_url($url, PHP_URL_HOST));
            }
            if (count(array_unique($entryDomains)) > 1) {
                $problems[] = "entry {$i}: rpc and rest are on different operators' domains";
            }
            $domains[] = $entryDomains[0] ?? '';
        }

        if (count(array_unique($operators)) !== count($operators)) {
            $problems[] = 'an operator is listed twice';
        }
        if (count(array_unique($domains)) !== count($domains)) {
            $problems[] = 'two entries share a domain';
        }

        return $problems;
    }

    /** The last two labels of $host (`cosmos-rpc.polkachu.com` → `polkachu.com`). */
    private static function registrableDomain(string $host): string
    {
        $labels = explode('.', strtolower($host));

        return implode('.', array_slice($labels, -2));
    }
}
