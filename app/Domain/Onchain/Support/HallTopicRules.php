<?php
/**
 * The rules a topic Hall's fields follow (F-597), shared by its create
 * (HallProvisioningService::provisionTopic()) and its edit (the admin Halls
 * API), so the two can never accept different values.
 *
 * Pure checks on strings the caller has already trimmed (and, at the REST
 * layer, sanitized): lengths in characters, no markup, and the slug's shape
 * and reserved words. check() returns why a value is refused, as a field, a
 * machine-readable rule and a message, or null. Admin UIs key their copy on
 * `field` + `rule`, never on the message.
 *
 * @package BCC\Trust\Onchain\Support
 */

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Core\Security\WordPressSignInGate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type FieldError array{field: string, rule: string, message: string}
 */
final class HallTopicRules {

    /** The fields these rules cover. */
    public const FIELDS = ['name', 'topic_slug', 'topic_label', 'description'];

    /** The rule keys check() reports. */
    public const RULE_EMPTY          = 'empty';
    public const RULE_TOO_LONG       = 'too_long';
    public const RULE_MARKUP         = 'markup';
    public const RULE_FORMAT         = 'format';
    public const RULE_NO_LETTER      = 'no_letter';
    public const RULE_ENDS_WITH_HALL = 'ends_with_hall';
    public const RULE_RESERVED       = 'reserved';

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

    /**
     * Why this field's value is refused, or null when it passes.
     *
     * @return FieldError|null
     */
    public static function check(string $field, string $value): ?array {
        $refused = match ($field) {
            'name'        => self::text($value, self::NAME_MAX, true),
            'topic_label' => self::text($value, self::LABEL_MAX, true),
            'description' => self::text($value, self::DESCRIPTION_MAX, false),
            'topic_slug'  => self::slug($value),
            default       => throw new \InvalidArgumentException("No rules for {$field}."),
        };

        return $refused === null ? null : ['field' => $field, 'rule' => $refused[0], 'message' => $refused[1]];
    }

    /**
     * The first refused field among these values, in FIELDS order.
     *
     * @param array<string, string> $values field => trimmed value
     * @return FieldError|null
     */
    public static function firstError(array $values): ?array {
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $values)) {
                $error = self::check($field, $values[$field]);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null */
    private static function text(string $value, int $max, bool $required): ?array {
        if ($required && $value === '') {
            return [self::RULE_EMPTY, 'This field is required.'];
        }
        if (mb_strlen($value) > $max) {
            return [self::RULE_TOO_LONG, sprintf('At most %d characters.', $max)];
        }
        if (strip_tags($value) !== $value) {
            return [self::RULE_MARKUP, 'No markup.'];
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null */
    private static function slug(string $slug): ?array {
        if ($slug === '') {
            return [self::RULE_EMPTY, 'This field is required.'];
        }
        if (strlen($slug) > self::SLUG_MAX) {
            return [self::RULE_TOO_LONG, sprintf('At most %d characters.', self::SLUG_MAX)];
        }
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return [self::RULE_FORMAT, 'Lowercase letters and digits, with single hyphens between them.'];
        }
        if (ctype_digit(str_replace('-', '', $slug))) {
            return [self::RULE_NO_LETTER, 'At least one letter.'];
        }
        if ($slug === 'hall' || str_ends_with($slug, '-hall')) {
            return [self::RULE_ENDS_WITH_HALL, 'A slug ending in "hall" is kept for chain Halls.'];
        }
        if (in_array($slug, [...WordPressSignInGate::RESERVED_PATH_WORDS, ...self::PEEPSO_RESERVED_GROUP_SLUGS], true)) {
            return [self::RULE_RESERVED, 'That slug is reserved.'];
        }

        return null;
    }
}
