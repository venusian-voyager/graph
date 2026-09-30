<?php

namespace Voyager\Graph\Database\Query\Grammars;

use InvalidArgumentException;
use LogicException;
use Voyager\Database\Query\Builder;
use Voyager\Database\Query\Grammars\Grammar;
use Voyager\NutsAndBolts\DataObjects\Arr;

/**
 * The query builder in Cypher. The builder's table is a node label, its columns are the
 * node's properties, and every statement matches the label as `n`:
 *
 *   select  MATCH (n:`Label`) WHERE … RETURN n ORDER BY … SKIP … LIMIT …
 *   insert  UNWIND [{…}, …] AS row CREATE (n:`Label`) SET n = row
 *   update  MATCH (n:`Label`) WHERE … SET n.`a` = ? RETURN count(n) AS affected
 *   delete  MATCH (n:`Label`) WHERE … DETACH DELETE n RETURN count(n) AS affected
 *   upsert  UNWIND [{…}, …] AS row MERGE (n:`Label` {`key`: row.`key`}) ON CREATE SET n = row ON MATCH SET … RETURN count(n) AS affected
 *
 * A property is addressed by its last segment, so a model's qualified key (`Label.id`) is
 * `n.id`. Clauses a single-label match has no form for (joins, groups, havings, unions,
 * locks, grouped limits) and where types with no Cypher counterpart are refused by name.
 * Placeholders stay positional (`?`) until the connection names them.
 */
class Neo4jGrammar extends Grammar
{
    /** A quoted string, a backticked name, or a placeholder. */
    protected const string PLACEHOLDERS = '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"|`[^`]*`|\?/s';

    /** The variable every statement matches its label as. */
    protected const string NODE = 'n';

    /**
     * Comparison operators and their Cypher spelling.
     *
     * @var array<string, string>
     */
    protected const array COMPARISONS = [
        '=' => '=', '<' => '<', '>' => '>', '<=' => '<=', '>=' => '>=', '<>' => '<>', '!=' => '<>',
    ];

    /**
     * Where types this grammar compiles, by the builder's type name.
     *
     * @var list<string>
     */
    protected const array WHERES = [
        'raw', 'basic', 'like', 'in', 'notin', 'inraw', 'notinraw', 'null', 'notnull',
        'between', 'betweencolumns', 'valuebetween', 'column', 'nested', 'expression',
    ];

    /**
     * Convert positional bindings to Neo4j named parameters.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function convertParametersToNamed(string $query, array $bindings): array
    {
        if ($bindings === [] || ! str_contains($query, '?')) {
            return [$query, $bindings];
        }

        $named = [];
        $index = 0;

        // strings and escaped names are passed over, so a '?' inside one stays a character
        $converted = preg_replace_callback(self::PLACEHOLDERS, function (array $matches) use (&$bindings, &$named, &$index): string {
            if ($matches[0] !== '?') {
                return $matches[0];
            }

            $key = 'p'.$index;
            $named[$key] = $bindings[$index] ?? null;
            $index++;

            return '$'.$key;
        }, $query) ?? $query;

        return [$converted, $named];
    }

    public function compileSelect(Builder $query): string
    {
        $this->refuseUnmatchable($query);

        return $this->concatenate([
            $this->compileMatch($query),
            $this->compileWheres($query),
            $this->compileReturn($query),
            $this->compileOrders($query, $query->orders ?? []),
            $this->compileOffset($query, $query->offset),
            $this->compileLimit($query, $query->limit),
        ]);
    }

    public function compileExists(Builder $query): string
    {
        return 'CALL { '.$this->compileSelect($query).' } RETURN count(*) > 0 AS `exists`';
    }

    public function compileInsert(Builder $query, array $values): string
    {
        $label = $this->wrapTable($query->from);

        if ($values === []) {
            return "CREATE (n:{$label})";
        }

        if (! is_array(Arr::first($values))) {
            $values = [$values];
        }

        return 'UNWIND '.$this->compileRows($values)." AS row CREATE (n:{$label}) SET n = row";
    }

    public function compileInsertGetId(Builder $query, $values, $sequence): string
    {
        throw new LogicException('Neo4j nodes have no auto-incrementing key; give the node its key and insert() it.');
    }

    public function compileUpdate(Builder $query, array $values): string
    {
        $this->refuseUnmatchable($query);

        $columns = implode(', ', array_map(
            fn (string $column, mixed $value): string => $this->wrap($column).' = '.$this->parameter($value),
            array_keys($values),
            $values,
        ));

        return $this->concatenate([
            $this->compileMatch($query),
            $this->compileWheres($query),
            $this->compileWriteWindow($query),
            'SET '.$columns,
            'RETURN count(n) AS affected',
        ]);
    }

    /**
     * Match and where come first in Cypher, so their bindings lead and the values follow.
     * A value is one binding even when it is a list: a list is a property on a node.
     */
    public function prepareBindingsForUpdate(array $bindings, array $values): array
    {
        foreach ($values as $column => $value) {
            if ($value instanceof \Closure) {
                throw new LogicException("A subquery can't set {$column} in the Neo4j grammar; set it with cypher().");
            }
        }

        return array_values(array_merge(
            Arr::flatten(Arr::except($bindings, ['select', 'join'])),
            array_values($values),
        ));
    }

    public function compileDelete(Builder $query): string
    {
        $this->refuseUnmatchable($query);

        return $this->concatenate([
            $this->compileMatch($query),
            $this->compileWheres($query),
            $this->compileWriteWindow($query),
            'DETACH DELETE n',
            'RETURN count(n) AS affected',
        ]);
    }

    public function compileTruncate(Builder $query): array
    {
        return ['MATCH (n:'.$this->wrapTable($query->from).') DETACH DELETE n' => []];
    }

    /**
     * Rows merge on their unique properties; a new node takes the whole row, a matched one the
     * update columns (by name, from the row) or the update values (bound after the rows).
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        $label = $this->wrapTable($query->from);

        $keys = implode(', ', array_map(
            fn (string $column): string => $this->wrapValue($column).': row.'.$this->wrapValue($column),
            $uniqueBy,
        ));

        $set = [];

        foreach ($update as $key => $value) {
            $set[] = is_int($key)
                ? $this->wrap($value).' = row.'.$this->wrapValue($value)
                : $this->wrap($key).' = '.$this->parameter($value);
        }

        return $this->concatenate([
            'UNWIND '.$this->compileRows($values).' AS row',
            "MERGE (n:{$label} {{$keys}})",
            'ON CREATE SET n = row',
            $set === [] ? '' : 'ON MATCH SET '.implode(', ', $set),
            'RETURN count(n) AS affected',
        ]);
    }

    public function compileRandom($seed): string
    {
        return 'rand()';
    }

    /**
     * A property of the matched node, or an expression as given.
     */
    public function wrap($value): string
    {
        if ($this->isExpression($value)) {
            return (string) $this->getValue($value);
        }

        if (stripos($value, ' as ') !== false) {
            [$column, $alias] = preg_split('/\s+as\s+/i', $value);

            return $this->wrap($column).' AS '.$this->wrapValue($alias);
        }

        $property = $this->property($value);

        return $property === '*' ? self::NODE : self::NODE.'.'.$this->wrapValue($property);
    }

    public function wrapTable($table, $prefix = null): string
    {
        if ($this->isExpression($table)) {
            return (string) $this->getValue($table);
        }

        return $this->wrapValue(($prefix ?? $this->connection->getTablePrefix()).$table);
    }

    protected function wrapValue($value): string
    {
        return $value === '*' ? $value : '`'.str_replace('`', '``', $value).'`';
    }

    protected function compileMatch(Builder $query): string
    {
        if (! is_string($query->from) || $query->from === '') {
            throw new LogicException('A graph query needs a node label: call from() or table() with it.');
        }

        return 'MATCH (n:'.$this->wrapTable($query->from).')';
    }

    protected function compileReturn(Builder $query): string
    {
        if (! is_null($query->aggregate)) {
            return 'RETURN '.$this->compileAggregate($query, $query->aggregate);
        }

        $columns = $query->columns ?? ['*'];

        $distinct = $query->distinct ? 'DISTINCT ' : '';

        return 'RETURN '.$distinct.implode(', ', array_map($this->compileProjection(...), $columns));
    }

    /**
     * A projected column keeps its property name, so a row reads back the way it was asked for.
     */
    protected function compileProjection(mixed $column): string
    {
        if ($this->isExpression($column) || stripos($column, ' as ') !== false) {
            return $this->wrap($column);
        }

        $property = $this->property($column);

        return $property === '*' ? self::NODE : $this->wrap($column).' AS '.$this->wrapValue($property);
    }

    protected function compileAggregate(Builder $query, $aggregate): string
    {
        $columns = $aggregate['columns'];

        $column = in_array('*', array_map(fn ($c) => $this->isExpression($c) ? $c : $this->property($c), $columns), true)
            ? self::NODE
            : implode(', ', array_map($this->wrap(...), $columns));

        $distinct = $query->distinct && $column !== self::NODE ? 'DISTINCT ' : '';

        return $aggregate['function'].'('.$distinct.$column.') AS aggregate';
    }

    protected function compileOrders(Builder $query, $orders): string
    {
        if (empty($orders)) {
            return '';
        }

        return 'ORDER BY '.implode(', ', $this->compileOrdersToArray($query, $orders));
    }

    protected function compileLimit(Builder $query, $limit): string
    {
        return is_null($limit) ? '' : 'LIMIT '.(int) $limit;
    }

    protected function compileOffset(Builder $query, $offset): string
    {
        return is_null($offset) || (int) $offset === 0 ? '' : 'SKIP '.(int) $offset;
    }

    /**
     * An ordered or limited update/delete narrows the matched nodes before it writes.
     */
    protected function compileWriteWindow(Builder $query): string
    {
        if (empty($query->orders) && is_null($query->limit) && empty($query->offset)) {
            return '';
        }

        return $this->concatenate([
            'WITH n',
            $this->compileOrders($query, $query->orders ?? []),
            $this->compileOffset($query, $query->offset),
            $this->compileLimit($query, $query->limit),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     */
    protected function compileRows(array $values): string
    {
        return '['.implode(', ', array_map(
            fn (array $row): string => '{'.implode(', ', array_map(
                fn (string $column, mixed $value): string => $this->wrapValue($column).': '.$this->parameter($value),
                array_keys($row),
                $row,
            )).'}',
            $values,
        )).']';
    }

    protected function compileWheresToArray($query): array
    {
        return array_map(function (array $where) use ($query): string {
            if (! in_array(strtolower($where['type']), self::WHERES, true)) {
                throw new LogicException("where{$where['type']}() has no Cypher form in the Neo4j grammar.");
            }

            return $where['boolean'].' '.$this->{"where{$where['type']}"}($query, $where);
        }, $query->wheres);
    }

    /**
     * `like` matches whereLike()'s default and ignores case; whereLike(caseSensitive: true) keeps it.
     */
    protected function whereBasic(Builder $query, $where): string
    {
        $operator = strtolower($where['operator']);

        return match (true) {
            isset(self::COMPARISONS[$operator]) => $this->wrap($where['column']).' '.self::COMPARISONS[$operator].' '.$this->parameter($where['value']),
            in_array($operator, ['like', 'ilike'], true) => $this->compileLike($where['column'], $where['value'], true, false),
            in_array($operator, ['not like', 'not ilike'], true) => $this->compileLike($where['column'], $where['value'], true, true),
            default => throw new InvalidArgumentException("The {$where['operator']} operator has no Cypher form in the Neo4j grammar."),
        };
    }

    protected function whereLike(Builder $query, $where): string
    {
        return $this->compileLike($where['column'], $where['value'], ! $where['caseSensitive'], $where['not']);
    }

    /**
     * SQL's LIKE as a Cypher regex: the bound pattern's regex characters are escaped, then `%`
     * and `_` become `.*` and `.`, so the value binds unchanged.
     */
    protected function compileLike(mixed $column, mixed $value, bool $insensitive, bool $not): string
    {
        $pattern = $this->parameter($value);

        foreach (['\\', '.', '^', '$', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|'] as $special) {
            $pattern = "replace({$pattern}, '".str_replace('\\', '\\\\', $special)."', '\\\\".str_replace('\\', '\\\\', $special)."')";
        }

        $pattern = "replace(replace({$pattern}, '%', '.*'), '_', '.')";

        if ($insensitive) {
            $pattern = "'(?i)' + {$pattern}";
        }

        $match = $this->wrap($column).' =~ '.$pattern;

        return $not ? 'NOT ('.$match.')' : $match;
    }

    protected function whereIn(Builder $query, $where): string
    {
        return empty($where['values'])
            ? 'false'
            : $this->wrap($where['column']).' IN ['.$this->parameterize($where['values']).']';
    }

    protected function whereNotIn(Builder $query, $where): string
    {
        return empty($where['values'])
            ? 'true'
            : 'NOT '.$this->wrap($where['column']).' IN ['.$this->parameterize($where['values']).']';
    }

    protected function whereInRaw(Builder $query, $where): string
    {
        return empty($where['values'])
            ? 'false'
            : $this->wrap($where['column']).' IN ['.implode(', ', $where['values']).']';
    }

    protected function whereNotInRaw(Builder $query, $where): string
    {
        return empty($where['values'])
            ? 'true'
            : 'NOT '.$this->wrap($where['column']).' IN ['.implode(', ', $where['values']).']';
    }

    protected function whereNull(Builder $query, $where): string
    {
        return $this->wrap($where['column']).' IS NULL';
    }

    protected function whereNotNull(Builder $query, $where): string
    {
        return $this->wrap($where['column']).' IS NOT NULL';
    }

    /**
     * Cypher chains comparisons, so a range reads as written: min <= value <= max.
     */
    protected function whereBetween(Builder $query, $where): string
    {
        [$min, $max] = array_values($where['values']);

        return $this->compileRange($this->parameter($min), $this->wrap($where['column']), $this->parameter($max), $where['not']);
    }

    protected function whereBetweenColumns(Builder $query, $where): string
    {
        [$min, $max] = array_values($where['values']);

        return $this->compileRange($this->wrap($min), $this->wrap($where['column']), $this->wrap($max), $where['not']);
    }

    protected function whereValueBetween(Builder $query, $where): string
    {
        [$min, $max] = array_values($where['columns']);

        return $this->compileRange($this->wrap($min), $this->parameter($where['value']), $this->wrap($max), $where['not']);
    }

    protected function compileRange(string $min, string $value, string $max, bool $not): string
    {
        $range = $min.' <= '.$value.' <= '.$max;

        return $not ? 'NOT ('.$range.')' : '('.$range.')';
    }

    protected function whereColumn(Builder $query, $where): string
    {
        $operator = self::COMPARISONS[strtolower($where['operator'])]
            ?? throw new InvalidArgumentException("The {$where['operator']} operator has no Cypher form in the Neo4j grammar.");

        return $this->wrap($where['first']).' '.$operator.' '.$this->wrap($where['second']);
    }

    protected function whereNested(Builder $query, $where): string
    {
        $nested = $this->compileWheres($where['query']);

        return $nested === '' ? 'true' : '('.substr($nested, strlen('where ')).')';
    }

    /**
     * The property a column names: its last dot segment, so `Label.id` and `id` are one property.
     */
    protected function property(string $column): string
    {
        $position = strrpos($column, '.');

        return $position === false ? $column : substr($column, $position + 1);
    }

    /**
     * Clauses with no single-label Cypher form, refused before anything is sent.
     */
    protected function refuseUnmatchable(Builder $query): void
    {
        foreach (['joins' => 'join', 'groups' => 'groupBy', 'havings' => 'having', 'unions' => 'union', 'lock' => 'lock'] as $property => $method) {
            if (! empty($query->{$property})) {
                throw new LogicException("{$method}() has no Cypher form in the Neo4j grammar; write it with cypher().");
            }
        }

        if (isset($query->groupLimit)) {
            throw new LogicException('A limit on an eager load has no Cypher form in the Neo4j grammar; load the relation unlimited or write it with cypher().');
        }
    }
}
