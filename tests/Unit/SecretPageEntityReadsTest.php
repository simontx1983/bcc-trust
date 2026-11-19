<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\CardReviewsEndpoint;
use BCC\Trust\Core\REST\EntityAttestationsEndpoint;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Services\AttestationService;
use BCC\Trust\Core\Support\PageAccess;
use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A SECRET page's reviews and vouches, read by page id:
 *   GET /entities/{validator|project|creator}_card/{id}/reviews
 *   GET /entities/{validator|project|creator}_card/{id}/attestations
 *
 * Both endpoints answer a page that does not exist with an EMPTY 200 (no
 * reviews, nobody vouched), not a 404. So a viewer PageAccess refuses must
 * get exactly that empty answer: a 404 here would itself say "this page
 * exists and is secret".
 *
 * The real handlers, the real CardReviewsService / VoteRepository and the
 * real AttestationService / AttestationRepository run over a fake $wpdb
 * that finds no rows. That makes "the answer for a page that does not
 * exist" something these tests can compute rather than copy.
 */
#[CoversClass(CardReviewsEndpoint::class)]
#[CoversClass(EntityAttestationsEndpoint::class)]
#[CoversClass(PageAccess::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SecretPageEntityReadsTest extends TestCase
{
    private const PAGE    = 42;
    private const MISSING = 4242;
    private const AUTHOR  = 5;
    private const VIEWER  = 7;

    private \BccSecretPageCardFakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/secret-page-card-stubs.php';
        require_once CoreSibling::require('src/Repositories/ProbesReads.php');
        require_once CoreSibling::require('src/Repositories/PeepSoPagePrivacy.php');

        $post              = new \WP_Post();
        $post->ID          = self::PAGE;
        $post->post_type   = 'peepso-page';
        $post->post_status = 'publish';
        $post->post_author = self::AUTHOR;

        $GLOBALS['__bcc_spc'] = [
            'posts'      => [self::PAGE => $post],
            'meta'       => [],
            'meta_reads' => [],
            'admin'      => false,
            'viewer'     => 0,
        ];

        $this->wpdb      = new \BccSecretPageCardFakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        // The real AttestationService, built without its eleven
        // collaborators: getForTarget() on a target with no rows reads only
        // its repository.
        $service = (new \ReflectionClass(AttestationService::class))->newInstanceWithoutConstructor();
        \Closure::bind(function (): void {
            $this->repo = new AttestationRepository();
        }, $service, AttestationService::class)();
        \Closure::bind(function () use ($service): void {
            $this->attestationService = $service;
        }, Plugin::instance(), Plugin::class)();
    }

    // ── vouches ─────────────────────────────────────────────────────────

    public function testHiddenVouchesAnswerExactlyAsAMissingPagesDo(): void
    {
        $this->wpdb->secret = true;
        $GLOBALS['__bcc_spc']['viewer'] = self::VIEWER;

        $hidden = self::vouches(self::PAGE);
        self::assertSame([], $this->targetReads('trust_attestations'), 'nothing about the secret page was read');

        $this->wpdb->secret = false;
        $missing = self::vouches(self::MISSING);
        self::assertNotSame([], $this->targetReads('trust_attestations'), 'the missing page really ran through the service');

        self::assertSame($missing->get_data(), $hidden->get_data());
        self::assertSame($missing->get_status(), $hidden->get_status());
        self::assertSame($missing->get_headers(), $hidden->get_headers());
    }

    public function testAnAnonymousViewerGetsTheSameAnswerForBothToo(): void
    {
        $this->wpdb->secret = true;
        $hidden = self::vouches(self::PAGE);

        $this->wpdb->secret = false;
        $missing = self::vouches(self::MISSING);

        self::assertSame($missing->get_data(), $hidden->get_data());
        self::assertSame($missing->get_headers(), $hidden->get_headers(), 'the same public cache line for both');
    }

    public function testAMemberReadsASecretPagesVouches(): void
    {
        $this->wpdb->secret   = true;
        $this->wpdb->statuses = ['member'];
        $GLOBALS['__bcc_spc']['viewer'] = self::VIEWER;

        self::vouches(self::PAGE);

        self::assertNotSame([], $this->targetReads('trust_attestations', self::PAGE), 'the roster was read');
    }

    public function testTheAuthorAndAnAdminReadASecretPagesVouches(): void
    {
        $this->wpdb->secret = true;

        $GLOBALS['__bcc_spc']['viewer'] = self::AUTHOR;
        self::vouches(self::PAGE);
        self::assertNotSame([], $this->targetReads('trust_attestations', self::PAGE), 'the author');

        $this->wpdb->queries = [];
        $GLOBALS['__bcc_spc']['viewer'] = self::VIEWER;
        $GLOBALS['__bcc_spc']['admin']  = true;
        self::vouches(self::PAGE);
        self::assertNotSame([], $this->targetReads('trust_attestations', self::PAGE), 'an admin');
    }

    public function testAFailedPrivacyReadHidesTheVouches(): void
    {
        $this->wpdb->failPrivacy = true;
        $GLOBALS['__bcc_spc']['viewer'] = self::VIEWER;

        self::vouches(self::PAGE);

        self::assertSame([], $this->targetReads('trust_attestations'));
    }

    public function testAMemberProfileIsNeverAskedAboutPagePrivacy(): void
    {
        $this->wpdb->secret = true;

        (new EntityAttestationsEndpoint())->list(new \WP_REST_Request([
            'target_kind' => 'user_profile', 'target_id' => self::PAGE,
        ]));

        self::assertSame([], $this->privacyReads());
        self::assertNotSame([], $this->targetReads('trust_attestations'), 'the roster was read');
    }

    // ── reviews ─────────────────────────────────────────────────────────

    public function testHiddenReviewsAnswerExactlyAsAMissingPagesDo(): void
    {
        $this->wpdb->secret = true;
        $GLOBALS['__bcc_spc']['viewer'] = self::VIEWER;

        $hidden = self::reviews(self::PAGE);
        self::assertSame([], $this->targetReads('bcc_trust_votes'), 'nothing about the secret page was read');

        $this->wpdb->secret = false;
        $missing = self::reviews(self::MISSING);
        self::assertNotSame([], $this->targetReads('bcc_trust_votes', self::MISSING), 'the missing page really ran through the service');

        self::assertSame($missing->get_data(), $hidden->get_data());
        self::assertSame($missing->get_status(), $hidden->get_status());
        self::assertSame($missing->get_headers(), $hidden->get_headers());
    }

    public function testAMemberReadsASecretPagesReviews(): void
    {
        $this->wpdb->secret   = true;
        $this->wpdb->statuses = ['pending_user'];
        $GLOBALS['__bcc_spc']['viewer'] = self::VIEWER;

        self::reviews(self::PAGE);

        self::assertNotSame([], $this->targetReads('bcc_trust_votes', self::PAGE), 'an invitee reads them');
    }

    public function testMemberReviewsAreNeverAskedAboutPagePrivacy(): void
    {
        $this->wpdb->secret = true;

        (new CardReviewsEndpoint())->list(new \WP_REST_Request([
            'target_kind' => 'user_profile', 'target_id' => self::PAGE,
        ]));

        self::assertSame([], $this->privacyReads());
        self::assertNotSame([], $this->targetReads('bcc_trust_votes'), 'the reviews were read');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private static function vouches(int $targetId): \WP_REST_Response
    {
        return (new EntityAttestationsEndpoint())->list(new \WP_REST_Request([
            'target_kind' => 'validator_card',
            'target_id'   => $targetId,
            'page'        => 2,
            'per_page'    => 10,
        ]));
    }

    private static function reviews(int $targetId): \WP_REST_Response
    {
        return (new CardReviewsEndpoint())->list(new \WP_REST_Request([
            'target_kind' => 'project_card',
            'target_id'   => $targetId,
            'page'        => 2,
            'per_page'    => 10,
        ]));
    }

    /** @return list<string> statements on $table, optionally naming $id */
    private function targetReads(string $table, ?int $id = null): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $q): bool => str_contains($q, $table)
                && !str_starts_with(ltrim($q), 'SHOW')
                && ($id === null || preg_match('/\b' . $id . '\b/', $q) === 1)
        ));
    }

    /** @return list<string> */
    private function privacyReads(): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $q): bool => str_contains($q, 'bcc_page_priv') || str_contains($q, 'peepso_page_members')
        ));
    }
}
