<?php
/**
 * One-shot: release the addresses that wallet sign-ups typed but never
 * verified (F-294).
 *
 * Why: a wallet sign-up used to write the typed email straight into
 * user_email, unverified. user_email is UNIQUE, so the address was reserved
 * against its real owner: they got "email already exists" at sign-up, and
 * F-285's reclaim deliberately never touches an account with a wallet.
 * Since F-294 a wallet sign-up keeps a placeholder in user_email and holds
 * the typed address pending (PendingEmailService::META_PENDING_SIGNUP_EMAIL)
 * until the code or link proves the inbox.
 *
 * This moves every existing such account into that same state. Selected:
 *   - `_bcc_email_verified` exactly '0' and a user_email that is neither
 *     empty nor already a placeholder;
 *   - NOT a password account (bcc_has_password '1') and no OAuth link;
 *   - at least one linked wallet.
 * No sign-up marker is required: accounts from before F-253 carry neither
 * bcc_signup_wallet_link nor bcc_has_password. The wallet is what tells
 * them apart: an unconfirmed password sign-up can never sign in (the
 * password gate refuses '0'), so it can never have linked one. An
 * unreadable wallet list stops the run (INCOMPLETE) rather than guess.
 *
 * For each: the typed address becomes the pending sign-up address, and
 * user_email becomes the salt-keyed per-user placeholder the wallet
 * placeholder backfill uses (bcc_trust_wallet_placeholder_email_for_user;
 * nothing re-derives a placeholder, AccountRecoveryService::
 * isPlaceholderEmail checks the domain only). The codes and links already
 * mailed keep working: POST /auth/verify-email resolves the pending address
 * and applies it on success. Written straight to wp_users like that
 * backfill: wp_update_user() would mail "your email changed" to an address
 * the owner never proved.
 *
 * Logs counts only, never an address. Runner conventions: bounded batches,
 * keyset cursor on wp_users.ID (skipped rows keep matching), no self-guard /
 * self-complete. Status: DB error, an empty salt or a failed write ->
 * INCOMPLETE (retry next request); batch cap hit -> MORE; end of the table
 * -> COMPLETE.
 *
 * @package BCC_Trust
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_release_wallet_signup_unverified_emails')) {
    function bcc_trust_release_wallet_signup_unverified_emails(): string
    {
        global $wpdb;

        $salt = (string) wp_salt('auth');
        if ($salt === '') {
            return BCC_TRUST_MIGRATION_INCOMPLETE; // no keying material yet: retry
        }

        $batchSize     = 100;
        $maxIterations = 20;
        $domain        = \BCC\Trust\Core\Services\AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN;

        $cursor  = 0;
        $moved   = 0;
        $skipped = 0;
        for ($i = 0; $i < $maxIterations; $i++) {
            /** @var list<object{ID: string, user_email: string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT u.ID, u.user_email
                   FROM {$wpdb->users} u
                  WHERE u.ID > %d
                    AND u.user_email <> ''
                    AND u.user_email NOT LIKE %s
                    AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} v
                                 WHERE v.user_id = u.ID AND v.meta_key = %s AND v.meta_value = '0')
                    AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} p
                                     WHERE p.user_id = u.ID AND p.meta_key = %s AND p.meta_value = '1')
                  ORDER BY u.ID ASC
                  LIMIT %d",
                $cursor,
                '%' . $wpdb->esc_like('@' . $domain),
                \BCC\Trust\Core\REST\Auth\AuthSupport::META_EMAIL_VERIFIED,
                \BCC\Trust\Core\Services\AccountRecoveryService::META_HAS_PASSWORD,
                $batchSize
            ));
            if ($wpdb->last_error !== '' || !is_array($rows)) {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            if ($rows === []) {
                \BCC\Core\Log\Logger::info('[bcc-trust] wallet sign-up unverified emails released', [
                    'moved'   => $moved,
                    'skipped' => $skipped,
                ]);
                return BCC_TRUST_MIGRATION_COMPLETE;
            }

            foreach ($rows as $row) {
                $userId = (int) $row->ID;
                $cursor = max($cursor, $userId);
                $result = bcc_trust_release_one_wallet_signup_email($userId, (string) $row->user_email, $domain, $salt);
                if ($result === null) {
                    return BCC_TRUST_MIGRATION_INCOMPLETE;
                }
                $result ? $moved++ : $skipped++;
            }
        }

        return BCC_TRUST_MIGRATION_MORE;
    }

    /**
     * Move one account. True = moved, false = not a wallet sign-up (or its
     * placeholder is somehow taken), null = a read or write failed (the
     * caller stops and retries next run).
     */
    function bcc_trust_release_one_wallet_signup_email(int $userId, string $typedEmail, string $domain, string $salt): ?bool
    {
        global $wpdb;

        foreach (array_keys((array) get_user_meta($userId)) as $metaKey) {
            if (str_starts_with((string) $metaKey, \BCC\Trust\Core\REST\Auth\AuthSupport::META_OAUTH_PREFIX)) {
                return false; // an OAuth account: not a wallet sign-up
            }
        }
        try {
            if (\BCC\Trust\Onchain\Repositories\WalletRepository::getForUserOrThrow($userId) === []) {
                return false; // no wallet: an unconfirmed password sign-up (F-285 reclaims those)
            }
        } catch (\Throwable $e) {
            return null;
        }

        $placeholder = bcc_trust_wallet_placeholder_email_for_user($userId, $domain, $salt);
        $holder      = email_exists($placeholder);
        if ($holder !== false && (int) $holder !== $userId) {
            return false; // a ~2^-64 truncation collision: leave it be
        }

        $metaKey = \BCC\Trust\Core\Services\PendingEmailService::META_PENDING_SIGNUP_EMAIL;
        if ((string) get_user_meta($userId, $metaKey, true) === ''
            && update_user_meta($userId, $metaKey, $typedEmail) === false
        ) {
            return null;
        }

        $updated = $wpdb->update($wpdb->users, ['user_email' => $placeholder], ['ID' => $userId]);
        if ($updated === false) {
            return null;
        }
        clean_user_cache($userId);

        return true;
    }
}
