<?php

namespace Voyager\Graph;

use Voyager\Database\DatabaseManager;
use Voyager\Graph\Console\GraphModelMakeCommand;
use Voyager\Graph\Database\Connectors\Neo4jConnector;
use Voyager\Graph\Database\Neo4jConnection;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Teaches the database manager the neo4j driver. Connections come from
 * `database.connections`; the client (laudis/neo4j-php-client) is only
 * needed once a neo4j connection is opened.
 */
class GraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.neo4j', fn () => new Neo4jConnector);

        $this->callAfterResolving('db', function (DatabaseManager $db) {
            $db->extend('neo4j', function (array $config, string $name) {
                $config['name'] = $name;

                $client = (new Neo4jConnector)->connect($config);

                return new Neo4jConnection(
                    $client,
                    $config['database'] ?? '',
                    $config['prefix'] ?? '',
                    $config
                );
            });
        });

        $this->commands([
            GraphModelMakeCommand::class,
        ]);
    }
}
