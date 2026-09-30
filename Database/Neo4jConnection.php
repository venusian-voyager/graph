<?php

namespace Voyager\Graph\Database;

use Closure;
use Exception;
use Generator;
use Voyager\Database\Connection;
use Voyager\Graph\Database\Query\Grammars\Neo4jGrammar;
use Voyager\Graph\Database\Query\Neo4jQueryBuilder;
use Voyager\Graph\Database\Query\Processors\Neo4jProcessor;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\CypherSequence;
use Laudis\Neo4j\Contracts\TransactionInterface;
use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Types\Node;
use Laudis\Neo4j\Types\Relationship;
use Throwable;

class Neo4jConnection extends Connection
{
    protected ClientInterface $neo4jClient;

    protected ?UnmanagedTransactionInterface $activeTransaction = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(ClientInterface $neo4jClient, string $database = '', string $tablePrefix = '', array $config = [])
    {
        $this->neo4jClient = $neo4jClient;

        parent::__construct(null, $database, $tablePrefix, $config);
    }

    public function getNeo4jClient(): ClientInterface
    {
        return $this->neo4jClient;
    }

    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        $this->settleOffloaded(false);

        return $this->run($query, $bindings, function (string $query, array $bindings): array {
            if ($this->pretending()) {
                return [];
            }

            $processedResults = [];

            foreach ($this->execute($query, $bindings) as $record) {
                $row = [];

                foreach ($record->toArray() as $key => $value) {
                    if ($value instanceof Node) {
                        $row = array_merge($row, $value->getProperties()->toRecursiveArray());
                    } elseif ($value instanceof Relationship) {
                        $row[$key] = $value->getProperties()->toRecursiveArray();
                    } elseif ($value instanceof CypherSequence) {
                        $row[$key] = $value->toRecursiveArray();
                    } else {
                        $row[$key] = $value;
                    }
                }

                $processedResults[] = $row;
            }

            return $processedResults;
        });
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true): mixed
    {
        $records = $this->select($query, $bindings, $useReadPdo);

        return array_shift($records);
    }

    /**
     * Rows one at a time. Bolt hands the whole result over at once, so the rows are the select's.
     */
    public function cursor($query, $bindings = [], $useReadPdo = true): Generator
    {
        yield from $this->select($query, $bindings, $useReadPdo);
    }

    public function statement($query, $bindings = []): bool
    {
        $this->settleOffloaded(true);

        return $this->run($query, $bindings, function (string $query, array $bindings): bool {
            if ($this->pretending()) {
                return true;
            }

            [$convertedQuery, $convertedBindings] = $this->getQueryGrammar()->convertParametersToNamed($query, $bindings);

            if (! is_null($this->activeTransaction)) {
                $this->activeTransaction->run($convertedQuery, $convertedBindings);
            } else {
                $this->neo4jClient->run($convertedQuery, $convertedBindings);
            }

            return true;
        });
    }

    /**
     * A statement that returns an `affected` column reports its own count; the grammar's
     * update, delete and upsert return the nodes they touched. Any other statement reports
     * the summary's counters.
     */
    public function affectingStatement($query, $bindings = []): int
    {
        $this->settleOffloaded(true);

        return $this->run($query, $bindings, function (string $query, array $bindings): int {
            if ($this->pretending()) {
                return 0;
            }

            $result = $this->execute($query, $bindings);

            $first = $result->isEmpty() ? null : $result->first();

            if (! is_null($first) && $first->hasKey('affected')) {
                return (int) $first->get('affected');
            }

            $counters = $result->getSummary()->getCounters();

            return $counters->nodesCreated()
                + $counters->nodesDeleted()
                + $counters->relationshipsCreated()
                + $counters->relationshipsDeleted()
                + $counters->propertiesSet();
        });
    }

    /**
     * Run on the open transaction, or in a write transaction of its own.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    protected function execute(string $query, array $bindings): SummarizedResult
    {
        [$convertedQuery, $convertedBindings] = $this->getQueryGrammar()->convertParametersToNamed($query, $bindings);

        if (! is_null($this->activeTransaction)) {
            return $this->activeTransaction->run($convertedQuery, $convertedBindings);
        }

        return $this->neo4jClient->writeTransaction(
            fn (TransactionInterface $tx) => $tx->run($convertedQuery, $convertedBindings)
        );
    }

    public function insert($query, $bindings = []): bool
    {
        return $this->statement($query, $bindings);
    }

    public function update($query, $bindings = []): int
    {
        return $this->affectingStatement($query, $bindings);
    }

    public function delete($query, $bindings = []): int
    {
        return $this->affectingStatement($query, $bindings);
    }

    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            try {
                $callbackResult = $callback($this);
                $this->commit();

                return $callbackResult;
            } catch (Throwable $e) {
                $this->rollBack();

                if ($currentAttempt >= $attempts) {
                    throw $e;
                }
            }
        }

        return null;
    }

    public function beginTransaction(): void
    {
        $this->settleOffloaded(true);

        if ($this->transactions === 0 && is_null($this->activeTransaction)) {
            $this->activeTransaction = $this->neo4jClient->beginTransaction();
        }

        $this->transactions++;
        $this->fireConnectionEvent('beganTransaction');
    }

    public function commit(): void
    {
        if ($this->transactionLevel() === 1 && ! is_null($this->activeTransaction)) {
            $this->activeTransaction->commit();
            $this->activeTransaction = null;
        }

        $this->transactions = max(0, $this->transactions - 1);
        $this->fireConnectionEvent('committed');
    }

    public function rollBack($toLevel = null): void
    {
        $toLevel = is_null($toLevel) ? $this->transactions - 1 : $toLevel;

        if ($toLevel < 0 || $toLevel >= $this->transactions) {
            return;
        }

        if (! is_null($this->activeTransaction)) {
            try {
                $this->activeTransaction->rollback();
            } catch (Exception) {
                //
            } finally {
                $this->activeTransaction = null;
            }
        }

        $this->transactions = $toLevel;
        $this->fireConnectionEvent('rollingBack');
    }

    protected function getDefaultQueryGrammar(): Neo4jGrammar
    {
        return new Neo4jGrammar($this);
    }

    protected function getDefaultPostProcessor(): Neo4jProcessor
    {
        return new Neo4jProcessor;
    }

    public function query(): Neo4jQueryBuilder
    {
        return new Neo4jQueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    public function getDriverName(): string
    {
        return 'neo4j';
    }

    protected function run($query, $bindings, Closure $callback): mixed
    {
        return $callback($query, $bindings);
    }
}
