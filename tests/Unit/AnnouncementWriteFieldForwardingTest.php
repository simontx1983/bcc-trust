<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\AnnouncementWriteService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A5b prerequisites — `summary` and `comments_enabled` reach the service.
 *
 * ── ⚠⚠ WHY THE FORWARDING CASES ARE THE POINT OF THIS FILE ─────────────────
 * A REST field needs THREE separate things to work, and two of them are silent
 * when missing:
 *
 *   1. a declaration in the route's `args`  — loud if wrong (schema refuses)
 *   2. a read in the handler's `$input`      — ⚠ SILENT
 *   3. a use in the service                  — loud if wrong (nothing happens)
 *
 * Step 2 was missed while writing this change, and the symptom was actively
 * misleading: the service read `$input['summary']`, found nothing, and refused
 * EVERY create — including the ones carrying a perfectly good summary — with
 * the message "summary is required". The field was declared, validated and
 * never forwarded. `php -l` passed, PHPStan passed, and the unit suites passed,
 * because both handlers build `$input` from an explicit allowlist that no test
 * compared against the service's reads.
 *
 * So these cases compare the two sides directly.
 *
 * ⚠ The live evidence is `scripts/tests/announcement-summary-comments-prereq.php`
 * (64 assertions, real dispatch, paired controls). This file holds what source
 * can prove and what a green suite would otherwise have missed.
 */
#[Group('unit')]
final class AnnouncementWriteFieldForwardingTest extends TestCase
{
    private const SERVICE  = 'app/Domain/Core/Services/AnnouncementWriteService.php';
    private const ENDPOINT = 'app/Domain/Core/REST/AnnouncementWriteEndpoint.php';

    private static function src(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $s    = file_get_contents($path);

        self::assertIsString($s, 'could not read ' . $rel);
        self::assertGreaterThan(2000, strlen($s), $rel . ' is implausibly short — wrong path?');

        return $s;
    }

    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(self::src($rel)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    private static function body(string $rel, string $method): string
    {
        $code  = self::code($rel);
        $start = strpos($code, 'function ' . $method . '(');
        self::assertIsInt($start, $method . ' not found in ' . $rel);

        $rest = substr($code, $start + 1);
        $next = strpos($rest, 'function ');

        return $next === false ? $rest : substr($rest, 0, $next);
    }

    // ── 1. the contract's own numbers ────────────────────────────────────

    /** §4.32.3 — "plain text, 1–300 chars". */
    public function testTheSummaryLimitIsTheContractValue(): void
    {
        self::assertSame(300, AnnouncementWriteService::MAX_SUMMARY_LEN);
    }

    /** §4.32.9 names this code; it must be the literal, not a paraphrase. */
    public function testTheSummaryReasonCodeIsTheContractCode(): void
    {
        self::assertSame('summary_required', AnnouncementWriteService::REASON_SUMMARY_REQUIRED);
    }

    // ── 2. ⚠⚠ the forwarding chain, end to end in source ─────────────────

    /**
     * ⚠⚠ THE CASE THAT WOULD HAVE CAUGHT THE REAL BUG. Every field the service
     * READS out of `$input` must be WRITTEN into `$input` by the handler.
     */
    #[DataProvider('forwardedFields')]
    public function testEveryFieldTheServiceReadsIsForwardedByTheHandler(string $field): void
    {
        $service  = self::code(self::SERVICE);
        $endpoint = self::code(self::ENDPOINT);

        // The service reads it...
        self::assertMatchesRegularExpression(
            "/\\\$input\[['\"]" . preg_quote($field, '/') . "['\"]\]/",
            $service,
            'the service should read ' . $field
        );

        // ...so the endpoint must name it. A field read but never forwarded is
        // indistinguishable, to the service, from a caller who omitted it.
        self::assertStringContainsString(
            "'" . $field . "'",
            $endpoint,
            $field . ' is read by the service but never forwarded by the endpoint'
        );
    }

    /** @return array<string, array{string}> */
    public static function forwardedFields(): array
    {
        return [
            'title'            => ['title'],
            'summary'          => ['summary'],
            'body'             => ['body'],
            'publish_at'       => ['publish_at'],
            'comments_enabled' => ['comments_enabled'],
        ];
    }

    /** Both write handlers must forward both new fields, not just one of them. */
    #[DataProvider('handlerFieldPairs')]
    public function testBothHandlersForwardBothNewFields(string $method, string $field): void
    {
        $body = self::body(self::ENDPOINT, $method);

        self::assertStringContainsString(
            "'" . $field . "'",
            $body,
            $method . '() must forward ' . $field
        );
    }

    /** @return array<string, array{string, string}> */
    public static function handlerFieldPairs(): array
    {
        return [
            'createInput + summary'          => ['createInput', 'summary'],
            'createInput + comments_enabled' => ['createInput', 'comments_enabled'],
            'edit + summary'                 => ['edit', 'summary'],
            'edit + comments_enabled'        => ['edit', 'comments_enabled'],
        ];
    }

    /**
     * ⚠ `comments_enabled` must NOT go through a string cast. `(string) false`
     * is `''`, which is falsy — so a boolean pushed through the edit handler's
     * string loop would appear to work while sending the service the wrong
     * type, and would break the moment the service type-checked it.
     */
    #[DataProvider('handlerNames')]
    public function testTheBooleanIsNotStringCast(string $method): void
    {
        $body = self::body(self::ENDPOINT, $method);

        self::assertDoesNotMatchRegularExpression(
            "/\(string\)\s*\\\$?[A-Za-z_>\[\]'\$-]*comments_enabled/",
            $body,
            'comments_enabled must not be string-cast in ' . $method . '()'
        );
        self::assertMatchesRegularExpression(
            '/\(bool\)\s*\$commentsEnabled/',
            $body,
            $method . '() must cast comments_enabled to bool'
        );
    }

    /** @return array<string, array{string}> */
    public static function handlerNames(): array
    {
        return ['createInput' => ['createInput'], 'edit' => ['edit']];
    }

    /**
     * ⚠ And it is forwarded ONLY when sent, so the service can tell "not sent"
     * (leave alone) from "sent as false" (close the discussion). A default in
     * the arg declaration would erase that distinction.
     */
    #[DataProvider('handlerNames')]
    public function testTheBooleanIsForwardedOnlyWhenSent(string $method): void
    {
        $body = self::body(self::ENDPOINT, $method);

        self::assertMatchesRegularExpression(
            '/\$commentsEnabled\s*!==\s*null/',
            $body,
            $method . '() must forward comments_enabled only when the caller sent it'
        );
    }

    public function testTheBooleanArgDeclaresNoDefault(): void
    {
        $body = self::body(self::ENDPOINT, 'boolArg');

        self::assertStringContainsString("'type'              => 'boolean'", $body);
        self::assertStringContainsString("'required'          => false", $body);
        self::assertStringNotContainsString(
            "'default'",
            $body,
            'a default would make every edit write the flag, erasing "not sent"'
        );
    }

    // ── 3. the service side ──────────────────────────────────────────────

    /**
     * ⚠⚠ `fail()` MUST accept the reason parameter. PHP SILENTLY IGNORES a
     * surplus argument to a user-defined method, so the four-argument
     * `fail(..., REASON_SUMMARY_REQUIRED)` calls would have passed `php -l`,
     * passed PHPStan at level 8, and dropped the reason on the floor — the
     * wire would have carried no `reason_code` and nothing would have said so.
     */
    public function testFailAcceptsTheReasonParameter(): void
    {
        $code = self::code(self::SERVICE);

        self::assertMatchesRegularExpression(
            '/function fail\(\s*string \$code,\s*string \$message,\s*int \$status,\s*\?string \$reason = null\s*\)/',
            $code,
            'fail() must declare the reason parameter or every reason_code is silently dropped'
        );
    }

    /** And it must be set only when supplied, or every 400 gains a null reason_code. */
    public function testTheReasonIsOmittedWhenNotSupplied(): void
    {
        $body = self::body(self::SERVICE, 'fail');

        self::assertMatchesRegularExpression(
            '/\$reason\s*!==\s*null/',
            $body,
            'a null reason must not be written, or render() emits reason_code: null on every error'
        );
    }

    /** §4.32.3 — the summary's storage is post_excerpt, which the read side already projects. */
    public function testTheSummaryIsStoredInPostExcerpt(): void
    {
        $create = self::body(self::SERVICE, 'create');
        $edit   = self::body(self::SERVICE, 'edit');

        self::assertStringContainsString("'post_excerpt'", $create, 'create must store the summary');
        self::assertStringContainsString("'post_excerpt'", $edit, 'edit must be able to update it');
    }

    /**
     * ⚠ The BLANK check must precede the length check, because `text()`
     * collapses both to null — so testing length first would report a missing
     * field as an oversized one, and §4.32.9's `summary_required` would never
     * be emitted.
     */
    public function testTheBlankCheckPrecedesTheLengthCheck(): void
    {
        $body = self::body(self::SERVICE, 'create');

        $blank  = strpos($body, 'REASON_SUMMARY_REQUIRED');
        $length = strpos($body, 'MAX_SUMMARY_LEN');

        self::assertIsInt($blank);
        self::assertIsInt($length);
        // MAX_SUMMARY_LEN appears inside the required message too, so compare
        // against the SECOND occurrence — the length refusal.
        $second = strpos($body, 'MAX_SUMMARY_LEN', $length + 1);
        self::assertIsInt($second, 'both a required and a length refusal must exist');
        self::assertLessThan($second, $blank, 'the blank refusal must come first');
    }

    /**
     * ⚠ An operator closing a discussion and changing nothing else is a REAL
     * edit. If the nothing-to-change guard ignored `comments_enabled`, that
     * request would be refused with a false 400.
     */
    public function testTheNothingToChangeGuardCountsCommentsEnabled(): void
    {
        $body = self::body(self::SERVICE, 'edit');

        $guard = strpos($body, 'Nothing to change');
        self::assertIsInt($guard, 'the empty-edit guard must exist');

        // The guard must be conditional on comments_enabled being absent too.
        $window = substr($body, max(0, $guard - 400), 600);
        self::assertStringContainsString(
            'comments_enabled',
            $window,
            'the guard must treat comments_enabled as a change'
        );
    }

    /**
     * ⚠ ABSENT MUST STAY ABSENT on create. Both readers
     * (`AnnouncementViewService::commentsEnabled()` and
     * `AnnouncementCommentRepository::commentsEnabled()`) treat a missing key
     * as ENABLED, because §4.32.7 makes discussion the default. Writing a
     * default '1' would give every row a stored preference nobody expressed.
     */
    public function testCreateWritesTheFlagOnlyWhenSupplied(): void
    {
        $body = self::body(self::SERVICE, 'create');

        self::assertMatchesRegularExpression(
            "/array_key_exists\(\s*'comments_enabled'\s*,\s*\\\$input\s*\)/",
            $body,
            'create must write the flag only when the caller supplied it'
        );
    }

    /** The meta key is the one A2.2 already defined — not a new one. */
    public function testTheFlagUsesTheExistingMetaKey(): void
    {
        $body = self::body(self::SERVICE, 'storeCommentsEnabled');

        self::assertStringContainsString(
            'META_COMMENTS_ENABLED',
            $body,
            'the write side must use the key the read side already reads'
        );
        self::assertStringNotContainsString(
            "'_bcc_",
            $body,
            'the key must come from the constant, never be retyped as a literal'
        );
    }
}
