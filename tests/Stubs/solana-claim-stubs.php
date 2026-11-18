<?php
/**
 * F-339: scripted collaborators at their REAL FQNs, so the real
 * ClaimService::matchCollection() and the real SolanaCollectionAuthority run
 * with no DB and no HTTP.
 *
 * Require from setUp() of a #[RunTestsInSeparateProcesses] class, BEFORE
 * anything can autoload the real classes (the role stub is required too).
 *
 * Fixture ($GLOBALS['__bcc_sc']):
 *   chain        ?object   what ChainRepository::getBySlug() returns
 *   asset        ?array    the DAS getAsset result (ok answers)
 *   asset_kind   string    'none' for an ok answer, else the failure kind
 *   asset_calls  list<string>  mints asked of getAsset
 *   slugs        list<string>  slugs asked of getBySlug
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(ChainRepository::class, false)) {
        final class ChainRepository
        {
            public static function getBySlug(string $slug): ?object
            {
                $GLOBALS['__bcc_sc']['slugs'][] = $slug;
                $chain = $GLOBALS['__bcc_sc']['chain'] ?? null;
                return is_object($chain) ? $chain : null;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Fetchers {
    if (!class_exists(SolanaFetcher::class, false)) {
        class SolanaFetcher
        {
            public function __construct(object $chain)
            {
                unset($chain);
            }

            /** @return array{ok: bool, result: ?array<string, mixed>, kind: string} */
            public function assetResult(string $mint): array
            {
                $GLOBALS['__bcc_sc']['asset_calls'][] = $mint;
                $kind = (string) ($GLOBALS['__bcc_sc']['asset_kind'] ?? 'none');
                if ($kind !== 'none') {
                    return ['ok' => false, 'result' => null, 'kind' => $kind];
                }
                $asset = $GLOBALS['__bcc_sc']['asset'] ?? null;
                return ['ok' => true, 'result' => is_array($asset) ? $asset : null, 'kind' => 'none'];
            }
        }
    }
}
