<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementCommentPolicy as Policy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A5 — §4.32.7 comment policy. Pure decisions, exercised directly.
 *
 * ⚠ This suite can call the subject, unlike the source-reading suites around
 * it, because the policy takes no WordPress dependency at all. That is the
 * reason the two rules easiest to get wrong were put there:
 *
 *   - RELATIONSHIP BEFORE ROLE on removal (an operator removing their own
 *     comment is recorded as `author`), and
 *   - the CLOSED two-string tombstone label vocabulary.
 */
#[Group('unit')]
final class AnnouncementCommentPolicyTest extends TestCase
{
    // ── body normalisation ───────────────────────────────────────────────

    #[DataProvider('acceptedBodies')]
    public function testAnAcceptableBodyNormalisesToTheExpectedText(string $raw, string $expected): void
    {
        self::assertSame($expected, Policy::normalizeBody($raw));
    }

    /** @return array<string, array{string, string}> */
    public static function acceptedBodies(): array
    {
        return [
            'plain'                  => ['Thanks for the heads up.', 'Thanks for the heads up.'],
            'surrounding whitespace' => ["  padded  ", 'padded'],
            'CRLF becomes LF'        => ["a\r\nb", "a\nb"],
            'bare CR becomes LF'     => ["a\rb", "a\nb"],
            'internal newlines kept' => ["one\ntwo", "one\ntwo"],
            'tab kept'               => ["a\tb", "a\tb"],
            'at the minimum'         => ['x', 'x'],
            'non-ascii survives'     => ['commission unchanged — 5%', 'commission unchanged — 5%'],
            'emoji survives'         => ['shipped 🚀', 'shipped 🚀'],
            'CJK survives'           => ['手数料は変わりません', '手数料は変わりません'],
        ];
    }

    /**
     * ⚠⚠ ANGLE BRACKETS ARE PRESERVED, NOT STRIPPED AND NOT REFUSED.
     *
     * §4.32.7 says the body is plain text with "no executable HTML and no
     * Markdown rendering" — a statement about RENDERING, not a licence to
     * rewrite what a member typed. Stripping tags would silently mangle
     * ordinary text: "5 < 10 and 20 > 15" loses its middle. Refusing would
     * reject a legitimate comment.
     *
     * So the body is stored verbatim and this codebase never renders it as
     * HTML; escaping at the render boundary is the consumer's job, and the API
     * returns a JSON string, never markup. This case is the pin on that
     * decision, in both directions.
     */
    #[DataProvider('markupLikeBodies')]
    public function testMarkupLikeTextIsKeptVerbatim(string $raw): void
    {
        self::assertSame($raw, Policy::normalizeBody($raw), 'the body must be stored byte-for-byte');
    }

    /** @return array<string, array{string}> */
    public static function markupLikeBodies(): array
    {
        return [
            'arithmetic'   => ['5 < 10 and 20 > 15'],
            'a tag'        => ['<b>bold</b>'],
            'a script tag' => ['<script>alert(1)</script>'],
            'markdown'     => ['**not bold** and _not italic_'],
            'an ampersand' => ['fees & commission'],
        ];
    }

    #[DataProvider('refusedBodies')]
    public function testAnUnacceptableBodyIsRefusedRatherThanRepaired(mixed $raw, string $why): void
    {
        self::assertNull(Policy::normalizeBody($raw), $why);
    }

    /** @return array<string, array{mixed, string}> */
    public static function refusedBodies(): array
    {
        return [
            'empty'                 => ['', 'an empty comment is not a comment'],
            'whitespace only'       => ["   \n\t  ", 'whitespace is not content'],
            'null'                  => [null, 'a missing body is a 400, not an empty comment'],
            'an int'                => [42, 'only strings are bodies'],
            'an array'              => [['a'], 'only strings are bodies'],
            'a NUL byte'            => ["ab\x00cd", 'a NUL cannot be typed deliberately'],
            'a vertical tab'        => ["ab\x0Bcd", 'C0 controls other than LF and TAB are refused'],
            'an escape character'   => ["ab\x1Bcd", 'an ESC is the shape of a smuggled payload'],
            'a DEL character'       => ["ab\x7Fcd", 'DEL is refused with the other controls'],
            'invalid UTF-8'         => ["\xC3\x28", 'invalid UTF-8 is refused outright'],
        ];
    }

    /**
     * ⚠ Length is measured in CHARACTERS, not bytes, and that is deliberate: a
     * 2000-BYTE limit would cut a CJK comment to a third of the documented
     * allowance and an emoji-bearing one to a quarter.
     */
    public function testTheLimitIsMeasuredInCharactersNotBytes(): void
    {
        $atMax = str_repeat('あ', Policy::MAX_LEN);          // 2000 chars, 6000 bytes
        self::assertSame($atMax, Policy::normalizeBody($atMax), 'exactly at the maximum must be accepted');

        $overMax = str_repeat('あ', Policy::MAX_LEN + 1);
        self::assertNull(Policy::normalizeBody($overMax), 'one character over must be refused');

        // The byte-length control: this is far over 2000 BYTES and still fine.
        self::assertGreaterThan(2000, strlen($atMax));
    }

    public function testTheBoundsAreTheDocumentedOnes(): void
    {
        self::assertSame(1, Policy::MIN_LEN);
        self::assertSame(2000, Policy::MAX_LEN, '§4.32.7 says 1–2000 after normalization');
    }

    /** Trimming happens BEFORE the length test, or a padded maximum would be refused. */
    public function testTrimmingPrecedesTheLengthTest(): void
    {
        $padded = '  ' . str_repeat('a', Policy::MAX_LEN) . '  ';
        self::assertSame(str_repeat('a', Policy::MAX_LEN), Policy::normalizeBody($padded));
    }

    // ── removal: relationship before role ────────────────────────────────

    /**
     * ⚠⚠ THE CASE THAT MATTERS MOST IN THIS FILE.
     *
     * §4.32.7: "An operator removing THEIR OWN comment is recorded as `author`
     * — the actor's relationship to the comment wins over their role."
     *
     * Getting this backwards publishes a tombstone saying "Comment removed by
     * validator" on a comment the operator wrote themselves, which
     * misrepresents a moderation action that never happened.
     */
    public function testAnOperatorRemovingTheirOwnCommentIsRecordedAsAuthor(): void
    {
        self::assertSame(
            Policy::ACTOR_AUTHOR,
            Policy::removalActorType(actorId: 7, commentAuthorId: 7, actorIsOperator: true),
            'relationship wins over role'
        );
    }

    #[DataProvider('removalCases')]
    public function testRemovalActorTypeIsResolvedCorrectly(
        int $actorId,
        int $commentAuthorId,
        bool $isOperator,
        ?string $expected,
        string $why
    ): void {
        self::assertSame($expected, Policy::removalActorType($actorId, $commentAuthorId, $isOperator), $why);
    }

    /** @return array<string, array{int, int, bool, string|null, string}> */
    public static function removalCases(): array
    {
        return [
            'author removes own, not operator' => [
                7, 7, false, Policy::ACTOR_AUTHOR, 'an author may always remove their own',
            ],
            'author removes own, is operator' => [
                7, 7, true, Policy::ACTOR_AUTHOR, 'relationship before role',
            ],
            'operator removes another' => [
                9, 7, true, Policy::ACTOR_VALIDATOR, 'an operator moderates their announcement',
            ],
            'stranger removes another' => [
                9, 7, false, null, 'a stranger may remove nothing',
            ],
            'anonymous' => [
                0, 7, false, null, 'there are no anonymous removals',
            ],
            'anonymous even if flagged operator' => [
                0, 7, true, null, 'a zero actor id can never be authorized',
            ],
            'orphan comment, stranger' => [
                9, 0, false, null, 'an unattributed comment is not removable by a stranger',
            ],
            'orphan comment, operator' => [
                9, 0, true, Policy::ACTOR_VALIDATOR, 'an operator can still moderate it',
            ],
        ];
    }

    /**
     * ⚠ An ADMINISTRATOR is not in §4.32.7's removal table — it gives them
     * "— (restore only)". So an admin who is neither author nor operator gets
     * no removal actor type, and this absence is the pin on that.
     */
    public function testAnAdministratorIsNotARemovalActor(): void
    {
        // There is no admin parameter at all: being an administrator cannot
        // make removalActorType() answer, which is the structural guarantee.
        self::assertNull(Policy::removalActorType(actorId: 3, commentAuthorId: 7, actorIsOperator: false));
    }

    // ── the closed label vocabulary ──────────────────────────────────────

    public function testTheTwoLabelsAreVerbatimFromTheContract(): void
    {
        self::assertSame('Comment removed by author', Policy::LABEL_AUTHOR);
        self::assertSame('Comment removed by validator', Policy::LABEL_VALIDATOR);
    }

    #[DataProvider('labelPairs')]
    public function testEachActorTypeMapsToItsLabel(string $actorType, string $expected): void
    {
        self::assertSame($expected, Policy::labelFor($actorType));
    }

    /** @return array<string, array{string, string}> */
    public static function labelPairs(): array
    {
        return [
            'author'    => [Policy::ACTOR_AUTHOR, Policy::LABEL_AUTHOR],
            'validator' => [Policy::ACTOR_VALIDATOR, Policy::LABEL_VALIDATOR],
        ];
    }

    /**
     * ⚠⚠ A CLOSED SET. An unexpected stored actor value must produce no label
     * at all, so the caller refuses rather than rendering a tombstone with a
     * caption that came from the database.
     */
    #[DataProvider('unknownActorTypes')]
    public function testAnUnknownActorTypeHasNoLabelAndNoAuditAction(string $actorType): void
    {
        self::assertNull(Policy::labelFor($actorType), 'no third label may be invented');
        self::assertNull(Policy::auditActionFor($actorType), 'no third audit action may be invented');
    }

    /** @return array<string, array{string}> */
    public static function unknownActorTypes(): array
    {
        return [
            'empty'          => [''],
            'administrator'  => ['administrator'],
            'admin'          => ['admin'],
            'moderator'      => ['moderator'],
            'wrong case'     => ['Author'],
            'padded'         => [' author '],
            'injected'       => ['author<script>'],
        ];
    }

    // ── three distinct audit actions ─────────────────────────────────────

    /** §4.32.7: "Author removal, validator removal and administrator restoration each write a DISTINCT audit action." */
    public function testTheThreeAuditActionsAreAllDistinct(): void
    {
        $actions = [
            Policy::AUDIT_REMOVED_BY_AUTHOR,
            Policy::AUDIT_REMOVED_BY_VALIDATOR,
            Policy::AUDIT_RESTORED_BY_ADMIN,
        ];

        self::assertCount(3, array_unique($actions), 'all three must be distinct');
        foreach ($actions as $a) {
            self::assertNotSame('', $a);
        }
    }

    // ── restoration is administrator-only ────────────────────────────────

    /**
     * ⚠ "Comment authors cannot restore; restoration is administrator-only."
     *
     * Kept as its own predicate because the two questions have DIFFERENT
     * answers for the same person: an author may remove and may not restore.
     */
    public function testOnlyAnAdministratorMayRestore(): void
    {
        self::assertTrue(Policy::mayRestore(true));
        self::assertFalse(Policy::mayRestore(false), 'an author who may remove may NOT restore');
    }

    // ── flat only ────────────────────────────────────────────────────────

    /**
     * ⚠ ANY present `parent_id` is a refusal, including `0` and `''`. A client
     * that sends the field at all is using a threading model this version does
     * not have, and answering 201 would tell it the reply was threaded.
     */
    public function testSendingAParentIdAtAllIsNotAFlatSubmission(): void
    {
        self::assertTrue(Policy::isFlatSubmission(false), 'no parent_id sent is flat');
        self::assertFalse(Policy::isFlatSubmission(true), 'any parent_id sent is refused');
    }

    public function testTheRepliesReasonIsTheContractCode(): void
    {
        self::assertSame('replies_not_supported', Policy::REASON_REPLIES_UNSUPPORTED, '§4.32.9 names this code');
    }

    // ── absences ─────────────────────────────────────────────────────────

    /**
     * §4.32.7 — "No editing, no reactions, no inline feed commenting in v1",
     * and §4.32.15 forbids engagement outright. The policy must therefore have
     * no vocabulary for any of it: an absence is the only way to express a
     * guarantee that nothing can be called.
     */
    #[DataProvider('forbiddenVocabulary')]
    public function testThePolicyHasNoVocabularyForEngagementOrEditing(string $needle): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Announcements/AnnouncementCommentPolicy.php'
        );

        // Strip comments so the explanatory prose cannot fail the assertion.
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        self::assertStringNotContainsStringIgnoringCase(
            $needle,
            $code,
            'the comment policy must have no notion of ' . $needle
        );
    }

    /** @return array<string, array{string}> */
    public static function forbiddenVocabulary(): array
    {
        return [
            'reaction' => ['reaction'],
            'stoke'    => ['stoke'],
            'helpful'  => ['helpful'],
            'score'    => ['score'],
            'reward'   => ['reward'],
            'edit'     => ['canEdit'],
        ];
    }
}
