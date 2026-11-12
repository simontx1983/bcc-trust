<?php
/**
 * BCC_TRUST_SCHEMA_VERSION — how it is computed (F-151a).
 *
 * ── WHAT CHANGED ────────────────────────────────────────────────────────
 * The version used to be an md5 of the RAW bytes of every schema file, so
 * a comment-only or whitespace-only edit moved it and fired the full
 * installer (~200 queries of dbDelta DESCRIBE/SHOW sweeps) on every
 * environment at deploy. It now hashes a normalised form of each file:
 *
 *   - comments (`//`, `#`, block comments, docblocks) are dropped;
 *   - whitespace BETWEEN tokens is dropped (token boundaries are kept);
 *   - every other token is kept verbatim — in particular every string,
 *     heredoc and nowdoc body, which is where the SQL lives. A change to
 *     a CREATE TABLE, a column, an index or a default still moves the
 *     version, exactly as before.
 *
 * ── WHY NOT A HAND-BUMPED VERSION CONSTANT PER FILE ─────────────────────
 * A forgotten bump is a schema change that silently never installs — the
 * dangerous direction. A normalised hash can only err toward an extra,
 * idempotent installer pass, never toward a missed one.
 *
 * ── WHY THIS FILE IS NOT NAMED schema-*.php ─────────────────────────────
 * Every `schema-*.php` file is a hash INPUT. This file computes the hash;
 * it must not be one of its own inputs.
 *
 * Cost: one token_get_all() per input file per request — measured at
 * roughly +30% over the raw md5_file() pass it replaces (53 files).
 *
 * @package BCC\Trust
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_schema_source_fingerprint')) {
    /**
     * md5 of a PHP source's TOKEN SEQUENCE, without comments or whitespace.
     *
     * Each kept token is followed by a unit separator (\x1F), so token
     * boundaries are part of the hash: `- -` and `--`, or `new Foo` and
     * `newFoo`, can never collide, and whether whitespace sat between two
     * tokens cannot matter. String, heredoc and nowdoc bodies are tokens of
     * their own and are kept byte-for-byte.
     */
    function bcc_trust_schema_source_fingerprint(string $source): string
    {
        $normalised = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $normalised .= $token . "\x1F";
                continue;
            }
            [$id, $text] = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_WHITESPACE) {
                continue;
            }
            // The open/close tags carry the newline that follows them.
            if ($id === T_OPEN_TAG || $id === T_CLOSE_TAG) {
                $text = trim($text);
            }
            $normalised .= $text . "\x1F";
        }

        return md5($normalised);
    }
}

if (!function_exists('bcc_trust_compute_schema_version')) {
    /**
     * The schema version for the plugin rooted at $pluginDir.
     *
     * Inputs: every includes/database/schema-*.php, plus the two
     * repositories that self-install their own DDL outside that directory
     * (DisputeRepository::install(), SignalRepository::install_own_table()).
     * An unreadable file contributes a fixed marker, so the version still
     * moves when it becomes readable.
     */
    function bcc_trust_compute_schema_version(string $pluginDir): string
    {
        $files = glob($pluginDir . '/includes/database/schema-*.php') ?: [];

        foreach ([
            $pluginDir . '/app/Domain/Disputes/Repositories/DisputeRepository.php',
            $pluginDir . '/app/Domain/Onchain/Repositories/SignalRepository.php',
        ] as $selfInstaller) {
            if (is_file($selfInstaller)) {
                $files[] = $selfInstaller;
            }
        }

        if (!$files) {
            return '0000000000';
        }
        sort($files);

        $input = '';
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $input .= basename($file) . ':'
                . ($source === false ? 'unreadable' : bcc_trust_schema_source_fingerprint($source))
                . "\n";
        }

        return substr(md5($input), 0, 10);
    }
}
