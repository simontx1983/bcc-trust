<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\MemberProfileComposer;
use BCC\Trust\Core\Services\MemberSelfPageService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * v1.49 pins for the member-review completion slice:
 *
 *   - buildPlaceholderCard (the degraded-path member card) must expose
 *     review_target_id = selfPageId(user) — viewer-independent, so the
 *     remove affordance survives resolver failures — while keeping the
 *     viewer-dependent fields honestly inert (no viewer id reaches it).
 *   - buildTabs must emit the received/written split: `reviews` counts
 *     reviews_received (public — never privacy-hidden), `written`
 *     counts reviews_written (governed by reviews_hidden).
 */
final class MemberCardReviewFieldsTest extends TestCase
{
    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function placeholder(int $userId, array $base = []): array
    {
        $m = new ReflectionMethod(MemberProfileComposer::class, 'buildPlaceholderCard');
        $m->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $m->invoke(null, $userId, array_merge([
            'display_name' => 'Smoke B',
            'handle'       => 'smokeb',
        ], $base));
        return $out;
    }

    /**
     * @param array<string, mixed> $base
     * @return list<array{key: string, label: string, count: int, hidden: bool}>
     */
    private function tabs(array $base): array
    {
        $m = new ReflectionMethod(MemberProfileComposer::class, 'buildTabs');
        $m->setAccessible(true);
        /** @var list<array{key: string, label: string, count: int, hidden: bool}> $out */
        $out = $m->invoke(null, $base);
        return $out;
    }

    /**
     * @param list<array{key: string, label: string, count: int, hidden: bool}> $tabs
     * @return array{key: string, label: string, count: int, hidden: bool}
     */
    private static function tab(array $tabs, string $key): array
    {
        foreach ($tabs as $tab) {
            if ($tab['key'] === $key) {
                return $tab;
            }
        }
        self::fail("tab '{$key}' not emitted");
    }

    public function testPlaceholderExposesRealReviewTargetId(): void
    {
        $card = $this->placeholder(142);

        self::assertSame(MemberSelfPageService::ID_BASE + 142, $card['review_target_id']);
        self::assertSame(MemberSelfPageService::selfPageId(142), $card['review_target_id']);
    }

    public function testPlaceholderViewerFieldsStayHonestlyInert(): void
    {
        $card = $this->placeholder(142);

        self::assertFalse($card['viewer_has_reviewed']);
        self::assertFalse($card['permissions']['can_review']['allowed']);
        self::assertSame('card_unavailable', $card['permissions']['can_review']['reason_code']);
    }

    public function testTabsSplitReceivedAndWrittenCounts(): void
    {
        $tabs = $this->tabs([
            'counts'  => ['reviews_received' => 7, 'reviews_written' => 3],
            'privacy' => [],
            'is_self' => false,
        ]);

        self::assertSame(7, self::tab($tabs, 'reviews')['count']);
        self::assertSame(3, self::tab($tabs, 'written')['count']);
    }

    public function testReceivedTabIsNeverPrivacyHiddenButWrittenIs(): void
    {
        $tabs = $this->tabs([
            'counts'  => ['reviews_received' => 7, 'reviews_written' => 3],
            'privacy' => ['reviews_hidden' => true],
            'is_self' => false,
        ]);

        self::assertFalse(
            self::tab($tabs, 'reviews')['hidden'],
            'received reviews are public by decision (2026-07-22) — reviews_hidden must not hide them'
        );
        self::assertTrue(self::tab($tabs, 'written')['hidden']);
    }

    public function testOwnerSeesWrittenTabDespitePrivacyFlag(): void
    {
        $tabs = $this->tabs([
            'counts'  => ['reviews_received' => 0, 'reviews_written' => 3],
            'privacy' => ['reviews_hidden' => true],
            'is_self' => true,
        ]);

        self::assertFalse(self::tab($tabs, 'written')['hidden']);
    }
}
