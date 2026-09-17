<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PR 7.14 — stored holdings evidence without a freshness bound must stay out
 * of every membership decision.
 *
 * `NftHoldingsRepository`'s stored rows (and the gallery's persisted read
 * path built on them) are not refreshed when an indexer stops, so a row can
 * outlive the holding by any amount of time. They are fine for DISPLAY and for
 * stance testimony. They must never be what grants a join, keeps a member,
 * makes a group auto-joinable, or decides a revocation — those go through
 * `HoldingsService`'s evaluator, whose only index read is ERC-1155 under a
 * fresh healthy checkpoint.
 *
 * NftRevocationFailSafeTest §7 proves the behaviour with a stale stored row.
 * This pins the STRUCTURE, so a future change that routes those rows into an
 * access path fails here even if nobody writes the behavioural test for it.
 * Call sites are found with the PHP tokenizer (not grep), so comments and
 * strings never count, and each is attributed to its enclosing Class::method.
 */
final class StoredHoldingsAccessIsolationTest extends TestCase
{
    /** @var array<string, list<string>>|null target "Class::method" => sorted callers "Class::method" */
    private static ?array $sites = null;

    public function testStoredRowsAreReadOnlyByDisplayAndTestimonyCode(): void
    {
        self::assertSame(
            ['CollectionStanceService::holdsPerPanelSources', 'CollectionStanceService::panelForUser'],
            self::callersOf('NftHoldingsRepository::findVisibleForWallet'),
            'stored rows feed the stance panel and the stance write gate only'
        );
        self::assertSame(
            ['HoldingsService::tryReadPersistent'],
            self::callersOf('NftHoldingsRepository::findVisibleEnrichedForWallet')
        );
        self::assertSame(
            ['HoldingsService::resolveTransientFallbackState', 'HoldingsService::tryReadPersistent'],
            self::callersOf('NftHoldingsRepository::walletHasAnyEnriched')
        );
    }

    public function testTheOnlyMembershipIndexReadIsTheFreshnessCheckedOne(): void
    {
        self::assertSame(
            ['HoldingsService::countFromCacheOrFetch'],
            self::callersOf('NftHoldingsRepository::countVisibleByContractOrThrow'),
            'the evaluator reads the index only here, under transferIndexIsFresh()'
        );
        // The unbounded reader is left only on the Core claim-role path
        // (collection claims, not group membership).
        self::assertSame(
            ['BlockchainQueryService::isEthNftHolderViaIndex'],
            self::callersOf('NftHoldingsRepository::countVisibleByContract')
        );
    }

    public function testTheDisplayPathsAreNotReachableFromMembershipCode(): void
    {
        self::assertSame(['CollectionStanceService::setStance'], self::callersOf('CollectionStanceService::holdsPerPanelSources'));
        self::assertSame(['HoldingsService::getForUser'], self::callersOf('HoldingsService::tryReadPersistent'));
        self::assertSame(['CollectionStanceService::panelForUser'], self::callersOf('HoldingsService::walletHoldingsWithCompleteness'));

        $membership = ['NftGroupGateService', 'NftGroupRevokeService', 'HolderGroupsEndpoint', 'UserGroupsEndpoint'];
        foreach ([
            'CollectionStanceService::setStance',
            'CollectionStanceService::panelForUser',
            'HoldingsService::getForUser',
            'HoldingsService::walletHoldingsWithCompleteness',
        ] as $target) {
            foreach (self::callersOf($target) as $caller) {
                self::assertNotContains(
                    strtok($caller, ':'),
                    $membership,
                    "{$caller} is membership code and must not consume {$target}"
                );
            }
        }
    }

    /** @return list<string> */
    private static function callersOf(string $target): array
    {
        self::$sites ??= self::scan();

        return self::$sites[$target] ?? [];
    }

    /** @return array<string, list<string>> */
    private static function scan(): array
    {
        $root  = dirname(__DIR__, 2) . '/app';
        $sites = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (self::callSitesIn((string) file_get_contents($file->getPathname())) as [$target, $caller]) {
                $sites[$target][$caller] = true;
            }
        }

        $out = [];
        foreach ($sites as $target => $callers) {
            $names = array_keys($callers);
            sort($names);
            $out[$target] = $names;
        }

        return $out;
    }

    /**
     * Every static call `X::method(` in one file, as [target, caller] pairs.
     * `self`/`static` resolve to the enclosing class; closures are attributed
     * to the method they sit in.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function callSitesIn(string $source): array
    {
        $tokens = token_get_all($source);
        $n      = count($tokens);
        $class  = '';
        $depth  = 0;
        /** @var list<array{0: string, 1: int}> $functions name, brace depth of its body */
        $functions   = [];
        $pendingName = null;
        $out         = [];

        $next = static function (int $i) use ($tokens, $n): int {
            for ($j = $i + 1; $j < $n; $j++) {
                if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    return $j;
                }
            }
            return $n;
        };
        $prev = static function (int $i) use ($tokens): int {
            for ($j = $i - 1; $j >= 0; $j--) {
                if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    return $j;
                }
            }
            return -1;
        };
        $text = static fn(int $i): string => $i >= 0 && $i < $n ? (is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]) : '';

        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];

            if (is_array($tok)) {
                if (in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $before = $prev($i);
                    $isName = $before < 0 || !is_array($tokens[$before]) || !in_array($tokens[$before][0], [T_DOUBLE_COLON, T_NEW], true);
                    $name   = $next($i);
                    if ($isName && $name < $n && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING) {
                        $class = $tokens[$name][1];
                    }
                } elseif ($tok[0] === T_FUNCTION) {
                    $name        = $next($i);
                    $name        = $text($name) === '&' ? $next($name) : $name;
                    $pendingName = ($name < $n && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING)
                        ? $tokens[$name][1]
                        : '{closure}';
                } elseif ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($tok[0] === T_DOUBLE_COLON) {
                    $method = $next($i);
                    $paren  = $next($method);
                    if ($method < $n && is_array($tokens[$method]) && $tokens[$method][0] === T_STRING && $text($paren) === '(') {
                        $left   = $text($prev($i));
                        $target = in_array(strtolower($left), ['self', 'static'], true) ? $class : basename(str_replace('\\', '/', $left));
                        $caller = '';
                        for ($k = count($functions) - 1; $k >= 0; $k--) {
                            if ($functions[$k][0] !== '{closure}') {
                                $caller = $functions[$k][0];
                                break;
                            }
                        }
                        if ($caller !== '' && $target !== $class) {
                            $out[] = [$target . '::' . $tokens[$method][1], $class . '::' . $caller];
                        } elseif ($caller !== '') {
                            $out[] = [$class . '::' . $tokens[$method][1], $class . '::' . $caller];
                        }
                    }
                }
                continue;
            }

            if ($tok === '{') {
                $depth++;
                if ($pendingName !== null) {
                    $functions[] = [$pendingName, $depth];
                    $pendingName = null;
                }
            } elseif ($tok === '}') {
                if ($functions !== [] && $functions[count($functions) - 1][1] === $depth) {
                    array_pop($functions);
                }
                $depth--;
            } elseif ($tok === ';' && $pendingName !== null) {
                $pendingName = null; // abstract or interface method
            }
        }

        return $out;
    }
}
