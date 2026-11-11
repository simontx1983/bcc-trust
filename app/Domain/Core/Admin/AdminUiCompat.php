<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Admin;

use BCC\Core\Admin\AdminUi;

/**
 * Release-order shim over bcc-core's BCC\Core\Admin\AdminUi.
 *
 * bcc-trust admin pages render badges / relative times through bcc-core's
 * shared helper. If bcc-trust reaches an environment BEFORE the bcc-core
 * that ships AdminUi, a direct `AdminUi::` reference (even a constant)
 * fatals the page. This shim keeps the same call shape, delegates when
 * AdminUi exists, and otherwise degrades to plain escaped text — the same
 * guard bcc-search's Developer panel uses.
 *
 * Not a second implementation: the fallback is deliberately minimal
 * (escaped label; UTC timestamp) and goes away once every environment
 * runs a bcc-core with AdminUi.
 */
final class AdminUiCompat
{
    /** Same values as AdminUi's tone constants. */
    public const OK    = 'ok';
    public const WARN  = 'warn';
    public const BAD   = 'bad';
    public const INFO  = 'info';
    public const MUTED = 'muted';

    /** Escaped HTML. */
    public static function badge(string $label, string $tone = self::MUTED): string
    {
        if (class_exists(AdminUi::class)) {
            return AdminUi::badge($label, $tone);
        }
        return esc_html($label);
    }

    /** Plain text ("in 5m" / "5m ago" / "never"); callers escape it. */
    public static function relative(int $timestamp, ?int $now = null): string
    {
        if (class_exists(AdminUi::class)) {
            return AdminUi::relative($timestamp, $now);
        }
        return $timestamp > 0 ? gmdate('Y-m-d H:i', $timestamp) . ' UTC' : 'never';
    }
}
