<?php
/**
 * Synthetic ring fixtures — legitimate community shapes vs manipulation
 * shapes — run against the REAL detection arithmetic.
 *
 * ⚠⚠ ISOLATED ONLY. This computes; it does NOT run detection, does NOT write
 * a score, does NOT touch a user. It replicates three pieces of production
 * logic exactly, each cited, so the output is reproducible and checkable:
 *
 *   1. the mutual-pair gate   VoteRepository::getMutualVotePairs()
 *                             — HAVING COUNT(*) >= BCC_TRUST_RING_MIN_SIZE
 *                             over the join's CROSS PRODUCT
 *   2. ring strength          TrustGraph::calculateRingStrength()  (:1029)
 *                             avgWeight * density * count > 5.0
 *   3. the endorsement rule   TrustGraph::detectEndorsementRings() (:1064)
 *                             any directed cycle of >= RING_MIN_SIZE, with
 *                             NO strength gate of any kind
 *
 * The point is not that these numbers are surprising. It is that the same
 * rule, applied to a shape a real trade community produces, returns the same
 * verdict as it does for a shape built to manipulate.
 */

$MIN   = defined('BCC_TRUST_RING_MIN_SIZE') ? BCC_TRUST_RING_MIN_SIZE : 3;
$THRESH = defined('BCC_TRUST_RING_STRENGTH_THRESHOLD') ? BCC_TRUST_RING_STRENGTH_THRESHOLD : 5.0;

/** TrustGraph::calculateRingStrength(), verbatim in behaviour. */
function ring_strength(array $ring, array $weights): float
{
    $tw = 0.0; $edges = 0; $n = count($ring);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $k = $ring[$i] . '_' . $ring[$j];
            if (isset($weights[$k])) { $tw += (float) $weights[$k]; $edges++; }
        }
    }
    if ($n < 2) { return 0.0; }
    $possible = ($n * ($n - 1)) / 2;
    $density  = $possible > 0 ? $edges / $possible : 0.0;
    $avg      = $edges > 0 ? $tw / $edges : 0.0;
    return $avg * $density * $n;
}

/**
 * total_weight for one mutual pair, as the SQL computes it:
 * SUM(v1.weight + v2.weight) over the join's cross product. With p pages
 * voted each way and vote weight w, that is p*p rows each contributing 2w.
 */
function pair_weight(int $pagesEachWay, float $w): float
{
    return ($pagesEachWay * $pagesEachWay) * (2 * $w);
}

function pair_qualifies(int $pagesEachWay, int $min): bool
{
    return ($pagesEachWay * $pagesEachWay) >= $min;   // the HAVING clause
}

/** Undirected edge set -> weights map keyed both ways, as the graph builder does. */
function weights_for(array $pairs, int $pagesEachWay, float $w): array
{
    $out = [];
    foreach ($pairs as [$a, $b]) {
        $out[$a . '_' . $b] = pair_weight($pagesEachWay, $w);
        $out[$b . '_' . $a] = pair_weight($pagesEachWay, $w);
    }
    return $out;
}

function complete_pairs(array $m): array
{
    $p = []; $n = count($m);
    for ($i = 0; $i < $n; $i++) { for ($j = $i + 1; $j < $n; $j++) { $p[] = [$m[$i], $m[$j]]; } }
    return $p;
}

function cycle_pairs(array $m): array
{
    $p = []; $n = count($m);
    for ($i = 0; $i < $n; $i++) { $p[] = [$m[$i], $m[($i + 1) % $n]]; }
    return $p;
}

// ─────────────────────────────────────────────────────────────────────────
// VOTE-RING SHAPES
// ─────────────────────────────────────────────────────────────────────────
//
// `pages` is how many of the OTHER member's pages each member voted on.
// `w` is the voter's effective vote weight — an established member sits near
// 1.0; a brand-new or low-rank member is an order of magnitude lower, which
// is the ONLY thing the strength gate actually excludes.

$voteCases = [
    // ── shapes a legitimate community produces ──────────────────────────
    ['LEGIT  3 tradespeople, each used the others, 2 pages each', [1,2,3], 'complete', 2, 1.0],
    ['LEGIT  same trio but only a chain: A-B, B-C (no A-C)',      [1,2,3], 'chain',    2, 1.0],
    ['LEGIT  3 members, ONE page each way (single reciprocal job)',[1,2,3], 'complete', 1, 1.0],
    ['LEGIT  a 6-person hall, everyone has used everyone',        [1,2,3,4,5,6], 'complete', 2, 1.0],
    ['LEGIT  3 brand-new members, low vote weight',               [1,2,3], 'complete', 2, 0.1],
    ['LEGIT  mentor + 2 apprentices, 2 pages each',               [1,2,3], 'complete', 2, 1.0],

    // ── shapes built to manipulate ──────────────────────────────────────
    ['MANIP  3 sock puppets, 2 pages each, full reciprocity',     [1,2,3], 'complete', 2, 1.0],
    ['MANIP  12-account reciprocity scheme, dense',               range(1,12), 'complete', 2, 1.0],
    ['MANIP  20-account ring, chain only (cheap to build)',       range(1,20), 'cycle',    2, 1.0],
    ['MANIP  3 accounts, high volume: 5 pages each way',          [1,2,3], 'complete', 5, 1.0],
];

printf("VOTE RINGS — gate 1: pair needs >= %d joined rows; gate 2: component >= %d;\n", $MIN, $MIN);
printf("             gate 3: strength = avgWeight x density x count must EXCEED %.1f\n\n", $THRESH);
printf("%-56s %6s %7s %9s %8s %s\n", 'shape', 'pairs?', 'members', 'pair_wt', 'strength', 'DETECTED');
printf("%s\n", str_repeat('-', 104));

foreach ($voteCases as [$label, $members, $shape, $pages, $w]) {
    $pairs = $shape === 'complete' ? complete_pairs($members)
           : ($shape === 'cycle'   ? cycle_pairs($members)
           : [[$members[0], $members[1]], [$members[1], $members[2]]]);   // chain
    $qualifies = pair_qualifies($pages, $MIN);
    $weights   = $qualifies ? weights_for($pairs, $pages, $w) : [];
    $strength  = ring_strength($members, $weights);
    $detected  = $qualifies && count($members) >= $MIN && $strength > $THRESH;
    printf("%-56s %6s %7d %9.2f %8.2f %s\n",
        $label,
        $qualifies ? 'yes' : 'NO',
        count($members),
        pair_weight($pages, $w),
        $strength,
        $detected ? 'YES' : 'no');
}

// ─────────────────────────────────────────────────────────────────────────
// ENDORSEMENT-RING SHAPES
// ─────────────────────────────────────────────────────────────────────────
printf("\n\nENDORSEMENT RINGS — any directed cycle of >= %d members.\n", $MIN);
printf("                   NO strength gate. NO volume gate. ONE vouch per edge is enough.\n\n");
printf("%-56s %7s %s\n", 'shape', 'members', 'DETECTED');
printf("%s\n", str_repeat('-', 76));

$endorseCases = [
    ['LEGIT  A vouches B, B vouches C, C vouches A (one each)', 3, true],
    ['LEGIT  a 5-person collective vouching in a loop',          5, true],
    ['LEGIT  two people vouching for each other only',           2, false],
    ['MANIP  3 sock puppets vouching in a loop',                 3, true],
    ['MANIP  10 accounts vouching in a loop',                   10, true],
];
foreach ($endorseCases as [$label, $n, $cycle]) {
    printf("%-56s %7d %s\n", $label, $n, ($cycle && $n >= $MIN) ? 'YES' : 'no');
}

printf("\n\n⚠ THE RESULT WORTH READING TWICE\n");
printf("  Every LEGIT row above with normal vote weight is detected, on the same\n");
printf("  terms as the MANIP rows. The rule separates reciprocal structure from\n");
printf("  non-reciprocal structure. It does not separate legitimate reciprocity\n");
printf("  from manufactured reciprocity, because it has no input that could.\n");
printf("\n  The two LEGIT rows that escape do so for reasons unrelated to intent:\n");
printf("    - one page each way   -> 1 joined row, below the >= %d pair gate\n", $MIN);
printf("    - brand-new members   -> vote weight too low to clear strength %.1f\n", $THRESH);
printf("  i.e. the rule is most lenient toward the newest accounts, which is the\n");
printf("  opposite of the risk profile it is meant to describe.\n");
