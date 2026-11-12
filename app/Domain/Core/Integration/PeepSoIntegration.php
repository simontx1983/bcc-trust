<?php

namespace BCC\Trust\Core\Integration;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\PeepSoPageResolver;
use BCC\Trust\Core\Support\Formatting;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PeepSo Pages integration for the Trust Engine.
 *
 * Data-sync only: page creation/deletion/save/owner-change hooks and
 * email-verification mirroring. UI rendering moved to Next.js.
 */
class PeepSoIntegration
{
    private ScoreRepository $scoreRepo;
    private UserInfoRepository $userInfoRepo;

    public function __construct(
        ScoreRepository    $scoreRepo,
        UserInfoRepository $userInfoRepo
    ) {
        $this->scoreRepo    = $scoreRepo;
        $this->userInfoRepo = $userInfoRepo;
    }

    /**
     * Register all PeepSo hooks. Called from bootstrap.
     */
    public function register(): void
    {
        add_action('peepso_page_after_create', [$this, 'onPageCreated'], 10, 2);
        add_action('peepso_page_after_delete', [$this, 'onPageDeleted']);
        add_action('save_post_peepso-page', [$this, 'onPageSaved'], 10, 3);
        add_filter('peepso_page_create_response', [$this, 'onPageCreateResponse']);

        // System-minted placeholder pages (ValidatorPageMinter) start with
        // post_author=0 because nobody owned them at mint time. On a verified
        // claim, transfer authorship to the claimer so WP-native /wp-admin
        // listings, peepso byline rendering, and post_count queries
        // attribute the page correctly. Idempotent: only fires when the page
        // is currently author-0; already-owned pages are left alone.
        add_action('bcc_page_claimed', [$this, 'onPageClaimed'], 20, 2);

        // Retry for the FOLLOWER DRAIN ALONE, fired by
        // PagesEndpoint::dispatchPageClaimed() when a resubmitted claim
        // finds the ownership transfer already done. onPageClaimed() moves
        // `post_author` first and drains `bcc_page_follows` last, and the
        // dispatcher gates its re-fire on `post_author === 0` — so the
        // drain is the one step of the event that nothing would otherwise
        // come back for. Same priority as the subscriber it repairs.
        add_action('bcc_page_claim_followers_drain', [$this, 'onClaimFollowersDrain'], 20, 2);

        // Email verification is delegated to PeepSo. When a user completes
        // activation via their shortcode flow, PeepSo fires this action with
        // a PeepSoUser instance; we mirror the verified state into
        // bcc_trust_user_info.is_verified so downstream trust logic
        // (VoteEligibilityChecker, FraudDetector) sees it.
        add_action('peepso_register_verified', [$this, 'onEmailVerified'], 10, 1);
    }

    // ── Lifecycle hooks ─────────────────────────────────────────────────

    /**
     * PeepSo fires `peepso_register_verified` after a successful activation
     * (peepso/classes/registershortcode.php::activate_account). Mirror the
     * verified state into user_info so the trust engine's own checks pass,
     * then fan out to downstream listeners.
     *
     * @param mixed $wpuser PeepSoUser instance with ID/get_user_id().
     */
    public function onEmailVerified($wpuser): void
    {
        $userId = 0;
        if (is_object($wpuser)) {
            if (method_exists($wpuser, 'get_user_id')) {
                $userId = (int) $wpuser->get_user_id();
            } elseif (isset($wpuser->ID)) {
                $userId = (int) $wpuser->ID;
            }
        }

        if ($userId <= 0) {
            return;
        }

        try {
            Plugin::instance()->userInfoRepository()->updateVerificationStatus($userId, true);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] onEmailVerified: updateVerificationStatus failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return;
        }

        AuditLogger::verificationComplete($userId);

        // Fire the read-model sync action (listened to by PageReadModelSync::onOwnerDataChanged)
        // so the owner's pages pick up the new is_verified flag without waiting for the
        // daily full sync. Same action XVerificationService/GitHubVerificationService use.
        do_action('bcc_trust_verification_changed', $userId);

        // Domain extensibility point (kept for third-party listeners).
        do_action('bcc_trust_user_verified', $userId);
    }

    public function onPageCreated(int $pageId, int $userId): void
    {
        $this->scoreRepo->createDefault($pageId, $userId);
        $this->userInfoRepo->incrementPageCount($userId, $pageId);

        // Eagerly sync the read model so brand-new pages are immediately
        // visible in /discover and have trust data in /search — rather than
        // waiting for the first vote or the daily full sync.
        Plugin::instance()->pageReadModelRepository()->syncPage($pageId);

        AuditLogger::log('page_created', $pageId, [
            'user_id' => $userId,
            'action'  => 'create',
        ], 'page');
    }

    public function onPageDeleted(int $pageId): void
    {
        $ownerId = $this->scoreRepo->getPageOwnerId($pageId);

        $this->scoreRepo->delete($pageId);

        if ($ownerId) {
            $this->userInfoRepo->decrementPageCount($ownerId, $pageId);
        }
    }

    /**
     * Transfer authorship from `post_author=0` to the claimer on a
     * verified claim. Only fires for system-minted placeholder pages
     * (ValidatorPageMinter and any future on-chain entity placeholders);
     * pages with a real author are left alone so a re-claim attempt
     * can never overwrite the existing owner.
     *
     * The trust-engine source of truth for "who claimed this" remains
     * the bcc_onchain_claims table — this handler is purely so WP-native
     * surfaces (/wp-admin, post_author queries, PeepSo byline rendering)
     * agree with what the claim flow already established.
     *
     * Hooked at priority 20 so BonusService::applyClaimBonus (10) has
     * already run and read scoreRepo state under the pre-claim author.
     */
    public function onPageClaimed(int $userId, int $pageId): void
    {
        if ($userId <= 0 || $pageId <= 0) {
            return;
        }

        $currentAuthor = (int) get_post_field('post_author', $pageId);
        if ($currentAuthor !== 0) {
            // ⛔ DELIBERATELY NO AUTOMATIC TRANSFER.
            //
            // A page keeps its owner. Nothing here moves `post_author`, and
            // nothing moves the owner-keyed `bcc_trust_page_scores` row.
            //
            // The condition this guards is real: claims are deleted when a
            // wallet is disconnected or an account is removed, which frees
            // the exclusive operator slot — so a DIFFERENT user can later
            // hold the only verified claim on this validator while the page
            // still belongs to the original claimer. Transferring on its own
            // would take a page away from a live account and strand that
            // account's score row, and a routine key rotation would trigger
            // it. So the mismatch is REPORTED, not resolved.
            //
            // Reassignment, if it is ever wanted, is an explicit operator- or
            // admin-initiated action that also decides what happens to the
            // previous owner's score row.
            if ($currentAuthor !== $userId) {
                \BCC\Core\Log\Logger::warning(
                    '[bcc-trust] page_claimed by a user who is not the page author — ownership left unchanged',
                    [
                        'page_id'        => $pageId,
                        'claiming_user'  => $userId,
                        'current_author' => $currentAuthor,
                        'note'           => 'no automatic transfer by design; needs an explicit reassignment decision',
                    ]
                );
            } else {
                // F-299: already this user's page — make sure the PeepSo
                // owner row exists too (idempotent; heals pre-F-299 claims).
                self::ensurePageOwnerMembership($pageId, $userId);
            }

            return;
        }

        $update = wp_update_post([
            'ID'          => $pageId,
            'post_author' => $userId,
        ], true);

        if (is_wp_error($update)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] onPageClaimed: wp_update_post failed', [
                'page_id' => $pageId,
                'user_id' => $userId,
                'error'   => $update->get_error_message(),
            ]);
            return;
        }

        $this->userInfoRepo->incrementPageCount($userId, $pageId);

        // F-299: record the claimer as the page's PeepSo OWNER too. Every
        // directory and card owner signal (the /members VALIDATORS filter
        // and chip counts, owned-page counts on member cards) reads the
        // `peepso_page_members` `member_owner` row, and moving post_author
        // alone never wrote one — so a verified operator never appeared as
        // the owner of the page they had just claimed. Best-effort and
        // logged: the claim itself is already recorded.
        self::ensurePageOwnerMembership($pageId, $userId);

        // V1.6 follower migration — drain bcc_page_follows for this
        // page into real PeepSo user→user follows on the new operator.
        // Every viewer who pulled the placeholder pre-claim becomes a
        // PeepSo follower of the claimer immediately, with their
        // tier_at_watch preserved in bcc_watch_meta so the watchlist UI
        // surfaces the historically-correct tier ribbon.
        //
        // Best-effort: an individual follow that fails is logged but does
        // not block the rest of the migration, and a row it could not
        // migrate is KEPT rather than deleted — the drain removes only
        // what it confirmed it had transferred.
        //
        // ⚠ This is the LAST step and the transfer above is the FIRST,
        // so a drain that stops on a DB fault leaves rows behind on a page
        // that already looks fully claimed. Nothing re-fires this event for
        // such a page; the repair is `bcc_page_claim_followers_drain` on the
        // next claim submission — see onClaimFollowersDrain().
        $this->migratePageFollowersToPeepSo($pageId, $userId);
    }

    /**
     * Retry the follower drain for a page whose ownership transfer has
     * ALREADY completed.
     *
     * onPageClaimed() does three things in order: move `post_author`, bump
     * the owner's page count, drain `bcc_page_follows`. Only the first is
     * observable afterwards, and it is exactly what
     * PagesEndpoint::dispatchPageClaimed() reads to decide whether a
     * resubmitted claim should re-fire the event. So a drain that stopped
     * early — a failed read, a failed delete, or rows whose PeepSo
     * follow refused — left rows behind that nothing would ever return
     * for: the page is owned, so the re-fire is gated off permanently.
     *
     * ⚠⚠ THE LEFTOVERS ARE NOT INERT, which is why this exists at
     * all. `WatchingService::getWatchlist()` is two-source: it prepends
     * every `bcc_page_follows` row on page 1 AND subtracts them from the
     * PeepSo budget (`max(0, $pageSize - count($pageFollowItems))`), while
     * `countByUserId()` feeds the total. A row whose follow DID transfer
     * therefore shows that viewer the same operator twice — once as the
     * placeholder page, once as the operator — and pushes a real follow
     * off page 1.
     *
     * ⭐ WHY REPEATING THE DRAIN IS SAFE, by construction rather than by
     * assumption: the drain deletes a row only after
     * PeepSoFollowWriter::follow() returned a NON-ZERO follow id for it in
     * THAT SAME PASS. For an already-migrated row that call returns the
     * EXISTING id, which is positive confirmation that the follow is really
     * there. So no row is ever removed on the strength of an earlier pass's
     * claim to have migrated it, and a row whose follow still cannot be
     * made keeps erroring and keeps its row. The `bcc_watch_meta` sidecar
     * is likewise written only when absent.
     *
     * ⛔ OWNERSHIP IS NEITHER CHANGED NOR ASSUMED. The drain points
     * follows at `$userId`, so it runs only when `$userId` IS the page
     * author. A claimer who is not the author gets the same treatment as in
     * onPageClaimed(): nothing moves, and the mismatch is logged. An
     * author-0 page is refused here too — that is the re-fire path's
     * job, not this one's.
     *
     * Runs inside the caller's `bcc_page_claim_event:<pageId>` advisory
     * lock, which is what keeps two overlapping drains off
     * `peepso_user_followers` (no unique index on the pair, and PeepSo's
     * own writer is SELECT-then-INSERT).
     */
    public function onClaimFollowersDrain(int $userId, int $pageId): void
    {
        if ($userId <= 0 || $pageId <= 0) {
            return;
        }

        $currentAuthor = (int) get_post_field('post_author', $pageId);

        if ($currentAuthor !== $userId) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] follower-drain retry skipped - claimer is not the page author',
                [
                    'page_id'        => $pageId,
                    'claiming_user'  => $userId,
                    'current_author' => $currentAuthor,
                    'note'           => 'no automatic transfer by design; draining would point follows at a non-owner',
                ]
            );
            return;
        }

        // F-299: the resubmitted-claim path for a page this user already
        // owns. It also heals a missing `member_owner` row — a claim made
        // before F-299, or one whose owner write failed — idempotently.
        self::ensurePageOwnerMembership($pageId, $userId);

        $this->migratePageFollowersToPeepSo($pageId, $userId);
    }

    /**
     * F-299: make $userId the PeepSo `member_owner` of $pageId, through
     * PeepSo's own model — the same `member_join()` + `member_modify(
     * 'member_owner')` pair PeepSoPage::create() runs for a page's creator,
     * which also recounts the page's members.
     *
     * Idempotent: an existing owner row is left alone; an existing row of
     * another status is promoted without a join (a join on an existing row
     * would rewrite it to plain `member` first). Silent on success; any
     * failure is logged and answered `false`, never thrown — the claim is
     * already committed when this runs, and the drain retry heals it.
     *
     * Exposed (public static) for the one-off backfill command, which must
     * write exactly what a claim writes.
     */
    public static function ensurePageOwnerMembership(int $pageId, int $userId): bool
    {
        if ($pageId <= 0 || $userId <= 0) {
            return false;
        }

        if (!class_exists('PeepSoPageUser')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] page owner membership not written - PeepSo Pages is not loaded', [
                'page_id' => $pageId,
                'user_id' => $userId,
            ]);
            return false;
        }

        try {
            $member = new \PeepSoPageUser($pageId, $userId);
            if ($member->is_owner === true) {
                return true;
            }

            if ($member->role === false && $member->member_join() !== true) {
                \BCC\Core\Log\Logger::error('[bcc-trust] page owner membership: member_join failed', [
                    'page_id' => $pageId,
                    'user_id' => $userId,
                ]);
                return false;
            }

            if ($member->member_modify('member_owner') !== true || $member->is_owner !== true) {
                \BCC\Core\Log\Logger::error('[bcc-trust] page owner membership: promotion to member_owner failed', [
                    'page_id' => $pageId,
                    'user_id' => $userId,
                    'role'    => is_string($member->role) ? $member->role : '',
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] page owner membership: write threw', [
                'page_id' => $pageId,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Drain `bcc_page_follows` for a freshly claimed page into real PeepSo
     * follows on the new operator.
     *
     * ⚠⚠ DELETES ONLY WHAT IT HANDLED. The previous version read one page
     * of rows (LIMIT 5000) and then called `deleteByPageId()`, which removes
     * EVERY row for the page. Two silent losses followed:
     *
     *   - a page with more than one read page of follows had the unread
     *     remainder destroyed without ever being migrated;
     *   - a row whose PeepSo follow failed was counted as `errored` and then
     *     deleted anyway, so the follow was gone for good with no retry.
     *
     * `deleteByPageId()` also returns 0 on a failed query, so the summary log
     * reported success either way.
     *
     * Now: migrate a page, delete exactly the ids that were migrated or
     * deliberately skipped, and leave errored rows in place. Repeat while a
     * pass makes progress, so a page of any size drains. A pass that handles
     * nothing stops the loop — the remaining rows all errored and are left
     * in place rather than being thrown away. They are re-attempted on the
     * next claim submission for this page, via
     * `bcc_page_claim_followers_drain` — which is user-initiated, so a
     * page whose operator never resubmits keeps its retained rows. There is
     * no cron repair pass; see onClaimFollowersDrain().
     *
     * ⚠⚠ Two DATABASE-LEVEL stop conditions, both of which used to read as
     * success:
     *
     *   - a failed READ returned `[]`, which the loop took for "no followers
     *     left" and treated as a completed drain;
     *   - a failed DELETE returned 0, which looked like "nothing matched" —
     *     and because the handled rows were still in the table, the next
     *     pass read the SAME full page and migrated every row again, up to
     *     $maxPasses times, deleting nothing.
     *
     * Both now stop the drain, log at error level, and leave every row in
     * place for a later attempt. Re-migrating a retained row is safe:
     * PeepSoFollowWriter::follow() returns the existing follow id and the
     * watch-meta sidecar is only written when absent.
     *
     * ⚠ Concurrency: this whole method runs inside the per-page
     * `bcc_page_claim_event:<pageId>` advisory lock held by
     * PagesEndpoint::dispatchPageClaimed(), so two overlapping claim
     * requests for one page cannot drain it at the same time. That matters
     * beyond tidiness — `peepso_user_followers` has NO unique index on
     * (uf_active_user_id, uf_passive_user_id) and PeepSo's own writer is
     * SELECT-then-INSERT, so concurrent drains could otherwise create
     * duplicate follower rows.
     */
    private function migratePageFollowersToPeepSo(int $pageId, int $newAuthorId): void
    {
        $pageFollowRepo = Plugin::instance()->pageFollowRepository();
        $watchMetaRepo  = Plugin::instance()->watchMetaRepository();

        $migrated = 0;
        $skipped  = 0;
        $deleted  = 0;
        $passes   = 0;

        // ⚠ A SET of row ids, not a counter. A row that fails is re-read by
        // the next pass and fails again, so a counter would report two
        // casualties for one retained row — and `retained` is derived from
        // this, so the summary log would overstate what was left behind.
        /** @var array<int, true> $erroredIds */
        $erroredIds = [];

        // Rows that were migrated (or deliberately skipped) and then SURVIVED
        // because the DELETE itself failed. Counted apart from $erroredIds
        // because the two mean opposite things to whoever reads the log: an
        // errored row was never migrated and must be retried, while a
        // delete-failed row IS migrated and only needs removing.
        $deleteFailed = 0;
        $readFailed   = false;

        // Bound the loop independently of row counts: each pass must either
        // handle at least one row or break, so this cap is a backstop against
        // an unforeseen non-progressing state, never the normal exit.
        $maxPasses = 64;

        while ($passes < $maxPasses) {
            $passes++;

            try {
                $rows = $pageFollowRepo->findByPageId($pageId);
            } catch (\BCC\Trust\Core\Exceptions\RepositoryException $e) {
                // ⚠ A FAILED READ IS NOT AN EMPTY PAGE. The repository now
                // throws rather than returning `[]`, so this is the one case
                // the loop could not previously see. Stop — the rows are
                // still there and the next claim-time attempt reads them
                // again — and say so, because an empty follow bar on a
                // freshly claimed page looks completely normal.
                $readFailed = true;
                \BCC\Core\Log\Logger::error('[bcc-trust] migratePageFollowersToPeepSo: follow read FAILED - drain stopped, rows retained', [
                    'page_id'   => $pageId,
                    'author_id' => $newAuthorId,
                    'pass'      => $passes,
                    'reason'    => $e->getMessage(),
                ]);
                break;
            }

            if ($rows === []) {
                break;
            }

            $handledIds   = [];
            $erroredInRun = 0;

            foreach ($rows as $row) {
                $viewerId = (int) $row->user_id;
                $rowId    = (int) $row->id;

                // Self-follow guard (the claimer's own pull would now be a
                // self-follow). PeepSoFollowWriter::follow rejects this too,
                // but filtering here keeps it out of the error count. It IS
                // handled, so it is safe to remove.
                if ($viewerId === $newAuthorId) {
                    $skipped++;
                    $handledIds[] = $rowId;
                    continue;
                }

                // F-29: a block between the two (either direction) means
                // this pre-claim follow must never become a real one.
                // PeepSoFollowWriter::follow() refuses it too, but with a
                // bare 0 the row would be retried on every claim forever:
                // it is HANDLED (skipped and removed). An unreadable block
                // state is not a decision: the row stays and retries.
                $blocked = \BCC\Core\Repositories\PeepSoBlockRepository::isMutuallyBlockedOrUnknown($viewerId, $newAuthorId);
                if ($blocked === true) {
                    $skipped++;
                    $handledIds[] = $rowId;
                    continue;
                }
                if ($blocked === null) {
                    $erroredIds[$rowId] = true;
                    $erroredInRun++;
                    continue;
                }

                try {
                    $followId = \BCC\Core\PeepSo\PeepSoFollowWriter::follow($viewerId, $newAuthorId);
                    if ($followId === 0) {
                        $erroredIds[$rowId] = true;
                        $erroredInRun++;
                        continue;
                    }

                    $existingMeta = $watchMetaRepo->find($followId);
                    if ($existingMeta === null) {
                        // ⚠ The follow already exists at this point. If the
                        // tier meta cannot be written the follow is still
                        // real, so the row is handled — losing the ribbon
                        // tier is better than re-following on every claim.
                        $watchMetaRepo->insert(
                            $followId,
                            $row->tier_at_watch,
                            null /* batch_id — Phase 3 */
                        );
                    }
                    $migrated++;
                    $handledIds[] = $rowId;
                } catch (\Throwable $e) {
                    $erroredIds[$rowId] = true;
                    $erroredInRun++;
                    \BCC\Core\Log\Logger::error('[bcc-trust] migratePageFollowersToPeepSo: per-row failure', [
                        'page_id'   => $pageId,
                        'viewer_id' => $viewerId,
                        'author_id' => $newAuthorId,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            if ($handledIds === []) {
                // Nothing handled this pass: every remaining row errored.
                // Leave them for the next attempt instead of deleting them.
                break;
            }

            $deletedThisPass = $pageFollowRepo->deleteByIds($handledIds);

            if ($deletedThisPass === false) {
                // ⚠⚠ STOP, do not loop. The handled rows are still in the
                // table, so the next pass reads the SAME page and
                // re-migrates every row — on a full page that is $maxPasses
                // × 5000 PeepSo follow calls inside one claim request, while
                // deleting nothing. Breaking costs one wasted pass and
                // retains the rows for a later attempt.
                $deleteFailed = count($handledIds);
                \BCC\Core\Log\Logger::error('[bcc-trust] migratePageFollowersToPeepSo: delete of handled rows FAILED - drain stopped, rows retained', [
                    'page_id'   => $pageId,
                    'author_id' => $newAuthorId,
                    'pass'      => $passes,
                    'retained'  => $deleteFailed,
                ]);
                break;
            }

            $deleted += $deletedThisPass;

            // A short page means we saw every remaining row.
            if (count($rows) < \BCC\Trust\Core\Repositories\PageFollowRepository::DRAIN_PAGE_SIZE
                && $erroredInRun === 0) {
                break;
            }
        }

        $errored = count($erroredIds);

        if ($migrated === 0 && $skipped === 0 && $errored === 0 && $deleteFailed === 0) {
            // Nothing was attempted. A read failure on the very first pass
            // lands here and has already logged at error level above, so it
            // does not also get a "complete" summary it would contradict.
            return;
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] Page-follow migration complete', [
            'page_id'       => $pageId,
            'author'        => $newAuthorId,
            'migrated'      => $migrated,
            'skipped'       => $skipped,
            'errored'       => $errored,
            'deleted'       => $deleted,
            // ⚠ `deleted: 0, delete_failed: 0` means the DELETE matched
            // nothing. `deleted: 0, delete_failed: N` means it ERRORED and N
            // handled rows are still in the table. The old log could not tell
            // those apart, because deleteByIds() returned 0 for both.
            'delete_failed' => $deleteFailed,
            'read_failed'   => $readFailed,
            'passes'        => $passes,
            // ⚠ Rows LEFT IN PLACE on purpose: never migrated (errored), or
            // migrated but not removable (delete_failed).
            'retained'      => $errored + $deleteFailed,
        ]);
    }

    public function onPageSaved(int $postId, \WP_Post $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        if ($post->post_type !== 'peepso-page') {
            return;
        }

        if (!$update) {
            $this->onPageCreated($postId, (int) $post->post_author);
            return;
        }

        $old_owner = get_post_meta($postId, '_peepso_page_owner_id', true);
        $new_owner = (int) $post->post_author;
        if ($old_owner && (int) $old_owner !== $new_owner) {
            $this->handleOwnerChange($postId, $new_owner);
        }
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function onPageCreateResponse(array $response): array
    {
        if (!empty($response['success']) && !empty($response['id'])) {
            $this->onPageCreated((int) $response['id'], get_current_user_id());
            $response['trust_score'] = (float) BCC_TRUST_NEUTRAL_SCORE;
            $response['trust_tier']  = 'neutral';
        }
        return $response;
    }

    // ── Owner change ────────────────────────────────────────────────────

    private function handleOwnerChange(int $pageId, int $newOwnerId): void
    {
        $oldOwner = $this->scoreRepo->getPageOwnerId($pageId);

        if ($oldOwner && $oldOwner !== $newOwnerId) {
            $this->scoreRepo->updateOwner($pageId, $newOwnerId);

            $this->userInfoRepo->transferPageOwnership($oldOwner, $newOwnerId, $pageId);

            AuditLogger::log('page_owner_changed', $pageId, [
                'old_owner' => $oldOwner,
                'new_owner' => $newOwnerId,
            ], 'page');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public static function isPeepSoPagesActive(): bool
    {
        return class_exists('PeepSoPage')
            || defined('PEEPSO_PAGES_PLUGIN_VERSION')
            || function_exists('PeepSoPage');
    }
}
