<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * F-94: the one way a unit test reaches a file in the bcc-core checkout
 * that sits beside this plugin.
 *
 * Every ci.yml job checks bcc-core out at
 * app/public/wp-content/plugins/bcc-core, next to bcc-trust, so
 * dirname(tests/Unit, 3) is the plugins directory.
 *
 * ⚠⚠ IN CI A MISSING FILE IS A FAILURE, NOT A SKIP. `composer test` runs
 * phpunit without --fail-on-skipped, so a skip is invisible in a green
 * run: a cross-repo reconciliation would silently stop running the day
 * the checkout step moved, or the day bcc-core moved the file. Locally a
 * bare git worktree legitimately has no sibling, so it skips there.
 *
 * Before this helper, CollectionPersistMetricsTest failed in CI but
 * NoReactionsGateTest and AnnouncementFeedProjectionTest skipped.
 */
final class CoreSibling
{
    /** Absolute path of the bcc-core checkout beside this plugin. */
    public static function root(): string
    {
        return dirname(__DIR__, 3) . '/bcc-core';
    }

    /**
     * Path of $relPath inside bcc-core; fails in CI or skips locally when
     * it is not there.
     */
    public static function require(string $relPath): string
    {
        $path = self::root() . '/' . ltrim($relPath, '/');
        if (is_file($path)) {
            return $path;
        }

        if (self::inCi()) {
            Assert::fail(
                'bcc-core file missing at ' . $path . ' while running in CI. '
                . 'Every ci.yml job checks bcc-core out beside bcc-trust; if that step '
                . 'moved, or bcc-core moved this file, this cross-repo check has stopped '
                . 'running and drift on either side would ship silently.'
            );
        }

        Assert::markTestSkipped(
            'bcc-core sibling not present at ' . $path
            . ' — expected outside CI (a bare git worktree has no sibling).'
        );
    }

    /** True on GitHub Actions (or any runner exporting CI=true). */
    public static function inCi(): bool
    {
        return (string) getenv('GITHUB_ACTIONS') !== '' || strtolower((string) getenv('CI')) === 'true';
    }
}
