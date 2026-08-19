<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * VC-B3b MARKUP contract: the four scanner controls as rendered.
 *
 * ── WHY A DOM PARSE AND NOT A SUBSTRING CHECK ───────────────────────────
 * Three of the properties that matter here are structural and invisible to
 * a string search: that no form is nested inside another (HTML forbids it,
 * and browsers silently drop the inner one), that every form id is unique
 * (a duplicate cross-wires one chain's button to another chain's form),
 * and that each button belongs to exactly one form. A page that failed any
 * of those would still contain every expected substring.
 *
 * ── AND WHY IT ASSERTS WHAT IS *NOT* OFFERED ────────────────────────────
 * Which controls appear is a safety property, not a cosmetic one. Backfill
 * is the only provider-consuming control on the page; offering it on a
 * paused chain, or where the environment gate is closed, is a button that
 * cannot work — and an operator who clicks it learns to distrust the page.
 */
#[CoversClass(ChainsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChainsCwScannerOperationsDomTest extends TestCase
{
    private const CHAIN_ID = 4;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/chains-cw-operations-stubs.php';

        \BccAdminTestState::reset();
        CosmwasmDiscoveryGate::reset();
        CosmwasmDiscoveryHealthSnapshot::reset();

        $_GET = [];
    }

    /**
     * One completed snapshot row, rendered through the REAL section
     * renderer so this asserts markup production emits rather than a copy.
     *
     * @param array<string, mixed> $overrides
     */
    private function dom(array $overrides = []): \DOMDocument
    {
        $row = array_merge([
            'chain_id'           => self::CHAIN_ID,
            'slug'               => 'cosmos',
            'name'               => 'Cosmos Hub',
            'discovery_opted_in' => true,
            'unsupported'        => false,
            'paused'             => false,
            'eligibility'        => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ELIGIBLE,
            'eligibility_reason' => 'Nothing is blocking this chain.',
        ], $overrides);

        ob_start();
        ChainsPage::render_cw_discovery_section([$row]);
        $html = (string) ob_get_clean();

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        return $doc;
    }

    /** @return list<\DOMElement> */
    private function elements(\DOMDocument $doc, string $tag): array
    {
        $out = [];
        foreach ($doc->getElementsByTagName($tag) as $el) {
            if ($el instanceof \DOMElement) {
                $out[] = $el;
            }
        }

        return $out;
    }

    /** Operation forms only — the discovery opt-in is another file's job. */
    private function operationForms(\DOMDocument $doc): array
    {
        $routes = [
            ChainsPage::ACTION_CW_PAUSE,
            ChainsPage::ACTION_CW_RESUME,
            ChainsPage::ACTION_CW_BACKFILL,
            ChainsPage::ACTION_CW_RETRY,
        ];

        $out = [];
        foreach ($this->elements($doc, 'form') as $form) {
            $action = $this->hiddenValue($form, 'action');
            if (in_array((string) $action, $routes, true)) {
                $out[(string) $action] = $form;
            }
        }

        return $out;
    }

    private function hiddenValue(\DOMElement $form, string $name): ?string
    {
        foreach ($form->getElementsByTagName('input') as $i) {
            if ($i instanceof \DOMElement && $i->getAttribute('name') === $name) {
                return $i->getAttribute('value');
            }
        }

        return null;
    }

    private function nonceAction(\DOMElement $form): ?string
    {
        foreach ($form->getElementsByTagName('input') as $i) {
            if ($i instanceof \DOMElement && $i->hasAttribute('data-nonce-action')) {
                return $i->getAttribute('data-nonce-action');
            }
        }

        return null;
    }

    // ── Wiring ──────────────────────────────────────────────────────────

    public function testEveryOperationFormPostsToAdminPostWithItsOwnScopedNonce(): void
    {
        $forms = $this->operationForms($this->dom());

        $this->assertNotSame([], $forms);

        foreach ($forms as $route => $form) {
            $this->assertSame('post', strtolower($form->getAttribute('method')), $route);
            $this->assertStringContainsString('admin-post.php', $form->getAttribute('action'), $route);
            $this->assertSame($route, $this->hiddenValue($form, 'action'));
            $this->assertSame((string) self::CHAIN_ID, $this->hiddenValue($form, 'chain_id'));

            // Bound to BOTH the operation and the chain — the property the
            // route suite refuses a request for.
            $this->assertSame(
                $route . '_' . self::CHAIN_ID,
                $this->nonceAction($form),
                'a Pause nonce must not authorise Backfill, nor another chain'
            );
        }
    }

    public function testEveryFormIdIsUniqueAndNamesItsOperationAndChain(): void
    {
        $doc = $this->dom();

        $ids = [];
        foreach ($this->elements($doc, 'form') as $form) {
            $id = $form->getAttribute('id');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        $this->assertSame(count($ids), count(array_unique($ids)), 'a duplicate id cross-wires two controls');

        foreach ($this->operationForms($doc) as $route => $form) {
            $this->assertSame(
                ChainsPage::cw_operation_form_id($route, self::CHAIN_ID),
                $form->getAttribute('id')
            );
            $this->assertStringContainsString((string) self::CHAIN_ID, $form->getAttribute('id'));
        }
    }

    public function testNoFormIsNested(): void
    {
        foreach ($this->elements($this->dom(), 'form') as $form) {
            $this->assertCount(
                0,
                iterator_to_array($form->getElementsByTagName('form')),
                'a browser silently drops the inner form, so its control would post nothing'
            );
        }
    }

    public function testEachOperationFormCarriesExactlyOneSubmitButton(): void
    {
        foreach ($this->operationForms($this->dom()) as $route => $form) {
            $buttons = 0;
            foreach ($form->getElementsByTagName('button') as $b) {
                if ($b instanceof \DOMElement && $b->getAttribute('type') === 'submit') {
                    $buttons++;
                }
            }

            $this->assertSame(1, $buttons, "{$route} must offer exactly one button");
        }
    }

    // ── Which controls are offered ──────────────────────────────────────

    /** A running chain offers Pause — never Resume as well. */
    public function testARunningChainOffersPauseAndNotResume(): void
    {
        $forms = $this->operationForms($this->dom(['paused' => false]));

        $this->assertArrayHasKey(ChainsPage::ACTION_CW_PAUSE, $forms);
        $this->assertArrayNotHasKey(ChainsPage::ACTION_CW_RESUME, $forms);
    }

    /**
     * And a paused chain offers Resume, never Pause. Two controls rather
     * than one toggle: a toggle decides its direction from the state the
     * page had at RENDER time, so a stale tab applies the opposite of what
     * the operator is looking at.
     */
    public function testAPausedChainOffersResumeAndNotPause(): void
    {
        $forms = $this->operationForms($this->dom(['paused' => true]));

        $this->assertArrayHasKey(ChainsPage::ACTION_CW_RESUME, $forms);
        $this->assertArrayNotHasKey(ChainsPage::ACTION_CW_PAUSE, $forms);
    }

    /** Backfill is refused while paused, so it is not offered while paused. */
    public function testAPausedChainIsNotOfferedBackfill(): void
    {
        $forms = $this->operationForms($this->dom(['paused' => true]));

        $this->assertArrayNotHasKey(ChainsPage::ACTION_CW_BACKFILL, $forms);
    }

    /** @return array<string, array{0: bool, 1: bool, 2: bool, 3: bool}> */
    public static function gateMatrix(): array
    {
        //                    discovery, backfill, offers Backfill, offers Retry
        return [
            'both on'       => [true,  true,  true,  true],
            'backfill off'  => [true,  false, false, true],
            'discovery off' => [false, true,  false, false],
            'both off'      => [false, false, false, false],
        ];
    }

    #[DataProvider('gateMatrix')]
    public function testTheEnvironmentGatesDecideWhichControlsAppear(
        bool $discovery,
        bool $backfill,
        bool $offersBackfill,
        bool $offersRetry
    ): void {
        CosmwasmDiscoveryGate::$discovery = $discovery;
        CosmwasmDiscoveryGate::$backfill  = $backfill;

        $forms = $this->operationForms($this->dom());

        $this->assertSame($offersBackfill, isset($forms[ChainsPage::ACTION_CW_BACKFILL]));
        $this->assertSame($offersRetry, isset($forms[ChainsPage::ACTION_CW_RETRY]));

        // Pause is a local hold and never depends on the environment gate:
        // a chain must remain pausable even with discovery switched off.
        $this->assertArrayHasKey(ChainsPage::ACTION_CW_PAUSE, $forms);
    }

    /**
     * An unsupported chain gets NO operation at all, and is told why —
     * rather than being handed four buttons that cannot do anything.
     */
    public function testAnUnsupportedChainIsOfferedNothingAndToldWhy(): void
    {
        $doc = $this->dom([
            'unsupported' => true,
            'eligibility' => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_UNSUPPORTED,
        ]);

        $this->assertSame([], $this->operationForms($doc));
        $this->assertStringContainsString(
            'nothing to pause, resume, backfill or retry',
            (string) $doc->saveHTML()
        );
    }

    // ── Copy ────────────────────────────────────────────────────────────

    /**
     * Every control confirms, and every confirmation says what the control
     * does NOT do. These four are the ones an operator most easily
     * mistakes for each other: Pause reads as "stop everything", Resume as
     * "start a scan", Backfill as "scan the chain", Retry as "go and look
     * now". All four are wrong, and all four are corrected here.
     */
    public function testEveryConfirmationIsTruthfulAboutItsLimits(): void
    {
        CosmwasmDiscoveryGate::$discovery = true;
        CosmwasmDiscoveryGate::$backfill  = true;

        $expectations = [
            // Pause cannot stop work already inside the advisory lock.
            ChainsPage::ACTION_CW_PAUSE => [
                'is NOT cancelled',
                'already',
                'different from disabling',
            ],
            // Resume starts nothing and contacts nothing.
            ChainsPage::ACTION_CW_RESUME => [
                'does NOT start a scan',
                'contacts nothing',
                'not re-walked',
            ],
            // Backfill is the one control that spends provider budget, and
            // it says so plainly — with its bound and its lock caveat.
            ChainsPage::ACTION_CW_BACKFILL => [
                'CONTACTS THE CHAIN IMMEDIATELY',
                'one bounded slice',
                'not a full scan',
                'already holds the lock',
                'arrives unverified',
            ],
            // Retry clears a wait; the work happens on a future pass.
            ChainsPage::ACTION_CW_RETRY => [
                'Nothing is contacted now',
                'next scheduled pass',
                'up to 100',
            ],
        ];

        $seen = [];

        foreach ([false, true] as $paused) {
            foreach ($this->operationForms($this->dom(['paused' => $paused])) as $route => $form) {
                $button = $form->getElementsByTagName('button')->item(0);
                $this->assertInstanceOf(\DOMElement::class, $button);

                $onclick = $button->getAttribute('onclick');
                $this->assertStringContainsString('confirm(', $onclick, $route);

                $this->assertSame(1, preg_match('/confirm\((.*)\);?$/s', $onclick, $m));
                $text = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
                $this->assertIsString($text, $route);

                foreach ($expectations[$route] as $phrase) {
                    $this->assertStringContainsString($phrase, $text, "{$route} confirmation");
                }

                // None of them verifies a collection or creates a community.
                $lower = strtolower($text);
                $this->assertStringNotContainsString('will be verified', $lower, $route);
                $this->assertStringNotContainsString('creates a community', $lower, $route);
                $this->assertStringNotContainsString('provisions', $lower, $route);

                $seen[$route] = true;
            }
        }

        $this->assertCount(4, $seen, 'all four controls must be exercised');
    }

    public function testLabelsNameTheOperationRatherThanOnOrOff(): void
    {
        $labels = [];
        foreach ([false, true] as $paused) {
            foreach ($this->operationForms($this->dom(['paused' => $paused])) as $route => $form) {
                $button = $form->getElementsByTagName('button')->item(0);
                $this->assertInstanceOf(\DOMElement::class, $button);
                $labels[$route] = trim($button->textContent);
            }
        }

        foreach ($labels as $route => $label) {
            $this->assertNotSame('', $label, $route);
            $this->assertNotSame('On', $label);
            $this->assertNotSame('Off', $label);
            $this->assertNotSame('Go', $label);
        }

        $this->assertStringContainsString('Pause', $labels[ChainsPage::ACTION_CW_PAUSE]);
        $this->assertStringContainsString('Resume', $labels[ChainsPage::ACTION_CW_RESUME]);
    }

    // ── Ownership ───────────────────────────────────────────────────────

    /** The retired page-wide token is minted nowhere on this section. */
    public function testTheSectionMintsNoSharedVerifyCollectionsNonce(): void
    {
        $html = (string) $this->dom()->saveHTML();

        $this->assertStringNotContainsString('bcc_verify_collections_nonce', $html);
        $this->assertStringNotContainsString('_bcc_vc_nonce', $html);
        $this->assertStringNotContainsString('bcc_vc_action', $html);
    }

    /**
     * THE SCANNER PANEL NO LONGER OFFERS ANY OF THE FOUR.
     *
     * Two surfaces owning the same mutation is the exact condition VC-B3b
     * removed, and the one a future edit is most likely to reintroduce by
     * "restoring" a control somebody missed.
     */
    public function testTheScannerPanelOffersNoneOfTheFourControls(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../app/Domain/Onchain/Admin/Views/CosmwasmScannerPanel.php'
        );

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        foreach ([
            'cw_pause_',
            'cw_resume_',
            'cw_backfill_',
            'cw_retry_',
            'bcc_vc_action',
            'wp_nonce_field',
            '<form',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $code,
                "the panel is a read-only surface now; `{$forbidden}` would make it a second owner"
            );
        }
    }
}
