<?php

namespace BCC\Trust\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A repository read DID NOT RUN — thrown instead of returning its empty
 * shape.
 *
 * ── WHY AN EXCEPTION AND NOT A RETURN VALUE ─────────────────────────────
 * {@see GuardsReadFailures::guardRead()} logs a failed read and lets the
 * empty result through. That is the right answer for a WORKER: an
 * exception there would convert a logged degradation into a fatal, and
 * "no work this tick, retry next" is a safe reading of an empty queue.
 *
 * It is the WRONG answer for an operator surface. There, `[]` / `0` /
 * `idle` / `green` are ANSWERS, and handing one back after a query
 * failed reports a healthy, empty, up-to-date system that nobody has
 * looked at. A return-flag can be dropped by any caller that forgets to
 * check it; an exception cannot. That is the whole reason this type
 * exists rather than a nullable return.
 *
 * Every successful return shape is preserved: nothing about the happy
 * path changes, so no caller had to be rewritten to adopt this.
 *
 * CAUGHT IN EXACTLY TWO PLACES, both deliberate:
 *   - {@see \BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot::buildSummary()}
 *     and the Verify Collections page, which degrade to an explicit
 *     "unavailable" panel rather than a panel full of zeroes;
 *   - {@see ChainCheckpointRepository::addCuUsage()}, where the read is
 *     INSIDE a transaction and the catch already rolls back and logs —
 *     so the worker keeps running, but a read error can no longer
 *     masquerade as "that chain has no checkpoint row".
 *
 * PII: carries a method name and a MySQL error string (SQL structure,
 * never member data). Safe to log; still never rendered raw to a page.
 */
final class RepositoryReadFailure extends \RuntimeException
{
    private string $repositoryMethod;
    private string $dbError;

    public function __construct(string $repository, string $method, string $dbError)
    {
        $this->repositoryMethod = $method;
        $this->dbError          = $dbError;

        parent::__construct(sprintf(
            '[%s] read failed in %s() — an empty result here is NOT "no rows": %s',
            $repository,
            $method,
            $dbError
        ));
    }

    /** The repository method whose read failed. */
    public function repositoryMethod(): string
    {
        return $this->repositoryMethod;
    }

    /** The raw `$wpdb->last_error` string behind the failure. */
    public function dbError(): string
    {
        return $this->dbError;
    }
}
