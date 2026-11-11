<?php
/**
 * The `{allowed, unlock_hint, reason_code}` triple — api-contract-v1.md
 * §2.1 `CardPermissionEntry` — as one producer instead of fifteen.
 *
 * ── WHY THIS CLASS EXISTS (§11) ──────────────────────────────────────────
 * `docs/pattern-registry.md` already names the canonical producer:
 *
 *     **Permission shape** (`{allowed, unlock_hint, reason_code}`) →
 *     `CardViewService::allow()` / `CardViewService::deny()`
 *
 * But both are **`private static`**, so nothing outside that one class can
 * reach them. The result is measurable: at the revision this file was
 * added the triple is open-coded across **fourteen** files —
 * `GroupsService` (28 occurrences), `AttestationService` (28),
 * `MessagesService` (16), `CardViewService` (15), `EndorsementService`
 * (10), `UserGroupsEndpoint` (10), `UserViewService` (6),
 * `MemberProfileComposer` (4), and six more with one or two each.
 *
 * The registry's claim was therefore aspirational rather than true. This
 * class makes it true by **extending** the named canonical producer
 * instead of adding a fifteenth copy: `CardViewService::allow()` and
 * `::deny()` survive as thin forwarders, so every existing call site is
 * untouched and the registry entry still resolves to a real method.
 *
 * ⚠ **This is deliberately NOT a sweep of the other thirteen.** Rewriting
 * ~130 call sites across eight services is a mechanical change with no
 * behavioural content, and bundling it into a feature branch would bury
 * the feature. The extraction is here, the delegation proves it is
 * equivalent, and the migration is a separate reviewable change.
 *
 * ── WHY A FACTORY AND NOT A VALUE OBJECT ─────────────────────────────────
 * The triple is serialized straight onto the wire by `ApiResponse`, and
 * the contract pins it as a JSON object with exactly these three keys. A
 * `Permission` object would need a `toArray()` at every one of ~130 sites
 * and would buy nothing the array does not already give: the shape is
 * pinned by PHPStan through the `Entry` alias below, and PHPStan level 8
 * rejects a missing or mistyped key.
 *
 * Pure by construction — no WordPress, no PeepSo, no DB, no statics with
 * state — so it is unit-testable with no bootstrap at all.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type Entry array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
 */
final class Permission
{
    /**
     * Granted. Carries no hint and no reason — a reason on an allowed
     * entry is a contradiction the client would have to decide how to
     * render, so the shape forbids expressing one.
     *
     * @return Entry
     */
    public static function allow(): array
    {
        return ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    }

    /**
     * Refused.
     *
     * `$reasonCode` is **required** and `$unlockHint` is not, which is the
     * asymmetry the contract asks for: §2.1 lets the hint be `null` when
     * there is no action the viewer could take to unlock the control (an
     * archived announcement can never be edited again), while a denial
     * with no reason code gives the client nothing to key its copy on and
     * forces it to invent one.
     *
     * @return Entry
     */
    public static function deny(string $reasonCode, ?string $unlockHint = null): array
    {
        return ['allowed' => false, 'unlock_hint' => $unlockHint, 'reason_code' => $reasonCode];
    }

    /**
     * Read an entry the way a client must: a missing map, a missing key,
     * a non-array entry or a non-`true` `allowed` all collapse to
     * `false`.
     *
     * ⚠ `=== true`, not truthy. `"false"`, `1`, `"1"` and `[]` are all
     * truthy or castable in PHP, and a capability map that arrived over a
     * wire — from a cache, a filter, a decoded payload — can hold any of
     * them. §4.32.11 requires absence AND malformation to fail closed, and
     * a loose comparison would grant on `"0"` being... well, it would not,
     * but it would grant on `"no"`, which is worse than any of them.
     *
     * Provided so the fail-closed read is written once rather than at
     * every consumer, and so a test can prove the hostile cases.
     *
     * @param mixed $capabilities
     */
    public static function isAllowed($capabilities, string $key): bool
    {
        if (!is_array($capabilities)) {
            return false;
        }
        $entry = $capabilities[$key] ?? null;
        if (!is_array($entry)) {
            return false;
        }
        return ($entry['allowed'] ?? null) === true;
    }
}
