<?php

namespace Rosalana\Safepoint\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\InertiaSharedData;
use Laravel\Ranger\Components\Model;
use Laravel\Ranger\Ranger;
use Rosalana\Safepoint\Generators\ModelGenerator;
use Rosalana\Safepoint\Generators\RoutesGenerator;
use Rosalana\Safepoint\Generators\SharedDataGenerator;
use Rosalana\Safepoint\Support\SafepointWriter;

class GenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'safepoint:generate {--path=} {--base-path=} {--app-path=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a typescript representation of the Laravel models, routes and actions.';

    /**
     * Execute the console command.
     */
    public function handle(Ranger $ranger, Filesystem $files): void
    {
        $models = [];
        $modelRegistry = [];
        $routes = null;
        $sharedData = null;

        $basePaths = $this->option('base-path')
            ? array_map('trim', explode(',', $this->option('base-path')))
            : [base_path()];

        $appPaths = $this->option('app-path')
            ? array_map('trim', explode(',', $this->option('app-path')))
            : [app_path()];

        $ranger->setBasePaths(...$basePaths);
        $ranger->setAppPaths(...$appPaths);

        $modelGenerator = new ModelGenerator();

        $ranger->onModel(function (Model $model) use (&$models, &$modelRegistry, $modelGenerator, $appPaths) {
            // Skip vendor models (e.g. DatabaseNotification from Notifiable trait)
            try {
                $modelFile = (new \ReflectionClass($model->name))->getFileName();
                $inAppPath = collect($appPaths)->contains(fn ($path) => str_starts_with($modelFile, $path));
                if (! $inAppPath) {
                    return;
                }
            } catch (\ReflectionException) {
                return;
            }

            $data = $modelGenerator->generate($model);
            $models[] = $data;
            $modelRegistry[$data['fqn']] = $data;
        });

        $ranger->onInertiaSharedData(function (InertiaSharedData $data) use (&$sharedData) {
            $sharedData = (new SharedDataGenerator())->generate($data);
        });

        $ranger->onRoutes(function (Collection $routeCollection) use (&$routes, &$modelRegistry, $appPaths) {
            $routes = (new RoutesGenerator($modelRegistry, $appPaths))->generate($routeCollection);
        });

        $ranger->walk();

        // Post-process: filter out relations that reference non-app models
        $knownModelNames = collect($modelRegistry)->pluck('name')->values()->all();
        $models = array_map(function (array $model) use ($knownModelNames) {
            $model['relations'] = array_filter(
                $model['relations'],
                function (string $tsType) use ($knownModelNames) {
                    // Extract base type name from 'Model[]', 'Model | null', etc.
                    $baseName = trim(preg_replace('/(\[\]|\s*\|\s*null)/', '', $tsType));
                    return in_array($baseName, $knownModelNames);
                },
            );
            return $model;
        }, $models);

        $output = (new SafepointWriter())->write(
            $models,
            $routes ?? [],
            $sharedData ?? '',
        );

        $outputPath = $this->option('path') ?? resource_path('js/safepoint.ts');

        $files->ensureDirectoryExists(dirname($outputPath));
        $files->put($outputPath, $output);

        $this->info("Generated: {$outputPath}");
    }
}
