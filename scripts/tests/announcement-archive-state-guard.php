<?php

/**
 * Archive-state safeguard — real WordPress dispatch.
 *
 * §4.32.6 defines archival as one-way, not a deletion, and promises the row
 * "keeps its permalink and stays publicly readable, so it remains
 * challengeable". All three properties presuppose a PUBLISHED row. Applied to a
 * draft, scheduled or blocked notice it delivered the opposite: the row was
 * never publicly readable, and because `listForOwner()` always applies
 * `notArchivedSql()` it also left the owner list — so the row survived,
 * readable only by an id its own operator had no remaining way to obtain, with
 * no route back.
 *
 * ── ⚠⚠ WHY A PROBE AND NOT A UNIT TEST ────────────────────────────────────
 * `AnnouncementWriteService::archive()` calls `update_post_meta()` and reads
 * `post_status` off a real row, and there is NO write-path integration suite in
 * this repo (measured: nothing under tests/Integration/ references
 * AnnouncementWriteService). The capability half IS unit-tested — see
 * AnnouncementCapabilityResolverTest — but the ROUTE half needs a real
 * WordPress, so this is where its evidence lives.
 *
 * ⚠ Not CI-wired, like every scripts/tests/ probe. Run it by hand against a
 * DISPOSABLE environment only; it mutates announcement rows.
 *
 * Usage, inside a disposable stack:
 *   BCC_PROBE_PAGE_ID=<validator page id> \
 *   wp eval-file scripts/tests/announcement-archive-state-guard.php \
 *     --allow-root --path=/var/www/html
 *
 * It expects the fixture shape seeded by the announcement fixture: one pinned
 * published row plus one each of archived / draft / scheduled / blocked on the
 * given page.
 */

// ⚠ NO `declare(strict_types=1)` — `wp eval-file` EVALS this file, and a
// strict-types declaration must be the first statement in a SCRIPT, so it is a
// fatal here rather than a nicety. Every probe under scripts/tests/ omits it for
// the same reason.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "run through wp eval-file\n");
    return;
}

$pageId = (int) getenv('BCC_PROBE_PAGE_ID');
if ($pageId <= 0) {
    fwrite(STDERR, "set BCC_PROBE_PAGE_ID to the validator page id\n");
    return;
}

$pass = 0;
$fail = 0;

/** @param mixed $got @param mixed $exp */
$check = static function (string $what, $exp, $got) use (&$pass, &$fail): void {
    $ok = $exp === $got;
    printf(
        "%s %-62s exp=%-22s got=%s\n",
        $ok ? 'PASS' : 'FAIL',
        $what,
        var_export($exp, true),
        var_export($got, true)
    );
    $ok ? $pass++ : $fail++;
};

// ── the operator, resolved the way the routes resolve them ────────────────
$decision = \BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority::decide(
    $pageId,
    (int) ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
        'SELECT cl.user_id FROM ' . \BCC\Core\DB\DB::table('onchain_claims') . " cl
          WHERE cl.claim_role = 'operator' AND cl.status = 'verified' LIMIT 1"
    )) ?? 0)
);
$check('the fixture operator resolves as the operator', 'operator', $decision['decision'] ?? null);
if (($decision['decision'] ?? null) !== 'operator') {
    printf("\n==== ABORTED: no resolved operator, every assertion below would be vacuous ====\n");
    return;
}
$actorId = (int) $decision['operator_user_id'];

// ── classify the fixture rows by their CONTRACT state ─────────────────────
$rows = $GLOBALS['wpdb']->get_results($GLOBALS['wpdb']->prepare(
    'SELECT ID, post_status FROM ' . $GLOBALS['wpdb']->posts . '
      WHERE post_type = %s AND post_parent = %d ORDER BY ID',
    \BCC\Trust\Core\Announcements\AnnouncementPostType::POST_TYPE,
    $pageId
), ARRAY_A);

$byState = [];
foreach ($rows as $r) {
    $id       = (int) $r['ID'];
    $archived = (string) get_post_meta($id, \BCC\Trust\Core\Announcements\AnnouncementVisibility::META_ARCHIVED_AT, true) !== '';
    $blocked  = (string) get_post_meta($id, \BCC\Trust\Core\Announcements\AnnouncementPublicationGuard::META_BLOCKED_AT, true) !== '';

    $state = $archived ? 'archived'
        : ($blocked ? 'blocked'
        : ($r['post_status'] === 'publish' ? 'published'
        : ($r['post_status'] === 'future' ? 'scheduled' : 'draft')));

    $byState[$state][] = $id;
}

foreach (['published', 'draft', 'scheduled', 'blocked', 'archived'] as $need) {
    $check("fixture supplies at least one {$need} row", true, isset($byState[$need]));
}
if (count(array_diff(['published', 'draft', 'scheduled', 'blocked', 'archived'], array_keys($byState))) > 0) {
    printf("\n==== ABORTED: fixture incomplete ====\n");
    return;
}

$svc = new \BCC\Trust\Core\Services\AnnouncementWriteService();

// ── A. every unpublished state is REFUSED, and nothing moves ──────────────
printf("\n-- A. draft / scheduled / blocked are refused, state and visibility unchanged --\n");

foreach (['draft', 'scheduled', 'blocked'] as $state) {
    $id     = $byState[$state][0];
    $before = [
        'status'   => get_post_status($id),
        'archived' => (string) get_post_meta($id, \BCC\Trust\Core\Announcements\AnnouncementVisibility::META_ARCHIVED_AT, true),
    ];

    $res = $svc->archive($pageId, $id, $decision);

    $check("{$state}: refused", false, $res['ok'] ?? null);
    $check("{$state}: 409", 409, $res['status'] ?? null);
    $check("{$state}: bcc_conflict", 'bcc_conflict', $res['code'] ?? null);
    $check("{$state}: reason is the REUSED closed-inventory code", 'announcement_not_published', $res['reason'] ?? null);

    // ⚠ The refusal is only worth anything if NOTHING moved. Checked against a
    // snapshot taken before the call, not against an expectation.
    $check("{$state}: post_status unchanged", $before['status'], get_post_status($id));
    $check(
        "{$state}: NO archived marker written",
        $before['archived'],
        (string) get_post_meta($id, \BCC\Trust\Core\Announcements\AnnouncementVisibility::META_ARCHIVED_AT, true)
    );
}

// ── B. the published workflow is preserved ────────────────────────────────
printf("\n-- B. a published row still archives, and a repeat still conflicts --\n");

$pub = $byState['published'][0];
$res = $svc->archive($pageId, $pub, $decision);
$check('published: archived', true, $res['ok'] ?? null);
$check('published: state archived', 'archived', $res['state'] ?? null);
$check(
    'published: marker written',
    true,
    (string) get_post_meta($pub, \BCC\Trust\Core\Announcements\AnnouncementVisibility::META_ARCHIVED_AT, true) !== ''
);

// ⚠⚠ The EXISTING repeated-archive behaviour, byte-for-byte: same message, and
// still NO reason code. A reason added here would be a wire change to an error
// that has never carried one.
$again = $svc->archive($pageId, $pub, $decision);
$check('repeat: 409', 409, $again['status'] ?? null);
$check('repeat: the original message, unchanged', 'Already archived.', $again['message'] ?? null);
$check('repeat: still carries NO reason code', false, isset($again['reason']));

// A row archived before this guard existed answers the same way.
$pre = $byState['archived'][0];
$res = $svc->archive($pageId, $pre, $decision);
$check('pre-archived: the original message', 'Already archived.', $res['message'] ?? null);

// ── C. the refusal is a STATE gate, not an ownership one ──────────────────
printf("\n-- C. ordering: ownership still resolves before state --\n");

$denied = ['decision' => 'denied', 'reason' => 'not_claimer', 'operator_user_id' => null];
$res    = $svc->archive($pageId, $byState['draft'][0], $denied);
$check('a non-operator gets the OWNERSHIP refusal, not the state one', 403, $res['status'] ?? null);

// ⚠ ASSERTED WRONG THE FIRST TIME, and the probe caught it rather than the
// code being wrong: I expected NO reason here, conflating "leaks no state" with
// "carries no reason". §4.32.2 REQUIRES the generic `not_claimer` on every
// ownership denial — what must not appear is the state-specific code, because a
// stranger learning `announcement_not_published` would learn that the row exists
// and is unpublished.
$check('…carries the generic ownership reason', 'not_claimer', $res['reason'] ?? null);
$check(
    '…and NEVER the state-specific one, which would disclose the row state',
    false,
    ($res['reason'] ?? null) === 'announcement_not_published'
);

// ── D. the capability agrees with the route ───────────────────────────────
printf("\n-- D. can_archive matches what the route will do --\n");

foreach (['draft', 'scheduled', 'blocked'] as $state) {
    $caps = \BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver::forRow(
        $decision,
        ['status' => $state === 'scheduled' ? 'scheduled' : ($state === 'blocked' ? 'blocked' : 'draft'),
         'is_archived' => false, 'comments_enabled' => true],
        ['authenticated' => true, 'suspended' => false, 'fraud_locked' => false, 'good_standing' => true],
        true
    );
    $check("{$state}: can_archive denied", false, $caps['can_archive']['allowed'] ?? null);
    $check("{$state}: with the same reason the route returns", 'announcement_not_published', $caps['can_archive']['reason_code'] ?? null);
}

$caps = \BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver::forRow(
    $decision,
    ['status' => 'published', 'is_archived' => false, 'comments_enabled' => true],
    ['authenticated' => true, 'suspended' => false, 'fraud_locked' => false, 'good_standing' => true],
    true
);
$check('published: can_archive allowed', true, $caps['can_archive']['allowed'] ?? null);

printf("\n==== ARCHIVE-STATE GUARD: pass=%d fail=%d ====\n", $pass, $fail);
