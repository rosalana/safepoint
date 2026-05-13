<?php

namespace Rosalana\Safepoint\Generators;

use Laravel\Ranger\Components\Route;
use Illuminate\Support\Collection;

class RouteListGenerator
{
    /**
     * @param list<string> $appPaths Only include routes whose controller lives under these paths
     */
    public function __construct(private array $appPaths = []) {}

    /**
     * @return array<string, array{method: string, uri: string}>
     */
    public function generate(Collection $routes): array
    {
        $result = [];

        foreach ($routes->filter(fn(Route $r) => $r->name() !== null && $this->isAppRoute($r)) as $route) {

            $result[$route->name()] = [
                'method' => strtolower($route->verbs()->first()->actual),
                'uri' => $route->uri(),
            ];
        }

        return $result;
    }

    private function isAppRoute(Route $route): bool
    {
        if (empty($this->appPaths)) {
            return true;
        }

        try {
            $controllerPath = $route->controllerPath();
        } catch (\Throwable) {
            return false;
        }

        if (in_array($controllerPath, ['[serialized-closure]', '[unknown]'])) {
            return false;
        }

        // Controller routes: must be under one of the configured app paths
        if ($route->hasController()) {
            foreach ($this->appPaths as $appPath) {
                if (str_starts_with($controllerPath, $appPath)) {
                    return true;
                }
            }

            return false;
        }

        // Closure routes: include if not from vendor (match /vendor/ as a path segment)
        return ! (bool) preg_match('#[/\\\\]vendor[/\\\\]#', $controllerPath);
    }
}
