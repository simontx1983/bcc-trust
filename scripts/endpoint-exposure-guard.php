<?php

declare(strict_types=1);

/**
 * Endpoint-exposure guard — a credential-bearing value must not reach an output.
 *
 * ── ⚠⚠⚠ WHY THIS EXISTS ─────────────────────────────────────────────────
 * On 2026-09-30 a live Alchemy API key was printed and had to be rotated. The
 * redactor in use masked the QUERY STRING only, and Alchemy carries its key in
 * the PATH — which `AlchemyEndpoint::nftBaseFromRpcUrl()` then rewrites into
 * `/nft/v3/<KEY>`, a third shape nobody had enumerated.
 *
 * The code was reviewed. The redactor's own docblock described the gap. Neither
 * caught it, because the defect was one line pairing a value with an output, and
 * that pairing is invisible in review unless you are looking for it. So it is
 * checked mechanically instead.
 *
 * ── WHAT IT CHECKS ──────────────────────────────────────────────────────
 * Within a single PHP statement, does a TAINTED SOURCE meet an OUTPUT SINK?
 *
 *   tainted   credential constants (BCC_ALCHEMY_API_KEY, BCC_HELIUS_API_KEY, …),
 *             `->rpc_url` / `['rpc_url']` / `rest_url`, the endpoint resolvers
 *             (`resolveRpcUrl`, `rpcEndpoint`, `metadataEndpoint`), the derived
 *             NFT API base (`nftBaseFromRpcUrl`, `alchemyNftBaseFromRpcUrl`), and
 *             `EndpointDescriptor::identity()`
 *
 *   sink      `echo` / `print` / `printf`, `esc_html*` / `esc_attr*`, the
 *             `Logger::*` methods, `error_log`, `trigger_error`, `var_dump`,
 *             `print_r`, `wp_json_encode`/`json_encode`, `new *Exception(…)` and
 *             `throw`
 *
 * Statement granularity is the point. It is narrow enough that `$url = rpc();`
 * on one line and `echo $host;` on another do not collide, and wide enough to
 * catch every real instance of this defect, all of which put the source inside
 * the sink's argument list.
 *
 * ── ⚠⚠ WHAT IT DELIBERATELY DOES NOT CATCH ──────────────────────────────
 *   1. TAINT THROUGH A VARIABLE, ACROSS STATEMENTS. `$u = $chain->rpc_url;` then
 *      `echo $u;` passes. Real dataflow analysis is out of scope for a guard that
 *      must stay readable; this catches the shape the incident actually had.
 *   2. `CosmosEndpointPolicy::fingerprint()`, an unsalted truncated SHA-256 of a
 *      Cosmos REST URL. It is NOT listed as tainted because it is internal-only
 *      (it reaches the breaker option and never admin HTML, REST or a view
 *      model) and because approved Cosmos endpoints are credential-free public
 *      LCDs from a closed allowlist. ⚠ If a credentialed Cosmos endpoint is ever
 *      allowed, that fingerprint becomes a 64-bit unsalted digest of a
 *      credentialed URL and must be added here.
 *   3. Anything outside `app/`. Tests construct credentialed fixtures on purpose.
 *
 * ── OVERRIDES ───────────────────────────────────────────────────────────
 * `endpoint-exposure-guard:allow — <reason>` on the same line or the line above.
 * A reason is REQUIRED; a bare allow is itself a failure, so silencing this is
 * never quieter than explaining it.
 *
 * Exit 0 = clean. Exit 1 = a tainted value meets an output. Exit 2 = the guard
 * could not run, which is NOT a pass.
 */

$root = dirname(__DIR__);
$note = static function (string $line): void { echo $line, "\n"; };

$note('  ENDPOINT-EXPOSURE GUARD');
$note('  ' . str_repeat('─', 68));

$scanRoot = $root . '/app';
if (!is_dir($scanRoot)) {
    $note('  FATAL: app/ not found — refusing to report a pass over nothing');
    exit(2);
}

// ── What counts as tainted ──────────────────────────────────────────────────
//
// ⚠⚠⚠ THE DISTINCTION THAT MAKES THIS GUARD USABLE: naming a credential is not
// reading one. The first draft matched token TEXT and produced six findings, of
// which four were prose —
//
//     Logger::error('BCC_ALCHEMY_API_KEY not configured in wp-config.php')
//     Logger::error('alchemy_getAssetTransfers: rpc_url missing or placeholder')
//
// Both are exactly the diagnostics an operator needs, and neither carries a
// value. A guard that cries wolf on them gets overridden into silence, so taint
// is decided from the TOKEN KIND instead of the characters.
//
// Constants: a value read is a BARE constant (`BCC_ALCHEMY_API_KEY`) or
// `constant('BCC_ALCHEMY_API_KEY')` — an exactly-equal quoted string. A longer
// message that happens to contain the name is a mention.
$credentialConstants = [
    'BCC_ALCHEMY_API_KEY',
    'BCC_HELIUS_API_KEY',
    'BCC_HELIUS_RPC_URL',
    'BCC_HELIUS_WEBHOOK_SECRET',
    'BCC_SOLANA_RPC_URL',
];

// Columns: a value read is `->rpc_url`, `?->rpc_url`, or the exactly-equal
// quoted string used as a subscript or an array key (`['rpc_url']`,
// `'rpc_url' => $url`). Prose containing the word is neither.
$credentialColumns = ['rpc_url', 'rest_url'];

// Calls that RETURN a credentialed or credential-derived endpoint.
$credentialResolvers = [
    'resolveRpcUrl',
    'rpcEndpoint',
    'metadataEndpoint',
    'nftBaseFromRpcUrl',
    'alchemyNftBaseFromRpcUrl',
];

// ⚠ `identity` is far too common a word to match on its own, so it counts only
// as `EndpointDescriptor::identity(…)` — the persisted comparison token, which
// must never be rendered or logged.
$identityOwner = 'EndpointDescriptor';

$sinkFunctions = [
    'esc_html', 'esc_html__', 'esc_html_e', 'esc_attr', 'esc_attr__', 'esc_attr_e',
    'esc_textarea', 'esc_js', 'esc_url', 'esc_url_raw',
    'error_log', 'trigger_error', 'var_dump', 'print_r', 'var_export',
    'json_encode', 'wp_json_encode', 'printf', 'sprintf', 'vsprintf', 'wp_die',
];

$sinkMethodSuffixes = ['::debug', '::info', '::notice', '::warning', '::error', '::critical', '::audit'];

/** @return list<string> */
$phpFiles = static function (string $dir): array {
    $out = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f instanceof SplFileInfo && $f->isFile() && strtolower($f->getExtension()) === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
};

$files = $phpFiles($scanRoot);
if ($files === []) {
    $note('  FATAL: no PHP files under app/ — refusing to report a pass over nothing');
    exit(2);
}

$violations  = [];
$allowed     = 0;
$badOverride = [];
$statements  = 0;

foreach ($files as $path) {
    $src = (string) file_get_contents($path);
    // ⚠ token_get_all(), not a regex. A `//` inside a string literal and a `;`
    // inside a comment both break naive splitting, and this guard's whole value
    // is that it does not have the blind spots the thing it replaced had.
    $tokens = @token_get_all($src);
    if ($tokens === []) {
        $note('  FATAL: could not tokenise ' . substr($path, strlen($root) + 1));
        exit(2);
    }

    $lines = preg_split('~\R~', $src) ?: [];

    // Group tokens into statements. Boundaries: `;`, `{`, `}`, and the close tag,
    // so a short echo embedded in markup is analysed on its own.
    //
    // ⚠ This comment does NOT spell out a close tag, because a close tag inside a
    // `//` comment really does end PHP mode — the first draft of this file did
    // exactly that and turned its own remaining 150 lines into inline HTML.
    $statement = [];
    $flush = static function () use (&$statement, &$statements, &$violations, &$allowed, &$badOverride, $credentialConstants, $credentialColumns, $credentialResolvers, $identityOwner, $sinkFunctions, $sinkMethodSuffixes, $lines, $path, $root): void {
        if ($statement === []) {
            return;
        }
        $current   = $statement;
        $statement = [];
        $statements++;

        $text = '';
        $line = 0;
        foreach ($current as $t) {
            if (is_array($t)) {
                $text .= $t[1];
                if ($line === 0) {
                    $line = (int) $t[2];
                }
            } else {
                $text .= $t;
            }
        }

        // ── Is there a sink? ──
        $sink = null;
        foreach ($current as $t) {
            if (is_array($t) && in_array($t[0], [T_ECHO, T_PRINT, T_THROW], true)) {
                $sink = strtolower(trim($t[1]));
                break;
            }
        }
        if ($sink === null) {
            foreach ($sinkFunctions as $fn) {
                if (preg_match('~(?<![A-Za-z0-9_$>])' . preg_quote($fn, '~') . '\s*\(~', $text) === 1) {
                    $sink = $fn . '()';
                    break;
                }
            }
        }
        if ($sink === null) {
            foreach ($sinkMethodSuffixes as $suffix) {
                if (stripos($text, $suffix) !== false) {
                    $sink = 'Logger' . $suffix;
                    break;
                }
            }
        }
        if ($sink === null && preg_match('~new\s+[A-Za-z0-9_\\\\]*Exception\s*\(~', $text) === 1) {
            $sink = 'new Exception()';
        }
        if ($sink === null) {
            return;
        }

        // ── Is there a tainted source? ──
        //
        // Decided from token kinds, so a credential NAMED in a message is not
        // confused with a credential READ.
        $taint = null;
        $count = count($current);
        for ($i = 0; $i < $count && $taint === null; $i++) {
            $tok = $current[$i];
            if (!is_array($tok)) {
                continue;
            }
            [$id, $textOf] = [$tok[0], $tok[1]];

            // A bare constant: `BCC_ALCHEMY_API_KEY`
            if ($id === T_STRING && in_array($textOf, $credentialConstants, true)) {
                $taint = $textOf;
                break;
            }

            // A resolver call returning an endpoint.
            if ($id === T_STRING && in_array($textOf, $credentialResolvers, true)) {
                $taint = $textOf . '()';
                break;
            }

            // `EndpointDescriptor::identity(…)` — the comparison token.
            if ($id === T_STRING && $textOf === 'identity') {
                $prev = $current[$i - 2] ?? null;
                if (is_array($prev) && $prev[1] === $identityOwner) {
                    $taint = 'EndpointDescriptor::identity()';
                    break;
                }
            }

            // `->rpc_url` / `?->rpc_url`
            if (in_array($id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $next = $current[$i + 1] ?? null;
                if (is_array($next) && in_array($next[1], $credentialColumns, true)) {
                    $taint = '->' . $next[1];
                    break;
                }
            }

            // An exactly-equal quoted string: a subscript, an array key, or
            // `constant('BCC_…')`. Prose is longer than the bare name and so
            // cannot match.
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $literal = trim($textOf, "'\"");
                if (in_array($literal, $credentialColumns, true)) {
                    $taint = "'" . $literal . "'";
                    break;
                }
                if (in_array($literal, $credentialConstants, true)) {
                    $taint = "constant('" . $literal . "')";
                    break;
                }
            }
        }
        if ($taint === null) {
            return;
        }

        // ── Override? ──
        $rel  = substr($path, strlen($root) + 1);
        $here = $lines[$line - 1] ?? '';
        $prev = $lines[$line - 2] ?? '';
        foreach ([$here, $prev] as $candidate) {
            if (stripos($candidate, 'endpoint-exposure-guard:allow') === false) {
                continue;
            }
            if (preg_match('~endpoint-exposure-guard:allow\s*[—-]\s*\S~u', $candidate) === 1) {
                $allowed++;

                return;
            }
            $badOverride[] = $rel . ':' . $line . ' — override with no reason';

            return;
        }

        $violations[] = $rel . ':' . $line . ' — `' . $taint . '` reaches ' . $sink;
    };

    foreach ($tokens as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_CLOSE_TAG, T_OPEN_TAG], true)) {
            // A comment cannot output anything, and inline HTML ends a statement.
            if (is_array($t) && in_array($t[0], [T_INLINE_HTML, T_CLOSE_TAG], true)) {
                $flush();
            }
            continue;
        }
        if (!is_array($t) && in_array($t, [';', '{', '}'], true)) {
            $flush();
            continue;
        }
        $statement[] = $t;
    }
    $flush();
}

$note(sprintf('  Scanned %d files, %d statements', count($files), $statements));
if ($allowed > 0) {
    $note(sprintf('  %d explicit override(s) with a stated reason', $allowed));
}

$fail = false;

if ($badOverride !== []) {
    $fail = true;
    $note('');
    $note('  OVERRIDES WITH NO REASON (' . count($badOverride) . '):');
    foreach ($badOverride as $v) {
        $note('    ✗ ' . $v);
    }
}

if ($violations !== []) {
    $fail = true;
    $note('');
    $note('  A CREDENTIAL-BEARING VALUE REACHES AN OUTPUT (' . count($violations) . '):');
    foreach ($violations as $v) {
        $note('    ✗ ' . $v);
    }
    $note('');
    $note('  Fix by describing the value first:');
    $note('    EndpointDescriptor::display($url)   scheme://host, safe to render or log');
    $note('    ProviderConfigStatus::describing()  provider/configured/hostname only');
    $note('  ⚠ EndpointDescriptor::identity() is a COMPARISON token. It must never');
    $note('    be rendered, logged, returned over REST or put in an exception.');
}

if ($fail) {
    $note('');
    $note('  FAIL');
    exit(1);
}

$note('  PASS — no credential-bearing value reaches an output');
exit(0);
