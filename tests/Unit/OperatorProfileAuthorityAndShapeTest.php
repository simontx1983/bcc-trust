<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Operator profiles: who may write, what the public card may see, and
 * which file a logo change is allowed to delete.
 *
 * ── WHY THIS SUITE EXISTS ────────────────────────────────────────────
 * The shared-profile backend arrived with no tests, and three of its
 * properties are the kind that fail silently rather than loudly:
 *
 *   1. AUTHORIZATION. A per-page override is a write against a page the
 *      caller does not own unless something checks. The repository
 *      deliberately does not check (repositories do not authorize), so
 *      the gate lives in the endpoint and nothing proved it was there.
 *   2. PUBLIC SHAPE. The card payload is assembled from rows that carry
 *      `user_id` and `updated_at`. Those must not reach a public card.
 *   3. IRREVERSIBLE DELETE. Replacing a logo now deletes the superseded
 *      attachment. A delete that could reach another member's media is
 *      not recoverable, so the ownership check is pinned here.
 *
 * Source-reading assertions where the behaviour is structural — the
 * house idiom (see ValidatorPageExistenceIndependentOfMembershipTest) —
 * and by-value assertions for the precedence rule, which is pure.
 */
#[CoversNothing]
final class OperatorProfileAuthorityAndShapeTest extends TestCase
{
    private const REPO     = 'BCC\\Trust\\Onchain\\Repositories\\OperatorProfileRepository';
    private const ENDPOINT = 'BCC\\Trust\\Onchain\\REST\\OperatorProfileEndpoint';

    private static function read(string $relative): string
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertNotFalse($src, "could not read {$relative}");
        self::assertNotSame('', $src, "{$relative} is empty");

        return (string) $src;
    }

    /** Strip comments so prose can never satisfy a code assertion. */
    private static function code(string $src): string
    {
        $out = preg_replace('!/\*.*?\*/!s', '', $src) ?? '';

        return (string) preg_replace('!//[^\r\n]*!', '', $out);
    }

    /**
     * Slice one method body out so an assertion cannot be satisfied by a
     * match elsewhere in the file.
     *
     * The end boundary must match ANY method declaration. An earlier version
     * looked for only two specific declaration prefixes, so a method whose
     * next sibling was declared differently sliced all the way to END OF
     * FILE -- and every "does this handler call X" assertion then passed by
     * matching X's own definition further down. Measured: with that slicer,
     * deleting the per-page authorization gate from updatePageOverride left
     * the whole suite green.
     */
    private static function method(string $src, string $name): string
    {
        $start = strpos($src, 'function ' . $name . '(');
        self::assertNotFalse($start, "method {$name} not found");

        $rest = substr($src, $start + 1);
        $next = '/[\r\n][ \t]*(?:public|private|protected)(?:\s+static)?\s+function /';

        if (preg_match($next, $rest, $m, PREG_OFFSET_CAPTURE) === 1) {
            return substr($src, $start, $m[0][1] + 1);
        }

        return substr($src, $start);
    }

    private static function endpointSrc(): string
    {
        return self::read('app/Domain/Onchain/REST/OperatorProfileEndpoint.php');
    }

    private static function repoSrc(): string
    {
        return self::read('app/Domain/Onchain/Repositories/OperatorProfileRepository.php');
    }

    // ── 0. anti-vacuity ───────────────────────────────────────────────

    public function testTheSubjectsAreReallyLoaded(): void
    {
        self::assertTrue(class_exists(self::REPO), 'OperatorProfileRepository must autoload');
        self::assertTrue(class_exists(self::ENDPOINT), 'OperatorProfileEndpoint must autoload');
        self::assertStringContainsString('updatePageOverrides', self::repoSrc());
        self::assertStringContainsString('requireVerifiedOperator', self::endpointSrc());
    }

    public function testTheCommentStripperRemovesProseButKeepsCode(): void
    {
        $stripped = self::code("// decide(\n\$x = 1; /* decide( */\n");

        self::assertStringNotContainsString('decide(', $stripped);
        self::assertStringContainsString('$x = 1;', $stripped);
    }

    // ── 1. precedence, by value ───────────────────────────────────────

    /** @return array<string, string|null> */
    private static function pick(?array $override, ?array $profile, string $field): string
    {
        $m = new ReflectionMethod(self::REPO, 'pick');
        $m->setAccessible(true);

        return (string) $m->invoke(null, $override, $profile, $field);
    }

    /** ⭐ An override that is SET wins over the shared value. */
    public function testASetOverrideWinsOverTheSharedValue(): void
    {
        self::assertSame(
            'Page brand',
            self::pick(['brand_name' => 'Page brand'], ['brand_name' => 'Shared brand'], 'brand_name')
        );
    }

    /** ⭐ NULL means inherit, never "blank it out". */
    public function testANullOverrideInheritsTheSharedValue(): void
    {
        self::assertSame(
            'Shared brand',
            self::pick(['brand_name' => null], ['brand_name' => 'Shared brand'], 'brand_name')
        );
        self::assertSame(
            'Shared brand',
            self::pick(null, ['brand_name' => 'Shared brand'], 'brand_name'),
            'no override row at all also inherits'
        );
    }

    /** An empty-string override is a deliberate blank, NOT an inherit. */
    public function testAnEmptyStringOverrideIsADeliberateBlank(): void
    {
        self::assertSame('', self::pick(['bio' => ''], ['bio' => 'Shared bio'], 'bio'));
    }

    /** Missing everywhere resolves to '' rather than null, so the wire type holds. */
    public function testMissingEverywhereResolvesToEmptyString(): void
    {
        self::assertSame('', self::pick(null, null, 'website_url'));
        self::assertSame('', self::pick([], [], 'x_url'));
    }

    /** A non-string shared value cannot leak onto the wire. */
    public function testANonStringSharedValueIsCoercedAway(): void
    {
        self::assertSame('', self::pick(null, ['bio' => 1234], 'bio'));
        self::assertSame('', self::pick(null, ['bio' => ['nested']], 'bio'));
    }

    // ── 2. authorization ──────────────────────────────────────────────

    /**
     * ⭐ A PER-PAGE write must be gated on the verified operator OF THAT
     * PAGE, not merely on holding some claim somewhere.
     */
    public function testPerPageWritesAreGatedOnThatPagesVerifiedOperator(): void
    {
        $src = self::code(self::endpointSrc());

        foreach (['updatePageOverride', 'resetPageOverride'] as $handler) {
            $body = self::method($src, $handler);
            self::assertStringContainsString(
                'requireVerifiedOperator(',
                $body,
                "{$handler} must call the per-page gate"
            );
        }

        $gate = self::method($src, 'requireVerifiedOperator');
        self::assertStringContainsString('ValidatorPageOperatorAuthority::decide(', $gate);
        self::assertStringContainsString('DECISION_OPERATOR', $gate);
        self::assertStringContainsString('403', $gate);
    }

    /** Shared-profile and logo writes require at least one verified claim. */
    public function testSharedWritesRequireAVerifiedClaim(): void
    {
        $src = self::code(self::endpointSrc());

        foreach (['updateShared', 'uploadLogo', 'deleteLogo'] as $handler) {
            $body = self::method($src, $handler);
            self::assertStringContainsString(
                'listVerifiedValidatorPagesForUser(',
                $body,
                "{$handler} must require a verified claim"
            );
            self::assertStringContainsString('get_current_user_id()', $body);
        }
    }

    /**
     * F-96: the two tests above only prove each gate is CALLED. Here, in
     * every write handler, the gate's refusal is returned and no write (row
     * update, upload, attachment delete) comes before it.
     *
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function gatedWrites(): iterable
    {
        $page   = 'return $gate;';
        $shared = "return self::privateError('bcc_forbidden'";

        yield 'updatePageOverride' => ['updatePageOverride', $page, ['->updatePageOverrides(']];
        yield 'resetPageOverride'  => ['resetPageOverride', $page, ['->deletePageOverrides(']];
        yield 'updateShared'       => ['updateShared', $shared, ['->updateProfile(']];
        yield 'uploadLogo'         => ['uploadLogo', $shared, ['->upload(', '->setLogoAttachment(', 'wp_delete_attachment(', 'discardSupersededLogo(']];
        yield 'deleteLogo'         => ['deleteLogo', $shared, ['->setLogoAttachment(', 'wp_delete_attachment(', 'discardSupersededLogo(']];
    }

    /** @param list<string> $writes */
    #[\PHPUnit\Framework\Attributes\DataProvider('gatedWrites')]
    public function testEveryWriteReturnsItsGatesRefusalBeforeWriting(string $handler, string $refusal, array $writes): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Onchain/REST/OperatorProfileEndpoint.php'), $handler);

        if ($refusal === 'return $gate;') {
            GateOrder::assertOrder($body, ['self::requireVerifiedOperator(', 'if ($gate !== null) {', $refusal], $handler);
        } else {
            self::assertMatchesRegularExpression(
                '/listVerifiedValidatorPagesForUser\(\$userId, 1\) === \[\]\) \{\s*return self::privateError\(\'bcc_forbidden\'/',
                $body,
                "{$handler}: an empty claim list must return the 403"
            );
        }
        GateOrder::assertNoWorkBefore($body, $refusal, $writes, $handler);
    }

    /** The per-page gate denies unless the authority answered OPERATOR. */
    public function testThePerPageGateOnlyPassesAnOperatorDecision(): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Onchain/REST/OperatorProfileEndpoint.php'), 'requireVerifiedOperator');

        GateOrder::assertOrder(
            $body,
            ['ValidatorPageOperatorAuthority::decide(', '!== ValidatorPageOperatorAuthority::DECISION_OPERATOR) {', '403', 'return null;'],
            'requireVerifiedOperator()'
        );
        self::assertSame(1, substr_count($body, 'return null;'), 'the only pass is after the OPERATOR check');
    }

    /** ⛔ No handler may take the acting user from the request. */
    public function testTheActingUserIsNeverTakenFromTheRequest(): void
    {
        $src = self::code(self::endpointSrc());

        self::assertStringNotContainsString("get_param('user_id')", $src);
        self::assertStringNotContainsString('get_param("user_id")', $src);
    }

    /**
     * ⭐ A stale override row left by a PREVIOUS operator must not be
     * served to a new owner: the read is keyed on the (page, user) pair.
     */
    public function testOverrideReadsAreKeyedOnThePageAndUserPair(): void
    {
        $repo = self::code(self::repoSrc());

        self::assertStringContainsString('(page_id = %d AND user_id = %d)', $repo);
        self::assertStringContainsString('($pairs[$pageId] ?? 0) === $userId', $repo);
    }

    // ── 3. public shape ───────────────────────────────────────────────

    /** ⛔ Private columns must not reach a public validator card. */
    public function testThePublicCardShapeLeaksNoPrivateColumns(): void
    {
        $card = self::code(self::read('app/Domain/Core/Services/CardViewService.php'));
        $shape = self::method($card, 'shapeOperatorProfile');

        foreach (["'user_id'", "'updated_at'", "'created_at'", "'logo_attachment_id'"] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $shape,
                "{$forbidden} must not be emitted on a public card"
            );
        }
        foreach (["'brand_name'", "'bio'", "'website_url'", "'logo_url'"] as $expected) {
            self::assertStringContainsString($expected, $shape);
        }
    }

    /**
     * The contract calls `operator_profile` ALWAYS PRESENT on the shared card
     * envelope, so every builder must emit the key -- null where the field
     * cannot apply. `chains`, `member_dossier` and `crest` already do this,
     * and the member builder's own comment calls it "shape uniformity".
     *
     * Measured gap this closes: the field was emitted only by getPageCard,
     * so member and community cards omitted the KEY and a client reading
     * card.operator_profile got `undefined` on those kinds and `null` on an
     * unclaimed validator page -- the exact inconsistency the envelope
     * exists to prevent, and a break of the §4 contract text.
     */
    public function testEveryCardEnvelopeBuilderEmitsTheKey(): void
    {
        $card = self::code(self::read('app/Domain/Core/Services/CardViewService.php'));

        // Anti-vacuity: the three builders must really be found, and the
        // count must match the sibling field that already holds the rule.
        $builders = ['getPageCard', 'getMemberCardByUserId', 'getCommunityCardFromGroupData'];
        foreach ($builders as $builder) {
            $body = self::method($card, $builder);
            self::assertStringContainsString(
                "'card_kind'",
                $body,
                "{$builder} must be a full envelope builder for this test to mean anything"
            );
            self::assertStringContainsString(
                "'operator_profile'",
                $body,
                "{$builder} must emit operator_profile; the contract says the key is always present"
            );
        }

        // The rule is the sibling fields' rule, not a new one: whatever
        // number of builders emit `chains`, the same number emit this.
        self::assertSame(
            substr_count($card, "'chains'"),
            substr_count($card, "'operator_profile'"),
            'operator_profile must be emitted by exactly the builders that emit chains'
        );
    }

    // ── 4. bounded image size ─────────────────────────────────────────

    /**
     * ⚠ BlogCoverImageWriter accepts 8 MiB / 24 million pixels, and this
     * URL renders on every validator card including directory grids, so
     * 'full' would ship the original to each one.
     */
    public function testTheLogoUrlUsesABoundedSizeNotFull(): void
    {
        foreach ([
            'app/Domain/Core/Services/CardViewService.php',
            'app/Domain/Onchain/REST/OperatorProfileEndpoint.php',
        ] as $file) {
            $src = self::code(self::read($file));
            self::assertStringNotContainsString(
                "wp_get_attachment_image_url(\$attachmentId, 'full')",
                $src,
                "{$file} must not request the full-size original"
            );
            self::assertStringContainsString("wp_get_attachment_image_url(\$attachmentId, 'medium')", $src);
        }
    }

    // ── 5. the irreversible delete ────────────────────────────────────

    /**
     * ⛔ A superseded logo is deleted, but ONLY when this user authored
     * it. `wp_delete_attachment($id, true)` cannot be undone.
     */
    public function testASupersededLogoIsDeletedOnlyWhenThisUserAuthoredIt(): void
    {
        $src  = self::code(self::endpointSrc());
        $body = self::method($src, 'discardSupersededLogo');

        self::assertStringContainsString("get_post_field('post_author'", $body, 'ownership must be checked');
        self::assertStringContainsString('$author !== $userId', $body);
        self::assertStringContainsString('wp_delete_attachment(', $body);

        // The author check must come BEFORE the delete.
        self::assertLessThan(
            strpos($body, 'wp_delete_attachment('),
            strpos($body, '$author !== $userId'),
            'the ownership check must guard the delete, not follow it'
        );

        // An unchanged id is a no-op, so a plain save never deletes.
        self::assertStringContainsString('$previousId === $currentId', $body);
    }

    /** Both logo paths collect the file they stopped pointing at. */
    public function testBothLogoPathsCollectTheSupersededFile(): void
    {
        $src = self::code(self::endpointSrc());

        foreach (['uploadLogo', 'deleteLogo'] as $handler) {
            $body = self::method($src, $handler);
            self::assertStringContainsString(
                'discardSupersededLogo(',
                $body,
                "{$handler} must collect the superseded attachment"
            );
            self::assertStringContainsString(
                'currentLogoAttachmentId(',
                $body,
                "{$handler} must read the outgoing id BEFORE writing"
            );
        }
    }

    // ── 6. schema invariants the code depends on ──────────────────────

    /**
     * Both insert paths must name every NOT NULL column: `bio` is
     * `TEXT NOT NULL` with no default, and a TEXT column cannot have one,
     * so an INSERT that omits it is rejected under STRICT_TRANS_TABLES.
     */
    public function testEveryInsertPathNamesTheDefaultlessNotNullColumn(): void
    {
        $schema = self::code(self::read('includes/database/schema-operator-profiles.php'));
        self::assertStringContainsString('bio TEXT NOT NULL', $schema, 'the hazard this test guards');

        $repo = self::code(self::repoSrc());
        foreach (['updateProfile', 'setLogoAttachment'] as $writer) {
            $body = self::method($repo, $writer);
            self::assertStringContainsString(
                'foreach (self::PROFILE_FIELDS as $field)',
                $body,
                "{$writer} must supply every profile column on INSERT"
            );
        }
    }

    /** Reads stay bounded and never SELECT *. */
    public function testReadsAreBoundedAndExplicit(): void
    {
        $repo = self::code(self::repoSrc());

        self::assertStringNotContainsString('SELECT *', $repo, 'explicit columns only (§2)');
        self::assertGreaterThanOrEqual(3, substr_count($repo, 'LIMIT'), 'every read is bounded');
    }
}
