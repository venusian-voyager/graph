<?php

namespace Voyager\Graph;

use Voyager\Database\DatabaseManager;
use Voyager\Graph\Console\GraphModelMakeCommand;
use Voyager\Graph\Database\Connectors\Neo4jConnector;
use Voyager\Graph\Database\Neo4jConnection;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Optional companion provider — not registered in System DefaultProviders.
 *
 * Apps that need Neo4j should register this provider and add a
 * `database.connections.neo4j` entry (see config/neo4j.php).
 */
class GraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.neo4j', fn () => new Neo4jConnector);

        $this->app->resolving('db', function (DatabaseManager $db) {
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

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/neo4j.php' => $this->app->configPath('neo4j.php'),
            ], 'voyager-graph-config');
        }
    }
}
