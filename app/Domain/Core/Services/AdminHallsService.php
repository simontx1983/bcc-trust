<?php
/**
 * Admin Halls (F-597, topic Halls step 3a): the reads and writes behind the
 * admin dashboard's Halls tab. The REST layer (AdminHallsEndpoint) gates
 * every call to site administrators; this service holds the rules.
 *
 *   - list: every published Hall, chain and topic alike, with the fields an
 *     admin edits and which of them this Hall allows;
 *   - create: a topic Hall, through HallProvisioningService::provisionTopic()
 *     (chain Halls are still created in wp-admin ▸ Chains);
 *   - update: a topic Hall's name, description and topic label; a chain
 *     Hall's description only (its name and chain come from the chain
 *     registry). A Hall's address never changes.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Core\PeepSo\PeepSoAddPostGuard;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\OnchainPlugin;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HallRepository;
use BCC\Trust\Onchain\Support\HallTopicRules;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type AdminHall array{
 *   id: int,
 *   slug: string,
 *   name: string,
 *   description: string,
 *   hall_kind: 'chain'|'topic'|null,
 *   topic: array{slug: string, label: string}|null,
 *   chain: string|null,
 *   member_count: int,
 *   editable: array{name: bool, description: bool, topic_label: bool},
 *   links: array{self: string}
 * }
 */
final class AdminHallsService
{
    /** Audit action for an edit. Fits VARCHAR(50). */
    public const AUDIT_UPDATED = 'admin_hall_updated';

    /**
     * Every published Hall, filtered as GET /halls is (kind, chain, name),
     * through the same reads, so the page and its total agree.
     *
     * @param 'chain'|'topic'|null $kind
     * @return array{items: list<AdminHall>, pagination: array{page: int, page_size: int, total: int, total_pages: int}}
     */
    public function list(int $page, int $pageSize, ?string $kind, ?string $chain, string $query): array
    {
        $payload = Plugin::instance()->hallsService()->getHalls(0, $page, $pageSize, $chain, $query, $kind);
        $ids     = array_map(static fn (array $item): int => (int) $item['id'], $payload['items']);

        return ['items' => $this->adminHalls($ids), 'pagination' => $payload['pagination']];
    }

    /**
     * Create a topic Hall. The provisioning result is passed through for the
     * REST layer to map; on `created` (or `incomplete`, where the Hall exists
     * but its ownership awaits repair) it carries the new Hall.
     *
     * @return array{status: string, group_id: int, message: string, failure_code: string|null, owner_id: int, audit_degraded: bool, hall: AdminHall|null}
     */
    public function create(string $name, string $topicSlug, string $topicLabel, string $description): array
    {
        $result = OnchainPlugin::instance()->hallProvisioningService()->provisionTopic($name, $topicSlug, $topicLabel, $description);

        $hall = null;
        if (in_array($result['status'], ['created', 'incomplete'], true) && $result['group_id'] > 0) {
            $hall = $this->adminHalls([$result['group_id']])[0] ?? null;
        }

        return [...$result, 'hall' => $hall];
    }

    /**
     * Edit a Hall's name, description or topic label. Values are trimmed and
     * checked by the same rules a topic Hall's create applies, and nothing is
     * written unless every change passes.
     *
     * The edit is recorded with a checked audit row. As HallProvisioningService
     * does, a correct edit whose audit row could not be written is kept and
     * reported (`audit_degraded`), never silently undone.
     *
     * @param array{name?: string, description?: string, topic_label?: string} $changes
     * @return array{hall: AdminHall, audit_degraded: bool}|array{error: string, message: string}
     */
    public function update(int $groupId, array $changes, int $adminId): array
    {
        $changes = array_map('trim', $changes);
        if ($changes === []) {
            return ['error' => 'bcc_invalid_request', 'message' => 'Send a name, description or topic_label to change.'];
        }

        $isHall = HallRepository::isHallOrUnknown($groupId);
        if ($isHall === false) {
            return ['error' => 'bcc_not_found', 'message' => 'Hall not found.'];
        }
        $facts = $isHall === null ? null : HallRepository::kindFactsForHalls([$groupId]);
        if ($facts === null) {
            return ['error' => 'bcc_unavailable', 'message' => "This Hall couldn't be read just now. Nothing was changed."];
        }
        $kind = $facts[$groupId]['hall_kind'] ?? null;

        $refused = self::refusedChange($kind, $changes);
        if ($refused !== null) {
            return ['error' => 'bcc_invalid_request', 'message' => $refused];
        }
        if (!class_exists('\\PeepSoGroup')) {
            return ['error' => 'bcc_unavailable', 'message' => "Halls can't be edited just now. Nothing was changed."];
        }

        try {
            $post = array_intersect_key($changes, ['name' => true, 'description' => true]);
            if ($post !== []) {
                // A PeepSo model write: `update()` runs wp_update_post().
                PeepSoAddPostGuard::pinOnly(
                    [], // an edit drives no post through the request
                    static function () use ($groupId, $post): bool {
                        /** @phpstan-ignore-next-line — PeepSo classes are runtime-only. */
                        (new \PeepSoGroup($groupId))->update($post);
                        return true;
                    }
                );
            }
            if (isset($changes['topic_label'])) {
                HallRepository::writeTopicLabel($groupId, $changes['topic_label']);
            }
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] admin Hall edit failed', ['group_id' => $groupId, 'error' => $e->getMessage()]);
            clean_post_cache($groupId);
            return ['error' => 'bcc_unavailable', 'message' => "The Hall couldn't be updated just now. Try again."];
        }

        $fields = array_keys($changes);
        sort($fields);
        $auditId = AuditLogger::logChecked(
            self::AUDIT_UPDATED,
            $groupId,
            ['group_id' => $groupId, 'hall_kind' => $kind ?? 'none', 'fields' => implode('|', $fields)],
            'hall',
            $adminId
        );
        if ($auditId === null) {
            Logger::error('[bcc-trust] admin Hall edited but its checked audit could not be written', ['group_id' => $groupId]);
        }

        clean_post_cache($groupId);

        $hall = $this->adminHalls([$groupId])[0] ?? null;
        if ($hall === null) {
            return ['error' => 'bcc_unavailable', 'message' => "The Hall was updated but couldn't be read back. Reload to see it."];
        }

        return ['hall' => $hall, 'audit_degraded' => $auditId === null];
    }

    /**
     * Why these changes are refused for a Hall of this kind, or null.
     *
     * @param array<string, string> $changes
     */
    public static function refusedChange(?string $kind, array $changes): ?string
    {
        $unknown = array_diff(array_keys($changes), ['name', 'description', 'topic_label']);
        if ($unknown !== []) {
            return 'Only name, description and topic_label can be changed.';
        }
        if ($kind === 'chain' && (isset($changes['name']) || isset($changes['topic_label']))) {
            return "A chain Hall's name comes from its chain; only its description can change here.";
        }
        if ($kind !== 'topic' && isset($changes['topic_label'])) {
            return 'This Hall has no topic to rename.';
        }

        return (isset($changes['name']) ? HallTopicRules::nameError($changes['name']) : null)
            ?? (isset($changes['description']) ? HallTopicRules::descriptionError($changes['description']) : null)
            ?? (isset($changes['topic_label']) ? HallTopicRules::labelError($changes['topic_label']) : null);
    }

    /**
     * Which fields an admin may change on a Hall of this kind.
     *
     * @return array{name: bool, description: bool, topic_label: bool}
     */
    public static function editable(?string $kind): array
    {
        return [
            'name'        => $kind !== 'chain',
            'description' => true,
            'topic_label' => $kind === 'topic',
        ];
    }

    /**
     * The admin view of each Hall, in the order given, in any post status.
     * Three bounded reads for the whole list; a Hall that no longer exists is
     * left out. An untrusted kind read leaves `hall_kind` / `topic` null, and
     * then only the description is editable.
     *
     * @param list<int> $groupIds
     * @return list<AdminHall>
     */
    private function adminHalls(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $rows   = PeepSoGroupRepository::findManyByIds($groupIds);
        $facts  = HallRepository::kindFactsForHalls($groupIds);
        $chains = ChainRepository::resolveSlugsForGroups($groupIds);

        $out = [];
        foreach ($groupIds as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $kind  = $facts === null ? null : ($facts[$id]['hall_kind'] ?? null);
            $out[] = [
                'id'           => $id,
                'slug'         => (string) $row->post_name,
                'name'         => (string) $row->post_title,
                'description'  => (string) $row->post_content,
                'hall_kind'    => $kind,
                'topic'        => $facts === null ? null : ($facts[$id]['topic'] ?? null),
                'chain'        => $chains[$id] ?? null,
                'member_count' => (int) $row->member_count,
                'editable'     => $facts === null
                    ? ['name' => false, 'description' => true, 'topic_label' => false]
                    : self::editable($kind),
                'links'        => ['self' => '/communities/' . $row->post_name],
            ];
        }

        return $out;
    }
}
