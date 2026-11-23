<?php

namespace BCC\Trust\Onchain\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The ONE answer to "what does this claim role mean on a page, and what may
 * it do there?" — which roles OWN a page, which are TEAM roles, and the
 * permission decision for each.
 *
 * Pure: no I/O, no globals. Callers read the viewer's ACTIVE page row (the
 * live relationship, never a cached decision) and the page's stored team
 * settings, and pass both in.
 *
 * ## D1: a team row is never the owner
 *
 * Every reader of `entity_type = 'page'` claim rows names the roles it
 * reads, from this class. A team row (administrator, representative) never
 * makes a page "claimed", never makes its holder "the claimer", never earns
 * the owner bonus and never holds an owner-only power.
 *
 * ## The decision, in order
 *
 *  1. An unknown action is a programming error and throws.
 *  2. OWNER-ONLY powers (LOCKED) are code, not data: manage administrators,
 *     transfer ownership, remove the validator link, change the
 *     permissions. Checked first; true for the owner and false for every
 *     other role, whatever the settings hold.
 *  3. TEAM_SELF actions (leave the team, choose public visibility) belong to
 *     team members only; the owner can do neither.
 *  4. DELEGABLE powers: the owner holds all of them. A team role holds the
 *     ones the owner's settings grant it, Phillip's defaults where the owner
 *     has changed nothing. Settings can only switch DELEGABLE keys; anything
 *     else stored is ignored. Settings that could not be READ (null) grant
 *     NOTHING: never the defaults, because the defaults grant things.
 *  5. A delegator-reaching power (announcements, operator messages, feed
 *     posts) needs a member added (or since re-confirmed) with a password
 *     re-confirmation, so granting it to a role later never bypasses the
 *     add-time step-up.
 *
 * The byline, the owner's alerts, invite acceptance, the notice hold and the
 * step-ups are guardrails in code. They are not keys and settings cannot
 * name them.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class PageClaimRolePolicy
{
    public const ROLE_OPERATOR       = 'operator';
    public const ROLE_CREATOR        = 'creator';
    public const ROLE_HOLDER         = 'holder';
    public const ROLE_ADMINISTRATOR  = 'administrator';
    public const ROLE_REPRESENTATIVE = 'representative';

    /** Roles whose page row means "this user owns the page". */
    public const OWNER_ROLES = [self::ROLE_OPERATOR, self::ROLE_CREATOR];

    /** Page-level team roles: non-exclusive, never ownership. */
    public const TEAM_ROLES = [self::ROLE_ADMINISTRATOR, self::ROLE_REPRESENTATIVE];

    /**
     * Every role a validator/collection ENTITY claim row can carry. Team
     * roles exist only on page rows, so they are never read here.
     */
    public const ENTITY_CLAIM_ROLES = [self::ROLE_OPERATOR, self::ROLE_CREATOR, self::ROLE_HOLDER];

    // ── Delegable powers: the owner switches these per team role ────────
    public const POWER_PUBLISH_ANNOUNCEMENTS       = 'publish_announcements';
    public const POWER_REPLY_REVIEWS               = 'reply_reviews';
    public const POWER_REPLY_MESSAGES              = 'reply_messages';
    public const POWER_POST_FEED                   = 'post_feed';
    public const POWER_EDIT_PROFILE                = 'edit_profile';
    public const POWER_EDIT_LINKS                  = 'edit_links';
    public const POWER_EDIT_COMMUNITY              = 'edit_community';
    public const POWER_EDIT_NOTIFICATIONS          = 'edit_notifications';
    public const POWER_MANAGE_REPRESENTATIVES      = 'manage_representatives';
    public const POWER_MESSAGE_HISTORY_BEFORE_JOIN = 'message_history_before_join';
    public const POWER_LINKS_UNRESTRICTED          = 'links_unrestricted';

    /** @var list<string> the closed allowlist of keys a settings grid may switch */
    public const DELEGABLE = [
        self::POWER_PUBLISH_ANNOUNCEMENTS,
        self::POWER_REPLY_REVIEWS,
        self::POWER_REPLY_MESSAGES,
        self::POWER_POST_FEED,
        self::POWER_EDIT_PROFILE,
        self::POWER_EDIT_LINKS,
        self::POWER_EDIT_COMMUNITY,
        self::POWER_EDIT_NOTIFICATIONS,
        self::POWER_MANAGE_REPRESENTATIVES,
        self::POWER_MESSAGE_HISTORY_BEFORE_JOIN,
        self::POWER_LINKS_UNRESTRICTED,
    ];

    /**
     * Powers that reach the validator's delegators. A member holds them only
     * when added (or since re-confirmed) with a password re-confirmation.
     *
     * @var list<string>
     */
    public const DELEGATOR_REACHING = [
        self::POWER_PUBLISH_ANNOUNCEMENTS,
        self::POWER_REPLY_MESSAGES,
        self::POWER_POST_FEED,
    ];

    // ── Owner-only powers: code, never data ─────────────────────────────
    public const LOCKED_MANAGE_ADMINISTRATORS = 'manage_administrators';
    public const LOCKED_TRANSFER_OWNERSHIP    = 'transfer_ownership';
    public const LOCKED_REMOVE_VALIDATOR_LINK = 'remove_validator_link';
    public const LOCKED_CHANGE_PERMISSIONS    = 'change_permissions';

    /** @var list<string> */
    public const LOCKED = [
        self::LOCKED_MANAGE_ADMINISTRATORS,
        self::LOCKED_TRANSFER_OWNERSHIP,
        self::LOCKED_REMOVE_VALIDATOR_LINK,
        self::LOCKED_CHANGE_PERMISSIONS,
    ];

    // ── Team members' own choices ───────────────────────────────────────
    public const SELF_LEAVE_TEAM     = 'leave_team';
    public const SELF_SET_VISIBILITY = 'set_visibility';

    /** @var list<string> */
    public const TEAM_SELF = [self::SELF_LEAVE_TEAM, self::SELF_SET_VISIBILITY];

    /**
     * Phillip's defaults (2026-11-23), used for a team role whose powers the
     * owner has not changed.
     *
     * @var array{administrator: array<string, bool>, representative: array<string, bool>}
     */
    public const DEFAULTS = [
        self::ROLE_ADMINISTRATOR => [
            self::POWER_PUBLISH_ANNOUNCEMENTS       => true,
            self::POWER_REPLY_REVIEWS               => true,
            self::POWER_REPLY_MESSAGES              => true,
            self::POWER_POST_FEED                   => true,
            self::POWER_EDIT_PROFILE                => true,
            self::POWER_EDIT_LINKS                  => true,
            self::POWER_EDIT_COMMUNITY              => true,
            self::POWER_EDIT_NOTIFICATIONS          => true,
            self::POWER_MANAGE_REPRESENTATIVES      => true,
            self::POWER_MESSAGE_HISTORY_BEFORE_JOIN => false,
            self::POWER_LINKS_UNRESTRICTED          => false,
        ],
        self::ROLE_REPRESENTATIVE => [
            self::POWER_PUBLISH_ANNOUNCEMENTS       => true,
            self::POWER_REPLY_REVIEWS               => true,
            self::POWER_REPLY_MESSAGES              => true,
            self::POWER_POST_FEED                   => false,
            self::POWER_EDIT_PROFILE                => false,
            self::POWER_EDIT_LINKS                  => false,
            self::POWER_EDIT_COMMUNITY              => false,
            self::POWER_EDIT_NOTIFICATIONS          => false,
            self::POWER_MANAGE_REPRESENTATIVES      => false,
            self::POWER_MESSAGE_HISTORY_BEFORE_JOIN => false,
            self::POWER_LINKS_UNRESTRICTED          => false,
        ],
    ];

    public static function isOwnerRole(?string $role): bool
    {
        return $role !== null && in_array($role, self::OWNER_ROLES, true);
    }

    public static function isTeamRole(?string $role): bool
    {
        return $role !== null && in_array($role, self::TEAM_ROLES, true);
    }

    /**
     * May a viewer holding $role on a page take $action there?
     *
     * @param string|null $role       claim_role of the viewer's ACTIVE page row, or null for none
     * @param array<mixed>|null $settings the page's stored team settings as read
     *        (role => [power => bool]); [] when nothing is stored; NULL when the
     *        read failed, which grants a team role nothing
     * @param bool $reconfirmed the member was added, or since re-confirmed,
     *        with a password re-confirmation (ignored for the owner)
     *
     * @throws \InvalidArgumentException for an action this class does not know
     */
    public static function may(?string $role, string $action, ?array $settings, bool $reconfirmed): bool
    {
        if (in_array($action, self::LOCKED, true)) {
            return self::isOwnerRole($role);
        }
        if (in_array($action, self::TEAM_SELF, true)) {
            return self::isTeamRole($role);
        }
        if (!in_array($action, self::DELEGABLE, true)) {
            throw new \InvalidArgumentException('Unknown page action: ' . $action);
        }

        if (self::isOwnerRole($role)) {
            return true;
        }
        if ($role !== self::ROLE_ADMINISTRATOR && $role !== self::ROLE_REPRESENTATIVE) {
            return false;
        }
        if ($settings === null) {
            return false; // unreadable settings: fail closed, never the defaults
        }
        if (!$reconfirmed && in_array($action, self::DELEGATOR_REACHING, true)) {
            return false;
        }

        return self::effective($settings)[$role][$action];
    }

    /**
     * The effective grid for both team roles: the defaults, overridden only
     * by stored DELEGABLE keys holding a real boolean. Unknown roles, unknown
     * keys, locked keys and non-boolean values are ignored.
     *
     * @param array<mixed> $settings
     * @return array{administrator: array<string, bool>, representative: array<string, bool>}
     */
    public static function effective(array $settings): array
    {
        $out = self::DEFAULTS;
        foreach (self::TEAM_ROLES as $teamRole) {
            $stored = $settings[$teamRole] ?? null;
            if (!is_array($stored)) {
                continue;
            }
            foreach (self::DELEGABLE as $power) {
                if (array_key_exists($power, $stored) && is_bool($stored[$power])) {
                    $out[$teamRole][$power] = $stored[$power];
                }
            }
        }

        return $out;
    }

    /**
     * Validate an owner's full settings grid (the PUT body). Both team roles,
     * every DELEGABLE key, booleans only; anything else is refused with the
     * offending field, so a locked power can never be written.
     *
     * @return array{administrator: array<string, bool>, representative: array<string, bool>}
     * @throws \InvalidArgumentException with the offending field as the message
     */
    public static function parseGrid(mixed $grid): array
    {
        if (!is_array($grid)) {
            throw new \InvalidArgumentException('roles');
        }
        foreach (array_keys($grid) as $teamRole) {
            if (!in_array($teamRole, self::TEAM_ROLES, true)) {
                throw new \InvalidArgumentException('roles.' . $teamRole);
            }
        }
        $out = self::DEFAULTS;
        foreach (self::TEAM_ROLES as $teamRole) {
            $row = $grid[$teamRole] ?? null;
            if (!is_array($row)) {
                throw new \InvalidArgumentException('roles.' . $teamRole);
            }
            foreach ($row as $power => $value) {
                if (!in_array($power, self::DELEGABLE, true)) {
                    throw new \InvalidArgumentException('roles.' . $teamRole . '.' . $power);
                }
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('roles.' . $teamRole . '.' . $power);
                }
            }
            foreach (self::DELEGABLE as $power) {
                if (!array_key_exists($power, $row)) {
                    throw new \InvalidArgumentException('roles.' . $teamRole . '.' . $power);
                }
                $out[$teamRole][$power] = (bool) $row[$power];
            }
        }

        return $out;
    }

    /**
     * May $actorRole invite someone into $targetRole? Administrators are the
     * owner's alone; representatives follow manage_representatives. Never
     * into ownership.
     *
     * @param array<mixed>|null $settings
     */
    public static function mayInvite(?string $actorRole, string $targetRole, ?array $settings, bool $actorReconfirmed): bool
    {
        return match ($targetRole) {
            self::ROLE_ADMINISTRATOR  => self::may($actorRole, self::LOCKED_MANAGE_ADMINISTRATORS, $settings, $actorReconfirmed),
            self::ROLE_REPRESENTATIVE => self::may($actorRole, self::POWER_MANAGE_REPRESENTATIVES, $settings, $actorReconfirmed),
            default                   => false,
        };
    }

    /**
     * May $actorRole remove (or cancel the invite of) a member in
     * $targetRole? Same rule as inviting; an owner is never removed this way.
     *
     * @param array<mixed>|null $settings
     */
    public static function mayRemove(?string $actorRole, string $targetRole, ?array $settings, bool $actorReconfirmed): bool
    {
        return self::mayInvite($actorRole, $targetRole, $settings, $actorReconfirmed);
    }

    /**
     * Does adding someone in $targetRole need the adder's password? Yes when
     * the role holds any delegator-reaching power under these settings, and
     * always when the settings could not be read.
     *
     * @param array<mixed>|null $settings
     */
    public static function requiresPasswordToInvite(string $targetRole, ?array $settings): bool
    {
        if (!self::isTeamRole($targetRole) || $settings === null) {
            return true;
        }
        $grid = self::effective($settings);
        foreach (self::DELEGATOR_REACHING as $power) {
            if ($grid[$targetRole][$power]) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `can` object for this viewer: every delegable power plus the team
     * actions, so the client renders from this and never derives the matrix.
     *
     * @param array<mixed>|null $settings
     * @return array<string, bool|array{administrator: bool, representative: bool}>
     */
    public static function capabilities(?string $role, ?array $settings, bool $reconfirmed): array
    {
        $can = [];
        foreach (self::DELEGABLE as $power) {
            $can[$power] = self::may($role, $power, $settings, $reconfirmed);
        }
        $can['invite_administrator']  = self::mayInvite($role, self::ROLE_ADMINISTRATOR, $settings, $reconfirmed);
        $can['invite_representative'] = self::mayInvite($role, self::ROLE_REPRESENTATIVE, $settings, $reconfirmed);
        $can['remove']                = [
            'administrator'  => self::mayRemove($role, self::ROLE_ADMINISTRATOR, $settings, $reconfirmed),
            'representative' => self::mayRemove($role, self::ROLE_REPRESENTATIVE, $settings, $reconfirmed),
        ];
        $can['leave']          = self::may($role, self::SELF_LEAVE_TEAM, $settings, $reconfirmed);
        $can['set_visibility'] = self::may($role, self::SELF_SET_VISIBILITY, $settings, $reconfirmed);

        return $can;
    }
}
