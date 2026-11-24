<?php
/**
 * Stubs for CardsSearchPageKindTest (F-592): the REAL
 * CardsSearchEndpoint::handle() over a bcc-search pages vertical that
 * answers by bcc-search's OWN rules.
 *
 * ⚠⚠ SUBPROCESS-ONLY. Declares the global `rest_do_request()`, `get_post()`,
 * `get_post_meta()`, `_prime_post_caches()` and a ClaimRepository double, then
 * the WP_REST_* classes from cards-search-upstream-stubs.php (whose own
 * `rest_do_request()` this one pre-empts). A declaration cannot be undone, so
 * the consuming test is `#[RunTestsInSeparateProcesses]` +
 * `#[PreserveGlobalState(false)]`.
 *
 * ⭐ WHY A MODEL, NOT A CANNED ANSWER. The defect was in what the endpoint
 * ASKED bcc-search: it sent the page type as `type`, which bcc-search reads
 * as a PeepSo CATEGORY slug, and an unknown category answers with nothing. A
 * fixture that hands back the same rows whatever the parameters can never
 * see that. So the pages vertical below applies bcc-search's rules
 * (SearchController::handle_search):
 *   - `type` must be one of the site's category slugs, or the answer is
 *     empty. The slugs are the real ones, typo included;
 *   - `kind` (validator | project | creator) keeps the pages whose stored
 *     type, with none meaning builder, is that kind's type. Anything else
 *     is a 400.
 *
 * Fixture ($GLOBALS['__bcc_cspk']):
 *   pages  array<int, array{name: string, type: string, category: ?string}>
 *          every page that matches the query, in bcc-search's ranked order:
 *          `type` is its stored `_bcc_page_type`, `category` the PeepSo
 *          category slug bcc-search reports as `category_slug`
 *   gone   list<int>   ids bcc-search still returns but get_post() no longer finds
 *   calls  list<array{string, array<string, mixed>}>  every rest_do_request(): route, params
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {
    // ⚠ `class_exists($x, false)`: a bare class_exists() would AUTOLOAD the
    // real class, the guard would always be true and this double would
    // never be declared.
    if (!\class_exists('BCC\Trust\Onchain\Repositories\ClaimRepository', false)) {
        /** No page carries a verified claim in this suite. */
        final class ClaimRepository
        {
            /**
             * @param int[] $pageIds
             * @return array<int, true>
             */
            public static function getVerifiedPagesMap(array $pageIds): array
            {
                unset($pageIds);
                return [];
            }
        }
    }
}

namespace {
    if (!function_exists('rest_do_request')) {
        function rest_do_request(WP_REST_Request $request): WP_REST_Response
        {
            $route  = $request->get_route();
            $params = $request->get_params();
            $GLOBALS['__bcc_cspk']['calls'][] = [$route, $params];

            if ($route !== '/bcc/v1/search') {
                // The groups and users verticals find nothing here.
                return new WP_REST_Response(['results' => []], 200);
            }

            // bcc-search: an unknown category short-circuits to empty.
            $categorySlugs = ['vaildators', 'builders', 'nft-creators', 'daos'];
            $type = isset($params['type']) ? (string) $params['type'] : '';
            if ($type !== '' && !in_array($type, $categorySlugs, true)) {
                return new WP_REST_Response(['results' => [], 'categories' => []], 200);
            }

            // bcc-search: `kind` filters on the stored page type.
            $kindToPageType = ['validator' => 'validator', 'project' => 'builder', 'creator' => 'nft'];
            $kind = isset($params['kind']) ? (string) $params['kind'] : '';
            if ($kind !== '' && !isset($kindToPageType[$kind])) {
                return new WP_REST_Response(
                    ['code' => 'bcc_invalid_request', 'message' => 'kind must be validator, project, or creator.', 'data' => ['status' => 400]],
                    400
                );
            }

            $results = [];
            foreach ($GLOBALS['__bcc_cspk']['pages'] as $id => $page) {
                if ($type !== '' && $page['category'] !== $type) {
                    continue;
                }
                $effective = $page['type'] !== '' ? $page['type'] : 'builder';
                if ($kind !== '' && $effective !== $kindToPageType[$kind]) {
                    continue;
                }
                $results[] = [
                    'page_id'       => $id,
                    'page_name'     => $page['name'],
                    'trust_score'   => 50,
                    'tier'          => 'steady',
                    'verified'      => false,
                    'category_slug' => $page['category'],
                ];
            }

            return new WP_REST_Response(['results' => $results, 'categories' => []], 200);
        }
    }

    if (!function_exists('get_post')) {
        /** @return object{post_name: string}|null */
        function get_post(int $id): ?object
        {
            $fx = $GLOBALS['__bcc_cspk'];
            if (!isset($fx['pages'][$id]) || in_array($id, $fx['gone'], true)) {
                return null;
            }
            return (object) ['ID' => $id, 'post_name' => 'page-' . $id];
        }
    }

    if (!function_exists('get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $id, string $key = '', bool $single = false)
        {
            unset($single);
            if ($key !== '_bcc_page_type') {
                return '';
            }
            return $GLOBALS['__bcc_cspk']['pages'][$id]['type'] ?? '';
        }
    }

    if (!function_exists('_prime_post_caches')) {
        /** @param int[] $ids */
        function _prime_post_caches(array $ids, bool $updateTermCache = true, bool $updateMetaCache = true): void
        {
            unset($ids, $updateTermCache, $updateMetaCache);
        }
    }

    require_once __DIR__ . '/cards-search-upstream-stubs.php';
}
