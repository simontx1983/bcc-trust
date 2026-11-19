<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit {

    use BCC\Trust\Core\Plugin;
    use BCC\Trust\Core\REST\WatchingEndpoint;
    use BCC\Trust\Core\Services\WatchingService;
    use BCC\Trust\Core\Support\MemberCardPrefetcher;
    use BCC\Trust\Core\Support\PageCardPrefetcher;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * §F-02 on the watch surfaces, with the REAL ProfileAccess gate over
     * PeepSo doubles (tests/Stubs/profile-access-stubs.php):
     *
     *  - POST /me/watching/watch, kind=member, answers with the member's
     *    handle and tier, so a member the viewer may not open is refused
     *    (403, `reason: profile_private`) before anything is written.
     *  - GET /me/watching?include=cards builds no card for such a member:
     *    the row stays (the viewer's own follow), its `card` is null.
     *
     * Pages are not people: the profile gate never asks about a page kind.
     * A SECRET page answers to PageAccess instead (over the REAL bcc-core
     * PeepSoPagePrivacy and a `$wpdb` that answers its reads,
     * tests/Stubs/secret-page-list-stubs.php): a viewer the page does not
     * admit keeps the row, and its card is null.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class WatchingProfileGateTest extends TestCase
    {
        private const VIEWER   = 5;
        private const VISIBLE  = 11; // public profile (10)
        private const HIDDEN   = 12; // "only me" (40)
        private const PAGE     = 900;
        private const SECRET   = 901;

        protected function setUp(): void
        {
            // Doubles first: they must exist before the real classes autoload.
            require_once dirname(__DIR__) . '/Stubs/watching-profile-gate-stubs.php';
            require_once dirname(__DIR__) . '/Stubs/profile-access-stubs.php';
            require_once dirname(__DIR__) . '/Stubs/secret-page-list-stubs.php';
            \bcc_spl_load_core();
            $GLOBALS['__bcc_spl'] = ['secret' => [self::SECRET => true]];
            $GLOBALS['wpdb']      = new \BccSecretPageListWpdb();

            $GLOBALS['__bcc_profile_access'] = [
                'viewer_id' => self::VIEWER,
                'levels'    => [self::VISIBLE => 10, self::HIDDEN => 40],
                'verdicts'  => [10 => true, 40 => false],
                'calls'     => [],
            ];
            Plugin::$watchCalls       = [];
            Plugin::$cardViewResolved = 0;
            MemberCardPrefetcher::$primed = [];
            PageCardPrefetcher::$primed   = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['wpdb'], $GLOBALS['__bcc_spl']);
        }

        // ── POST /me/watching/watch ─────────────────────────────────────

        public function testWatchingAMemberTheViewerMayNotOpenIsRefusedBeforeAnythingIsWritten(): void
        {
            $response = $this->watch('member', self::HIDDEN);

            self::assertSame(403, $response->get_status());
            self::assertSame('profile_private', $response->get_data()['error']['data']['reason'] ?? null);
            self::assertSame([], Plugin::$watchCalls, 'the follow is never written');
        }

        public function testWatchingAMemberTheViewerMayOpenGoesThrough(): void
        {
            $response = $this->watch('member', self::VISIBLE);

            self::assertSame(200, $response->get_status());
            self::assertSame([[self::VIEWER, 'member', self::VISIBLE]], Plugin::$watchCalls);
        }

        public function testAPageKindIsNotGated(): void
        {
            // The same number as the private member: a page id, not a person.
            $response = $this->watch('validator', self::HIDDEN);

            self::assertSame(200, $response->get_status());
            self::assertSame([[self::VIEWER, 'validator', self::HIDDEN]], Plugin::$watchCalls);
            self::assertSame([], $GLOBALS['__bcc_profile_access']['calls'], 'the profile gate is never asked');
        }

        // ── GET /me/watching?include=cards ──────────────────────────────

        public function testAMemberTheViewerMayNotOpenKeepsTheRowButGetsNoCard(): void
        {
            $out = $this->attachCards([
                self::memberRow(1, self::VISIBLE),
                self::memberRow(2, self::HIDDEN),
                self::pageRow(3, self::PAGE),
            ]);

            self::assertCount(3, $out, 'no row is dropped');
            self::assertSame(['kind' => 'member', 'id' => self::VISIBLE], $out[0]['card']);
            self::assertNull($out[1]['card'], 'no card for a profile the viewer may not open');
            self::assertSame(2, $out[1]['follow_id'], 'the row keeps the follow_id the viewer unwatches with');
            self::assertSame(['kind' => 'validator', 'id' => self::PAGE], $out[2]['card'], 'an open page is not gated');
            self::assertSame([[self::VISIBLE]], MemberCardPrefetcher::$primed, "the hidden member's card is never built");
        }

        public function testWhenEveryMemberIsHiddenNoCardIsBuiltAtAll(): void
        {
            $out = $this->attachCards([self::memberRow(1, self::HIDDEN)]);

            self::assertNull($out[0]['card']);
            self::assertSame(0, Plugin::$cardViewResolved, 'nothing to hydrate, so the card builder is never resolved');
            self::assertSame([], MemberCardPrefetcher::$primed);
        }

        // ── SECRET pages ────────────────────────────────────────────────

        public function testAPageFollowOnASecretPageKeepsTheRowButGetsNoCard(): void
        {
            $out = $this->attachCards([
                self::pageFollowRow(3, self::PAGE),
                self::pageFollowRow(4, self::SECRET),
            ]);

            self::assertCount(2, $out, 'no row is dropped');
            self::assertSame(['kind' => 'validator', 'id' => self::PAGE], $out[0]['card']);
            self::assertNull($out[1]['card'], 'no card for a secret page the viewer is not in');
            self::assertSame(4, $out[1]['follow_id'], 'the row keeps the follow_id the viewer unwatches with');
            self::assertSame([[self::PAGE]], PageCardPrefetcher::$primed, "the secret page's card is never built");
            self::assertCount(1, $GLOBALS['wpdb']->ofKind('batch'), 'one batched privacy read for the page');
        }

        public function testAMemberOfTheSecretPageGetsItsCard(): void
        {
            $GLOBALS['__bcc_spl']['statuses'][self::SECRET][self::VIEWER] = ['member'];

            $out = $this->attachCards([self::pageFollowRow(4, self::SECRET)]);

            self::assertSame(['kind' => 'validator', 'id' => self::SECRET], $out[0]['card']);
        }

        public function testWhenEveryPageIsHiddenNoCardIsBuiltAtAll(): void
        {
            $out = $this->attachCards([self::pageFollowRow(4, self::SECRET)]);

            self::assertNull($out[0]['card']);
            self::assertSame(0, Plugin::$cardViewResolved, 'nothing to hydrate, so the card builder is never resolved');
            self::assertSame([], PageCardPrefetcher::$primed);
        }

        // ── helpers ─────────────────────────────────────────────────────

        private function watch(string $kind, int $targetId): \WP_REST_Response
        {
            return (new WatchingEndpoint())->watch(new \WP_REST_Request([
                'target_kind' => $kind,
                'target_id'   => $targetId,
            ]));
        }

        /**
         * @param list<array<string, mixed>> $items
         * @return list<array<string, mixed>>
         */
        private function attachCards(array $items): array
        {
            $service = (new \ReflectionClass(WatchingService::class))->newInstanceWithoutConstructor();
            $m       = new \ReflectionMethod(WatchingService::class, 'attachCards');

            /** @var list<array<string, mixed>> $out */
            $out = $m->invoke($service, $items, self::VIEWER);

            return $out;
        }

        /** @return array<string, mixed> */
        private static function memberRow(int $followId, int $userId): array
        {
            return [
                'follow_id'     => $followId,
                'follow_source' => 'peepso',
                'card_kind'     => 'member',
                'card_id'       => $userId,
                'card_handle'   => 'm' . $userId,
                'page_id'       => null,
            ];
        }

        /**
         * A bcc_page_follows row: it names its page directly.
         *
         * @return array<string, mixed>
         */
        private static function pageFollowRow(int $followId, int $pageId): array
        {
            return [
                'follow_id'     => $followId,
                'follow_source' => 'page',
                'card_kind'     => 'validator',
                'card_id'       => $pageId,
                'card_handle'   => 'page-' . $pageId,
                'page_id'       => $pageId,
            ];
        }

        /** @return array<string, mixed> */
        private static function pageRow(int $followId, int $pageId): array
        {
            return [
                'follow_id'     => $followId,
                'follow_source' => 'peepso',
                'card_kind'     => 'validator',
                'card_id'       => 77,
                'card_handle'   => 'v77',
                'page_id'       => $pageId,
            ];
        }
    }
}
