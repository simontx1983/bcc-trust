<?php
/**
 * PublicProfileIndex — the ONE owner of "may this be listed publicly"
 * (F-518): which member profiles, entity pages and groups the app sitemap
 * lists, and whether a profile page may be indexed (its `indexable` flag).
 *
 * The sitemap and the page's robots tag ask the same question, so both come
 * from here. A member is listed — and their page indexable — only when ALL of
 * the following hold:
 *
 *   1. a PeepSo user row exists, the role is not register / verified / ban,
 *      the profile access is public (≤ 10), the account is not suspended, and
 *      no hide-from-listings row reads '1' (PublicIndexRepository's SQL);
 *   2. none of the three moderation flags is set (hidden, shadow-limited,
 *      under review), read the way UserViewService::resolveFlags() reads
 *      them (FILTER_VALIDATE_BOOLEAN on the first value);
 *   3. a current handle exists and has the current handle shape: runs of
 *      [a-z0-9] joined by single hyphens, and not the `member-{id}` fallback.
 *      Legacy handles (an email address, say) are never advertised.
 *
 * ⚠⚠ FAIL CLOSED, NEVER PARTIAL. Every read goes through ProbesReads or
 * bcc-core's UserMetaRepository, which answer null for a read that cannot be
 * trusted. Any null makes the WHOLE page null (the endpoint answers 503), so
 * an unknown member is never listed and a failure never silently drops part
 * of the index. For one profile, an unknown read means "not indexable".
 *
 * ⚠ VIEWER-INDEPENDENT. Nothing here reads the current user: the answer is
 * the anonymous one for every caller, an admin included.
 *
 * Paths come from CardUrlMap, the composer every other link goes through, so
 * an entry is byte-for-byte the canonical the page itself declares.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Core\Repositories\UserMetaRepository;
use BCC\Trust\Core\Repositories\PublicIndexRepository;
use BCC\Trust\Core\Support\CardUrlMap;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type IndexPage array{items: list<array{path: string}>, next_after: int|null}
 */
final class PublicProfileIndex
{
    /** The current handle shape (HandleService::validate()'s character rules). */
    private const HANDLE_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /** The system fallback handle (PublicIdentity), never chosen by a member. */
    private const FALLBACK_HANDLE_PATTERN = '/\Amember-\d+\z/';

    /** The usermeta keys read per member after the SQL pass. */
    private const MEMBER_META_KEYS = [
        HandleService::META_HANDLE,
        UserViewService::META_HIDDEN,
        UserViewService::META_SHADOW_LIMITED,
        UserViewService::META_UNDER_REVIEW,
    ];

    /** The moderation flags among {@see MEMBER_META_KEYS}. */
    private const MODERATION_FLAG_KEYS = [
        UserViewService::META_HIDDEN,
        UserViewService::META_SHADOW_LIMITED,
        UserViewService::META_UNDER_REVIEW,
    ];

    public function __construct(
        private readonly PublicIndexRepository $repository,
        private readonly GroupContextResolver $groups,
    ) {
    }

    /**
     * One keyset page of listable member profiles.
     *
     * `next_after` is the last SCANNED id: the SQL pass reads `$limit` rows,
     * and the meta checks may drop some, so a page can hold fewer than
     * `$limit` items (even none) and still have a next page. It is null only
     * when the scan reached the end.
     *
     * @return IndexPage|null null = a read failed, or the arguments are out of range
     */
    public function membersAfter(int $afterId, int $limit): ?array
    {
        $ids = $this->repository->memberIdsAfter($afterId, $limit);
        if ($ids === null) {
            return null;
        }

        $handles = $this->listableHandles($ids);
        if ($handles === null) {
            return null;
        }

        $items = [];
        foreach ($ids as $id) {
            if (isset($handles[$id])) {
                $items[] = ['path' => CardUrlMap::frontendUrl('member', $handles[$id])];
            }
        }

        return [
            'items'      => $items,
            'next_after' => self::nextAfter($ids, $limit),
        ];
    }

    /**
     * May this member's profile page be indexed? The same predicate as
     * {@see membersAfter()}, for one id. Any read that cannot be trusted
     * answers false: an unknown profile is never declared indexable.
     */
    public function isIndexable(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ($this->repository->memberIsCandidate($userId) !== true) {
            return false;
        }

        $handles = $this->listableHandles([$userId]);

        return $handles !== null && isset($handles[$userId]);
    }

    /**
     * One keyset page of listable entity pages (validators, projects,
     * creators). A page whose `_bcc_page_type` maps to no card kind is not
     * listed; an empty type is a project (the PageTypeMap default).
     *
     * @return IndexPage|null
     */
    public function pagesAfter(int $afterId, int $limit): ?array
    {
        $rows = $this->repository->pagesAfter($afterId, $limit);
        if ($rows === null) {
            return null;
        }

        $items = [];
        foreach ($rows as $row) {
            $pageType = $row['page_type'] === '' ? 'builder' : $row['page_type'];
            $kind     = PageTypeMap::PAGE_TYPE_TO_KIND[$pageType] ?? null;
            if ($kind === null) {
                continue;
            }
            $items[] = ['path' => CardUrlMap::frontendUrl($kind, $row['slug'])];
        }

        return [
            'items'      => $items,
            'next_after' => self::nextAfter(array_column($rows, 'id'), $limit),
        ];
    }

    /**
     * One keyset page of listable groups: bcc-core's explicitly OPEN groups,
     * kept only when GroupContextResolver can place them AND reads them open.
     *
     * The URL uses the resolver's own type (Hall → /halls/, otherwise
     * /communities/), so a group the app cannot route — disagreeing kind
     * rows, an unreadable kind — has no context and is not listed: every
     * advertised group URL is one the app serves.
     *
     * @return IndexPage|null
     */
    public function groupsAfter(int $afterId, int $limit): ?array
    {
        $rows = PeepSoGroupRepository::openGroupsAfter($afterId, $limit);
        if ($rows === null) {
            return null;
        }

        $contexts = $rows === [] ? [] : $this->groups->forManyGroups(array_column($rows, 'id'));

        $items = [];
        foreach ($rows as $row) {
            $ctx = $contexts[$row['id']] ?? null;
            if ($ctx === null || $ctx->privacy !== PeepSoPrivacy::Open) {
                continue;
            }
            $items[] = ['path' => CardUrlMap::groupUrl($ctx->type->value, $row['slug'])];
        }

        return [
            'items'      => $items,
            'next_after' => self::nextAfter(array_column($rows, 'id'), $limit),
        ];
    }

    /**
     * Of these SQL-passing members, the ones the usermeta checks keep, with
     * their handle. Null when the meta read cannot be trusted.
     *
     * @param list<int> $ids
     * @return array<int, string>|null
     */
    private function listableHandles(array $ids): ?array
    {
        if ($ids === []) {
            return [];
        }

        $meta = UserMetaRepository::firstValuesOrUnknown($ids, self::MEMBER_META_KEYS);
        if ($meta === null) {
            return null;
        }

        $out = [];
        foreach ($ids as $id) {
            $values = $meta[$id] ?? [];

            foreach (self::MODERATION_FLAG_KEYS as $flagKey) {
                if (isset($values[$flagKey]) && filter_var($values[$flagKey], FILTER_VALIDATE_BOOLEAN)) {
                    continue 2;
                }
            }

            $handle = $values[HandleService::META_HANDLE] ?? '';
            if (!self::isListableHandle($handle)) {
                continue;
            }

            $out[$id] = $handle;
        }

        return $out;
    }

    private static function isListableHandle(string $handle): bool
    {
        return preg_match(self::HANDLE_PATTERN, $handle) === 1
            && preg_match(self::FALLBACK_HANDLE_PATTERN, $handle) !== 1;
    }

    /**
     * The cursor for the next page: the last id scanned when the scan filled
     * the page (more may follow), null when it came back short (the end).
     *
     * @param list<int> $scannedIds
     */
    private static function nextAfter(array $scannedIds, int $limit): ?int
    {
        if (count($scannedIds) < $limit || $scannedIds === []) {
            return null;
        }

        return (int) $scannedIds[count($scannedIds) - 1];
    }
}
