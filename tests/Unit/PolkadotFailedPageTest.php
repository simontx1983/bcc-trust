<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\PolkadotFetcher;
use BCC\Trust\Onchain\Support\ProviderOutcomeReceipt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * F-68 — PolkadotFetcher::fetch_all_validators() read a FAILED page as the
 * end of the list: apiPost() returns null on any failure, `null['list'] ?? []`
 * is empty, and the loop broke out and indexed a truncated set as complete.
 * A failed page now throws, so the run fails instead.
 */
#[CoversClass(PolkadotFetcher::class)]
final class PolkadotFailedPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
    }

    /** @param list<array<string, mixed>|null> $pages one entry per page request, in order */
    private static function fetcher(array $pages): ScriptedPolkadotFetcher
    {
        $f = new ScriptedPolkadotFetcher((object) [
            'id' => 19, 'slug' => 'polkadot', 'name' => 'Polkadot', 'chain_type' => 'polkadot',
            'decimals' => 10, 'rest_url' => 'https://polkadot.api.subscan.io',
        ]);
        $f->pages = $pages;
        return $f;
    }

    /** @return array{list: list<array<string, mixed>>} */
    private static function page(int $from, int $count): array
    {
        $list = [];
        for ($i = $from; $i < $from + $count; $i++) {
            $list[] = [
                'stash_account_display' => ['address' => sprintf('1stash%05d', $i)],
                'controller_account_display' => ['address' => sprintf('1ctrl%05d', $i)],
                'bonded_total' => (string) (1000000 - $i),
                'validator_prefs_value' => 0,
            ];
        }
        return ['list' => $list];
    }

    public function testAFailedMiddlePageFailsTheRunInsteadOfTruncating(): void
    {
        $f = self::fetcher([self::page(0, 100), self::page(100, 100), null, self::page(300, 50)]);

        try {
            $f->fetch_all_validators(new ProviderOutcomeReceipt());
            self::fail('a failed page must not end the list');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('page 2 failed', $e->getMessage());
            self::assertStringContainsString('200 validators fetched before it', $e->getMessage());
        }
        self::assertSame([0, 1, 2], $f->requestedPages, 'no page after the failure is requested');
    }

    public function testAFailedFirstPageFailsTheRunToo(): void
    {
        $this->expectException(\RuntimeException::class);
        self::fetcher([null])->fetch_all_validators();
    }

    public function testAGenuineShortLastPageStillEndsTheList(): void
    {
        $f = self::fetcher([self::page(0, 100), self::page(100, 40)]);

        $rows = $f->fetch_all_validators();

        self::assertSame([0, 1], $f->requestedPages);
        self::assertNotSame([], $rows);
    }

    public function testAGenuineEmptyPageStillEndsTheList(): void
    {
        $f = self::fetcher([self::page(0, 100), ['list' => []]]);

        self::assertNotSame([], $f->fetch_all_validators());
        self::assertSame([0, 1], $f->requestedPages);
    }
}

/** Feeds scripted pages through the apiPost() seam; null = a failed request. */
final class ScriptedPolkadotFetcher extends PolkadotFetcher
{
    /** @var list<array<string, mixed>|null> */
    public array $pages = [];

    /** @var list<int> */
    public array $requestedPages = [];

    protected function apiPost(string $path, array $body, ?ProviderOutcomeReceipt $outcome = null): ?array
    {
        $page = (int) ($body['page'] ?? -1);
        $this->requestedPages[] = $page;
        // array_key_exists, not `??`: a scripted null (failed request) must reach the caller.
        return array_key_exists($page, $this->pages) ? $this->pages[$page] : ['list' => []];
    }
}
