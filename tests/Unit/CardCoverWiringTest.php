<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Services\UserViewService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionObject;

/**
 * `cover_photo_url` survives all the way onto a card a LIST PRODUCER built.
 *
 * ## Why this exists, in review's words
 *
 * With `'cover_photo_url' => null` swapped in on the page card AND on the
 * member card, both mutants SURVIVED all 28 `CardViewService` suites. The
 * resolvers were tested; nothing asserted that the builders call them. A
 * field can be perfectly resolved and never emitted.
 *
 * ⇒ So this drives `getMemberCardForList()` — the entry point the member
 * directory, watchers and followers lists go through — and asserts on the
 * EMITTED field.
 *
 * ## How the eleven-dependency constructor is dealt with
 *
 * The house pattern (`MemberOpenDisputePermissionTest`): build the service
 * with `newInstanceWithoutConstructor()` and set only the properties this
 * path touches, using anonymous classes that extend the real collaborator
 * and skip its constructor.
 *
 * ⭐ `$viewerId = 0` is load-bearing, not laziness. Every viewer-specific
 * branch in the builder (attestations, votes, watches) short-circuits for
 * an anonymous viewer, so the harness needs two doubles instead of five —
 * and the cover does not depend on the viewer, so nothing under test is
 * skipped. An anonymous list is also the cheapest real request this code
 * serves.
 */
#[Group('unit')]
#[CoversClass(CardViewService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CardCoverWiringTest extends TestCase
{
    private const MEMBER_ID = 8100;
    private const PAGE_ID   = 8200;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/card-cover-wiring-stubs.php';

        $GLOBALS['__bcc_ccw'] = ['covers' => []];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_ccw']);
        parent::tearDown();
    }

    /**
     * ⭐ THE WIRING: a member card built by the list producer carries the
     * cover. Swapping the emission to `null` fails here.
     */
    public function testAMemberListCardCarriesTheCover(): void
    {
        $GLOBALS['__bcc_ccw']['covers'][self::MEMBER_ID] = 'https://cms.example.test/wp-content/peepso/users/8100/cover.jpg';

        $card = $this->memberListCard();

        self::assertArrayHasKey('cover_photo_url', $card, 'the field is not on the card at all');
        self::assertSame(
            'https://cms.example.test/wp-content/peepso/users/8100/cover.jpg',
            $card['cover_photo_url'],
            'the builder does not emit what the resolver returns'
        );
    }

    /** And a member with no cover gets the documented null, not a missing key. */
    public function testAMemberListCardWithoutACoverCarriesNull(): void
    {
        $card = $this->memberListCard();

        self::assertArrayHasKey('cover_photo_url', $card);
        self::assertNull($card['cover_photo_url']);
    }

    /**
     * ⭐⭐ THE OTHER HALF, and the one that caught me out: a PAGE card
     * built by the list producer carries the cover, rebased.
     *
     * The member test above did NOT cover this. I ran SA3's page mutant
     * (`'cover_photo_url' => null` on the page card) against the suite
     * and it SURVIVED. A surviving mutant is a missing scenario, not a
     * redundant one, so here it is.
     */
    public function testAPageListCardCarriesTheCoverRebased(): void
    {
        $GLOBALS['__bcc_ccw']['meta'][self::PAGE_ID]['page_cover_photo']
            = 'https://stage.example.test/wp-content/peepso/pages/8200/cover.jpg';

        $card = $this->pageListCard();

        self::assertArrayHasKey('cover_photo_url', $card, 'the field is not on the page card at all');
        self::assertSame(
            'https://cms.example.test/wp-content/peepso/pages/8200/cover.jpg',
            $card['cover_photo_url'],
            'the page builder does not emit what the reader returns'
        );
    }

    /** And a page with no cover carries the documented null. */
    public function testAPageListCardWithoutACoverCarriesNull(): void
    {
        $card = $this->pageListCard();

        self::assertArrayHasKey('cover_photo_url', $card);
        self::assertNull($card['cover_photo_url']);
    }

    /**
     * One page card, through the list entry point.
     *
     * ⭐ The PREFETCH BUNDLE is what makes this reachable: with one
     * supplied the builder skips `PageAccess::viewerMaySee()` — a list
     * caller's ids already came from a query that applied the privacy SQL
     * — and takes the read model from the map rather than the repository.
     * So this needs no `$wpdb` and no read-model double.
     *
     * @return array<string, mixed>
     */
    private function pageListCard(): array
    {
        $service = (new ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();

        // The same declared doubles the member path uses.
        $this->inject($service, 'attestationService', new \BCC\Trust\Core\Services\AttestationService());
        $this->inject(
            $service,
            'divergenceClassifier',
            (new ReflectionClass(\BCC\Trust\Core\Services\DivergenceStateClassifier::class))->newInstanceWithoutConstructor()
        );

        // Final, no constructor: built without one, like the classifier.
        $this->inject(
            $service,
            'newEntityVelocityCap',
            (new ReflectionClass(\BCC\Trust\Core\Services\NewEntityReputationVelocityCap::class))
                ->newInstanceWithoutConstructor()
        );

        /** @var array<string, mixed>|null $card */
        $card = $service->getPageCardForList('validator', self::PAGE_ID, 0, [
            'read_models'                => [],
            'claimed_pages'              => [],
            'viewer_claims'              => [],
            'viewer_votes'               => [],
            'viewer_watches'             => [],
            // Populated for THIS page, because the prefetch branch falls
            // back to the endorsement service when the id is absent. The
            // shape is the service's own anonymous answer
            // (EndorsementService::getEndorseEligibility, viewerId <= 0).
            'endorse_eligibility'        => [
                self::PAGE_ID => [
                    'allowed'     => false,
                    'unlock_hint' => 'Sign in to vouch.',
                    'reason_code' => 'auth_required',
                ],
            ],
            'viewer_attestations'        => [],
            'validator_rows'             => [],
            'validator_logos'            => [],
            'operator_profiles'          => [],
            // ⭐ EVERY key the service reads, enumerated from the source
            // rather than discovered one error at a time. A key that is
            // PRESENT sends the builder down its prefetch branch, which is
            // what keeps the repositories (and their core DB and $wpdb
            // doubles) out of this harness entirely.
            'page_entities'              => [],
            'page_operators'             => [],
            'page_activations'           => [],
            'message_eligibility'        => [],
            'delegator_groups'           => [],
            'dispute_active_counts'      => [],
            'attestation_active_counts'  => [],
            'attestation_revoked_counts' => [],
        ]);

        self::assertIsArray($card, 'the harness did not produce a page card at all');

        return $card;
    }
    /**
     * One member card, through the list entry point.
     *
     * @return array<string, mixed>
     */
    private function memberListCard(): array
    {
        $service = (new ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();

        // ⚠ NOT an anonymous subclass: the real `UserViewService` is FINAL
        // and the property is readonly-TYPED, so the double is DECLARED
        // under the same FQN in the stub world instead. See that file.
        $summary = new UserViewService();

        $reputation = new class extends ReputationRepository {
            public function __construct()
            {
                // Skip the parent constructor (TableRegistry + consts).
            }

            public function getTier(int $userId): string
            {
                unset($userId);

                return 'trusted';
            }

            public function getScore(int $userId): float
            {
                unset($userId);

                return 72.0;
            }
        };

        $this->inject($service, 'userViewService', $summary);
        $this->inject($service, 'reputationRepo', $reputation);
        // Also declared rather than subclassed (final). Only the member
        // PERMISSIONS block reads it, and an anonymous viewer is refused.
        $this->inject($service, 'attestationService', new \BCC\Trust\Core\Services\AttestationService());
        $this->inject($service, 'divergenceClassifier', (new ReflectionClass(\BCC\Trust\Core\Services\DivergenceStateClassifier::class))->newInstanceWithoutConstructor());

        /** @var array<string, mixed>|null $card */
        // ⭐ A PREFETCH BUNDLE, which is what a real list passes — and it
        // is what keeps this harness small. The builder prefers its map
        // over every per-card read, so supplying the three negative-signal
        // counts skips the DisputeRepository statics (and the core DB and
        // $wpdb doubles they would otherwise drag in) entirely. Nothing
        // under test is skipped: the cover does not come from here.
        $card = $service->getMemberCardForList(self::MEMBER_ID, 0, [
            'dispute_active_counts'       => [],
            'attestation_active_counts'   => [],
            'attestation_revoked_counts'  => [],
        ]);

        self::assertIsArray($card, 'the harness did not produce a card at all');

        return $card;
    }

    private function inject(object $service, string $property, object $double): void
    {
        $prop = (new ReflectionObject($service))->getProperty($property);
        $prop->setValue($service, $double);
    }
}
