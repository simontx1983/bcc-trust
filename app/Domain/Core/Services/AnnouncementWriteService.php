<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Announcements\AnnouncementPostType;
use BCC\Trust\Core\Announcements\AnnouncementPublicationGuard as PublicationGuard;
use BCC\Trust\Core\Announcements\AnnouncementTransition as Verb;
use BCC\Trust\Core\Announcements\AnnouncementVisibility as Vis;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\AnnouncementRepository;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;

/**
 * A3 — the announcement write layer.
 *
 * ── THE ORDER IS THE GUARANTEE ──────────────────────────────────────────────
 * announcements-crud-transition.md §4 fixes it, and every method here follows
 * it without exception:
 *
 *   feature switch -> schema validation -> operator authority -> transition
 *                                                            -> marker write
 *                                                            -> read-back
 *                                                            -> response
 *
 * The switch is checked by the ROUTE, before this service is reached, for the
 * same reason the reads do it there: a withdrawn surface must not resolve a
 * claim and must not write. This service additionally refuses to act on a
 * non-operator decision, so the guarantee does not rest on the route alone.
 *
 * ── ⚠⚠ WHAT MAKES A ROW PUBLIC, AND WHY FAILURE CANNOT LEAK ────────────────
 * §4.32.1 needs THREE predicates true at once: post_status='publish',
 * post_date_gmt <= now, and a publication marker that is present, non-empty,
 * dated at-or-before now, and whose user id numerically matches post_author.
 *
 * That conjunction is why every failure path here is closed by construction
 * rather than by care:
 *
 *   - transition succeeds, marker write fails  -> predicate 3 FAILS -> private
 *   - marker written, transition fails         -> predicate 1 FAILS -> private
 *   - both succeed, read-back disagrees        -> refuse, report 503
 *
 * There is no ordering of those two writes that can produce a publicly visible
 * row without a valid marker, because the marker IS one of the predicates. The
 * service still attempts a rollback on partial failure — leaving a publish row
 * that is private only by predicate 3 is correct but confusing — and then
 * ASSERTS non-visibility through the repository's own public finder before
 * returning, so the claim is measured rather than reasoned.
 *
 * ── WHAT THIS SERVICE DELIBERATELY DOES NOT DO ──────────────────────────────
 * No engagement, no scoring, no automatic report-based hiding. It emits no
 * activity row, writes no rank or score table, and never calls AutoHideService.
 * AnnouncementWriteGuaranteesTest pins all three by inventory, because the
 * cheapest way to break the no-engagement guarantee is to add a write here that
 * looks like bookkeeping.
 */
final class AnnouncementWriteService
{
    /** Bounded so a single request's writes stay predictable (§4.32.4 shape). */
    public const MAX_TITLE_LEN = 200;
    public const MAX_BODY_LEN  = 20000;

    /** Refusal codes. The house validation code is bcc_invalid_request. */
    public const ERR_INVALID     = 'bcc_invalid_request';
    public const ERR_NOT_FOUND   = 'bcc_not_found';
    public const ERR_CONFLICT    = 'bcc_conflict';
    public const ERR_UNAVAILABLE = 'bcc_unavailable';

    private AnnouncementRepository $repo;

    public function __construct(?AnnouncementRepository $repo = null)
    {
        $this->repo = $repo ?? new AnnouncementRepository();
    }

    /**
     * Create an announcement under one of the three verbs.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision the authority decision array, passed
     *        in rather than resolved here — the discipline the capability
     *        resolver and the repository already follow. ⚠ §4.32.2 forbids
     *        current_user_can, page authorship, mirror claim rows, PeepSo
     *        membership and read models as ownership signals; taking the
     *        decision makes reaching for one of them by accident impossible.
     * @param array<string,mixed> $input title, body, at
     *
     * @return array<string,mixed>
     */
    public function create(
        int $pageId,
        array $decision,
        int $actorId,
        string $verb,
        array $input,
        ?string $nowUtc = null
    ): array {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        if (!$this->isOperator($decision)) {
            return self::deny();
        }
        if (!Verb::isVerb($verb)) {
            return self::fail(
                self::ERR_INVALID,
                'status must be one of: ' . implode(', ', Verb::verbs()) . '.',
                400
            );
        }

        $title = self::text((string) ($input['title'] ?? ''), self::MAX_TITLE_LEN);
        if ($title === null) {
            return self::fail(
                self::ERR_INVALID,
                'title is required and must be at most ' . self::MAX_TITLE_LEN . ' characters.',
                400
            );
        }
        $body = self::text((string) ($input['body'] ?? ''), self::MAX_BODY_LEN, true);
        if ($body === null) {
            return self::fail(
                self::ERR_INVALID,
                'body must be at most ' . self::MAX_BODY_LEN . ' characters.',
                400
            );
        }

        // ⚠ §4.32.13: `publish_at` is sent ONLY with `schedule`. Sending it
        // otherwise is 400, not silently ignored — ignoring it would accept a
        // request whose intent the server did not honour, and the caller would
        // have no way to tell that their chosen instant was discarded.
        if (!Verb::requiresInstant($verb) && trim((string) ($input['publish_at'] ?? '')) !== '') {
            return self::fail(
                self::ERR_INVALID,
                'publish_at is only accepted with status=schedule.',
                400
            );
        }

        $date = $now;
        if (Verb::requiresInstant($verb)) {
            $at = Verb::normalizeInstant((string) ($input['publish_at'] ?? ''), $now);
            if ($at === null) {
                return self::fail(
                    self::ERR_INVALID,
                    'publish_at must be a strictly future UTC instant formatted Y-m-d H:i:s.',
                    400
                );
            }
            $date = $at;
        }

        // ── transition ──────────────────────────────────────────────────────
        // ⚠ post_author is the ACTOR, because predicate 3 compares the marker's
        // user id against post_author numerically. Any other author makes the
        // row permanently unpublishable.
        // ⚠ post_password is never set: §4.32.1 records that
        // post_password_required() short-circuits to edit_post BEFORE
        // check_read_permission(), so a password is a REST bypass surface.
        $postId = wp_insert_post([
            'post_type'     => AnnouncementPostType::POST_TYPE,
            'post_status'   => Verb::statusFor($verb),
            'post_title'    => $title,
            'post_content'  => $body,
            'post_author'   => $actorId,
            'post_date_gmt' => $date,
            'post_date'     => $date,
            'post_parent'   => $pageId,
            'post_password' => '',
        ], true);

        if (is_wp_error($postId) || !is_int($postId) || $postId <= 0) {
            return self::fail(self::ERR_UNAVAILABLE, 'The announcement could not be stored.', 503);
        }

        return $this->finishPublicationWrite($pageId, $postId, $decision, $actorId, $verb, $now);
    }

    /**
     * Edit title and body, and optionally re-schedule.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function edit(
        int $pageId,
        int $announcementId,
        array $decision,
        array $input,
        ?string $nowUtc = null
    ): array {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        if (!$this->isOperator($decision)) {
            return self::deny();
        }

        $row = $this->ownRowOrNull($pageId, $decision, $announcementId);
        if ($row === null) {
            return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
        }
        if (self::isArchived($announcementId)) {
            return self::fail(self::ERR_CONFLICT, 'An archived announcement cannot be edited.', 409);
        }

        $update = ['ID' => $announcementId];

        if (array_key_exists('title', $input)) {
            $title = self::text((string) $input['title'], self::MAX_TITLE_LEN);
            if ($title === null) {
                return self::fail(
                    self::ERR_INVALID,
                    'title must be non-empty and at most ' . self::MAX_TITLE_LEN . ' characters.',
                    400
                );
            }
            $update['post_title'] = $title;
        }
        if (array_key_exists('body', $input)) {
            $body = self::text((string) $input['body'], self::MAX_BODY_LEN, true);
            if ($body === null) {
                return self::fail(
                    self::ERR_INVALID,
                    'body must be at most ' . self::MAX_BODY_LEN . ' characters.',
                    400
                );
            }
            $update['post_content'] = $body;
        }
        if (array_key_exists('publish_at', $input)) {
            // Re-scheduling only means anything while the row is still pending.
            if ((string) ($row->post_status ?? '') !== Verb::STATUS_FUTURE) {
                return self::fail(self::ERR_CONFLICT, 'Only a scheduled announcement can be re-scheduled.', 409);
            }
            $at = Verb::normalizeInstant((string) $input['publish_at'], $now);
            if ($at === null) {
                return self::fail(
                    self::ERR_INVALID,
                    'publish_at must be a strictly future UTC instant formatted Y-m-d H:i:s.',
                    400
                );
            }
            $update['post_date_gmt'] = $at;
            $update['post_date']     = $at;
        }

        if (count($update) === 1) {
            return self::fail(self::ERR_INVALID, 'Nothing to change: supply title, body or publish_at.', 400);
        }

        $res = wp_update_post($update, true);
        if (is_wp_error($res)) {
            return self::fail(self::ERR_UNAVAILABLE, 'The announcement could not be updated.', 503);
        }

        return $this->readBackOrFail($pageId, $announcementId, $decision);
    }

    /**
     * Promote an existing draft to published.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @return array<string,mixed>
     */
    public function publish(
        int $pageId,
        int $announcementId,
        array $decision,
        int $actorId,
        ?string $nowUtc = null
    ): array {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        if (!$this->isOperator($decision)) {
            return self::deny();
        }
        $row = $this->ownRowOrNull($pageId, $decision, $announcementId);
        if ($row === null) {
            return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
        }
        if (self::isArchived($announcementId)) {
            return self::fail(self::ERR_CONFLICT, 'An archived announcement cannot be published.', 409);
        }
        if ((string) ($row->post_status ?? '') === Verb::STATUS_PUBLIC) {
            return self::fail(self::ERR_CONFLICT, 'Already published.', 409);
        }

        $res = wp_update_post([
            'ID'            => $announcementId,
            'post_status'   => Verb::STATUS_PUBLIC,
            'post_date_gmt' => $now,
            'post_date'     => $now,
        ], true);
        if (is_wp_error($res)) {
            return self::fail(self::ERR_UNAVAILABLE, 'The announcement could not be published.', 503);
        }

        // ⚠⚠ THE PERMITTED RECOVERY PATH. If reconciler arm C blocked this row
        // because authority had lapsed, a fresh publish by a CURRENTLY
        // authorized operator clears the block. The clear happens only after
        // isOperator($decision) passed at the top of this method, so recovery
        // cannot be a bypass: no path clears the blocked marker without a fresh
        // operator check first.
        PublicationGuard::clearBlock($announcementId);

        return $this->finishPublicationWrite(
            $pageId,
            $announcementId,
            $decision,
            $actorId,
            Verb::VERB_PUBLISH,
            $now
        );
    }

    /**
     * Archive — one-way, keeps the permalink (§4.32.6). Not a deletion.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @return array<string,mixed>
     */
    public function archive(int $pageId, int $announcementId, array $decision, ?string $nowUtc = null): array
    {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        if (!$this->isOperator($decision)) {
            return self::deny();
        }
        if ($this->ownRowOrNull($pageId, $decision, $announcementId) === null) {
            return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
        }
        if (self::isArchived($announcementId)) {
            // ⚠ Refuse rather than re-stamp: archival is one-way, and moving the
            // timestamp would change a value a reader may already hold.
            return self::fail(self::ERR_CONFLICT, 'Already archived.', 409);
        }

        if (update_post_meta($announcementId, Vis::META_ARCHIVED_AT, $now) === false) {
            return self::fail(self::ERR_UNAVAILABLE, 'The announcement could not be archived.', 503);
        }
        if (!self::isArchived($announcementId)) {
            return self::fail(self::ERR_UNAVAILABLE, 'Archival could not be confirmed.', 503);
        }

        return ['ok' => true, 'id' => $announcementId, 'state' => 'archived'];
    }

    /**
     * Pin or unpin. §4.32.12 allows at most one pinned row per page, so pinning
     * clears any other pin first.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @return array<string,mixed>
     */
    public function pin(
        int $pageId,
        int $announcementId,
        array $decision,
        bool $pinned,
        ?string $nowUtc = null
    ): array {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        if (!$this->isOperator($decision)) {
            return self::deny();
        }
        $row = $this->ownRowOrNull($pageId, $decision, $announcementId);
        if ($row === null) {
            return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
        }
        // §4.32.11 already reports can_pin false on an archived or unpublished
        // row; the writer enforces it rather than trusting the client read it.
        if (self::isArchived($announcementId)) {
            return self::fail(self::ERR_CONFLICT, 'An archived announcement cannot be pinned.', 409);
        }
        if ($pinned && (string) ($row->post_status ?? '') !== Verb::STATUS_PUBLIC) {
            return self::fail(self::ERR_CONFLICT, 'Only a published announcement can be pinned.', 409);
        }

        if (!$pinned) {
            delete_post_meta($announcementId, AnnouncementRepository::META_PINNED_AT);

            return ['ok' => true, 'id' => $announcementId, 'state' => 'unpinned'];
        }

        // ⚠⚠ THE CRITICAL SECTION IS LOCKED, AND IT HAD TO BE. Read-then-write
        // without a lock is a race: six parallel processes pinning six rows on
        // one page left TWO pins surviving (a real run: ids 164 and 167). The
        // sequential "pin twice, find one" test cannot observe that, because it
        // never interleaves — which is exactly why it was not sufficient proof.
        //
        // ⚠ Refuse rather than queue. A waiting pin would return 200 to a
        // caller whose pin is then cleared by the request behind it; a conflict
        // is the honest answer.
        if (!$this->repo->acquirePinLock($pageId)) {
            return self::fail(self::ERR_CONFLICT, 'Another pin change is in progress for this page.', 409);
        }

        try {
            // ⚠ A read failure here REFUSES the pin rather than proceeding. An
            // errored read would come back as "nothing is pinned", and pinning
            // on that basis would produce the two-pin state this section exists
            // to prevent.
            try {
                $existing = $this->pinnedIdsFor($pageId);
            } catch (RepositoryException $e) {
                return self::fail(self::ERR_UNAVAILABLE, 'The current pin could not be read, so the pin was not applied.', 503);
            }

            foreach ($existing as $other) {
                if ($other !== $announcementId) {
                    delete_post_meta($other, AnnouncementRepository::META_PINNED_AT);
                }
            }
            if (update_post_meta($announcementId, AnnouncementRepository::META_PINNED_AT, $now) === false) {
                return self::fail(self::ERR_UNAVAILABLE, 'The announcement could not be pinned.', 503);
            }

            return ['ok' => true, 'id' => $announcementId, 'state' => 'pinned'];
        } finally {
            // ⚠ EXACTLY ONE RELEASE FOR THE ONE ACQUIRE ABOVE. GET_LOCK is
            // reentrant per session, so an unmatched pair leaks the lock for
            // the rest of the request. The `finally` covers every return path
            // including the two refusals inside the try.
            $this->repo->releasePinLock($pageId);
        }
    }

    // ── internals ───────────────────────────────────────────────────────────

    /**
     * Marker write and read-back, shared by create(publish|schedule) and publish().
     *
     * ⚠⚠ THE FAIL-CLOSED CORE. If the marker cannot be written, the row is
     * reverted to draft and non-visibility is then MEASURED through the
     * repository's own public finder before 503 is returned. The revert is a
     * courtesy; the guarantee comes from predicate 3, which an absent marker
     * fails regardless of whether the revert succeeded.
     *
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @return array<string,mixed>
     */
    private function finishPublicationWrite(
        int $pageId,
        int $postId,
        array $decision,
        int $actorId,
        string $verb,
        string $now
    ): array {
        if (!Verb::authorizesPublication($verb)) {
            // draft: no marker, per the §2 mapping table. Nothing further to
            // confirm beyond the row id wp_insert_post already returned.
            return ['ok' => true, 'id' => $postId, 'state' => Verb::VERB_DRAFT];
        }

        $okAt   = update_post_meta($postId, Vis::META_AUTHORIZED_AT, $now) !== false;
        $okUser = update_post_meta($postId, Vis::META_AUTHORIZED_USER_ID, (string) $actorId) !== false;

        if (!$okAt || !$okUser) {
            return $this->failClosed($pageId, $postId, 'The publication authorization could not be recorded.');
        }

        return $this->readBackOrFail($pageId, $postId, $decision);
    }

    /**
     * Revert to draft and PROVE the row is not publicly visible.
     *
     * @return array<string,mixed>
     */
    private function failClosed(int $pageId, int $postId, string $message): array
    {
        delete_post_meta($postId, Vis::META_AUTHORIZED_AT);
        delete_post_meta($postId, Vis::META_AUTHORIZED_USER_ID);
        wp_update_post(['ID' => $postId, 'post_status' => Verb::STATUS_DRAFT], true);

        $leaked = false;
        try {
            $leaked = $this->repo->findPublic($pageId, $postId) !== null;
        } catch (RepositoryException $e) {
            // ⚠ An unreadable confirmation is not a confirmation. Treat it as a
            // possible leak so an operator is told to check, rather than
            // reporting a clean failure that cannot be substantiated.
            $leaked = true;
        }

        if ($leaked) {
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] announcement write failed and the row may still be public',
                ['announcement_id' => $postId, 'page_id' => $pageId]
            );
        }

        return self::fail(self::ERR_UNAVAILABLE, $message, 503);
    }

    /**
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     * @return array<string,mixed>
     */
    private function readBackOrFail(int $pageId, int $postId, array $decision): array
    {
        // ⚠ The precedent is the Cosmos endpoint switch: "no DB error" is also
        // true for zero rows matched, so a write is not confirmed until a read
        // says the row holds the new state.
        try {
            $row = $this->repo->findForOwner($pageId, $decision, $postId);
        } catch (RepositoryException $e) {
            return self::fail(self::ERR_UNAVAILABLE, 'The write could not be confirmed.', 503);
        }
        if ($row === null) {
            return self::fail(self::ERR_UNAVAILABLE, 'The write could not be confirmed.', 503);
        }

        return ['ok' => true, 'id' => $postId, 'state' => (string) ($row->post_status ?? '')];
    }

    /**
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     */
    private function ownRowOrNull(int $pageId, array $decision, int $announcementId): ?\stdClass
    {
        try {
            return $this->repo->findForOwner($pageId, $decision, $announcementId);
        } catch (RepositoryException $e) {
            return null;
        }
    }

    /**
     * ⚠⚠ ASKS THE REPOSITORY. Its first version ran the query here, and
     * `arch-guardrails` rejected it with five `WPDB_OUTSIDE_REPO` violations —
     * correctly: §1 puts raw `$wpdb` in a repository and nowhere else. The
     * query now lives in `AnnouncementRepository::pinnedIdsFor()`.
     *
     * ⚠ A read failure must NOT be swallowed into an empty list here. An empty
     * list means "nothing is pinned", and the caller would then pin a second
     * row — breaking the very at-most-one invariant this supports. So the
     * RepositoryException propagates to the caller, which refuses the pin.
     *
     * @return list<int>
     * @throws RepositoryException when the read fails
     */
    private function pinnedIdsFor(int $pageId): array
    {
        return $this->repo->pinnedIdsFor($pageId);
    }

    /**
     * ⚠⚠ @phpstan-impure, and this is a correctness annotation rather than
     * a silenced finding. This reads post meta that a write immediately
     * before it may have just CHANGED — archive() stamps the key and then
     * re-reads it as its post-write confirmation. Without the marker,
     * PHPStan remembers the pre-write return value, concludes the
     * confirmation is always true, and declares the real 503 path dead
     * code. That is exactly the shape of the A2.4 defect where an early
     * return made a 503 unreachable while a test counting source shapes
     * stayed green.
     *
     * @phpstan-impure
     */
    private static function isArchived(int $announcementId): bool
    {
        $v = get_post_meta($announcementId, Vis::META_ARCHIVED_AT, true);

        return is_string($v) && trim($v) !== '';
    }

    /**
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     */
    private function isOperator(array $decision): bool
    {
        return ($decision['decision'] ?? null) === Authority::DECISION_OPERATOR;
    }

    /**
     * ⚠ Returns null for unacceptable, never a silently corrected value. The
     * request-bounds work in this codebase established the rule: a sanitizer
     * that rewrites invalid input into valid input hides the defect instead of
     * reporting it.
     */
    private static function text(string $raw, int $max, bool $allowEmpty = false): ?string
    {
        $clean = trim(wp_kses_post($raw));
        if ($clean === '' && !$allowEmpty) {
            return null;
        }
        if (mb_strlen($clean) > $max) {
            return null;
        }

        return $clean;
    }

    /** @return array<string,mixed> */
    private static function deny(): array
    {
        // ⚠ 404, never 403, and never the private reason. §4.32.11: a
        // non-operator must not learn that a page has announcements at all, so
        // the refusal is indistinguishable from a missing row.
        return self::fail(self::ERR_NOT_FOUND, 'Not found.', 404);
    }

    /** @return array<string,mixed> */
    private static function fail(string $code, string $message, int $status): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'status' => $status];
    }
}
