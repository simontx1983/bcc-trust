<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Core\Repositories\PublicIndexRepository;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * May staff link this member as a validator's owner? Asked INSIDE the link's
 * run, so a typo cannot create an owner that blocks real claims and a
 * restricted account never receives page powers.
 *
 * Every read is fail-closed (anything not known to be fine refuses):
 *  - the account exists (wp_users, read strictly: a failed read refuses);
 *  - the suspension state is KNOWN not suspended (core's tri-state; an
 *    unknown state refuses);
 *  - the PeepSo role is not one that holds no member powers: awaiting email
 *    validation, awaiting approval, or banned (PublicIndexRepository's
 *    UNLISTED_ROLES, the same set PeepSo's own member listing excludes).
 *    PeepSo not loaded, or an unreadable role, refuses.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class ValidatorLinkTargetCheck
{
    public static function eligible(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            if (!ClaimRepository::accountExistsStrict($userId)) {
                return false;
            }
        } catch (RepositoryReadFailure) {
            return false;
        }
        if (\BCC\Core\Permissions\Permissions::suspensionState($userId, false) !== false) {
            return false;
        }
        if (!class_exists('PeepSoUser')) {
            return false;
        }
        try {
            $role = (string) \PeepSoUser::get_instance($userId)->get_user_role();
        } catch (\Throwable) {
            return false;
        }

        return $role !== '' && !in_array($role, PublicIndexRepository::UNLISTED_ROLES, true);
    }
}
