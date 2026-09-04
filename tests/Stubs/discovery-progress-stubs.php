<?php

declare(strict_types=1);

/**
 * The minimum surface `DiscoveryScanProgress` needs to LOAD and to build a
 * sentence.
 *
 * `summarySentence()`, `actionLabel()` and `actionHint()` are pure — they
 * take a progress array and return a string — so nothing here needs to
 * behave. The repositories exist only because the class file imports them,
 * and the i18n functions are identity/format shims: the assertions are
 * about WHICH sentence is chosen, never about translation.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (!function_exists('__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }
    if (!function_exists('_n')) {
        function _n(string $single, string $plural, int $number, string $domain = 'default'): string
        {
            return $number === 1 ? $single : $plural;
        }
    }
    if (!function_exists('number_format_i18n')) {
        function number_format_i18n(float $number, int $decimals = 0): string
        {
            return number_format($number, $decimals);
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(ChainCheckpointRepository::class, false)) {
        final class ChainCheckpointRepository
        {
            public static function get(int $chainId): ?object
            {
                return null;
            }
        }
    }

    if (!class_exists(CosmwasmCodeFamilyRepository::class, false)) {
        final class CosmwasmCodeFamilyRepository
        {
            public static function countForChainOrThrow(int $chainId): int
            {
                return 0;
            }

            public static function countPendingClassificationOrThrow(int $chainId, int $v): int
            {
                return 0;
            }

            public static function countCollectionFamiliesOrThrow(int $chainId): int
            {
                return 0;
            }
        }
    }

    if (!class_exists(RepositoryReadFailure::class, false)) {
        final class RepositoryReadFailure extends \RuntimeException
        {
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(CosmwasmClassifier::class, false)) {
        final class CosmwasmClassifier
        {
            public const VERSION = 2;
        }
    }
}
