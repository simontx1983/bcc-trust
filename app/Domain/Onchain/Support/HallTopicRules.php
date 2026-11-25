<?php
/**
 * The rules a topic Hall's fields follow (F-597), shared by its create
 * (HallProvisioningService::provisionTopic()) and its edit (the admin Halls
 * API), so the two can never accept different values.
 *
 * Pure checks on strings the caller has already trimmed (and, at the REST
 * layer, sanitized): lengths in characters, no markup, and the slug's shape
 * and reserved words. Each check returns why a value is refused, or null.
 *
 * @package BCC\Trust\Onchain\Support
 */

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Core\Security\WordPressSignInGate;

if (!defined('ABSPATH')) {
    exit;
}

final class HallTopicRules {

    /**
     * A topic's slug, which is also its Hall's address: lowercase letters and
     * digits in hyphen-separated runs, at most SLUG_MAX characters.
     */
    public const SLUG_PATTERN    = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const SLUG_MAX        = 50;
    public const NAME_MAX        = 80;
    public const LABEL_MAX       = 60;
    public const DESCRIPTION_MAX = 1000;

    /**
     * Addresses PeepSo rewrites on load (its group-slug blocklist), beside
     * WordPressSignInGate::RESERVED_PATH_WORDS, which no group may take.
     */
    private const PEEPSO_RESERVED_GROUP_SLUGS = ['category'];

    public static function nameError(string $name): ?string {
        return ($name === '' || mb_strlen($name) > self::NAME_MAX || strip_tags($name) !== $name)
            ? sprintf('The Hall needs a name of 1 to %d characters, without markup.', self::NAME_MAX)
            : null;
    }

    public static function labelError(string $label): ?string {
        return ($label === '' || mb_strlen($label) > self::LABEL_MAX || strip_tags($label) !== $label)
            ? sprintf('The topic needs a label of 1 to %d characters, without markup.', self::LABEL_MAX)
            : null;
    }

    public static function descriptionError(string $description): ?string {
        return mb_strlen($description) > self::DESCRIPTION_MAX
            ? sprintf('The description can be at most %d characters.', self::DESCRIPTION_MAX)
            : null;
    }

    public static function slugError(string $slug): ?string {
        if (strlen($slug) > self::SLUG_MAX || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return sprintf('The topic needs a slug of lowercase letters, digits and single hyphens, at most %d characters.', self::SLUG_MAX);
        }
        if (ctype_digit(str_replace('-', '', $slug))) {
            return 'The topic slug needs at least one letter.';
        }
        if ($slug === 'hall' || str_ends_with($slug, '-hall')) {
            return 'A slug ending in "hall" is kept for chain Halls.';
        }
        if (in_array($slug, [...WordPressSignInGate::RESERVED_PATH_WORDS, ...self::PEEPSO_RESERVED_GROUP_SLUGS], true)) {
            return 'That slug is reserved.';
        }

        return null;
    }
}
