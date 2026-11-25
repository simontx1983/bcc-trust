<?php

namespace BCC\Trust\Onchain\Fetchers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A validator walk stopped at its own time budget before the list ended.
 *
 * ⚠ NOT A PROVIDER FAILURE. Every page the walk asked for may have been
 * answered; the fetcher stopped so one slow chain cannot hold the whole
 * index run, and it refuses to hand back a list it knows is unfinished.
 *
 * The index loop ({@see \BCC\Trust\Onchain\Services\ChainRefreshService::index_validators()})
 * treats it as this chain's partial fetch: nothing is written for the chain,
 * the run-wide dead-marking sweep is withheld, no clean-index success is
 * credited, and the breaker is NOT charged. The next run walks again from
 * the first page.
 */
final class IndexTimeBudgetSpent extends \RuntimeException
{
}
