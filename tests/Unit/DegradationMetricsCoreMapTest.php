<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every DegradationMetrics event bcc-trust records, and bcc-core's canonical
 * health map (`bcc-core.php`, the `healthSnapshot([...])` in the
 * `bcc_system_health` filter), agree in BOTH directions:
 *
 *   1. every event trust records is registered in core's map, or it never
 *      reaches /system/health;
 *   2. every event registered under a TRUST-EMITTED subsystem has an emitter
 *      in trust, or the health page shows a counter that can never move.
 *
 * ONE test for every subsystem, so no feature writes its own copy.
 *
 * ── HOW EMITTERS ARE FOUND ──────────────────────────────────────────────
 * Every `DegradationMetrics::record(<subsystem>, <event>)` call in app/,
 * includes/ and bcc-trust.php, found by TOKENS (never a docblock or comment):
 *   - each argument a string literal, or a same-file `self::`/`static::`
 *     constant holding one → read directly;
 *   - anything else (a variable, a concatenation) → the file must be in
 *     {@see dynamicEmitters()}, which derives the events it can record from
 *     the literals that feed it. An undeclared non-literal call fails, so the
 *     scan can never silently miss a writer.
 *
 * ── WHAT "TRUST-EMITTED" MEANS ──────────────────────────────────────────
 * A subsystem with at least one record() call in trust. Pinned in
 * TRUST_EMITTED, so a new one is a conscious change. A registered event of
 * such a subsystem that trust does not emit must be listed: in
 * EMITTED_ELSEWHERE (another plugin records it) or NOT_EMITTED (registered
 * ahead of its writer, or after it was removed). Each NOT_EMITTED entry says
 * when and why it was listed, and the
 * test fails once it is stale: the event gained an emitter, or is no longer
 * registered. There is deliberately no calendar expiry, which would turn
 * main red on a date for every unrelated PR.
 *
 * Core is read from the sibling checkout, as CI lays it out
 * ({@see \BCC\Trust\Tests\Support\CoreSibling}).
 */
final class DegradationMetricsCoreMapTest extends TestCase
{
    /** Every subsystem trust records into, sorted. */
    private const TRUST_EMITTED = [
        'account_security_mail',
        'attestation_synthesis',
        'audit_log_swallow',
        'auth_throttle',
        'auth_token',
        'caution_list',
        'caution_list_risky_ceiling',
        'collection_persist',
        'contribution_recovery',
        'cosmos_tx_proof',
        'cron_dispatch',
        'elite_eligibility',
        'gated_group_provision',
        'helius_dedup',
        'moderation_hidden_ids',
        'nft_indexer',
        'page_privacy',
        'password_breach_check',
        'post_commit_task',
        'rank_scoring',
        'read_model_fallback',
        'validator_index',
        'validator_messaging',
        'validator_relationships',
    ];

    /**
     * Registered events of trust-emitted subsystems that another plugin
     * records. 'subsystem.event' => who records it.
     *
     * @var array<string, string>
     */
    private const EMITTED_ELSEWHERE = [];

    /**
     * Registered, but no trust code records them: ahead of their writer, or
     * after it was removed. 'subsystem.event' => [the date listed, why].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NOT_EMITTED = [
        'collection_persist.watermark_withheld' => ['2026-11-24', 'declared by #221 with no writer (the only withholding is the incomplete walk in HoldingsService); CollectionPersistMetricsTest pins the absence'],
    ];

    /**
     * Non-literal record() calls: relative file => [subsystem, the events it
     * can record (derived from the literals that feed it), why it is dynamic].
     *
     * @return array<string, array{0: string, 1: list<string>, 2: string}>
     */
    private static function dynamicEmitters(): array
    {
        $root = self::trustRoot();
        $read = static fn (string $rel): string => (string) file_get_contents($root . '/' . $rel);

        // auth_throttle: record('auth_throttle', $action . '_ceiling'); the
        // actions are the literals its callers pass.
        preg_match_all("/allowIdentityAttempt\\(\\s*'([a-z0-9_]+)'/", implode("\n", array_map($read, self::phpFiles())), $actions);

        $literals = static function (string $rel, string $pattern) use ($read): array {
            preg_match_all($pattern, $read($rel), $m);

            return array_values(array_unique($m[1]));
        };

        return [
            'app/Domain/Core/REST/Auth/AuthSupport.php' => [
                'auth_throttle',
                array_values(array_unique(array_map(static fn (string $a): string => $a . '_ceiling', $actions[1]))),
                'the event is the caller\'s action + "_ceiling"',
            ],
            'app/Domain/Core/Repositories/HiddenActivityRepository.php' => [
                'moderation_hidden_ids',
                $literals('app/Domain/Core/Repositories/HiddenActivityRepository.php', "/self::recordReadFailure\\(\\s*'([a-z0-9_]+)'/"),
                'recordReadFailure($event) is called with a literal',
            ],
            'app/Domain/Core/Services/AccountSecurityMailer.php' => [
                'account_security_mail',
                $literals('app/Domain/Core/Services/AccountSecurityMailer.php', "/self::send\\([^;]*?'([a-z0-9_]+_send_failed)'/s"),
                'send(..., $failureEvent) is called with a literal',
            ],
            'app/Domain/Onchain/Services/CosmosTxInclusionFetcher.php' => [
                'cosmos_tx_proof',
                $literals('app/Domain/Onchain/Services/CosmosTxInclusionFetcher.php', "/self::count\\('([a-z0-9_]+)'\\)/"),
                'count($event) is called with a literal',
            ],
        ];
    }

    // ── the tests ───────────────────────────────────────────────────────

    /** Every record() call is either read directly or declared, and every declaration is live. */
    public function testEveryRecordCallIsReadOrDeclared(): void
    {
        [, $undeclared, $dynamicFiles] = self::scan();

        self::assertSame([], $undeclared, 'a non-literal DegradationMetrics::record() call must be declared in dynamicEmitters()');
        foreach (self::dynamicEmitters() as $file => [$subsystem, $events]) {
            self::assertContains($file, $dynamicFiles, "{$file} is declared dynamic but has no non-literal record() call (stale declaration)");
            self::assertNotSame([], $events, "{$file}: the derivation found no events (anti-vacuity)");
        }
    }

    /** Direction 1: every event trust records is registered in core. */
    public function testEveryEventTrustRecordsIsRegistered(): void
    {
        $map = self::coreMap();
        foreach (self::emitted() as $subsystem => $events) {
            self::assertArrayHasKey($subsystem, $map, "trust records into '{$subsystem}', which bcc-core does not register");
            foreach ($events as $event) {
                self::assertContains($event, $map[$subsystem], "trust records '{$subsystem}.{$event}', which bcc-core does not register");
            }
        }
    }

    /** The trust-emitted subsystems are exactly these. */
    public function testTheTrustEmittedSubsystemsAreThese(): void
    {
        $emitted = array_keys(self::emitted());
        sort($emitted);

        self::assertSame(self::TRUST_EMITTED, $emitted);
    }

    /** Direction 2: every registered event of a trust-emitted subsystem has an emitter, or is listed. */
    public function testEveryRegisteredEventOfATrustSubsystemHasAnEmitter(): void
    {
        $map     = self::coreMap();
        $emitted = self::emitted();
        $missing = [];
        foreach (self::TRUST_EMITTED as $subsystem) {
            foreach ($map[$subsystem] ?? [] as $event) {
                $key = $subsystem . '.' . $event;
                if (!in_array($event, $emitted[$subsystem] ?? [], true)
                    && !isset(self::EMITTED_ELSEWHERE[$key])
                    && !isset(self::NOT_EMITTED[$key])
                ) {
                    $missing[] = $key;
                }
            }
        }

        self::assertSame([], $missing, 'registered under a trust-emitted subsystem, but nothing in trust records it (and it is not listed)');
    }

    /** A listed exception is dropped the moment it stops being true. */
    public function testTheListedExceptionsAreCurrent(): void
    {
        $map     = self::coreMap();
        $emitted = self::emitted();
        foreach (array_keys(self::NOT_EMITTED + self::EMITTED_ELSEWHERE) as $key) {
            [$subsystem, $event] = explode('.', $key, 2);
            self::assertContains($event, $map[$subsystem] ?? [], "{$key} is listed but no longer registered");
            self::assertNotContains($event, $emitted[$subsystem] ?? [], "{$key} is listed but trust now records it; remove it from the list");
        }
        foreach (self::NOT_EMITTED as $key => [$added, $why]) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $added, "{$key}: the date it was added");
            self::assertNotSame('', trim($why), "{$key}: why it is pending");
        }
    }

    // ── the scan ────────────────────────────────────────────────────────

    /** @return array<string, list<string>> subsystem => events trust records */
    private static function emitted(): array
    {
        [$pairs] = self::scan();
        foreach (self::dynamicEmitters() as [$subsystem, $events]) {
            foreach ($events as $event) {
                $pairs[$subsystem][] = $event;
            }
        }
        foreach ($pairs as $subsystem => $events) {
            $pairs[$subsystem] = array_values(array_unique($events));
        }
        ksort($pairs);

        return $pairs;
    }

    /**
     * @return array{0: array<string, list<string>>, 1: list<string>, 2: list<string>}
     *         literal pairs, undeclared non-literal call sites, files with a non-literal call
     */
    private static function scan(): array
    {
        $root     = self::trustRoot();
        $declared = self::dynamicEmitters();
        $pairs    = [];
        $undeclared = [];
        $dynamicFiles = [];

        foreach (self::phpFiles() as $rel) {
            $src    = (string) file_get_contents($root . '/' . $rel);
            $tokens = array_values(array_filter(
                token_get_all($src),
                static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            $n = count($tokens);
            for ($i = 0; $i + 3 < $n; $i++) {
                $name = is_array($tokens[$i]) ? $tokens[$i][1] : '';
                if (!str_ends_with($name, 'DegradationMetrics')
                    || !is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_COLON
                    || !is_array($tokens[$i + 2]) || strtolower($tokens[$i + 2][1]) !== 'record'
                    || $tokens[$i + 3] !== '('
                ) {
                    continue;
                }
                $args = self::arguments($tokens, $i + 4);
                $subsystem = isset($args[0]) ? self::resolve($args[0], $src) : null;
                $event     = isset($args[1]) ? self::resolve($args[1], $src) : null;
                if ($subsystem !== null && $event !== null) {
                    $pairs[$subsystem][] = $event;
                    continue;
                }
                $dynamicFiles[] = $rel;
                if (!isset($declared[$rel])) {
                    $undeclared[] = $rel . ':' . $tokens[$i][2];
                }
            }
        }

        return [$pairs, $undeclared, array_values(array_unique($dynamicFiles))];
    }

    /**
     * The top-level arguments of a call whose '(' precedes $start.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<list<array{0: int, 1: string, 2: int}|string>>
     */
    private static function arguments(array $tokens, int $start): array
    {
        $args  = [[]];
        $depth = 0;
        for ($j = $start, $n = count($tokens); $j < $n; $j++) {
            $t = $tokens[$j];
            if (in_array($t, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($t, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($t === ',' && $depth === 0) {
                $args[] = [];
                continue;
            }
            $args[count($args) - 1][] = $t;
        }

        return $args;
    }

    /**
     * A string literal, or a same-file `self::`/`static::` constant holding one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $arg
     */
    private static function resolve(array $arg, string $src): ?string
    {
        if (count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return substr($arg[0][1], 1, -1);
        }
        if (count($arg) === 3 && is_array($arg[0]) && in_array(strtolower($arg[0][1]), ['self', 'static'], true)
            && is_array($arg[1]) && $arg[1][0] === T_DOUBLE_COLON && is_array($arg[2])
            && preg_match('/const\s+' . preg_quote($arg[2][1], '/') . "\\s*=\\s*'([a-z0-9_]+)'\\s*;/", $src, $m) === 1
        ) {
            return $m[1];
        }

        return null;
    }

    /** @return array<string, list<string>> core's canonical map: subsystem => events */
    private static function coreMap(): array
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents(\BCC\Trust\Tests\Support\CoreSibling::require('bcc-core.php'))),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $n = count($tokens);
        for ($i = 0; $i < $n - 2; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][1] === 'healthSnapshot' && $tokens[$i + 1] === '(' && $tokens[$i + 2] === '[') {
                break;
            }
        }
        self::assertLessThan($n - 2, $i, 'bcc-core.php no longer builds its map with healthSnapshot([...])');

        $map = [];
        $j   = $i + 3;
        while ($j < $n && $tokens[$j] !== ']') {
            $key = $tokens[$j];
            self::assertTrue(is_array($key) && $key[0] === T_CONSTANT_ENCAPSED_STRING, 'a map key is not a string literal');
            self::assertTrue(is_array($tokens[$j + 1]) && $tokens[$j + 1][0] === T_DOUBLE_ARROW && $tokens[$j + 2] === '[', 'a map value is not a literal list');
            $events = [];
            for ($j += 3; $tokens[$j] !== ']'; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $events[] = substr($tokens[$j][1], 1, -1);
                }
            }
            $map[substr($key[1], 1, -1)] = $events;
            $j++; // past ']'
            if ($tokens[$j] === ',') {
                $j++;
            }
        }
        self::assertGreaterThan(30, count($map), 'anti-vacuity: the canonical map was read');

        return $map;
    }

    /** @return list<string> every PHP file trust ships, relative to the plugin root */
    private static function phpFiles(): array
    {
        $root  = self::trustRoot();
        $files = ['bcc-trust.php'];
        foreach (['app', 'includes'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        sort($files);

        return $files;
    }

    private static function trustRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
