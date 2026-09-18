# Voyager Graph

Neo4j companion to [`voyager/database`](../Database). Register
`Voyager\Graph\GraphServiceProvider` and add a `database.connections.neo4j`
entry (publish `config/neo4j.php` with `--tag=voyager-graph-config`).

This package is **opt-in**. It is not listed in `DefaultProviders`.

## Requirements

- PHP `^8.4|^8.5`
- `voyager/database`, `voyager/contracts`, `voyager/nuts-and-bolts` `^0.8.0`
- `laudis/neo4j-php-client` `^3.3` to open Bolt connections

## Usage

```php
use Voyager\Graph\Instrument\Model;

class Person extends Model
{
    protected $table = 'Person';
}

cypher_run('CREATE (n:Person {id: $id, name: $name})', [
    'id' => 'p1',
    'name' => 'Ada',
]);

$records = cypher('MATCH (n:Person {id: $id}) RETURN n', ['id' => 'p1']);
```

Generate a model with `php computer make:graph-model Person --label=Person`.
