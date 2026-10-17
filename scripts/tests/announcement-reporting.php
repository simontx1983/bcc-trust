<?php

/**
 * Reporting an announcement — real WordPress dispatch.
 *
 * §4.32.11 advertises `can_report` on a notice. Until this slice,
 * `POST /bcc/v1/me/reports` declared `target_kind` with an enum of
 * `feed_item` ONLY, and the announcement view-model carried no activity,
 * feed or module id — so the server said "you may report this" and offered
 * neither a route nor an id to do it with. This probe is the evidence that
 * the journey now completes, and that it completes without any of the five
 * things it must not do.
 *
 * ── ⚠⚠ WHY A PROBE AND NOT AN INTEGRATION TEST ───────────────────────────
 * `ContentReportService::fileReport()` reads a real post through
 * `get_post()`, composes the §4.32.1 predicate in SQL through
 * `AnnouncementRepository::findPublic()`, writes through a UNIQUE index and
 * fires `do_action()`. There is no write-path integration suite in this
 * repo (measured: nothing under tests/Integration/ references
 * `ContentReportService`). The decision half IS unit-tested —
 * `AnnouncementReportTargetTest` and `AnnouncementCapabilityResolverTest` —
 * so this is where the behavioural half lives.
 *
 * ── ⚠ WHAT THIS ASSERTS THAT NOTHING ELSE CAN ────────────────────────────
 * The PAIRWISE equivalence in section 2: for every (row state, viewer)
 * pair, `can_report`'s verdict and the route's verdict must AGREE. That is
 * the "capability consistent with actual reportability" property, and it
 * cannot be checked from either side alone — a capability suite does not
 * know what the route does, and a route probe does not know what was
 * advertised.
 *
 * ⚠ Not CI-wired, like every scripts/tests/ probe. Run it against a
 * DISPOSABLE environment only: it files real reports and fires the real
 * `bcc_content_reported` event.
 *
 * Usage, inside a disposable stack:
 *   BCC_PROBE_PAGE_ID=<validator page id> \
 *   BCC_PROBE_REPORTER_ID=<a disposable user who is NOT the operator> \
 *   wp eval-file scripts/tests/announcement-reporting.php \
 *     --allow-root --path=/var/www/html
 *
 * ⚠ The reporter is REQUIRED rather than invented: a probe that created an
 * account would be a probe that mutates the user table, and one that picked
 * an arbitrary existing user would file a report in a real member's name.
 *
 * It expects the fixture shape seeded by the announcement fixture: one
 * published row plus one each of archived / draft / scheduled / blocked on
 * the given page.
 */

// ⚠ NO `declare(strict_types=1)` — `wp eval-file` EVALS this file, and a
// strict-types declaration must be the first statement in a SCRIPT, so it is
// a fatal here rather than a nicety. Every probe under scripts/tests/ omits
// it for the same reason.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "run through wp eval-file\n");
    return;
}

$pageId     = (int) getenv('BCC_PROBE_PAGE_ID');
$reporterId = (int) getenv('BCC_PROBE_REPORTER_ID');

if ($pageId <= 0 || $reporterId <= 0) {
    fwrite(STDERR, "set BCC_PROBE_PAGE_ID and BCC_PROBE_REPORTER_ID\n");
    return;
}

$pass = 0;
$fail = 0;
$skip = 0;

/** @param mixed $exp @param mixed $got */
$check = static function (string $what, $exp, $got) use (&$pass, &$fail): void {
    $ok = $exp === $got;
    printf(
        "%s %-66s exp=%-24s got=%s\n",
        $ok ? 'PASS' : 'FAIL',
        $what,
        var_export($exp, true),
        var_export($got, true)
    );
    $ok ? $pass++ : $fail++;
};

$note = static function (string $what, string $why) use (&$skip): void {
    printf("SKIP %-66s %s\n", $what, $why);
    $skip++;
};

$wpdb = $GLOBALS['wpdb'];

$CAPS = \BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver::class;
$CPT  = \BCC\Trust\Core\Announcements\AnnouncementPostType::class;
$SVC  = \BCC\Trust\Core\Services\ContentReportService::class;

// ══ 0. PRECONDITIONS — every one of them a vacuity guard ══════════════════
printf("\n==== 0. preconditions ====\n");

$featureOn = \BCC\Trust\Core\Announcements\AnnouncementFeature::isEnabled();
$check('the feature is enabled (every route 404s while off)', true, $featureOn);

// â  A CANDIDATE, not the answer. An earlier probe of mine bound itself to
// an ORPHAN validator this way (`ORDER BY id LIMIT 1` picked one with no
// claim) and reported a working change as a regression. The authority below
// is what decides; this only finds someone to ask about.
$operatorId = (int) ($wpdb->get_var(
    'SELECT user_id FROM ' . \BCC\Core\DB\DB::table('onchain_claims') . "
      WHERE claim_role = 'operator' AND status = 'verified'
      ORDER BY id DESC LIMIT 1"
) ?? 0);

// ⚠ Resolved through the AUTHORITY, not read off the claim row — the claim
// above only finds a candidate. If the authority disagrees, every ownership
// assertion below would be measuring the wrong user.
$decision = \BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority::decide($pageId, $operatorId);
$check('the fixture operator resolves as the operator', 'operator', $decision['decision'] ?? null);
$operatorId = (int) ($decision['operator_user_id'] ?? 0);

$check('the reporter is NOT the operator', true, $reporterId > 0 && $reporterId !== $operatorId);
$check('the reporter exists', true, get_userdata($reporterId) !== false);

if ($fail > 0) {
    printf("\n==== ABORTED: a precondition failed, every assertion below would be vacuous ====\n");
    return;
}

// ── classify the fixture rows by their CONTRACT state ─────────────────────
//
// ⚠ Computed here independently of `AnnouncementViewService::status()`
// rather than by calling it. That is deliberate: if the two ever disagree,
// the pairwise equivalence in section 2 fails loudly instead of agreeing
// with itself.
$rows = $wpdb->get_results($wpdb->prepare(
    'SELECT ID, post_status, post_author FROM ' . $wpdb->posts . '
      WHERE post_type = %s AND post_parent = %d ORDER BY ID',
    $CPT::POST_TYPE,
    $pageId
), ARRAY_A);

$byState = [];
foreach ((array) $rows as $r) {
    $id      = (int) $r['ID'];
    $blocked = (string) get_post_meta($id, \BCC\Trust\Core\Announcements\AnnouncementPublicationGuard::META_BLOCKED_AT, true);
    $arch    = (string) get_post_meta($id, \BCC\Trust\Core\Announcements\AnnouncementVisibility::META_ARCHIVED_AT, true);

    if ($blocked !== '') {
        $state = 'blocked';
    } elseif ((string) $r['post_status'] === 'future') {
        $state = 'scheduled';
    } elseif ((string) $r['post_status'] !== 'publish') {
        $state = 'draft';
    } else {
        $state = $arch !== '' ? 'archived' : 'published';
    }

    // First of each state wins; the fixture seeds one each.
    $byState[$state] ??= [
        'id'          => $id,
        'author'      => (int) $r['post_author'],
        'is_archived' => $arch !== '',
        'status'      => $state === 'archived' ? 'published' : $state,
    ];
}

foreach (['published', 'archived', 'draft', 'scheduled', 'blocked'] as $state) {
    $check("fixture carries a {$state} row", true, isset($byState[$state]));
}

if ($fail > 0) {
    printf("\n==== ABORTED: the fixture is incomplete ====\n");
    return;
}

$check(
    'the operator is the author of the published row',
    $operatorId,
    $byState['published']['author']
);

printf(
    "\n  page=%d operator=%d reporter=%d  published=%d archived=%d draft=%d scheduled=%d blocked=%d\n",
    $pageId,
    $operatorId,
    $reporterId,
    $byState['published']['id'],
    $byState['archived']['id'],
    $byState['draft']['id'],
    $byState['scheduled']['id'],
    $byState['blocked']['id']
);

// ⚠ THE THROTTLE BUDGET, stated before it can confound anything. The route
// throttles per REPORTER at BCC_TRUST_RATE_LIMIT_FLAG per window, and the
// gate sits AFTER target resolution — so only ACCEPTED attempts consume it.
// This probe makes exactly three: published, archived, and one deliberate
// duplicate. Section 5 asserts no result was ever rate-limited, so a budget
// overrun cannot be mistaken for a refusal.
printf(
    "  throttle: %d accepted attempts per %ds; this probe makes 3\n",
    BCC_TRUST_RATE_LIMIT_FLAG,
    BCC_TRUST_RATE_WINDOW_FLAG
);
$check('the throttle budget exceeds this probe\'s accepted attempts', true, BCC_TRUST_RATE_LIMIT_FLAG > 3);

// ⚠⚠ AND THE WINDOW IS CLEARED, because a SECOND run inside the same window
// otherwise produces a FALSE FAILURE that looks like a broken duplicate path.
// That happened: run 1 spent 3 of 5 slots, run 2 spent 2 more, and the
// duplicate attempt — the 6th — came back `bcc_rate_limited` while the probe
// reported "a duplicate report is idempotent: got false". The behaviour was
// correct and the probe was wrong, which is the worse of the two.
//
// `RateLimiter::slidingWindowCheck()` stores its buckets as options named
// `_transient_bcc_rl_<md5>`, and the md5 is over a key this probe cannot
// reconstruct, so the clear is by PREFIX. Bounded, disposable-only, and
// stated here rather than left as a mystery — and section 5 asserts no
// attempt was rate-limited anyway, so a failure to clear is REPORTED rather
// than assumed away.
$cleared = (int) $wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bcc\_rl\_%'"
);
printf("  cleared %d rate-limit bucket row(s) before the first attempt\n", $cleared);

// ⚠⚠ A CLEAN REPORT LEDGER IS A PRECONDITION, and it is checked rather
// than assumed. Section 2 asserts the two accepted attempts come back
// `created`; if this reporter already reported either target, the route
// correctly answers `existing` and the probe would read a correct
// idempotent response as a failure. Stated as a precondition with the fix
// in the message, because the second-run failure is otherwise baffling.
$already = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . \BCC\Trust\Core\Database\TableRegistry::contentReports() . '
      WHERE reporter_user_id = %d AND target_kind = %s',
    $reporterId,
    'announcement'
));
$check('the reporter has filed no announcement report yet', 0, $already);
if ($already > 0) {
    printf(
        "\n==== ABORTED: delete this reporter's announcement reports first ====\n"
        . "     DELETE FROM %s WHERE target_kind = 'announcement';\n",
        \BCC\Trust\Core\Database\TableRegistry::contentReports()
    );
    return;
}

// ══ 1. SNAPSHOTS, taken before any write ══════════════════════════════════
$reportsTable = \BCC\Trust\Core\Database\TableRegistry::contentReports();
$hiddenTable  = \BCC\Trust\Core\Database\TableRegistry::hiddenActivities();

$reportsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$reportsTable}");
$hiddenBefore  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$hiddenTable}");

$sideTables = [];
// ⚠ EACH NAME WAS MEASURED, not guessed. A first version of this probe
// named `bcc_attestations` and `peepso_likes`; both were absent and both
// SKIPPED, so the section reported nothing while looking complete. The two
// below exist on a real install and are the rows a scoring or reward effect
// would land in; `peepso_likes` is retained because it exists on installs
// with the engagement add-on, and a SKIP that names the missing table is
// honest in a way a silent pass is not.
foreach ([
    'bcc_trust_attestations' => 'attestation rows',
    'bcc_trust_activity'     => 'trust-activity ledger rows',
    'peepso_likes'           => 'reward/like rows',
] as $suffix => $label) {
    $name   = $wpdb->prefix . $suffix;
    $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name;
    if ($exists) {
        $sideTables[$name] = ['label' => $label, 'before' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$name}")];
    } else {
        $note("{$label} unchanged", "{$name} absent on this install — NOT asserted");
    }
}

// ══ 2. THE PAIRWISE EQUIVALENCE — the assertion nothing else can make ═════
printf("\n==== 2. can_report vs the route, every (state, viewer) pair ====\n");

// â  Through the plugin's own accessor, not `new`: the service takes its
// repository by constructor injection, and a hand-built one would be a
// second wiring that could drift from the one the routes use.
$service = \BCC\Trust\Core\Plugin::instance()->contentReportService();

$viewers = [
    'reporter'  => $reporterId,
    'operator'  => $operatorId,
    'anonymous' => 0,
];

$results = [];

foreach (['published', 'archived', 'draft', 'scheduled', 'blocked'] as $state) {
    foreach ($viewers as $who => $viewerId) {
        $row = $byState[$state];

        $caps = $CAPS::forRow(
            \BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority::decide($pageId, $viewerId),
            [
                'status'           => $row['status'],
                'is_archived'      => $row['is_archived'],
                'comments_enabled' => true,
                'author_user_id'   => $row['author'],
            ],
            \BCC\Trust\Core\Announcements\AnnouncementViewerStanding::forViewer($viewerId),
            $featureOn
        );

        $advertised = ($caps['can_report']['allowed'] ?? null) === true;
        $reason     = $caps['can_report']['reason_code'] ?? null;

        $result   = $service->fileReport($viewerId, $SVC::KIND_ANNOUNCEMENT, $row['id'], 'spam', '');
        $accepted = ($result['ok'] ?? false) === true;

        $results["{$state}/{$who}"] = [
            'advertised' => $advertised,
            'reason'     => $reason,
            'result'     => $result,
            'accepted'   => $accepted,
        ];

        $check(
            sprintf('%-10s / %-9s  advertised === accepted', $state, $who),
            $advertised,
            $accepted
        );
    }
}

// The accepted set must be EXACTLY the two publicly readable rows, for the
// one viewer who is not their author. Anything else and the equivalence
// above could be satisfied by a capability that denies everything.
$accepted = array_keys(array_filter($results, static fn (array $r): bool => $r['accepted']));
sort($accepted);
$check(
    'ANTI-VACUITY: exactly two pairs were accepted',
    ['archived/reporter', 'published/reporter'],
    $accepted
);

$check(
    'the accepted published report was CREATED, with a real id',
    'created',
    $results['published/reporter']['result']['status'] ?? null
);
$check(
    'report_id is a real row id',
    true,
    (int) ($results['published/reporter']['result']['report_id'] ?? 0) > 0
);
$check(
    '§4.32.6 — an ARCHIVED notice is still reportable',
    'created',
    $results['archived/reporter']['result']['status'] ?? null
);

// ══ 3. NON-DISCLOSURE — one answer for every unreportable shape ═══════════
printf("\n==== 3. non-disclosure ====\n");

$absent = $service->fileReport($reporterId, $SVC::KIND_ANNOUNCEMENT, 999999999, 'spam', '');

$envelopes = [
    'draft'       => $results['draft/reporter']['result'],
    'scheduled'   => $results['scheduled/reporter']['result'],
    'blocked'     => $results['blocked/reporter']['result'],
    'nonexistent' => $absent,
];

$canonical = json_encode($envelopes['draft']);
foreach ($envelopes as $label => $env) {
    $check(
        "{$label} answers the SAME envelope as a row that never existed",
        $canonical,
        json_encode($env)
    );
}
$check('…and that envelope is bcc_not_found', 'bcc_not_found', $envelopes['draft']['error'] ?? null);
$check('…with a message that names nothing', 'Not found.', $envelopes['draft']['message'] ?? null);

// ⚠ The capability agrees, and with the SAME reason for all three states —
// so a client cannot tell them apart either.
foreach (['draft', 'scheduled', 'blocked'] as $state) {
    $check(
        "can_report on a {$state} row denies as unavailable",
        $CAPS::REASON_UNAVAILABLE,
        $results["{$state}/reporter"]['reason']
    );
}

// ══ 4. THE AUTHOR, THE ANONYMOUS VIEWER, AND THE DUPLICATE ════════════════
printf("\n==== 4. refusals with their own reasons ====\n");

$check(
    'the AUTHOR is refused by the route',
    'bcc_invalid_request',
    $results['published/operator']['result']['error'] ?? null
);
$check(
    '…with the self-report message',
    "You can't report your own post.",
    $results['published/operator']['result']['message'] ?? null
);
$check(
    '…and can_report said so FIRST, with its own reason',
    $CAPS::REASON_OWN_CONTENT,
    $results['published/operator']['reason']
);
$check(
    '…on the archived row too — archival does not rescue the author',
    $CAPS::REASON_OWN_CONTENT,
    $results['archived/operator']['reason']
);

$check(
    'an anonymous viewer is refused by the route',
    'bcc_unauthorized',
    $results['published/anonymous']['result']['error'] ?? null
);
$check(
    '…and can_report said auth_required',
    $CAPS::REASON_AUTH_REQUIRED,
    $results['published/anonymous']['reason']
);

// ⚠ The duplicate is ACCEPTED and changes nothing — the UNIQUE index makes
// the route idempotent per (reporter, kind, target), and that behaviour is
// preserved rather than re-implemented for the new kind.
$dup = $service->fileReport($reporterId, $SVC::KIND_ANNOUNCEMENT, $byState['published']['id'], 'spam', '');
$check('a duplicate report is idempotent', true, ($dup['ok'] ?? null) === true);
$check('…reported as existing', 'existing', $dup['status'] ?? null);
$check('…minting no new row', 0, (int) ($dup['report_id'] ?? -1));

// ══ 5. WHAT WAS STORED, AND WHAT WAS NOT ══════════════════════════════════
printf("\n==== 5. stored state ====\n");

$storedKinds = $wpdb->get_col(
    "SELECT DISTINCT target_kind FROM {$reportsTable} ORDER BY target_kind"
);
$check('the new kind is stored under its own name', true, in_array('announcement', (array) $storedKinds, true));

$announcementReports = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$reportsTable} WHERE target_kind = %s",
    'announcement'
));
$check('exactly two announcement reports exist', 2, $announcementReports);
$check(
    'the report table grew by exactly those two',
    $reportsBefore + 2,
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$reportsTable}")
);

// ⚠ No result anywhere was rate-limited, so no refusal above can be the
// throttle wearing a 404's clothes.
// ⚠ THE DUPLICATE IS IN THIS COUNT. An earlier version checked only the
// pairwise results, so a throttled duplicate produced three failures in
// section 4 and a cheerful "no attempt was rate-limited" here — the one
// place that would have named the cause.
$allResults = array_map(static fn (array $r): array => $r['result'], $results);
$allResults[] = $dup;
$allResults[] = $absent;

$rateLimited = array_filter(
    $allResults,
    static fn (array $r): bool => ($r['error'] ?? null) === 'bcc_rate_limited'
);
$check('no attempt was rate-limited, the duplicate included', 0, count($rateLimited));

// ══ 6. NO AUTOMATIC HIDING — §4.32.16 ════════════════════════════════════
printf("\n==== 6. no automatic hiding ====\n");

// ⚠ POSITIVE CONTROL FIRST. Firing an event nobody listens to proves
// nothing, and `AutoHideService` subscribes at runtime — so the listener is
// asserted before the firings that depend on it.
$check(
    'POSITIVE CONTROL: the auto-hide subscriber is wired to the event',
    true,
    has_action('bcc_content_reported') !== false
);

$targetId  = $byState['published']['id'];
$threshold = (int) get_option('bcc_autohide_threshold', 3);
$firings   = max(10, $threshold * 5);

for ($i = 1; $i <= $firings; $i++) {
    // Distinct reporter ids, because the real counter counts DISTINCT
    // reporters — repeating one id would make a zero meaningless.
    do_action('bcc_content_reported', 100000 + $i, $SVC::KIND_ANNOUNCEMENT, $targetId, $i, 'spam');
}

printf("  fired %d announcement-kind events against a threshold of %d\n", $firings, $threshold);

$check(
    'the notice is STILL published',
    'publish',
    (string) ($wpdb->get_var($wpdb->prepare(
        'SELECT post_status FROM ' . $wpdb->posts . ' WHERE ID = %d',
        $targetId
    )) ?? '')
);
$check(
    'no archived marker was written',
    '',
    (string) get_post_meta($targetId, \BCC\Trust\Core\Announcements\AnnouncementVisibility::META_ARCHIVED_AT, true)
);
$check('no hidden-activity row was created', $hiddenBefore, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$hiddenTable}"));

// ══ 7. NO SCORING, NO REWARD ══════════════════════════════════════════════
printf("\n==== 7. no scoring or reward effect ====\n");

foreach ($sideTables as $name => $info) {
    $check(
        $info['label'] . ' unchanged',
        $info['before'],
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$name}")
    );
}

// ══ 8. STILL PUBLICLY READABLE — reporting is not a takedown ══════════════
printf("\n==== 8. the notice survives being reported ====\n");

$repo = new \BCC\Trust\Core\Repositories\AnnouncementRepository();

$check(
    'the reported notice is still publicly readable',
    true,
    $repo->findPublic($pageId, $targetId) !== null
);
$check(
    'the archived one too (§4.32.6 keeps the permalink)',
    true,
    $repo->findPublic($pageId, $byState['archived']['id']) !== null
);
// ⚠ ANTI-VACUITY for the two above: the predicate still REFUSES a draft, so
// `findPublic` is not simply returning everything.
$check(
    'ANTI-VACUITY: findPublic still refuses the draft',
    null,
    $repo->findPublic($pageId, $byState['draft']['id'])
);

printf("\n==== pass=%d fail=%d skip=%d ====\n", $pass, $fail, $skip);
