<?php

namespace Rosalana\Safepoint\Support;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Surveyor\Types;
use Laravel\Surveyor\Types\Contracts\Type;

class SurveyorTypeConverter
{
    public static function convert(Type $type): string
    {
        return match (true) {
            $type instanceof Types\ArrayType => static::convertArray($type),
            $type instanceof Types\ArrayShapeType => static::convertArrayShape($type),
            $type instanceof Types\BoolType => static::decorate('boolean', $type),
            $type instanceof Types\ClassType => static::convertClass($type),
            $type instanceof Types\IntType,
            $type instanceof Types\FloatType,
            $type instanceof Types\NumberType => static::decorate('number', $type),
            $type instanceof Types\StringType => static::decorate('string', $type),
            $type instanceof Types\NullType => 'null',
            $type instanceof Types\MixedType => 'unknown',
            $type instanceof Types\UnionType => static::convertUnion($type),
            $type instanceof Types\IntersectionType => static::convertIntersection($type),
            $type instanceof Types\CallableType => static::convert($type->returnType),
            $type instanceof Types\NeverType => 'never',
            $type instanceof Types\VoidType => 'void',
            default => throw new InvalidArgumentException('Unsupported Surveyor type: ' . get_class($type)),
        };
    }

    protected static function convertArray(Types\ArrayType $type): string
    {
        $value = $type->value;
        $nullSuffix = $type->isNullable() ? ' | null' : '';

        if (empty($value)) {
            return 'unknown[]' . $nullSuffix;
        }

        if (array_is_list($value)) {
            // List of type options → union[]
            $types = collect($value)
                ->map(fn ($t) => $t instanceof Type ? static::convert($t) : (string) $t)
                ->unique()
                ->filter()
                ->implode(' | ');

            if (str_contains($types, ' | ')) {
                return '(' . $types . ')[]' . $nullSuffix;
            }

            return $types . '[]' . $nullSuffix;
        }

        // Associative → object shape
        return static::convertObjectShape($value) . $nullSuffix;
    }

    protected static function convertArrayShape(Types\ArrayShapeType $type): string
    {
        $keyType = static::convert($type->keyType);
        $valueType = static::convert($type->valueType);

        if ($keyType === 'number') {
            return $valueType . '[]';
        }

        if ($keyType === 'unknown') {
            $keyType = 'string';
        }

        return "Record<{$keyType}, {$valueType}>";
    }

    protected static function convertClass(Types\ClassType $type): string
    {
        $value = ltrim($type->value, '\\');

        if ($value === \Illuminate\Support\Stringable::class) {
            return static::decorate('string', $type);
        }

        // Handle any Collection subclass (Eloquent, Support, etc.)
        if (is_a($value, \Illuminate\Support\Collection::class, true)) {
            $generics = $type->genericTypes();
            if (! empty($generics)) {
                $valueGeneric = end($generics);
                $itemType = static::convert($valueGeneric);
                $nullSuffix = $type->isNullable() ? ' | null' : '';
                return $itemType . '[]' . $nullSuffix;
            }
            return 'unknown[]';
        }

        $shortName = str($value)->afterLast('\\')->toString();

        return static::decorate($shortName, $type);
    }

    protected static function convertUnion(Types\UnionType $type): string
    {
        return static::convertUnionOrIntersection($type->types, '|');
    }

    protected static function convertIntersection(Types\IntersectionType $type): string
    {
        return static::convertUnionOrIntersection($type->types, '&');
    }

    protected static function convertUnionOrIntersection(array $types, string $glue): string
    {
        $result = collect($types)
            ->map(function ($item) {
                if (is_array($item)) {
                    return collect($item)
                        ->filter()
                        ->map(fn ($t) => $t instanceof Type ? static::convert($t) : (string) $t)
                        ->unique()
                        ->implode(' | ');
                }

                if ($item === null) {
                    return null;
                }

                return $item instanceof Type ? static::convert($item) : (string) $item;
            })
            ->filter()
            ->unique();

        // Simplify: if we have other types with 'unknown', keep only the non-unknown ones
        if ($result->count() > 1 && $result->contains('unknown')) {
            $withoutUnknown = $result->filter(fn ($t) => $t !== 'unknown');
            if ($withoutUnknown->count() === 1 && $withoutUnknown->first() === 'null') {
                return 'unknown';
            }
            $result = $withoutUnknown;
        }

        return $result->implode(' ' . $glue . ' ');
    }

    protected static function decorate(string $tsType, Type $type): string
    {
        if ($type->isNullable()) {
            return $tsType . ' | null';
        }

        return $tsType;
    }

    /**
     * Convert an associative array of Type objects to a TS object type string.
     * Used for inline object shapes (e.g. shared data, inertia props).
     */
    public static function convertObjectShape(array $properties, int $indent = 0): string
    {
        if (empty($properties)) {
            return 'Record<string, never>';
        }

        $spaces = str_repeat('  ', $indent + 1);
        $closingSpaces = str_repeat('  ', $indent);
        $lines = [];

        foreach ($properties as $key => $value) {
            $optional = $value instanceof Type && $value->isOptional();
            $tsType = $value instanceof Type ? static::convert($value) : (string) $value;
            $separator = $optional ? '?: ' : ': ';

            // Re-indent nested multi-line types so inner content aligns properly
            if (str_contains($tsType, PHP_EOL)) {
                $typeParts = explode(PHP_EOL, $tsType);
                $tsType = $typeParts[0] . PHP_EOL . implode(PHP_EOL, array_map(
                    fn (string $line) => $line !== '' ? $spaces . $line : $line,
                    array_slice($typeParts, 1),
                ));
            }

            $lines[] = $spaces . $key . $separator . $tsType;
        }

        return '{' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL . $closingSpaces . '}';
    }
}
