<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Admin;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared request-boundary helpers for the on-chain admin action handlers.
 *
 * This is deliberately NOT an admin framework — it is five static helpers
 * that existed as copy-pasted blocks in four files. Each one encodes a
 * decision made once for the whole batch:
 *
 *  1. Authorization / CSRF failures HALT (wp_die 403). They are never part
 *     of normal operation, and this matches both check_admin_referer()'s own
 *     behaviour and RepairService::securityCheck(). The previous code
 *     `return`ed silently, which rendered a normal-looking page and let the
 *     operator believe the action had run.
 *
 *  2. Validation and domain failures REDIRECT back to the page with a
 *     result code. They ARE part of normal operation, so the operator should
 *     land somewhere useful rather than on a wp_die wall.
 *
 *  3. Raw Throwable messages never reach the browser. They can carry SQL
 *     fragments, absolute paths and provider response bodies. Instead we mint
 *     a short non-secret correlation ID, put the full exception in the file
 *     log under that ID, and show the operator the ID.
 *
 *  4. Durable audit rows go through the existing AuditLogger →
 *     wp_bcc_trust_activity. NOTE: that table has no meta column
 *     (schema-core.php:191 — id/user_id/action/target_type/target_id/
 *     ip_address/created_at), and AuditLogger::log() accepts $meta but does
 *     not persist it. So the durable row carries WHO did WHAT to WHICH
 *     target, with the outcome encoded in the action name. Counts and
 *     correlation IDs go to the file log. $meta is still passed through for
 *     forward-compatibility if a meta column is ever added.
 *
 *  5. Action names are capped at the action column's VARCHAR(50) so a long
 *     name can never be silently truncated into a different event.
 */
final class AdminActionSupport
{
    /** wp_bcc_trust_activity.action is VARCHAR(50). */
    public const MAX_ACTION_LENGTH = 50;

    private const CAPABILITY = 'manage_options';

    /**
     * Capability gate for a state-changing admin handler.
     *
     * Every handler calls this even though add_submenu_page() already gates
     * the page — the handler is reachable via admin-post.php without ever
     * rendering the page, so the menu capability is not a gate on the write.
     */
    public static function requireCapability(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(
                esc_html__('You do not have permission to perform this action.', 'bcc-trust'),
                esc_html__('Forbidden', 'bcc-trust'),
                ['response' => 403]
            );
        }
    }

    /**
     * Method gate for a state-changing admin handler.
     *
     * `admin-post.php` dispatches `admin_post_{action}` for GET as well as
     * POST — it reads the action out of `$_REQUEST`. So a handler that only
     * reads `$_POST` is not thereby POST-only: a crafted GET reaches it,
     * finds an empty `$_POST`, and runs whatever the empty-input path does.
     * Refusing the method outright is the gate; it also keeps a mutation out
     * of anything that pre-fetches links.
     */
    public static function requirePost(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';

        if ($method !== 'POST') {
            wp_die(
                esc_html__('This action must be submitted as a form.', 'bcc-trust'),
                esc_html__('Method Not Allowed', 'bcc-trust'),
                ['response' => 405]
            );
        }
    }

    /**
     * Action-scoped CSRF gate.
     *
     * check_admin_referer() wp_die()s with a 403 on failure, so an expired or
     * forged nonce can never reach the write path — and, unlike the previous
     * silent `return`, the operator is told the request was rejected.
     */
    public static function requireNonce(string $action, string $queryArg = '_wpnonce'): void
    {
        check_admin_referer($action, $queryArg);
    }

    /**
     * Operator-safe rendering of an UPSTREAM error excerpt.
     *
     * ── WHY THIS IS NOT esc_html() ──────────────────────────────────────
     * `esc_html()` stops markup executing. It does nothing about a message
     * that legitimately contains a credentialed URL, an absolute server
     * path or an SQL fragment — those render perfectly safely and are still
     * a disclosure.
     *
     * ── AND WHY IT IS NOT sanitizeExcerpt() EITHER ──────────────────────
     * {@see \BCC\Trust\Onchain\Services\CosmwasmClassifier::sanitizeExcerpt()}
     * is a FORMATTER, despite the name: it strips control characters,
     * collapses whitespace and truncates. Its own docblock calls the result
     * an "excerpt". Content survives verbatim. That is correct for the
     * stored column and the technical log — an engineer needs the real
     * text — but it is not a redactor, and `cw_last_error` demonstrably
     * carries `$e->getMessage()` and raw LCD response bodies
     * ({@see \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker}).
     *
     * ── SO THIS IS A DISPLAY FILTER, AND ONLY THAT ──────────────────────
     * The stored value and the logs are untouched; this is what an admin
     * PAGE is allowed to show. It redacts rather than allowlists, because
     * an allowlist would throw away the useful half — "wasm module not
     * available (HTTP 501)" is exactly what an operator needs to read.
     */
    public static function operatorSafeExcerpt(string $raw): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        if ($s === '') {
            return '';
        }

        // An SQL statement is never operator-facing detail. Replace the
        // whole thing rather than trying to scrub it clause by clause.
        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER)\b.{0,80}\b(FROM|INTO|TABLE|SET|WHERE)\b/i', $s) === 1) {
            return '[database error — details in the bcc-trust error log]';
        }

        $patterns = [
            // Credentialed parameters, before the URL rule can keep them.
            '/\b(api[_-]?key|apikey|token|secret|password|passwd|authorization|auth)\b\s*[=:]\s*\S+/i'
                => '$1=[redacted]',
            // Any URL — covers chain endpoints and anything carrying a key.
            '~\b[a-z][a-z0-9+.\-]*://\S+~i' => '[redacted-url]',
            // Absolute paths, Windows and POSIX.
            '~\b[A-Za-z]:\\\\[^\s,;]+~'     => '[redacted-path]',
            '~(?<![\w.])/(?:home|var|srv|usr|etc|root|tmp|opt)/[^\s,;]*~i' => '[redacted-path]',
            // Stack-trace frames and "in /file.php:12" tails.
            '/#\d+\s+\S+/'                  => '[redacted-trace]',
            '/\bin\s+\S+\.php(?::\d+)?/i'   => 'in [redacted-path]',
            // Exception / error class names, namespaced or not.
            '/\b[A-Za-z_][\w\\\\]*(?:Exception|Error)\b/' => '[redacted-type]',
            // Long opaque blobs: keys, tokens, signatures.
            '/\b[A-Fa-f0-9]{32,}\b/'        => '[redacted]',
            '/\b[A-Za-z0-9_\-]{40,}\b/'     => '[redacted]',
        ];

        foreach ($patterns as $re => $with) {
            $next = preg_replace($re, $with, $s);
            if (is_string($next)) {
                $s = $next;
            }
        }

        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);

        return function_exists('mb_substr') ? mb_substr($s, 0, 200) : substr($s, 0, 200);
    }

    /**
     * Short, non-secret correlation ID used to join an operator-facing error
     * message to the full exception in the file log.
     */
    public static function correlationId(): string
    {
        try {
            return 'bcc-' . bin2hex(random_bytes(4));
        } catch (\Throwable $e) {
            // random_bytes only throws when the platform has no CSPRNG. The
            // ID is a log-correlation token, not a security boundary, so a
            // degraded fallback is preferable to failing the whole request.
            return 'bcc-' . substr(md5((string) microtime(true)), 0, 8);
        }
    }

    /**
     * Record a durable admin-action audit row.
     *
     * @param string               $action     Must start with `admin_` and fit VARCHAR(50).
     * @param string               $targetType Entity kind, e.g. 'chain'.
     * @param int|null             $targetId   Entity id where one exists.
     * @param array<string, mixed> $meta       Structured context. NOT persisted by the
     *                                         current schema; written to the file log.
     */
    public static function audit(
        string $action,
        string $targetType,
        ?int $targetId = null,
        array $meta = []
    ): void {
        if (strlen($action) > self::MAX_ACTION_LENGTH) {
            // Truncation would silently merge two distinct events into one
            // audit action. Fail loudly in dev; degrade to a marker in prod.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                throw new \LengthException(
                    'Audit action "' . $action . '" exceeds ' . self::MAX_ACTION_LENGTH . ' chars.'
                );
            }
            $action = substr($action, 0, self::MAX_ACTION_LENGTH);
        }

        $operator = get_current_user_id();

        AuditLogger::log($action, $targetId, $meta, $targetType);

        // The durable row cannot hold $meta, so the counts/context live here.
        Logger::info('[bcc-trust] admin action: ' . $action, array_merge(
            ['operator' => $operator, 'target_type' => $targetType, 'target_id' => $targetId],
            $meta
        ));
    }

    /**
     * Log a failed admin action and return its correlation ID.
     *
     * The raw Throwable message is written to the file log only. Callers pair
     * the returned ID with failureMessage() for display, or carry it through a
     * PRG redirect — it is a log-join token, not a secret.
     *
     * @param array<string, mixed> $meta
     * @return string Correlation ID.
     */
    public static function failure(
        \Throwable $e,
        string $auditAction,
        string $targetType,
        ?int $targetId = null,
        array $meta = []
    ): string {
        $correlationId = self::correlationId();

        Logger::error('[bcc-trust] admin action failed: ' . $auditAction, array_merge(
            [
                'correlation_id' => $correlationId,
                'operator'       => get_current_user_id(),
                'target_type'    => $targetType,
                'target_id'      => $targetId,
                'exception'      => get_class($e),
                'message'        => $e->getMessage(),
                'file'           => $e->getFile(),
                'line'           => $e->getLine(),
            ],
            $meta
        ));

        // Durable record that an authorized operation started and failed.
        self::audit($auditAction, $targetType, $targetId, array_merge(
            $meta,
            ['correlation_id' => $correlationId, 'outcome' => 'failed']
        ));

        return $correlationId;
    }

    /**
     * Operator-facing failure text for a correlation ID.
     *
     * Deliberately says nothing about WHY — the cause is in the log under the
     * reference. Plain text; callers escape at the point of output.
     */
    public static function failureMessage(string $correlationId): string
    {
        return sprintf(
            /* translators: %s: short correlation id an engineer can grep the logs for. */
            __('The operation failed. Reference %s — the full error is in the bcc-trust log.', 'bcc-trust'),
            $correlationId
        );
    }

    /**
     * Post/Redirect/Get terminator.
     *
     * Every state-changing handler ends here so a browser refresh re-issues a
     * GET against an inert results page instead of replaying the mutation.
     *
     * Declared `never` so callers can rely on control flow stopping here —
     * that is what lets a handler write `if ($x === null) { redirect(); }` and
     * treat $x as non-null afterwards.
     *
     * @param array<string, string|int> $args Query args merged onto admin.php.
     */
    public static function redirect(array $args): never
    {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * JSON literal for a confirm() message inside an onsubmit attribute.
     *
     * wp_json_encode() returns false on malformed UTF-8. Falling back to an
     * empty string literal degrades the control to a blank confirmation
     * dialog — which the operator can still cancel — rather than emitting
     * broken JS that would submit the form with no prompt at all.
     */
    public static function confirmLiteral(string $message): string
    {
        $json = wp_json_encode($message);

        return is_string($json) ? $json : '""';
    }
}
