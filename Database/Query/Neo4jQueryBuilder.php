<?php

namespace Voyager\Graph\Database\Query;

use Voyager\Database\Query\Builder;

class Neo4jQueryBuilder extends Builder
{
    /**
     * The Neo4j label currently targeted (Instrument "from" table).
     *
     * @var string|null
     */
    public $from;
}
