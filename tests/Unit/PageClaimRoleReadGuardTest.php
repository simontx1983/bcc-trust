<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

/**
 * D1 guard: a page TEAM row (administrator, representative) must never be
 * read as the page's owner. Every reader of claim rows names the roles it
 * reads, and those roles come from PageClaimRolePolicy.
 *
 * Source scans over app/ with comments removed:
 *  1. every call to a role-scoped ClaimRepository reader passes a
 *     PageClaimRolePolicy:: role list;
 *  2. each of those readers builds its WHERE through roleCondition();
 *  3. every SQL string that can match `entity_type = 'page'` rows also
 *     names claim_role, except the listed write-path lookups.
 */
final class PageClaimRoleReadGuardTest extends TestCase
{
    private const READERS = ['getForEntity', 'getUserClaim', 'getForEntityBatch', 'getUserClaimsForEntities'];

    private const CLAIM_REPOSITORY = 'app/Domain/Onchain/Repositories/ClaimRepository.php';

    /**
     * SQL that names entity_type without claim_role, on purpose. Each is
     * keyed by the method it lives in, with the reason it is not a
     * role-sensitive read.
     */
    private const SQL_ALLOWLIST = [
        // upsert(): reads back the id of the row it just wrote, by the
        // (user, entity_type, entity_id) unique key. Not an authority read.
        'upsert',
    ];

    /** @return array<string, string> relative path => source without comments */
    private static function appSources(): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative       = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $out[$relative] = GateOrder::code($relative);
        }
        ksort($out);

        return $out;
    }

    /** The paren-balanced argument text of the call whose "(" is at $open. */
    private static function argumentsAt(string $code, int $open): string
    {
        $depth = 0;
        for ($i = $open, $n = strlen($code); $i < $n; $i++) {
            if ($code[$i] === '(') {
                $depth++;
            } elseif ($code[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open + 1, $i - $open - 1);
                }
            }
        }
        self::fail('unbalanced call at offset ' . $open);
    }

    public function testEveryRoleScopedReadNamesItsRolesFromThePolicy(): void
    {
        $calls     = 0;
        $pageReads = 0;
        foreach (self::appSources() as $path => $code) {
            $pattern = '/ClaimRepository::(' . implode('|', self::READERS) . ')\s*\(/';
            if (preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($m[0] as $i => [$text, $offset]) {
                $args = self::argumentsAt($code, $offset + strlen($text) - 1);
                self::assertMatchesRegularExpression(
                    '/PageClaimRolePolicy::(OWNER_ROLES|TEAM_ROLES|ENTITY_CLAIM_ROLES)\b/',
                    $args,
                    "{$path}: ClaimRepository::{$m[1][$i][0]}() must read a PageClaimRolePolicy role list"
                );
                // Every page-row read through these readers is an OWNER read
                // (is_claimed, the viewer's claim, the claim flow). The team
                // is read through its own repository, never here.
                if (preg_match("/'page'/", $args) === 1) {
                    self::assertMatchesRegularExpression(
                        '/PageClaimRolePolicy::OWNER_ROLES\b/',
                        $args,
                        "{$path}: a page-row ClaimRepository::{$m[1][$i][0]}() read must be an OWNER_ROLES read"
                    );
                    ++$pageReads;
                }
                ++$calls;
            }
        }
        self::assertGreaterThanOrEqual(9, $calls, 'the scan found the known callers (it is not scanning nothing)');
        self::assertGreaterThanOrEqual(4, $pageReads, 'CardViewService x2 and PageCardPrefetcher x2');
    }

    public function testEachReaderFiltersOnTheRolesItIsGiven(): void
    {
        $code = GateOrder::code(self::CLAIM_REPOSITORY);
        foreach (self::READERS as $reader) {
            $body = GateOrder::body($code, $reader);
            self::assertStringContainsString('self::roleCondition(', $body, "{$reader}() builds its role filter");
            self::assertStringContainsString('{$roleSql}', $body, "{$reader}() puts the role filter in its WHERE");
            self::assertMatchesRegularExpression('/\$roles\s*===\s*\[\]/', $body, "{$reader}() reads nothing for no roles");
        }
    }

    /**
     * Every SQL string literal (single- or double-quoted, interpolation
     * kept) in app/ that names `entity_type = 'page'` or a bound
     * `entity_type = %s` also names claim_role.
     */
    public function testEveryPageCapableClaimSqlNamesClaimRole(): void
    {
        $checked = 0;
        foreach (self::appSources() as $path => $code) {
            foreach (self::sqlStrings($code) as [$sql, $offset]) {
                if (!preg_match("/entity_type\s*=\s*('page'|%s)/", $sql)) {
                    continue;
                }
                ++$checked;
                // {$roleSql} is roleCondition()'s claim_role filter (pinned by
                // testEachReaderFiltersOnTheRolesItIsGiven).
                if (str_contains($sql, 'claim_role') || str_contains($sql, '{$roleSql}')) {
                    continue;
                }
                $method = self::enclosingMethod($code, $offset);
                self::assertContains(
                    $method,
                    self::SQL_ALLOWLIST,
                    "{$path} {$method}(): a claim SQL that can match page rows must name claim_role:\n{$sql}"
                );
            }
        }
        self::assertGreaterThanOrEqual(6, $checked, 'the scan found the known page-capable SQL (it is not scanning nothing)');
    }

    /**
     * Every string literal in $code with its offset. A double-quoted string
     * with interpolation is returned whole, from its opening to its closing
     * quote.
     *
     * @return list<array{string, int}>
     */
    private static function sqlStrings(string $code): array
    {
        $out    = [];
        $offset = 0;
        $open   = null;
        $buffer = '';
        foreach (token_get_all($code) as $t) {
            $text = is_array($t) ? $t[1] : $t;
            if ($open !== null) {
                $buffer .= $text;
                if ($text === '"') {
                    $out[] = [$buffer, $open];
                    $open  = null;
                }
            } elseif ($text === '"') {
                $open   = $offset;
                $buffer = '"';
            } elseif (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = [$text, $offset];
            }
            $offset += strlen($text);
        }

        return $out;
    }

    /** The name of the last `function name(` that starts before $offset. */
    private static function enclosingMethod(string $code, int $offset): string
    {
        preg_match_all('/function\s+(\w+)\s*\(/', substr($code, 0, $offset), $m);

        return $m[1] === [] ? '' : (string) end($m[1]);
    }
}
