<?php
/**
 * The `Group` wire shape and the validator card's `delegator_group` block.
 *
 * SINGLE SOURCE for both, so the create response, the conflict body, the edit
 * response and the card can't drift. Read-only.
 *
 * Works from `ValidatorCardRow`s, so a group's validator and chain always come
 * from the database row bound to that page.
 *
 * ⚠ No operator address leaves through here. A valoper converts to the operator's
 * account address, and this block is on an anonymous surface. See
 * docs/wallet-privacy-policy.md.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Support\CardUrlMap;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Onchain\Repositories\ValidatorGroupRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\REST\ValidatorGroupsEndpoint;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type ValidatorCardRow from ValidatorRepository
 * @phpstan-type DelegatorGroup array{id: int, slug: string, url: string, min_stake: string, min_stake_display: string|null, validator: array{id: int, name: string, chain_slug: string, chain_name: string}}
 */
final class DelegatorGroupView {

    /** The only chain type the delegation gate can verify. */
    private const SUPPORTED_CHAIN_TYPE = 'cosmos';

    /**
     * The block for one validator card.
     *
     * @param list<object>|null $rows Page's rows from the prefetch bundle, or
     *                                null to read them (single-card path).
     * @param array<int, array<string, mixed>>|null $groupsByPage Bundle's map,
     *                                or null to read it (single-card path).
     * @phpstan-param list<ValidatorCardRow>|null $rows
     * @phpstan-param array<int, DelegatorGroup>|null $groupsByPage
     * @return array{
     *     group: array{id: int, slug: string, url: string, min_stake: string, min_stake_display: string|null, validator: array{id: int, name: string, chain_slug: string, chain_name: string}}|null,
     *     can_create: bool,
     *     can_edit_min_stake: bool,
     *     options: list<array{validator_id: int, name: string, chain_slug: string, chain_name: string, native_token: string|null}>,
     *     unavailable_reason: string|null
     * }
     */
    public static function forCard(int $pageId, bool $viewerIsOperator, ?array $rows, ?array $groupsByPage): array {
        $rows ??= ValidatorRepository::findCardRowsByPageIds([$pageId])[$pageId] ?? [];
        $groupsByPage ??= self::groupsForPages([$pageId => $rows]);
        $group = $groupsByPage[$pageId] ?? null;

        $options     = [];
        $unsupported = [];
        foreach ($rows as $row) {
            if ((string) $row->chain_type !== self::SUPPORTED_CHAIN_TYPE) {
                $unsupported[] = (string) $row->chain_name;
                continue;
            }
            $options[] = [
                'validator_id' => (int) $row->validator_id,
                'name'         => self::validatorName((string) $row->moniker, $pageId),
                'chain_slug'   => (string) $row->chain_slug,
                'chain_name'   => (string) $row->chain_name,
                'native_token' => self::nativeToken($row),
            ];
        }

        return [
            'group'              => $group,
            'can_create'         => $viewerIsOperator && $options !== [] && $group === null,
            'can_edit_min_stake' => $viewerIsOperator && $group !== null,
            'options'            => $options,
            'unavailable_reason' => ($options === [] && $unsupported !== [])
                ? sprintf("Delegator groups aren't available on %s yet.", implode(', ', $unsupported))
                : null,
        ];
    }

    /**
     * The `Group` for one page, read fresh. Null when it has none.
     *
     * @phpstan-return DelegatorGroup|null
     * @return array{id: int, slug: string, url: string, min_stake: string, min_stake_display: string|null, validator: array{id: int, name: string, chain_slug: string, chain_name: string}}|null
     */
    public static function forPage(int $pageId): ?array {
        $rows = ValidatorRepository::findCardRowsByPageIds([$pageId])[$pageId] ?? [];

        return self::groupsForPages([$pageId => $rows])[$pageId] ?? null;
    }

    /**
     * Each page's community, in a flat three queries however many pages: the
     * reverse lookup, the meta cache warm, and the group rows. One query when
     * none of them has a community.
     *
     * @param array<int, list<object>> $rowsByPage
     * @phpstan-param array<int, list<ValidatorCardRow>> $rowsByPage
     * @phpstan-return array<int, DelegatorGroup>
     * @return array<int, array{id: int, slug: string, url: string, min_stake: string, min_stake_display: string|null, validator: array{id: int, name: string, chain_slug: string, chain_name: string}}>
     */
    public static function groupsForPages(array $rowsByPage): array {
        $validatorIds = [];
        foreach ($rowsByPage as $rows) {
            foreach ($rows as $row) {
                $validatorIds[] = (int) $row->validator_id;
            }
        }

        $groupByValidator = ValidatorGroupRepository::findGroupIdsForValidators($validatorIds);
        if ($groupByValidator === []) {
            return [];
        }

        $groupIds = array_values(array_unique($groupByValidator));
        $configs  = ValidatorGroupRepository::findManyByGroupIds($groupIds);
        $displays = PeepSoGroupRepository::findManyByIds($groupIds);

        $out = [];
        foreach ($rowsByPage as $pageId => $rows) {
            // Lowest id across the page's validators. One group per page is
            // the rule; a page bound to several validators can still carry
            // several from before that rule, and every reader must pick alike.
            $chosen    = null;
            $validator = null;
            foreach ($rows as $row) {
                $groupId = $groupByValidator[(int) $row->validator_id] ?? null;
                if ($groupId !== null && ($chosen === null || $groupId < $chosen)) {
                    $chosen    = $groupId;
                    $validator = $row;
                }
            }

            $display = $chosen !== null ? ($displays[$chosen] ?? null) : null;
            if ($chosen === null || $validator === null || $display === null) {
                continue;
            }

            $slug = (string) $display->post_name;
            $out[$pageId] = [
                'id'                => $chosen,
                'slug'              => $slug,
                'url'               => CardUrlMap::groupUrl(GroupType::Validator->value, $slug),
                'min_stake'         => ValidatorGroupRepository::readMinStake($chosen),
                'min_stake_display' => ValidatorGroupsEndpoint::formatMinStakeDisplay(
                    isset($configs[$chosen]) ? $configs[$chosen]->minStake : null,
                    self::nativeToken($validator)
                ),
                'validator'         => [
                    'id'         => (int) $validator->validator_id,
                    'name'       => self::validatorName((string) $validator->moniker, $pageId),
                    'chain_slug' => (string) $validator->chain_slug,
                    'chain_name' => (string) $validator->chain_name,
                ],
            ];
        }
        return $out;
    }

    /**
     * What each removed member's bell says, rendered when the bell is read.
     *
     * ⚠ NOT STORED, BECAUSE IT CAN'T BE. PeepSo's `not_message` is VARCHAR(200)
     * and `PeepSoNotifications::add_notification()` cuts it with substr(). The
     * fixed text of this notice is already 180 characters before a group title,
     * validator name, chain and minimum go in, so the stored form would lose its
     * last sentence every time, and could split a multibyte character. The row
     * stores a short fallback for a group that has since gone; this is the
     * notice. It reads the current minimum for the same reason the gate does.
     *
     * Flat: three queries for the whole bell page, however many rows are this
     * type.
     *
     * @param list<int> $groupIds
     * @return array<int, array{message: string, link: string}> keyed by group id.
     *         A group that can no longer be resolved is absent.
     */
    public static function removalNotices(array $groupIds): array {
        if ($groupIds === []) {
            return [];
        }

        $configs  = ValidatorGroupRepository::findManyByGroupIds($groupIds);
        $displays = PeepSoGroupRepository::findManyByIds($groupIds);
        $stats    = ValidatorRepository::findCommunityStatsByIds(array_map(
            static fn ($config): int => $config->validatorId,
            array_values($configs)
        ));

        $out = [];
        foreach ($configs as $groupId => $config) {
            $display = $displays[$groupId] ?? null;
            $row     = $stats[$config->validatorId] ?? null;
            if ($display === null || $row === null) {
                continue;
            }

            $name = trim((string) $row->moniker);
            if ($name === '') {
                $name = trim((string) $row->page_title);
            }
            $minimum = ValidatorGroupsEndpoint::formatMinStakeDisplay($config->minStake, $row->native_token);
            if ($name === '' || $minimum === null) {
                continue;
            }

            $out[$groupId] = [
                'message' => sprintf(
                    "You're no longer in %s, the delegator group for %s on %s. It requires at least %s delegated, and we couldn't confirm that from your linked wallet. You can rejoin any time once you meet it.",
                    (string) $display->post_title,
                    $name,
                    (string) $row->chain_name,
                    $minimum
                ),
                'link'    => CardUrlMap::groupUrl(GroupType::Validator->value, (string) $display->post_name),
            ];
        }
        return $out;
    }

    /**
     * The moniker, falling back to the page title.
     */
    private static function validatorName(string $moniker, int $pageId): string {
        $name = trim($moniker);
        if ($name !== '') {
            return $name;
        }

        $post = get_post($pageId);

        return $post instanceof \WP_Post ? trim($post->post_title) : '';
    }

    /**
     * @phpstan-param ValidatorCardRow $row
     */
    private static function nativeToken(object $row): ?string {
        $token = (string) ($row->native_token ?? '');

        return $token !== '' ? strtoupper($token) : null;
    }
}
