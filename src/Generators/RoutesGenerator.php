<?php

namespace Rosalana\Safepoint\Generators;

use Illuminate\Support\Collection;
use Laravel\Ranger\Components\InertiaResponse;
use Laravel\Ranger\Components\Route;
use Laravel\Ranger\Validation\Rule;
use Laravel\Surveyor\Types\ArrayType;
use Laravel\Surveyor\Types\ClassType;
use Laravel\Surveyor\Types\Contracts\Type;
use Rosalana\Safepoint\Support\AnnotationReader;
use Rosalana\Safepoint\Support\SurveyorTypeConverter;

class RoutesGenerator
{
    /**
     * @param array<string, array{fqn: string, name: string, attributeKeys: list<string>}> $modelRegistry FQN → model data
     * @param list<string> $appPaths Only include routes whose controller lives under these paths
     */
    public function __construct(private array $modelRegistry, private array $appPaths = []) {}

    /**
     * @param Collection<Route> $routes
     * @return array<string, array{method: string, params: string, body: string, props: string}>
     */
    public function generate(Collection $routes): array
    {
        $result = [];

        foreach ($routes->filter(fn(Route $r) => $r->name() !== null && $this->isAppRoute($r)) as $route) {
            $annotations = AnnotationReader::read($route);

            if ($annotations['ignore']) {
                continue;
            }

            $result[$route->name()] = [
                'method' => strtoupper($route->verbs()->first()->actual),
                'params' => $this->buildParams($route, $annotations),
                'body' => $this->buildBody($route, $annotations),
                'props' => $this->buildProps($route, $annotations),
            ];
        }

        return $result;
    }

    private function isAppRoute(Route $route): bool
    {
        if (empty($this->appPaths)) {
            return true;
        }

        if (! $route->hasController()) {
            return false;
        }

        try {
            $controllerPath = $route->controllerPath();
        } catch (\Throwable) {
            return false;
        }

        foreach ($this->appPaths as $appPath) {
            if (str_starts_with($controllerPath, $appPath)) {
                return true;
            }
        }

        return false;
    }

    private function buildParams(Route $route, array $annotations): string
    {
        $parts = [];

        foreach ($route->parameters() as $parameter) {
            // @safepoint-param overrides the type for this parameter
            if (isset($annotations['params'][$parameter->name])) {
                $type = $annotations['params'][$parameter->name];
            } else {
                $types = array_map(
                    fn(Type $t) => SurveyorTypeConverter::convert($t),
                    $parameter->types,
                );
                $type = implode(' | ', array_unique(array_filter($types)));
                if ($type === '') {
                    $type = 'string | number';
                }
            }

            $suffix = $parameter->optional ? '?' : '';
            $parts[] = $parameter->name . $suffix . ': ' . $type;
        }

        // @safepoint-param can also add params not in the route definition
        foreach ($annotations['params'] as $name => $type) {
            $alreadyAdded = $route->parameters()->contains(fn($p) => $p->name === $name);
            if (! $alreadyAdded) {
                $parts[] = $name . ': ' . $type;
            }
        }

        if (empty($parts)) {
            return 'never';
        }

        return '{ ' . implode(', ', $parts) . ' }';
    }

    private function buildBody(Route $route, array $annotations): string
    {
        $method = strtoupper($route->verbs()->first()->actual);

        $base = null;

        if ($method !== 'GET' && $method !== 'HEAD') {
            $validator = $route->requestValidator();
            if ($validator !== null) {
                $base = $this->convertValidationRules($validator->rules);
            }
        }

        if (empty($annotations['body'])) {
            return $base ?? 'never';
        }

        $annotationLines = array_map(
            fn($k, $v) => '  ' . $k . ': ' . $v,
            array_keys($annotations['body']),
            array_values($annotations['body']),
        );

        if ($base === null) {
            return '{' . PHP_EOL . implode(PHP_EOL, $annotationLines) . PHP_EOL . '}';
        }

        // Append annotation fields before closing }
        return rtrim(substr(rtrim($base), 0, -1)) . PHP_EOL
            . implode(PHP_EOL, $annotationLines) . PHP_EOL . '}';
    }

    private function buildProps(Route $route, array $annotations): string
    {
        /** @var InertiaResponse|null $inertia */
        $inertia = collect($route->possibleResponses())
            ->first(fn($r) => $r instanceof InertiaResponse);

        $props = [];

        if ($inertia !== null && ! empty($inertia->data)) {
            foreach ($inertia->data as $key => $type) {
                /** @var Type $type */
                $props[$key] = $this->convertPropType($type, $annotations['include']);
            }
        }

        // @safepoint-prop: add or override individual props
        foreach ($annotations['props'] as $key => $tsType) {
            $props[$key] = $tsType;
        }

        if (empty($props)) {
            return 'never';
        }

        $lines = [];
        foreach ($props as $key => $type) {
            $lines[] = '  ' . $key . ': ' . $type;
        }

        return '{' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL . '}';
    }

    /**
     * @param string[] $includeRelations Relation names to add to RequiredKeys (from @safepoint-include)
     */
    private function convertPropType(Type $type, array $includeRelations = []): string
    {
        $nullable = $type->isNullable() ? ' | null' : '';

        // Single model → RequiredKeys<ModelName, 'attr1' | 'attr2'>
        if ($type instanceof ClassType) {
            $fqn = ltrim($type->value, '\\');

            if (isset($this->modelRegistry[$fqn])) {
                $model = $this->modelRegistry[$fqn];
                $keys = $this->buildRequiredKeys($model, $includeRelations);
                return "RequiredKeys<{$model['name']}, {$keys}>{$nullable}";
            }

            // Collection<TKey, TModel> → RequiredKeys<ModelName, ...>[]
            if (is_a($fqn, \Illuminate\Support\Collection::class, true)) {
                $generics = $type->genericTypes();
                if (! empty($generics)) {
                    $valueGeneric = end($generics);
                    if ($valueGeneric instanceof ClassType) {
                        $modelFqn = ltrim($valueGeneric->value, '\\');
                        if (isset($this->modelRegistry[$modelFqn])) {
                            $model = $this->modelRegistry[$modelFqn];
                            $keys = $this->buildRequiredKeys($model, $includeRelations);
                            return "RequiredKeys<{$model['name']}, {$keys}>[]{$nullable}";
                        }
                    }
                }
            }
        }

        // Array containing model class → RequiredKeys<ModelName, ...>[]
        if ($type instanceof ArrayType && ! $type->isList()) {
            return SurveyorTypeConverter::convert($type);
        }

        if ($type instanceof ArrayType && $type->isList()) {
            $items = $type->value;

            if (count($items) === 1) {
                $item = reset($items);
                if ($item instanceof ClassType) {
                    $fqn = ltrim($item->value, '\\');

                    if (isset($this->modelRegistry[$fqn])) {
                        $model = $this->modelRegistry[$fqn];
                        $keys = $this->buildRequiredKeys($model, $includeRelations);
                        return "RequiredKeys<{$model['name']}, {$keys}>[]{$nullable}";
                    }
                }
            }
        }

        return SurveyorTypeConverter::convert($type);
    }

    /**
     * Build the quoted key list for RequiredKeys<T, keys>, merging attribute keys
     * with any @safepoint-include relations that actually exist on the model.
     */
    private function buildRequiredKeys(array $model, array $includeRelations): string
    {
        $validIncludes = array_filter(
            $includeRelations,
            fn($rel) => array_key_exists($rel, $model['relations']),
        );

        $allKeys = array_unique(array_merge($model['attributeKeys'], array_values($validIncludes)));

        return collect($allKeys)->map(fn($k) => "'{$k}'")->implode(' | ');
    }

    /**
     * Recursively convert Laravel validation rules to a TypeScript type string.
     *
     * @param array<string, list<Rule>|array> $rules
     */
    private function convertValidationRules(array $rules): string
    {
        if (empty($rules)) {
            return 'Record<string, unknown>';
        }

        $lines = [];

        foreach ($rules as $field => $fieldRules) {
            $lines[] = $this->ruleToDefinition($fieldRules, $field, 1);
        }

        // Add trailing semicolon-less object type
        return '{' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL . '}';
    }

    private function ruleToDefinition(mixed $rules, string $key, int $indent = 1): string
    {
        $spaces = str_repeat('  ', $indent);
        $quotedKey = $this->quoteKey($key);

        // Leaf: list of rules for a single field
        if ($this->isFlatRuleList($rules)) {
            $ruleList = collect(is_array($rules) ? $rules : $rules->all());
            $required = $this->isRequired($ruleList);
            $tsType = $this->resolveFieldType($ruleList);
            $sep = $required ? ': ' : '?: ';

            return $spaces . $quotedKey . $sep . $tsType;
        }

        // Nested: associative array of sub-fields
        $subLines = [];
        $hasRequired = false;
        $hasWildcard = isset($rules['*']);

        foreach ($rules as $subKey => $subRules) {
            if ($subKey === '*') {
                foreach ($subRules as $grandKey => $grandRules) {
                    $subLines[] = $this->ruleToDefinition($grandRules, $grandKey, $indent + 1);
                }
            } else {
                $line = $this->ruleToDefinition($subRules, $subKey, $indent + 1);
                $subLines[] = $line;

                if (str_contains($line, ': ') && ! str_contains($line, '?: ')) {
                    $hasRequired = true;
                }
            }
        }

        $sep = $hasRequired ? ': {' : '?: {';

        if ($hasWildcard) {
            return $spaces . $quotedKey . ': {' . PHP_EOL
                . implode(PHP_EOL, $subLines) . PHP_EOL
                . $spaces . '}[]';
        }

        return $spaces . $quotedKey . $sep . PHP_EOL
            . implode(PHP_EOL, $subLines) . PHP_EOL
            . $spaces . '}';
    }

    private function isFlatRuleList(mixed $rules): bool
    {
        if ($rules instanceof \Illuminate\Support\Collection) {
            return array_is_list($rules->all());
        }

        return is_array($rules) && array_is_list($rules);
    }

    private function isRequired(Collection $rules): bool
    {
        return $rules->first(fn(Rule $r) => $r->is('Required')) !== null;
    }

    private function resolveFieldType(Collection $rules): string
    {
        $baseType = $this->resolveBaseType($rules);

        if ($rules->first(fn(Rule $r) => $r->is('Nullable'))) {
            return $baseType . ' | null';
        }

        return $baseType;
    }

    private function resolveBaseType(Collection $rules): string
    {
        // In rule → literal union
        $inRule = $rules->first(fn(Rule $r) => $r->is('In'));
        if ($inRule) {
            return collect($inRule->getParams())
                ->filter(fn($v) => ! is_null($v) && $v !== '')
                ->map(fn($v) => '"' . $v . '"')
                ->implode(' | ');
        }

        if ($rules->first(fn(Rule $r) => $r->is('String') || $r->is('Email') || $r->is('Url') || $r->is('Uuid'))) {
            return 'string';
        }

        if ($rules->first(fn(Rule $r) => $r->is('Integer') || $r->is('Numeric') || $r->is('Digits') || $r->is('DigitsBetween'))) {
            return 'number';
        }

        if ($rules->first(fn(Rule $r) => $r->is('Boolean') || $r->is('Accepted'))) {
            return 'boolean';
        }

        if ($rules->first(fn(Rule $r) => $r->is('Decimal'))) {
            return '`${number}.${number}`';
        }

        $arrayRule = $rules->first(fn(Rule $r) => $r->is('Array'));
        if ($arrayRule) {
            return 'unknown[]';
        }

        // Default fallback
        return 'string';
    }

    private function quoteKey(string $key): string
    {
        // Quote keys that contain special characters
        if (preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*$/', $key)) {
            return $key;
        }

        return '"' . $key . '"';
    }
}
