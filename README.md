# Voyager Graph

Neo4j driver for [`voyager/database`](../Database). `GraphServiceProvider` is in
`DefaultProviders`; the connection is `database.connections.neo4j`.

## Requirements

- PHP `^8.4|^8.5`
- `voyager/database`, `voyager/contracts`, `voyager/nuts-and-bolts`
- `laudis/neo4j-php-client` `^3.3`, needed once a neo4j connection opens

## Raw Cypher

```php
cypher_run('CREATE (n:Person {id: $id, name: $name})', ['id' => 'p1', 'name' => 'Ada']);

$records = cypher('MATCH (n:Person) WHERE n.name = ? RETURN n', ['Ada']);
```

Named (`$id`) or positional (`?`) parameters. A `?` inside a string or backticked name stays a character.

## Models

```php
use Voyager\Graph\Instrument\Model;

class Person extends Model
{
    protected $table = 'Person';
}

Person::create(['id' => 'p1', 'name' => 'Ada']);
Person::where('name', 'like', 'a%')->orderBy('name')->get();
```

Table = label, columns = node properties, key = string `id`, no auto-increment.
The builder compiles to Cypher, matching the label as `n`:

| Builder | Cypher |
|---|---|
| `get()` | `MATCH (n:Label) WHERE … RETURN n ORDER BY … SKIP … LIMIT …` |
| `insert()` | `UNWIND [{…}] AS row CREATE (n:Label) SET n = row` |
| `update()` | `MATCH … SET n.a = ? RETURN count(n) AS affected` |
| `delete()` | `MATCH … DETACH DELETE n RETURN count(n) AS affected` |
| `upsert()` | `UNWIND … MERGE (n:Label {key: row.key}) ON CREATE SET n = row ON MATCH SET …` |

Wheres: basic, like/whereLike (Cypher regex; `like` ignores case), in, null,
between, column, nested, raw. Update/delete return nodes touched. Lists are
native list properties.

No single-label form, so refused by name: joins, groupBy, having, unions, locks,
eager-load limits, date/JSON/fulltext/exists wheres. Write those in `cypher()`.

## Async

Same as any `voyager/database` connection: `via()` runs terminals in a worker,
`via()->transaction()` a whole transaction, `stream()` sends `db-chunk:neo4j:{label}` mail.
Blocking calls on the connection wait for its offloaded writes.

Generate a model: `php computer make:graph-model Person --label=Person`.
