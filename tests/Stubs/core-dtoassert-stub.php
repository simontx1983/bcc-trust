<?php
/**
 * Minimal stand-in for bcc-core's BCC\Core\DTO\DTOAssert, the parent of
 * bcc-trust's DTOAssert, so a test can build a trust DTO without the core
 * plugin on the autoload path. Every assertion is a no-op: tests that load
 * this are not testing DTO validation.
 *
 * Subprocess-only; load BEFORE anything autoloads a trust DTO.
 */

declare(strict_types=1);

namespace BCC\Core\DTO;

if (!class_exists(DTOAssert::class, false)) {
    abstract class DTOAssert
    {
        /** @param array<int, mixed> $args */
        public static function __callStatic(string $name, array $args): void
        {
        }
    }
}
