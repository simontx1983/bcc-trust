<?php

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves which blockchain chains are supported (have active rows + a fetcher driver).
 */
final class ChainSupport
{
    /** @var string[]|null Per-request static cache. */
    private static ?array $slugs = null;

    /**
     * All active chain slugs that have a fetcher driver registered.
     *
     * @return string[]
     */
    public static function supported(): array
    {
        if (self::$slugs !== null) {
            return self::$slugs;
        }

        self::$slugs = [];
        foreach (ChainRepository::getActive() as $chain) {
            if (FetcherFactory::has_driver($chain->chain_type)) {
                self::$slugs[] = $chain->slug;
            }
        }

        return self::$slugs;
    }
}
