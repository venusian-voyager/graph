<?php

namespace Voyager\Graph\Database\Query\Grammars;

use Voyager\Database\Query\Grammars\Grammar;

class Neo4jGrammar extends Grammar
{
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

        $converted = preg_replace_callback('/\?/', function (array $_matches) use (&$bindings, &$named, &$index): string {
            $key = 'p'.$index;
            $named[$key] = $bindings[$index] ?? null;
            $index++;

            return '$'.$key;
        }, $query) ?? $query;

        return [$converted, $named];
    }
}
