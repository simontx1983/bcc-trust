<?php
/**
 * F-518 P1 — the profile's `indexable` flag comes from the ONE public-index
 * predicate, never from a rule of its own.
 *
 * The sitemap lists a member exactly when PublicProfileIndex says so, and
 * the profile page's robots tag reads `indexable`. If the composer ever
 * computed `indexable` itself (from `flags`, the access level, the handle),
 * the two could disagree: a page in the sitemap marked noindex, or an
 * indexable page the sitemap leaves out.
 *
 * ⚠ STATIC, and deliberately scoped, like PrimaryHallChipWiringTest. It
 * proves the wiring: compose() publishes `indexable` from
 * `PublicProfileIndex::isIndexable()` and nothing else, and the Plugin hands
 * the composer the shared instance. WHAT isIndexable() answers is proven on
 * a real engine, member by member, in PublicSitemapIndexIntegrationTest.
 * {@see GateOrder::code()} strips comments, so a docblock mention cannot
 * satisfy these.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\TestCase;

final class ProfileIndexableWiringTest extends TestCase
{
    private const COMPOSER = 'app/Domain/Core/Services/MemberProfileComposer.php';
    private const PLUGIN   = 'app/Domain/Core/Plugin.php';

    public function testComposePublishesIndexableFromThePublicIndex(): void
    {
        $body = GateOrder::body(GateOrder::code(self::COMPOSER), 'compose');

        self::assertStringContainsString(
            "\$base['indexable'] = \$this->publicIndex->isIndexable(\$userId);",
            $body,
            'compose(): `indexable` must be the public index\'s answer for this user'
        );
        self::assertSame(1, substr_count($body, "'indexable'"), 'compose(): `indexable` is set once, nowhere else');
    }

    public function testTheComposerHoldsNoIndexingRuleOfItsOwn(): void
    {
        $code = GateOrder::code(self::COMPOSER);

        foreach (['usr_profile_acc', 'peepso_is_hide_profile_from_user_listing', 'isIndexableProfile', 'is_suspended'] as $rule) {
            self::assertStringNotContainsString($rule, $code, "the composer must not decide indexing itself ({$rule})");
        }
    }

    public function testThePluginWiresTheSharedIndexIntoTheComposer(): void
    {
        $body = GateOrder::body(GateOrder::code(self::PLUGIN), 'memberProfileComposer');

        self::assertStringContainsString('$this->publicProfileIndex()', $body);
    }
}
