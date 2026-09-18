<?php

namespace Voyager\Graph\Console;

use Voyager\Console\GeneratorCommand;
use Voyager\NutsAndBolts\DataObjects\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:graph-model')]
class GraphModelMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected ?string $name = 'make:graph-model';

    /**
     * The console command description.
     *
     * @var string
     */
    protected string $description = 'Create a new Graph Instrument model class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected ?string $type = 'Graph model';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/graph-model.stub');
    }

    protected function resolveStubPath(string $stub): string
    {
        return file_exists($customPath = $this->venusian->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__.$stub;
    }

    protected function getDefaultNamespace(string $rootNamespace): string
    {
        return is_dir(app_path('Models')) ? $rootNamespace.'\\Models' : $rootNamespace;
    }

    /**
     * Build the class with the given name.
     *
     * @param  string  $name
     * @return string
     */
    protected function buildClass(string $name): string
    {
        $stub = parent::buildClass($name);

        $label = $this->option('label')
            ?: Str::studly(class_basename($this->argument('name')));

        return str_replace('{{ label }}', $label, $stub);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the model already exists'],
            ['label', null, InputOption::VALUE_OPTIONAL, 'The Neo4j label for the model'],
        ];
    }
}
