<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Support\PublicIdentity;
use BCC\Trust\Onchain\Repositories\AdminValidatorRepository as Reads;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Repositories\ValidatorRelationshipLogRepository as Log;
use BCC\Trust\Onchain\Services\ValidatorClaimSupport;
use BCC\Trust\Onchain\Services\ValidatorRelationshipService;

/** Staff-only projections; never run a verifier, repair or relationship write. */
final class AdminValidatorReadService
{
    public const STATES = ['unclaimed', 'wallet_verified', 'staff_verified', 'conflict'];
    public const REVOKE_LABELS = [
        'linked_in_error' => 'Linked in error',
        'evidence_withdrawn' => 'Evidence withdrawn',
        'owner_requested' => 'Owner requested removal',
        'ownership_disputed' => 'Ownership disputed',
    ];

    /** @return array<string,mixed> */
    public static function directory(string $chain, string $state, string $query, int $after, int $limit): array
    {
        $bindings = Reads::bindings();
        $pages = [];
        foreach ($bindings as $binding) {
            $id = (int) $binding['page_id'];
            $pages[$id][] = $binding;
        }
        $owners = ClaimRepository::ownerClaimsForPagesStrict(array_keys($pages));
        $items = [];
        $facets = [];
        $totalCounts = array_fill_keys(self::STATES, 0);
        foreach ($pages as $id => $rows) {
            $pageState = self::state($owners[$id] ?? []);
            $totalCounts[$pageState]++;
            $pageChains = [];
            foreach ($rows as $row) {
                $slug = (string) $row['chain_slug'];
                if (isset($pageChains[$slug])) {
                    continue;
                }
                $pageChains[$slug] = $row;
                if (!isset($facets[$slug])) {
                    $facets[$slug] = ['slug' => $slug, 'name' => (string) $row['chain_name'],
                        'wallet_claim_possible' => self::claimable($row), 'counts' => array_fill_keys(self::STATES, 0)];
                }
                $facets[$slug]['counts'][$pageState]++;
            }
            if ($id <= $after || ($chain !== '' && !isset($pageChains[$chain])) || ($state !== '' && $state !== $pageState)) {
                continue;
            }
            $first = $chain !== '' ? $pageChains[$chain] : $rows[0];
            $addresses = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['operator_address'], $rows)));
            if ($query !== '' && stripos((string) $first['title'] . ' ' . implode(' ', $addresses), $query) === false) {
                continue;
            }
            $items[] = ['page_id' => $id, 'title' => (string) $first['title'], 'slug' => (string) $first['slug'],
                'chain' => ['slug' => (string) $first['chain_slug'], 'name' => (string) $first['chain_name']],
                'operator_addresses' => $addresses, 'state' => $pageState,
                'wallet_claim_possible' => self::claimable($first)];
        }
        $hasMore = count($items) > $limit;
        $items = array_slice($items, 0, $limit);
        $userIds = [];
        $claimIds = [];
        foreach ($items as $item) {
            foreach ($owners[$item['page_id']] ?? [] as $claim) {
                $userIds[] = $claim['user_id'];
                $claimIds[] = $claim['claim_id'];
            }
        }
        $users = self::users($userIds);
        $claimRows = Reads::claims($claimIds);
        foreach ($items as &$item) {
            $claims = $owners[$item['page_id']] ?? [];
            $bindings = $pages[$item['page_id']];
            self::verifyBindings($bindings, $claims, $claimRows);
            $summary = self::ownership($claims, $claimRows, $users);
            $item['owner'] = $summary['owner'];
            $covered = [];
            foreach ($claims as $claim) {
                $covered[(int) $claimRows[$claim['claim_id']]['entity_id']] = true;
            }
            $uncovered = [];
            foreach ($bindings as $binding) {
                $validatorId = (int) $binding['validator_id'];
                if (!isset($covered[$validatorId])) {
                    $uncovered[$validatorId] = true;
                }
            }
            $item['uncovered_validator_count'] = count($uncovered);
        }
        unset($item);
        $last = $items[count($items) - 1] ?? null;
        return ['items' => $items, 'pagination' => ['has_more' => $hasMore,
            'next_cursor' => $hasMore && $last !== null ? (string) $last['page_id'] : null],
            'chains' => array_values($facets), 'total_counts' => $totalCounts];
    }

    /**
     * null means a successful read found no validator page.
     * @return array<string,mixed>|null
     */
    public static function relationships(int $pageId): ?array
    {
        $bindings = Reads::bindings($pageId);
        if ($bindings === []) {
            return null;
        }
        $claims = ClaimRepository::ownerClaimsForPageStrict($pageId, false);
        $claimRows = Reads::claims(array_column($claims, 'claim_id'));
        self::verifyBindings($bindings, $claims, $claimRows);
        $users = self::users(array_column($claims, 'user_id'));
        $summary = self::ownership($claims, $claimRows, $users);
        $first = $bindings[0];
        $owner = $summary['owner'];
        if ($owner !== null) {
            $claim = $summary['claim'];
            if ($claim === null) {
                throw new RepositoryReadFailure(self::class, __FUNCTION__, 'owner_details_missing');
            }
            $staff = $owner['locked_for_staff'] === false;
            $event = $staff ? Reads::linkEvent($pageId, (int) $claim['user_id'], (int) $claim['entity_id']) : null;
            $payload = $event === null ? [] : self::payload($event['payload'] ?? null);
            $actorId = $event === null ? 0 : (int) ($event['actor_user_id'] ?? 0);
            $actors = self::users($actorId > 0 ? [$actorId] : []);
            $owner += [
                'link_id' => (int) $claim['id'],
                'linked_by' => $staff ? ($actors[$actorId] ?? null) : null,
                'linked_at' => self::iso($event['created_at'] ?? $claim['verified_at'] ?? null),
                'evidence_type' => $staff && is_string($payload['evidence_type'] ?? null) ? $payload['evidence_type'] : null,
                'evidence_note' => $staff && is_string($event['evidence_note'] ?? null) ? $event['evidence_note'] : null,
                'evidence_ref' => $staff && is_string($event['evidence_ref'] ?? null) ? $event['evidence_ref'] : null,
                'cancellable' => $staff,
            ];
        }
        return ['page' => ['page_id' => $pageId, 'title' => (string) $first['title'], 'slug' => (string) $first['slug'],
            'chain' => ['slug' => (string) $first['chain_slug'], 'name' => (string) $first['chain_name']]],
            'state' => $summary['state'], 'owner' => $owner, 'conflict_users' => $summary['conflicts'],
            'revoke_reasons' => array_map(static fn (string $key): array => ['key' => $key, 'label' => self::REVOKE_LABELS[$key]], ValidatorRelationshipService::STAFF_REVOKE_REASONS)];
    }

    /** @return array<string,mixed> */
    public static function history(?int $pageId, ?int $userId, ?int $before, int $limit): array
    {
        $rows = Log::history($pageId, $userId, $before, $limit + 1);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $userIds = [];
        $pageIds = [];
        foreach ($rows as $row) {
            $userIds[] = (int) $row->subject_user_id;
            $userIds[] = (int) $row->actor_user_id;
            $pageIds[] = (int) $row->page_id;
        }
        $users = self::users($userIds);
        $titles = Reads::pageTitles($pageIds);
        $items = [];
        foreach ($rows as $row) {
            $payload = self::payload($row->payload);
            $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : null;
            $items[] = ['id' => (int) $row->id, 'created_at' => self::iso($row->created_at),
                'action' => match ($row->action) {'owner_linked' => 'linked', 'owner_revoked' => 'revoked', 'owner_link_cancelled' => 'cancelled', default => $row->action},
                'subject' => $users[(int) $row->subject_user_id] ?? null, 'actor' => $users[(int) $row->actor_user_id] ?? null,
                'page' => ['page_id' => (int) $row->page_id, 'title' => $titles[(int) $row->page_id] ?? 'Deleted page'],
                'prev' => self::snapshot($payload['prev'] ?? null), 'new' => self::snapshot($payload['new'] ?? null),
                'reason' => $reason, 'reason_label' => $reason === null ? null : (self::REVOKE_LABELS[$reason] ?? str_replace('_', ' ', $reason)),
                'evidence_note' => $row->evidence_note, 'evidence_ref' => $row->evidence_ref,
                'step_up' => $payload['step_up'] ?? null];
        }
        $last = $rows[count($rows) - 1] ?? null;
        return ['items' => $items, 'pagination' => ['has_more' => $hasMore, 'next_cursor' => $hasMore && $last !== null ? (string) $last->seq : null]];
    }

    /** @return array<string,mixed> */
    public static function searchUsers(string $query): array
    {
        return ['items' => array_values(self::users(Reads::searchUserIds($query)))];
    }

    /** @return array<string,mixed>|null */
    public static function health(): ?array
    {
        $health = Reads::logHealth();
        if ($health === null || !is_bool($health['chain_ok'] ?? null) || !is_string($health['checked_at'] ?? null)
            || !is_int($health['rows_checked'] ?? null) || !is_int($health['head_seq'] ?? null)
            || $health['rows_checked'] < 0 || $health['head_seq'] < 0 || self::iso($health['checked_at']) === '') {
            return null;
        }
        // A warning or incomplete verdict must never be painted as a verified chain.
        return ['chain_ok' => $health['chain_ok'] && ($health['status'] ?? null) === 'ok',
            'checked_at' => self::iso($health['checked_at']), 'rows_checked' => $health['rows_checked'], 'head_seq' => $health['head_seq']];
    }

    /** @param list<int> $ids
     * @return array<int,array{user_id:int,handle:string,display_name:string,avatar_url:string|null}> */
    private static function users(array $ids): array
    {
        $out = [];
        foreach (Reads::users(array_values(array_filter($ids, static fn (int $id): bool => $id > 0))) as $id => $row) {
            $handle = PublicIdentity::handle($id, $row['handle'] ?? null);
            $avatar = get_avatar_url($id);
            $out[$id] = ['user_id' => $id, 'handle' => $handle,
                'display_name' => PublicIdentity::displayName($row['display_name'] ?? null, $row['user_login'] ?? null, $handle),
                'avatar_url' => is_string($avatar) ? $avatar : null];
        }
        return $out;
    }

    /**
     * @param list<array{claim_id:int,user_id:int,verification_method:string}> $claims
     * @param array<int,array<string,mixed>> $claimRows
     * @param array<int,array{user_id:int,handle:string,display_name:string,avatar_url:string|null}> $users
     * @return array{state:string,owner:array<string,mixed>|null,claim:array<string,mixed>|null,conflicts:list<array<string,mixed>>}
     */
    private static function ownership(array $claims, array $claimRows, array $users): array
    {
        $byUser = [];
        foreach ($claims as $claim) {
            $details = $claimRows[$claim['claim_id']] ?? null;
            $user = $users[$claim['user_id']] ?? null;
            if ($details === null || $user === null) {
                throw new RepositoryReadFailure(self::class, __FUNCTION__, 'owner_reference_missing');
            }
            $method = $claim['verification_method'];
            $locked = ValidatorRelationshipService::lockedForStaff($method);
            $prior = $byUser[$claim['user_id']] ?? null;
            if ($prior !== null && ($prior['locked'] || !$locked)) {
                continue;
            }
            $byUser[$claim['user_id']] = ['user' => $user, 'verification' => [
                'kind' => $method === 'staff' ? 'staff' : ($method === 'solana_identity' ? 'node_key' : 'wallet'),
                'method' => $method, 'label' => $method === 'staff' ? 'Verified by BCC' : ($method === 'solana_identity' ? 'Node Key Verified' : 'Wallet Verified'),
                'verified_at' => self::iso($details['verified_at'] ?? null)], 'locked' => $locked, 'claim' => $details];
        }
        if (count($byUser) > 1) {
            return ['state' => 'conflict', 'owner' => null, 'claim' => null,
                'conflicts' => array_values(array_map(static fn (array $entry): array => ['user' => $entry['user'], 'verification' => $entry['verification']], $byUser))];
        }
        $entry = array_values($byUser)[0] ?? null;
        return ['state' => $entry === null ? 'unclaimed' : ($entry['locked'] ? 'wallet_verified' : 'staff_verified'),
            'owner' => $entry === null ? null : ['user' => $entry['user'], 'verification' => $entry['verification'], 'locked_for_staff' => $entry['locked']],
            'claim' => $entry['claim'] ?? null, 'conflicts' => []];
    }

    /** @param list<array{claim_id:int,user_id:int,verification_method:string}> $claims */
    private static function state(array $claims): string
    {
        $users = [];
        $locked = false;
        foreach ($claims as $claim) {
            $users[$claim['user_id']] = true;
            $locked = $locked || ValidatorRelationshipService::lockedForStaff($claim['verification_method']);
        }
        return count($users) > 1 ? 'conflict' : ($users === [] ? 'unclaimed' : ($locked ? 'wallet_verified' : 'staff_verified'));
    }

    /**
     * @param list<array<string,mixed>> $bindings
     * @param list<array{claim_id:int,user_id:int,verification_method:string}> $claims
     * @param array<int,array<string,mixed>> $details
     */
    private static function verifyBindings(array $bindings, array $claims, array $details): void
    {
        $ids = array_map(static fn (array $row): int => (int) $row['validator_id'], $bindings);
        foreach ($claims as $claim) {
            $detail = $details[$claim['claim_id']] ?? null;
            if ($detail === null || ($detail['entity_type'] ?? null) !== 'validator'
                || ($detail['status'] ?? null) !== 'verified' || ($detail['claim_role'] ?? null) !== 'operator'
                || (int) ($detail['user_id'] ?? 0) !== $claim['user_id']
                || ($detail['verification_method'] ?? null) !== $claim['verification_method']
                || !in_array((int) ($detail['entity_id'] ?? 0), $ids, true)) {
                throw new RepositoryReadFailure(self::class, __FUNCTION__, 'owner_binding_mismatch');
            }
        }
    }

    /** @param array<string,mixed> $binding */
    private static function claimable(array $binding): bool
    {
        return ValidatorClaimSupport::assess((string) $binding['chain_type'], (string) $binding['chain_slug'], (string) $binding['chain_name'], (string) $binding['operator_address'])['claimable'];
    }

    /** @return array<string,mixed> */
    private static function payload(mixed $raw): array
    {
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new RepositoryReadFailure(self::class, __FUNCTION__, 'invalid_log_payload');
        }
        return $data;
    }

    /** @return array{role:string|null,status:string|null,method:string|null} */
    private static function snapshot(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];
        return ['role' => $data === [] ? null : 'owner', 'status' => $data === [] ? null : 'verified',
            'method' => is_string($data['method'] ?? null) ? $data['method'] : null];
    }

    private static function iso(mixed $raw): string
    {
        $ts = is_string($raw) && $raw !== '' ? strtotime($raw . ' UTC') : false;
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
