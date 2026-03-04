<?php

namespace Rosalana\Safepoint\Support;

use Laravel\Ranger\Components\Route;

class AnnotationReader
{
    /**
     * Read safepoint PHPDoc annotations from a controller method.
     *
     * @return array{ignore: bool, include: string[], props: array<string, string>, body: array<string, string>, params: array<string, string>}
     */
    public static function read(Route $route): array
    {
        $defaults = ['ignore' => false, 'include' => [], 'props' => [], 'body' => [], 'params' => []];

        if (! $route->hasController()) {
            return $defaults;
        }

        try {
            $reflection = new \ReflectionMethod(ltrim($route->controller(), '\\'), $route->method());
            $doc = $reflection->getDocComment();
        } catch (\Throwable) {
            return $defaults;
        }

        if ($doc === false) {
            return $defaults;
        }

        return [
            'ignore'  => (bool) preg_match('/@safepoint-ignore\b/', $doc),
            'include' => static::parseList($doc, 'safepoint-include'),
            'props' => static::parseKeyType($doc, 'safepoint-prop'),
            'body' => static::parseKeyType($doc, 'safepoint-body'),
            'params' => static::parseKeyType($doc, 'safepoint-param'),
        ];
    }

    /**
     * Parse a comma-separated list annotation: @tag val1, val2
     *
     * @return string[]
     */
    private static function parseList(string $doc, string $tag): array
    {
        if (! preg_match('/@' . $tag . '\s+([^\n]+)/', $doc, $m)) {
            return [];
        }

        return array_map('trim', explode(',', trim($m[1])));
    }

    /**
     * Parse key+type annotations: @tag key tsType
     *
     * @return array<string, string>
     */
    private static function parseKeyType(string $doc, string $tag): array
    {
        $result = [];
        preg_match_all('/@' . $tag . '\s+(\S+)\s+([^\n]+)/', $doc, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $result[trim($match[1])] = trim($match[2]);
        }

        return $result;
    }
}
