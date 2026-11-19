<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The single card `/cards/{type}/{id}` for a SECRET page.
 *
 * A viewer who may not see the page gets null from CardViewService::getCard(),
 * which CardsEndpoint turns into the same 404 it gives a page that does not
 * exist. Who may see it, cheapest check first:
 *   1. the page's author (some owners have no member_owner row yet);
 *   2. a site admin (ProfileAccess's shared exemption);
 *   3. a member or invitee, per bcc-core's PeepSoPagePrivacy::isVisibleTo().
 *
 * The REAL bcc-core PeepSoPagePrivacy runs here, over a fake $wpdb, so this
 * pins the wiring and the rule together. Whether the gate let the card
 * through is read from the page-type read that comes right after it: the
 * fixture's page type never matches, so a card that passed the gate still
 * ends in null, but only after reading `_bcc_page_type`.
 */
#[CoversClass(CardViewService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SecretPageSingleCardGateTest extends TestCase
{
    private const PAGE   = 42;
    private const AUTHOR = 5;
    private const VIEWER = 7;

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
            // Never 'validator', so even a card the gate lets through ends
            // in null right after the page-type read.
            'meta'       => [self::PAGE => ['_bcc_page_type' => 'builder']],
            'meta_reads' => [],
            'admin'      => false,
        ];

        $this->wpdb      = new \BccSecretPageCardFakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    // ── the gate's verdicts ─────────────────────────────────────────────

    /** @return iterable<string, array{bool, list<string>, int, bool}> secret, viewer's rows, viewer, passes */
    public static function verdicts(): iterable
    {
        yield 'open page, anonymous'           => [false, [], 0, true];
        yield 'open page, signed in'           => [false, [], self::VIEWER, true];
        yield 'secret page, anonymous'         => [true, [], 0, false];
        yield 'secret page, stranger'          => [true, [], self::VIEWER, false];
        yield 'secret page, member'            => [true, ['member'], self::VIEWER, true];
        yield 'secret page, owner row'         => [true, ['member_owner'], self::VIEWER, true];
        yield 'secret page, invited'           => [true, ['pending_user'], self::VIEWER, true];
        yield 'secret page, asked to join'     => [true, ['pending_admin'], self::VIEWER, false];
        yield 'secret page, banned and member' => [true, ['banned', 'member'], self::VIEWER, false];
        yield 'secret page, member and banned' => [true, ['member', 'banned'], self::VIEWER, false];
    }

    /** @param list<string> $statuses */
    #[DataProvider('verdicts')]
    public function testTheGateDecidesWhoSeesTheCard(bool $secret, array $statuses, int $viewer, bool $passes): void
    {
        $this->wpdb->secret   = $secret;
        $this->wpdb->statuses = $statuses;

        self::assertNull(self::service()->getCard('validator', (string) self::PAGE, $viewer));
        self::assertSame($passes, self::readPageType(), $passes ? 'the card went on past the gate' : 'the gate stopped the card');
    }

    public function testASecretPageAnswersExactlyAsAMissingPageDoes(): void
    {
        $this->wpdb->secret = true;
        $secret = self::service()->getCard('validator', (string) self::PAGE, self::VIEWER);

        $missing = self::service()->getCard('validator', '4242', self::VIEWER);

        self::assertNull($secret);
        self::assertNull($missing);
        self::assertSame([], $GLOBALS['__bcc_spc']['meta_reads'], 'neither read anything about the page');
    }

    public function testAFailedPrivacyReadHidesTheCardFromANonMember(): void
    {
        $this->wpdb->failPrivacy = true;

        self::assertNull(self::service()->getCard('validator', (string) self::PAGE, self::VIEWER));
        self::assertFalse(self::readPageType(), 'unknown counts as secret');
    }

    // ── exemptions, checked before any privacy read ─────────────────────

    public function testTheAuthorSeesTheirSecretPageWithoutAMemberRowOrAnyRead(): void
    {
        $this->wpdb->secret = true;

        self::service()->getCard('validator', (string) self::PAGE, self::AUTHOR);

        self::assertTrue(self::readPageType(), 'the author passes the gate');
        self::assertSame([], $this->wpdb->queries, 'decided before the privacy read');
    }

    public function testAnAnonymousViewerIsNeverTakenForAnAuthorlessPagesAuthor(): void
    {
        $this->wpdb->secret = true;
        $GLOBALS['__bcc_spc']['posts'][self::PAGE]->post_author = 0;

        self::service()->getCard('validator', (string) self::PAGE, 0);

        self::assertFalse(self::readPageType(), 'viewer 0 must not match post_author 0');
    }

    public function testASiteAdminSeesASecretPageWithoutAnyRead(): void
    {
        $this->wpdb->secret          = true;
        $GLOBALS['__bcc_spc']['admin'] = true;

        self::service()->getCard('validator', (string) self::PAGE, self::VIEWER);

        self::assertTrue(self::readPageType(), 'the admin passes the gate');
        self::assertSame([], $this->wpdb->queries, 'decided before the privacy read');
    }

    // ── list callers ────────────────────────────────────────────────────

    /**
     * List callers pass a prefetch bundle and take their ids from discovery
     * queries that already exclude secret pages, so the per-card read is
     * skipped rather than paid once per card.
     */
    public function testAListCallerPaysNoPerCardPrivacyRead(): void
    {
        $this->wpdb->secret = true;

        self::service()->getPageCardForList('validator', self::PAGE, self::VIEWER, []);

        self::assertTrue(self::readPageType());
        self::assertSame([], $this->wpdb->queries);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private static function service(): CardViewService
    {
        // getCard() returns before any collaborator is touched in every case
        // here, so the constructor's dependencies are not needed.
        return (new \ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();
    }

    private static function readPageType(): bool
    {
        return in_array([self::PAGE, '_bcc_page_type'], $GLOBALS['__bcc_spc']['meta_reads'], true);
    }
}
