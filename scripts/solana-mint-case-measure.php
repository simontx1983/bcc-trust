<?php
/**
 * READ-ONLY measurement of the Solana contract_address case fold.
 * No writes. No provider calls. Prints aggregates and redacted samples only.
 */
global $wpdb;
$h = $wpdb->prefix . 'bcc_nft_holdings';
$c = $wpdb->prefix . 'bcc_chains';

$exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE table_schema = DATABASE() AND table_name = %s", $h));
if ((int) $exists !== 1) { echo "  holdings table ABSENT\n"; return; }

$chains = $wpdb->get_results(
    "SELECT id, slug, chain_type FROM {$c} WHERE LOWER(chain_type) = 'solana'");
echo "  solana chains: " . count($chains) . "\n";
foreach ($chains as $ch) {
    echo "    chain_id {$ch->id}  slug {$ch->slug}\n";
}
if ($chains === []) { echo "  -> no solana chain rows; nothing to measure\n"; return; }

$ids = implode(',', array_map(static fn($r) => (int) $r->id, $chains));

$tot = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$h} WHERE chain_id IN ({$ids})");
echo "\n  TOTAL solana holdings rows: {$tot}\n";
if ($tot === 0) { echo "  -> zero rows: the fold has written nothing on this environment\n"; }

// BINARY comparisons so the case-insensitive column collation cannot hide the difference.
$q = [
  'contract != token_id (BINARY)        ' => "BINARY contract_address <> BINARY token_id",
  'contract is all-lowercase            ' => "BINARY contract_address = BINARY LOWER(contract_address)",
  'token_id has uppercase (case intact) ' => "BINARY token_id <> BINARY LOWER(token_id)",
  'RECOVERABLE: lower(token_id)=contract' => "BINARY LOWER(token_id) = BINARY contract_address AND BINARY token_id <> BINARY contract_address",
  'AMBIGUOUS: both all-lowercase        ' => "BINARY token_id = BINARY LOWER(token_id) AND BINARY contract_address = BINARY LOWER(contract_address)",
  'UNEXPLAINED: differ, not by case     ' => "BINARY LOWER(token_id) <> BINARY contract_address AND BINARY contract_address <> BINARY token_id",
];
foreach ($q as $label => $where) {
    $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$h} WHERE chain_id IN ({$ids}) AND {$where}");
    printf("  %-38s %d\n", $label, $n);
}

// Distinct folded contracts, and whether each maps to exactly ONE case-exact token_id.
$d = (int) $wpdb->get_var("SELECT COUNT(DISTINCT contract_address) FROM {$h} WHERE chain_id IN ({$ids})");
echo "\n  distinct contract_address values: {$d}\n";
$amb = (int) $wpdb->get_var(
  "SELECT COUNT(*) FROM (
     SELECT contract_address, COUNT(DISTINCT BINARY token_id) k
       FROM {$h} WHERE chain_id IN ({$ids})
      GROUP BY contract_address HAVING k > 1) t");
echo "  folded contracts mapping to >1 distinct token_id (recovery ambiguity): {$amb}\n";

// Redacted sample: show only the CASE PATTERN, never the address.
$rows = $wpdb->get_results("SELECT contract_address, token_id FROM {$h}
    WHERE chain_id IN ({$ids}) AND BINARY contract_address <> BINARY token_id LIMIT 5");
if ($rows) {
    echo "\n  sample case patterns (U=upper L=lower D=digit, address REDACTED):\n";
    foreach ($rows as $r) {
        $pat = static fn(string $s): string => preg_replace(['/[A-Z]/','/[a-z]/','/[0-9]/'], ['U','L','D'], $s);
        echo "    contract " . $pat((string) $r->contract_address) . "\n";
        echo "    token_id " . $pat((string) $r->token_id) . "\n    --\n";
    }
}
echo "\n  (READ-ONLY: no UPDATE, INSERT or DELETE was issued)\n";
