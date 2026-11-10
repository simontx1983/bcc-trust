<?php
/**
 * READ-ONLY release probe for #122 / bcc-core #63 — the unread badge.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * bcc-core #63 replaced `PeepSoMessageRecipients::get_unread_messages_count`
 * with BCC-owned SQL, because that helper exposes no filter and the
 * blocked-sender exclusion cannot be applied from outside it. The
 * replacement must return the SAME NUMBER as the helper for every viewer
 * who has blocked nobody — otherwise the header badge moves for people
 * this feature is not about.
 *
 * ⚠ That claim cannot be made by the test suite:
 *   - bcc-core has no database harness at all;
 *   - bcc-trust's integration suite proves the predicates against SEEDED
 *     rows, which is the only way to exercise them (see below), but
 *     cannot speak to live data;
 *   - and the one comparison that matters here is against PeepSo's own
 *     query over the real table.
 * So this probe is the check, and it is meant to be re-run before each
 * deploy of either half.
 *
 * ⚠⚠ IT COMPARES SQL TO SQL, NOT TO A CALL INTO PeepSo, AND THAT IS
 * FORCED. PeepSo disables itself under WP-CLI, so
 * `class_exists('PeepSoMessageRecipients')` is FALSE here — which also
 * means the deployed `getUnreadConversationCountForUser` would hit its
 * own "PeepSo absent => 0" guard and return 0 before reaching any SQL.
 * Calling the real method from the CLI therefore proves nothing. Both
 * sides below are the two queries, the OLD one reproduced verbatim in
 * shape from:
 *     peepso-messages/classes/messagerecipients.php  get_unread_messages_count
 *     peepso-messages/classes/messageparticipants.php get_muted_conversations
 * whose PHP is nothing but that SQL plus count(). Stated as a
 * reproduction so nobody reads it as a call into PeepSo.
 *
 * ⚠ READ THE "unexercised" LINES IN THE OUTPUT. Measured 2026-11-10,
 * production AND staging both held ZERO rows in `wp_peepso_blocks` and
 * ZERO muted participant rows, and exactly ONE viewer with any unread
 * message. On that data "identical" is necessarily true and proves
 * nothing about the new clause, which is why the probe ends with a
 * POSITIVE CONTROL that simulates a block inside the query. If the
 * control cannot run, the parity result is weak and the output says so.
 *
 * ── HOW TO USE IT ───────────────────────────────────────────────────────
 *   wp eval-file scripts/tests/blocked-sender-badge-parity-probe.php \
 *       --skip-plugins --skip-themes
 *
 * `--skip-plugins` is deliberate: this probe needs no BCC class, and
 * skipping the plugins avoids firing the schema pass as a side effect of
 * measuring.
 *
 * PASS means: every zero-block viewer compared identical, AND the
 * positive control moved a number.
 *
 * ── WRITES ──────────────────────────────────────────────────────────────
 * ⛔ None. SELECT only. Prints user ids and counts; no message bodies, no
 * email addresses, no handles.
 */

declare(strict_types=1);

global $wpdb;

$recipients   = $wpdb->prefix . 'peepso_message_recipients';
$participants = $wpdb->prefix . 'peepso_message_participants';
$blocks       = $wpdb->prefix . 'peepso_blocks';

$fail = 0;
$weak = [];

printf("=== environment ===\n");
printf("  siteurl             %s\n", (string) get_option('siteurl'));
foreach ([$recipients, $participants, $blocks, $wpdb->posts, $wpdb->users] as $t) {
    $present = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $t
    )) > 0;
    if (!$present) {
        printf("  %-40s ABSENT  <-- cannot run\n", $t);
        $fail++;
        continue;
    }
    printf("  %-40s %d rows\n", $t, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}`"));
}
if ($fail > 0) {
    printf("\nFAIL: a required table is missing.\n");
    exit(1);
}

$blockRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$blocks}`");
$mutedRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$participants}` WHERE mpart_muted = 1");
printf("\n=== what this data can and cannot exercise ===\n");
printf("  block rows                %d%s\n", $blockRows, $blockRows === 0 ? '   <-- block clause UNEXERCISED by real data' : '');
printf("  muted participant rows    %d%s\n", $mutedRows, $mutedRows === 0 ? '   <-- muted clause UNEXERCISED by real data' : '');
if ($blockRows === 0) {
    $weak[] = 'no block rows exist, so parity is necessarily true';
}
if ($mutedRows === 0) {
    $weak[] = 'no muted rows exist, so the muted clause is not compared';
}

/** PeepSo's query, reproduced verbatim in shape. */
$oldCount = static function (int $uid) use ($wpdb, $recipients, $participants): int {
    $muted = $wpdb->get_col($wpdb->prepare(
        "SELECT `mpart_msg_id` FROM `{$participants}` WHERE `mpart_muted` = 1 and `mpart_user_id` = %d",
        $uid
    ));
    $muted = implode(',', array_map('intval', (array) $muted));

    $sql = "SELECT `mrec_msg_id` FROM `{$recipients}`
        WHERE `mrec_user_id` = %d AND `mrec_viewed` = 0 and `mrec_deleted` = 0 AND `mrec_parent_id` NOT IN
        (SELECT mpart_msg_id FROM `{$participants}` WHERE mpart_user_id NOT IN (SELECT ID FROM {$wpdb->users}))";
    if (strlen($muted)) {
        $sql .= " AND `mrec_parent_id` NOT IN ({$muted}) ";
    }
    $sql .= ' GROUP BY `mrec_parent_id`';

    return count((array) $wpdb->get_col($wpdb->prepare($sql, $uid)));
};

/**
 * The #63 query. BLOCKSOURCE is a placeholder so the positive control can
 * swap the blocks table for an inline literal pair without writing a row.
 */
$newTemplate = "SELECT COUNT(*) FROM (
     SELECT r.mrec_parent_id
       FROM {$recipients} r
      WHERE r.mrec_user_id = %d
        AND r.mrec_viewed = 0
        AND r.mrec_deleted = 0
        AND r.mrec_parent_id NOT IN (
            SELECT mpart_msg_id FROM {$participants}
             WHERE mpart_user_id NOT IN (SELECT ID FROM {$wpdb->users})
        )
        AND r.mrec_parent_id NOT IN (
            SELECT mpart_msg_id FROM {$participants}
             WHERE mpart_muted = 1 AND mpart_user_id = %d
        )
        AND NOT EXISTS (
            SELECT 1
              FROM {$wpdb->posts} p
              JOIN BLOCKSOURCE b ON b.blk_blocked_id = p.post_author
             WHERE p.ID = r.mrec_msg_id
               AND b.blk_user_id = %d
        )
      GROUP BY r.mrec_parent_id
 ) g";
$newSql = str_replace('BLOCKSOURCE', $blocks, $newTemplate);

$viewerIds = array_map('intval', (array) $wpdb->get_col(
    "SELECT DISTINCT mrec_user_id
       FROM `{$recipients}`
      WHERE mrec_viewed = 0 AND mrec_deleted = 0
      ORDER BY mrec_user_id
      LIMIT 500"
));

printf("\n=== OLD (PeepSo SQL) vs NEW (#63 SQL) ===\n");
printf("  %-9s %-8s %-6s %-6s %s\n", 'user_id', 'blocks', 'OLD', 'NEW', 'verdict');
$zeroBlock = 0;
$mismatch  = [];
foreach ($viewerIds as $uid) {
    $nBlocks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$blocks}` WHERE blk_user_id = %d", $uid));
    $old = $oldCount($uid);
    $new = (int) $wpdb->get_var($wpdb->prepare($newSql, $uid, $uid, $uid));
    $same = ($old === $new);
    if ($nBlocks === 0) {
        $zeroBlock++;
        if (!$same) {
            $mismatch[] = sprintf('user %d blocks nobody but OLD=%d NEW=%d', $uid, $old, $new);
        }
    }
    printf(
        "  %-9d %-8d %-6d %-6d %s\n",
        $uid,
        $nBlocks,
        $old,
        $new,
        $same ? 'identical' : ($nBlocks === 0 ? '*** MISMATCH ***' : 'differs (has blocks: expected)')
    );
}

printf("\n=== parity ===\n");
printf("  viewers with unread       %d\n", count($viewerIds));
printf("  zero-block viewers        %d\n", $zeroBlock);
printf("  mismatches                %d\n", count($mismatch));
foreach ($mismatch as $m) {
    printf("    %s\n", $m);
}
if ($mismatch !== []) {
    $fail++;
}
if ($zeroBlock <= 1) {
    $weak[] = sprintf('only %d zero-block viewer(s) exist, so parity samples almost nothing', $zeroBlock);
}

/**
 * POSITIVE CONTROL. Swap the blocks table for an inline literal pair --
 * a pretend "this viewer blocks this author" row -- and require the count
 * to MOVE. Nothing is written: the literal lives in the query.
 */
printf("\n=== POSITIVE CONTROL: a simulated block, in the query only ===\n");
$pairs = (array) $wpdb->get_results(
    "SELECT r.mrec_user_id AS viewer, p.post_author AS author, COUNT(*) AS n
       FROM `{$recipients}` r
       JOIN `{$wpdb->posts}` p ON p.ID = r.mrec_msg_id
      WHERE r.mrec_viewed = 0 AND r.mrec_deleted = 0
        AND p.post_author <> r.mrec_user_id
        AND p.post_author > 0
      GROUP BY r.mrec_user_id, p.post_author
      ORDER BY n DESC
      LIMIT 3",
    ARRAY_A
);

$controlMoved = 0;
if ($pairs === []) {
    printf("  no (viewer, author) pair with an unread message exists here, so the exclusion\n");
    printf("  CANNOT be demonstrated on this data. Do not record it as working.\n");
    $weak[] = 'the positive control could not run at all';
} else {
    foreach ($pairs as $pair) {
        $viewer = (int) $pair['viewer'];
        $author = (int) $pair['author'];
        $base   = (int) $wpdb->get_var($wpdb->prepare($newSql, $viewer, $viewer, $viewer));
        $simSql = str_replace(
            $blocks,
            '(SELECT ' . $viewer . ' AS blk_user_id, ' . $author . ' AS blk_blocked_id)',
            $newSql
        );
        $sim = (int) $wpdb->get_var($wpdb->prepare($simSql, $viewer, $viewer, $viewer));
        if ($sim < $base) {
            $controlMoved++;
        }
        printf(
            "  viewer %-7d blocks author %-7d (%s unread from them): %d -> %d  %s\n",
            $viewer,
            $author,
            (string) $pair['n'],
            $base,
            $sim,
            $sim < $base ? 'EXCLUSION WORKS' : ($sim === $base ? 'no change' : 'UNEXPECTED RISE')
        );
    }
    if ($controlMoved === 0) {
        $weak[] = 'the positive control ran but moved no number';
        $fail++;
    }
}

printf("\n=== verdict ===\n");
foreach ($weak as $w) {
    printf("  \u{26a0} weak: %s\n", $w);
}
printf("\n%s\n", $fail === 0
    ? ($weak === []
        ? 'PASS: parity holds and the exclusion is demonstrated.'
        : 'PASS with the caveats above -- read them before calling this verified.')
    : sprintf('FAIL: %d problem(s).', $fail));
printf("NOTHING WAS WRITTEN. SELECT only.\n");

exit($fail === 0 ? 0 : 1);
