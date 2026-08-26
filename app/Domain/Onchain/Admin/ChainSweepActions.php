<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Admin;

use BCC\Trust\Onchain\Services\ChainRefreshService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Run cron now" handlers for the all-chains indexers.
 *
 * Extracted from the anonymous admin_init closure that previously lived in
 * bcc-trust.php. The extraction is the minimum needed to make these handlers
 * nameable and therefore testable — the domain work still lives entirely in
 * ChainRefreshService.
 *
 * WHAT CHANGED (Batch 1 — safety hardening):
 *   - Dispatch moved from `GET ?bcc_run_index_validators=1` to POST via
 *     admin-post.php. The old links were replayed by a browser refresh, a
 *     link prefetch, or a "restore tabs", each time re-running a synchronous
 *     multi-provider sweep.
 *   - One shared nonce (`bcc_onchain_admin_trigger`) became four
 *     operation-scoped nonces, so a nonce minted for "Validators only" can no
 *     longer authorize the full "All" sweep.
 *   - Each run now writes a durable audit row.
 *   - Failures no longer surface raw exception text.
 *
 * WHAT DELIBERATELY DID NOT CHANGE:
 *   - ChainRefreshService::index_validators() / refresh_validators() are
 *     called exactly as before, in the same order, with the same arguments
 *     (none). Their advisory lock (group `bcc_cron`), per-chain
 *     circuit-breaker skip, and EnrichmentScheduler API-call cap are
 *     untouched.
 *   - The validator fan-out is still synchronous and still unbounded across
 *     active chains. Making dispatch deliberate was this batch's scope;
 *     bounding the work is a later batch.
 *
 * NO COLLECTION STEP. A `collections` step used to sit between the two,
 * calling `ChainRefreshService::index_collections()` — a chain-wide sweep of
 * every active EVM/Solana/Cosmos chain for "top collections". It is retired
 * along with the cron hook that drove it: chain-wide NFT collection
 * discovery is operator-initiated, one named chain at a time, and a "Run
 * All" button is the opposite of naming one chain. The remaining operations
 * are validator work only, and the labels say so.
 */
final class ChainSweepActions
{
    public const ACTION_VALIDATORS = 'bcc_onchain_sweep_validators';
    public const ACTION_ENRICHMENT = 'bcc_onchain_sweep_enrichment';
    public const ACTION_ALL        = 'bcc_onchain_sweep_all';

    /**
     * Steps each operation runs, in order: validators → enrichment.
     *
     * @var array<string, list<string>>
     */
    private const STEPS = [
        self::ACTION_VALIDATORS => ['validators'],
        self::ACTION_ENRICHMENT => ['enrichment'],
        self::ACTION_ALL        => ['validators', 'enrichment'],
    ];

    /** @var array<string, string> Human labels for the result notice. */
    private const STEP_LABELS = [
        'validators' => 'validators',
        'enrichment' => 'validator enrichment',
    ];

    public static function register(): void
    {
        foreach (array_keys(self::STEPS) as $action) {
            add_action('admin_post_' . $action, [self::class, 'handle']);
        }
    }

    /**
     * Single entry point for all four operations.
     *
     * Resolves which operation ran from the admin_post_ hook that fired, so
     * the nonce is always checked against the operation actually requested
     * rather than against a client-supplied operation name.
     */
    public static function handle(): void
    {
        AdminActionSupport::requireCapability();

        $operation = self::resolveOperationFromCurrentAction();
        if ($operation === null) {
            // Unreachable via the registered hooks; defensive only.
            wp_die(
                esc_html__('Unknown sweep operation.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }

        // Operation-scoped nonce: the nonce action IS the admin_post action.
        AdminActionSupport::requireNonce($operation);

        self::run($operation);
    }

    /**
     * Map the currently-firing admin_post_ hook back to its operation.
     */
    private static function resolveOperationFromCurrentAction(): ?string
    {
        $hook = (string) current_action();
        $operation = str_starts_with($hook, 'admin_post_')
            ? substr($hook, strlen('admin_post_'))
            : '';

        return isset(self::STEPS[$operation]) ? $operation : null;
    }

    /**
     * Execute the operation's steps and PRG back to the Chains page.
     */
    private static function run(string $operation): void
    {
        $steps = self::STEPS[$operation];
        $slug  = self::operationSlug($operation);
        $ran   = [];

        foreach ($steps as $step) {
            try {
                self::runStep($step);
            } catch (\Throwable $e) {
                // A step threw AFTER the operator was authorized. Record the
                // failure durably, keep the raw message out of the browser,
                // and report which steps did complete — a partial sweep is
                // materially different from one that never started.
                $correlationId = AdminActionSupport::failure(
                    $e,
                    'admin_onchain_sweep_failed',
                    'onchain_sweep',
                    null,
                    [
                        'operation' => $slug,
                        'failed_at' => $step,
                        'completed' => $ran === [] ? '(none)' : implode(',', $ran),
                    ]
                );

                AdminActionSupport::redirect([
                    'page'           => ChainsPage::PAGE_SLUG,
                    'bcc_sweep'      => $slug,
                    'bcc_result'     => 'failed',
                    'bcc_failed_at'  => $step,
                    'bcc_ref'        => $correlationId,
                ]);
            }

            $ran[] = $step;
        }

        AdminActionSupport::audit(
            'admin_onchain_sweep_' . $slug,
            'onchain_sweep',
            null,
            ['steps' => implode(',', $ran)]
        );

        AdminActionSupport::redirect([
            'page'       => ChainsPage::PAGE_SLUG,
            'bcc_sweep'  => $slug,
            'bcc_result' => 'ok',
        ]);
    }

    private static function runStep(string $step): void
    {
        switch ($step) {
            case 'validators':
                ChainRefreshService::index_validators();
                return;
            case 'enrichment':
                ChainRefreshService::refresh_validators();
                return;
        }
    }

    private static function operationSlug(string $operation): string
    {
        return substr($operation, strlen('bcc_onchain_sweep_'));
    }

    /**
     * Human label for a completed step list, used by the result notice.
     *
     * @param list<string> $steps
     */
    public static function stepLabels(array $steps): string
    {
        $labels = [];
        foreach ($steps as $step) {
            $labels[] = self::STEP_LABELS[$step] ?? $step;
        }

        return implode(' + ', $labels);
    }

    /**
     * Steps belonging to an operation slug, for the result notice.
     *
     * @return list<string>
     */
    public static function stepsForSlug(string $slug): array
    {
        $operation = 'bcc_onchain_sweep_' . $slug;

        return self::STEPS[$operation] ?? [];
    }
}
