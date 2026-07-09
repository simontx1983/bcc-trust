<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\LocalsService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A1 security regression — Locals join gate.
 *
 * POST /bcc/v1/me/locals/{id}/membership → LocalsService::joinLocal must
 * NOT let a user join a non-Local group (NFT holder / plain user group) or
 * a closed/secret Local through the PeepSoGroupWriter::join door. That
 * writer lands membership as `member` UNCONDITIONALLY (it bypasses PeepSo's
 * UI approval), so the server-side type + privacy gate is the only thing
 * standing between an attacker and a closed, secret, or NFT-gated holder
 * group. Before the fix, `joinLocal` gated only on "group exists", so any
 * peepso-group id joined successfully.
 *
 * ## Isolation
 * Runs in its own subprocess; setUp() pulls in tests/Stubs/locals-gate-stubs.php
 * which defines the WordPress functions (get_post / get_post_meta) that the
 * REAL GroupContextResolver + PeepSoPrivacy call, backed by a per-test
 * fixture. The main process is untouched. All cases below are rejected at
 * the gate BEFORE any PeepSo / $wpdb membership write, so no repository
 * stubs are needed.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LocalsJoinGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/locals-gate-stubs.php';
        $GLOBALS['__bcc_locals_gate_fixture'] = [];
    }

    /**
     * @param int    $id      group post id
     * @param string $kind    `_bcc_group_kind` meta (holders|local|system|'' )
     * @param int    $privacy `peepso_group_privacy` meta (0 open, 1 closed, 2 secret)
     */
    private function registerGroup(int $id, string $kind, int $privacy): void
    {
        $GLOBALS['__bcc_locals_gate_fixture'][$id] = [
            'post_type' => 'peepso-group',
            'meta'      => [
                '_bcc_group_kind'      => $kind,
                'peepso_group_privacy' => (string) $privacy,
            ],
        ];
    }

    private function service(): LocalsService
    {
        return new LocalsService(new GroupContextResolver());
    }

    public function testNftHolderGroupIsRejectedAsNotFound(): void
    {
        // The live "Holders: Bad Kids" shape: kind=holders, privacy=1 (closed).
        $this->registerGroup(2043, 'holders', 1);
        $result = $this->service()->joinLocal(7, 2043);
        self::assertSame('bcc_not_found', $result['error'] ?? null);
    }

    public function testPlainUserGroupIsRejectedAsNotFound(): void
    {
        // No `_bcc_group_kind` → GroupType::User. Not a Local.
        $this->registerGroup(500, '', 0);
        $result = $this->service()->joinLocal(7, 500);
        self::assertSame('bcc_not_found', $result['error'] ?? null);
    }

    public function testClosedLocalIsRejectedAsForbidden(): void
    {
        $this->registerGroup(600, 'local', 1);
        $result = $this->service()->joinLocal(7, 600);
        self::assertSame('bcc_forbidden', $result['error'] ?? null);
    }

    public function testSecretLocalIsRejectedAsForbidden(): void
    {
        $this->registerGroup(601, 'local', 2);
        $result = $this->service()->joinLocal(7, 601);
        self::assertSame('bcc_forbidden', $result['error'] ?? null);
    }

    public function testUnknownGroupIsRejectedAsNotFound(): void
    {
        // No fixture row → get_post returns null → not a peepso-group.
        $result = $this->service()->joinLocal(7, 999999);
        self::assertSame('bcc_not_found', $result['error'] ?? null);
    }
}
