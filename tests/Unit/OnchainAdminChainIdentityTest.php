<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage for the Chain Identity save.
 *
 * This handler writes the ONLY public-facing data on the Chains page — the
 * `chain_profile` block of `/halls/:slug` — and before Batch 1 it left no
 * record of any kind and re-POSTed on refresh.
 *
 * The sanitisation contract under test is deliberately asymmetric and is the
 * part most likely to regress: an EMPTY field is a deliberate clear → NULL,
 * but a NON-EMPTY field the sanitiser rejects must keep its stored value and
 * surface an error, never silently become a clear.
 */
#[CoversClass(ChainsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OnchainAdminChainIdentityTest extends TestCase
{
    private const CHAIN_ID = 4;
    private const NONCE_FIELD = 'bcc_chain_identity_nonce';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/onchain-admin-render-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'ethereum');
        \BCC\Trust\Onchain\Repositories\ChainRepository::$chains[self::CHAIN_ID]->icon_url = 'https://old.example/icon.svg';
        \BCC\Trust\Onchain\Repositories\ChainRepository::$chains[self::CHAIN_ID]->color = '#111111';

        $_POST = [];
        $_GET  = [];
    }

    /** @param array<string, string|int> $overrides */
    private function seedPost(array $overrides = []): void
    {
        $_POST = array_merge([
            'chain_id'    => self::CHAIN_ID,
            'description' => 'The original proof-of-work chain.',
            'icon_url'    => 'https://cdn.example/eth.svg',
            'color'       => '#627EEA',
        ], $overrides);
    }

    private function validNonce(): void
    {
        \BccAdminTestState::$validNonceAction = ChainsPage::ACTION_IDENTITY_SAVE;
    }

    // 1 — capability
    public function testMissingCapabilityPerformsNoMutation(): void
    {
        \BccAdminTestState::$can = false;
        $this->seedPost();
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
            $this->fail('Expected a 403 halt.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertSame([], \BCC\Trust\Onchain\Repositories\ChainRepository::$identityWrites);
        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    // 2 — nonce
    public function testInvalidNoncePerformsNoMutation(): void
    {
        $this->seedPost();
        \BccAdminTestState::$validNonceAction = null;

        $this->expectException(\BccAdminDie::class);
        try {
            ChainsPage::handle_identity_save();
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Repositories\ChainRepository::$identityWrites);
            $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
        }
    }

    public function testNonceIsReadFromItsDedicatedFieldName(): void
    {
        $this->seedPost();
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $this->assertSame(
            [['action' => ChainsPage::ACTION_IDENTITY_SAVE, 'arg' => self::NONCE_FIELD]],
            \BccAdminTestState::$nonceChecks
        );
    }

    // 3 — exactly one authoritative row updated
    public function testValidSaveUpdatesExactlyOneChainRow(): void
    {
        $this->seedPost();
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $writes = \BCC\Trust\Onchain\Repositories\ChainRepository::$identityWrites;
        $this->assertCount(1, $writes);
        $this->assertSame(self::CHAIN_ID, $writes[0]['chain_id']);
        $this->assertSame('The original proof-of-work chain.', $writes[0]['description']);
    }

    // 4 — invalid / missing chain
    public function testMissingChainIdIsRejected(): void
    {
        $this->seedPost(['chain_id' => 0]);
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('invalid_chain', $r->args['bcc_identity']);
        }

        $this->assertSame([], \BCC\Trust\Onchain\Repositories\ChainRepository::$identityWrites);
    }

    public function testUnresolvableChainIsRejected(): void
    {
        $this->seedPost(['chain_id' => 999]);
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('invalid_chain', $r->args['bcc_identity']);
        }

        $this->assertSame([], \BCC\Trust\Onchain\Repositories\ChainRepository::$identityWrites);
    }

    // 5 — success audit
    public function testSuccessWritesAdminChainIdentitySaved(): void
    {
        $this->seedPost();
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        $this->assertSame(['admin_chain_identity_saved'], \BCC\Trust\Core\Security\AuditLogger::actions());
        $this->assertSame('chain', $rows[0]['targetType']);
        $this->assertSame(self::CHAIN_ID, $rows[0]['targetId']);
    }

    // 6 + 7 — domain failure audit, exception not shown
    public function testDomainFailureAuditsAndHidesTheException(): void
    {
        $this->seedPost();
        $this->validNonce();
        \BCC\Trust\Onchain\Repositories\ChainRepository::$identityThrows =
            new \RuntimeException('SQLSTATE[HY000] wp_bcc_chains locked at /var/www/html/secret.php:12');

        try {
            ChainsPage::handle_identity_save();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('exception', $r->args['bcc_identity']);
            $this->assertMatchesRegularExpression('/^bcc-[0-9a-f]{8}$/', $r->args['bcc_ref']);
            $this->assertStringNotContainsString('SQLSTATE', $r->url);
            $this->assertStringNotContainsString('wp_bcc_chains', $r->url);
            $this->assertStringNotContainsString('/var/www', $r->url);
        }

        $this->assertSame(['admin_chain_identity_failed'], \BCC\Trust\Core\Security\AuditLogger::actions());

        // Full detail is retained internally.
        $errors = \BCC\Core\Log\Logger::ofLevel('error');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('SQLSTATE[HY000]', $errors[0]['context']['message']);
    }

    public function testRepositoryReturningFalseIsReportedAsNotFound(): void
    {
        $this->seedPost();
        $this->validNonce();
        \BCC\Trust\Onchain\Repositories\ChainRepository::$identityResult = false;

        try {
            ChainsPage::handle_identity_save();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('not_found', $r->args['bcc_identity']);
        }

        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    // 8 + 9 — PRG, and the destination cannot replay
    public function testSuccessUsesPrgAndTheDestinationCannotReplay(): void
    {
        $this->seedPost();
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('bcc-onchain-chains', $r->args['page']);
            $this->assertSame('identity', $r->args['subtab']);
            $this->assertSame('ok', $r->args['bcc_identity']);
            $this->assertSame((string) self::CHAIN_ID, $r->args['bcc_chain']);

            // The redirect target carries NO field the handler reads. Re-issuing
            // it as a GET cannot re-run the save: the handler only ever reads
            // $_POST, and admin.php is not the admin-post router.
            $this->assertArrayNotHasKey('description', $r->args);
            $this->assertArrayNotHasKey('icon_url', $r->args);
            $this->assertArrayNotHasKey('color', $r->args);
            $this->assertArrayNotHasKey('action', $r->args);
            $this->assertStringContainsString('/wp-admin/admin.php', $r->url);
            $this->assertStringNotContainsString('admin-post.php', $r->url);
        }
    }

    // 10 — sanitisation, including icon_url
    public function testRejectedIconUrlKeepsStoredValueAndReportsPartial(): void
    {
        $this->seedPost(['icon_url' => 'javascript:alert(1)']);
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('partial', $r->args['bcc_identity']);
        }

        $writes = \BCC\Trust\Onchain\Repositories\ChainRepository::$identityWrites;
        // The rejected field keeps its STORED value — it is not cleared.
        $this->assertSame('https://old.example/icon.svg', $writes[0]['icon_url']);
        // Every other field still saved.
        $this->assertSame('#627EEA', $writes[0]['color']);
    }

    public function testRejectedColorKeepsStoredValue(): void
    {
        $this->seedPost(['color' => 'not-a-color']);
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('partial', $r->args['bcc_identity']);
        }

        $writes = \BCC\Trust\Onchain\Repositories\ChainRepository::$identityWrites;
        $this->assertSame('#111111', $writes[0]['color']);
    }

    public function testEmptyFieldIsADeliberateClearToNull(): void
    {
        $this->seedPost(['icon_url' => '', 'color' => '', 'description' => '']);
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
        } catch (\BccAdminRedirect $r) {
            // An empty field is valid input, so this is a clean save.
            $this->assertSame('ok', $r->args['bcc_identity']);
        }

        $writes = \BCC\Trust\Onchain\Repositories\ChainRepository::$identityWrites;
        $this->assertNull($writes[0]['icon_url']);
        $this->assertNull($writes[0]['color']);
        $this->assertNull($writes[0]['description']);
    }

    public function testDescriptionIsStrippedOfMarkup(): void
    {
        $this->seedPost(['description' => 'Ethereum <script>alert(1)</script>']);
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $writes = \BCC\Trust\Onchain\Repositories\ChainRepository::$identityWrites;
        $this->assertStringNotContainsString('<script>', (string) $writes[0]['description']);
    }

    // 11 — unrelated fields untouched
    public function testOnlyTheThreeIdentityFieldsAreWritten(): void
    {
        $this->seedPost();
        $this->validNonce();

        try {
            ChainsPage::handle_identity_save();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $writes = \BCC\Trust\Onchain\Repositories\ChainRepository::$identityWrites;
        // updateIdentity's whole surface is (chainId, description, icon_url,
        // color). Nothing else — slug, name, rpc_url, is_active — is reachable
        // from this handler.
        $this->assertSame(
            ['chain_id', 'description', 'icon_url', 'color'],
            array_keys($writes[0])
        );
    }
}
