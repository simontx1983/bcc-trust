<?php
/**
 * One-shot backfill — replace address-derived wallet placeholder emails
 * with salt-keyed, non-reversible tokens.
 *
 * Why: wallet-only signups with no caller-supplied email were minted with
 *   user_email = 'wallet-' . substr(md5(lower(address)),0,16) . '@noreply.bcc.local'
 * (AuthSupport::placeholderEmailForWallet, pre-2026-07-23). That local
 * part is a *guessable function of the wallet address*: Gravatar publishes
 * md5(user_email) on every avatar URL, so an attacker with a candidate
 * address could recompute the placeholder, hash it, and confirm which
 * member owns that wallet — a member↔wallet oracle
 * (docs/wallet-privacy-policy.md).
 *
 * The signup path is fixed to HMAC(wp_salt, address). This migration
 * closes the same hole for accounts CREATED BEFORE the fix by rewriting
 * their placeholder email to a token derived from user_id keyed on the
 * site salt — unique, non-reversible, and not a function of the address
 * at all.
 *
 * It deliberately does NOT read the wallet address: user_id is always
 * available (so orphaned or multi-wallet accounts are handled uniformly),
 * and nothing re-derives the email — AccountRecoveryService::isPlaceholderEmail
 * checks only the domain — so the derivation base is invisible to every
 * consumer. Only placeholder-domain emails are touched; a user who has
 * since set a real recovery email is skipped, because their email is no
 * longer a placeholder.
 *
 * The rewrite goes straight to wp_users via $wpdb (the database-migration
 * layer) rather than wp_update_user() on purpose: wp_update_user() emails
 * an "your email changed" notice to the OLD address, which here is the
 * dead placeholder — pointless mail, and a flood into Mailpit on local.
 *
 * Idempotent: option-guarded, and the derivation is deterministic per
 * user, so a re-run is a no-op UPDATE (same token → same email).
 *
 * Fail-safe: aborts (without setting the done-flag) if wp_salt('auth')
 * is empty, and skips — without setting the done-flag — any row whose
 * UPDATE errors or whose target email already exists (uniqueness). A
 * partial run therefore stays RETRYABLE: the next activation/upgrade
 * re-enters, skips the rows already on the new form, and finishes the
 * rest. The done-flag is set only after a fully clean pass.
 *
 * Registration mirrors backfill-canonical-handles.php — required from
 * bcc-trust.php, invoked inside bcc_trust_create_tables().
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since 2026-07-23 (wallet-privacy remediation)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_backfill_wallet_placeholder_emails')) {

    function bcc_trust_backfill_wallet_placeholder_emails(): void
    {
        if (get_option('bcc_trust_wallet_placeholder_emails_backfilled')) {
            return;
        }

        $salt = (string) wp_salt('auth');
        if ($salt === '') {
            // Fail closed and stay RETRYABLE: without keying material we
            // can only produce guessable or nondeterministic tokens, so we
            // rewrite nothing and do NOT set the done-flag. The next
            // activation/upgrade re-enters and completes once the salt is
            // readable. (Same fail-closed stance as the signup path.)
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] wallet placeholder-email backfill: wp_salt(auth) empty — skipping, will retry'
            );
            return;
        }

        global $wpdb;

        $domain    = \BCC\Trust\Core\Services\AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN;
        $batchSize = 200;
        // Hard cap: 10k placeholder accounts per migration request (matches
        // the canonical-handle backfill). Pre-launch scale is a handful of
        // accounts, so this is only a runaway guard.
        $maxIterations = 50;
        $rewritten     = 0;
        // Any per-row problem (DB error or a uniqueness collision) flips
        // this. The done-flag is set ONLY on a fully clean pass, so a
        // partial failure leaves the migration retryable instead of
        // silently marking itself complete with rows still on the old form.
        $hadFailure = false;

        // OFFSET paging, not match-set-shrinking paging: a rewritten email
        // is STILL on the placeholder domain, so it keeps matching the
        // search. The full ID-ordered result set is therefore invariant
        // under the rewrites, and stepping the offset visits every account
        // exactly once. (Selecting "rows still on the old form" the way the
        // handle backfill selects "users missing the meta" is impossible
        // here — old and new placeholders are indistinguishable by shape.)
        for ($i = 0; $i < $maxIterations; $i++) {
            // Suffix match on the placeholder domain. WP_User_Query turns a
            // leading '*' into a LIKE '%…' — an anchored suffix match on
            // user_email — so this selects exactly the synthetic addresses.
            /** @var list<\WP_User> $users */
            $users = get_users([
                'search'         => '*@' . $domain,
                'search_columns' => ['user_email'],
                'number'         => $batchSize,
                'offset'         => $i * $batchSize,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);

            if ($users === []) {
                break;
            }

            foreach ($users as $user) {
                if (!$user instanceof \WP_User) {
                    continue;
                }
                $userId  = (int) $user->ID;
                $current = (string) $user->user_email;
                if ($userId <= 0) {
                    continue;
                }

                $newEmail = bcc_trust_wallet_placeholder_email_for_user($userId, $domain, $salt);

                // Deterministic token already matches → already migrated
                // (idempotent re-run). Skip the write. Checked BEFORE the
                // uniqueness probe so a re-run doesn't flag the row's own
                // email as a collision.
                if (strtolower($current) === strtolower($newEmail)) {
                    continue;
                }

                // Preserve WP's app-level user_email uniqueness. The token
                // is HMAC(salt, uid) so distinct users yield distinct
                // emails by construction — email_exists() only ever fires
                // on a ~2^-64 truncation collision, which we refuse to
                // resolve blindly (that would duplicate an email). Log,
                // skip, and stay retryable.
                $owner = email_exists($newEmail);
                if ($owner !== false && (int) $owner !== $userId) {
                    \BCC\Core\Log\Logger::error(
                        '[bcc-trust] wallet placeholder-email backfill: target email already exists; skipping',
                        ['user_id' => $userId, 'conflicting_user_id' => (int) $owner]
                    );
                    $hadFailure = true;
                    continue;
                }

                $updated = $wpdb->update(
                    $wpdb->users,
                    ['user_email' => $newEmail],
                    ['ID' => $userId]
                );

                if ($updated === false) {
                    \BCC\Core\Log\Logger::warning(
                        '[bcc-trust] wallet placeholder-email backfill: update failed',
                        ['user_id' => $userId]
                    );
                    $hadFailure = true;
                    continue;
                }

                // $updated is a row count. 0 means the row vanished between
                // the SELECT and the UPDATE (user deleted mid-run) — not a
                // failure, just nothing to cache-bust or count.
                if ($updated > 0) {
                    clean_user_cache($userId);
                    $rewritten++;
                }
            }

            if (count($users) < $batchSize) {
                break;
            }
        }

        if ($hadFailure) {
            // Leave the done-flag UNSET so a later run retries the rows
            // that failed (already-migrated rows are skipped by the
            // equality check above, so the retry is cheap and idempotent).
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] Wallet placeholder-email backfill incomplete — will retry on next run',
                ['rewritten' => $rewritten]
            );
            return;
        }

        update_option('bcc_trust_wallet_placeholder_emails_backfilled', time(), false);

        \BCC\Core\Log\Logger::info(
            '[bcc-trust] Wallet placeholder-email backfill complete',
            ['rewritten' => $rewritten]
        );
    }

    /**
     * Salt-keyed, non-reversible placeholder email for one user.
     *
     * Derived from user_id (not the wallet address): unique by
     * construction, and not a function of the address at all, so it
     * cannot be reconstructed from a candidate wallet.
     *
     * Requires a non-empty salt — the caller guards this before the loop
     * and aborts the whole migration if the salt is empty, so an empty
     * salt never reaches here. Throws rather than fall back to a random
     * (non-idempotent) or empty-keyed (guessable) token if it somehow
     * does.
     *
     * @throws \RuntimeException when $salt is empty.
     */
    function bcc_trust_wallet_placeholder_email_for_user(int $userId, string $domain, string $salt): string
    {
        if ($salt === '') {
            throw new \RuntimeException(
                'wallet placeholder backfill: empty salt reached the token helper.'
            );
        }

        return 'wallet-' . substr(hash_hmac('sha256', 'wallet-uid:' . $userId, $salt), 0, 16)
            . '@' . $domain;
    }
}
