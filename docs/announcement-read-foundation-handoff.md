# Validator announcements — the disabled read foundation, as shipped

Durable record of what landed on 2026-10-14, the evidence behind it, and the
combined-tree interaction probe that evidence depends on.

Written for an engineer picking up the announcement write slice (A3) or auditing
the read foundation later. Nothing here is a plan; it is a record.

## What is on `main`

Seven PRs, merged in this order, all staging-verified, feature **off**:

| PR | Slice | Merge |
|---|---|---|
| #329 | `UsersEndpoint` sibling request bounds | `1301f09c4b784eeecfdd64cbf4cb012414d39274` |
| #321 | A1 private CPT + four bypass guards | `d81d60ef57d4189ec8283d4b387224a3dce09332` |
| #324 | A2.2 visibility predicate | `419f4994656b745601323eee83dcd2936e99773a` |
| #325 | A2.3 keyset cursor | `8aaf9470187e3a81c2b9f07350d618042f057859` |
| #326 | A2.3a/b repository reads + view service | `a625b8ddf53cd298d363a0616084c51ef17ab3b7` |
| #323 | reportability | `eb4bc613aa4729bf27da477c85a6dc385fb63abd` |
| #327 | A2.4 read routes + feature switch | `d3f3a2098be3835a0611d41f1a5d75ab544d3cb7` |

Matching umbrella docs: #184 `f85c23fa0b`, #183 `24a29c6ff0`, #182 `109b464707`.

`main` tree `7f76d5ae73698028b6e66ac1ee10fee5dbe14ad2`. Final CI:
`OK (4406 tests, 31066 assertions)` unit, `OK (772, 7991)` MySQL,
`OK (388, 4015)` MariaDB, PHPStan L8 clean, classmap 548.

## The feature is off, and that is structural

`bcc_announcements_enabled` is **absent** from `wp_options` on staging.
`AnnouncementFeature::isEnabled()` is the **first statement of all four
handlers**, so with the switch absent every route answers 404 before any
authority resolution or data read.

Proved on live staging against a **real** validator page id (1434, from
`wp_bcc_page_read_model`), so a 404 cannot be mistaken for a missing page:

```
GET /bcc/v1/validators/1434/announcements           -> 404
GET /bcc/v1/validators/1434/announcements/rotator   -> 404
GET /bcc/v1/validators/1434/announcements/1         -> 404
GET /bcc/v1/me/validators/1434/announcements        -> 404
{"error":{"code":"bcc_not_found","message":"Not found.","status":404},"_meta":{"version":"v1"}}
```

⚠⚠ The code is **`bcc_not_found`** — the handler's own refusal from
`AnnouncementFeature::notFound()` — **not `rest_no_route`**. A control request to
an unregistered sibling path returns `rest_no_route`. That contrast is the proof:
the routes are registered, the handlers ran, and they refused because the switch
is absent. Checking only for "404" would not have distinguished the two.

## The interaction probe this file exists to preserve

`scripts/tests/announcement-combined-interactions.php` — restored here from the
verification branch `tmp/candidate-a24-with-323` (`a594163`), where it was
committed directly and therefore belonged to no slice PR.

It is **not CI-wired**: it needs a real WordPress with a seeded claim chain, which
neither the unit nor the integration bootstrap provides. Run it by hand:

```
wp --allow-root --skip-themes eval-file \
  wp-content/plugins/bcc-trust/scripts/tests/announcement-combined-interactions.php
```

Last result, on WP 6.7.2 / PHP 8.2 / MariaDB 11.8.9 against the committed bytes of
the complete tree: **pass=42 fail=0**, 103 output lines. The eight sections:

| # | What it proves |
|---|---|
| 0 | all three slices are present in the tree under test (anti-vacuity) |
| 1 | #323 x #321 — the stored module id matches the stored kind |
| 2 | #323 auto-hide, the fail-closed direction |
| 3 | seeds the full claim chain plus one genuinely public announcement |
| 4 | ⚠⚠ #323 x #324 x #327 — **reports do not hide an announcement** |
| 5 | #323 x #327 — `can_report` appears only where capabilities do |
| 6 | #323 adds no engagement; the no-reactions guarantee still holds |
| 7 | feature-off still wins **with reports present** |

⚠ Section 4 is the one that matters most and the one that was hardest to get
right. Three defects of mine were found while building it, all of which had
produced a GREEN run first:

1. Module ids were passed as strings to `mayAutoHide(int)` — a `TypeError` killed
   the run outright.
2. ⚠⚠ **Report column names were guessed, so every insert failed silently and
   "still listed after 6 reports" PASSED with zero reports actually present.**
   Fixed by naming columns explicitly and asserting every insert's return value.
3. Six reports from one user were rejected by `unique_reporter_target`, so the
   threshold was never reached. Fixed with six distinct reporters.

The lesson worth carrying: a passing assertion about "after N reports" is
worthless unless the N inserts are themselves asserted.

## Evidence limitations, stated

- The probe is **hand-run**, not CI-wired. A future change to `AutoHideService`
  or `ContentReportRepository` will not trip it automatically.
- `commentCountsFor()` returns a **measured** zero, not a substituted one: the only
  `wp_insert_comment` occurrence under `app/` is inside a docblock, so nothing
  writes `bcc_ann_comment` yet. The count becomes real when A5 lands, with no
  change to the read path.
- `blocked` is unreachable. `AnnouncementVisibility` never returns it and the owner
  route's schema rejects `?status=blocked` outright, because reconciler arm C does
  not exist. Do not map it to `draft` to make it resolve.
- The archived-row path keeps its permalink by design (§4.32.6); archival is not
  deletion and is one-way in v1.

## What the read foundation deliberately does not do

No write route of any kind: four routes, all `WP_REST_Server::READABLE`, zero
`CREATABLE`/`EDITABLE`/`DELETABLE`. The publication-authorization marker
(`_bcc_announcement_publish_authorized_at` + `_bcc_announcement_publish_authorized_user_id`)
is **read** by predicate 3 and written by **nothing** — that writer is A3's, and
which moment writes it is a real design decision with a failure mode on each side.
No scheduler promotes a `future`-dated row. `FeedColdStartService::VERB_BY_MODULE`
has no `205` entry, so an announcement falls back to "Recently on the floor."
