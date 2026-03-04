<?php

namespace Rosalana\Safepoint\Generators;

use Illuminate\Support\Str;
use Laravel\Ranger\Components\Model;
use Laravel\Surveyor\Types\Contracts\Type;
use Rosalana\Safepoint\Support\SurveyorTypeConverter;

class ModelGenerator
{
    /**
     * Process a Ranger Model DTO into a structured data array.
     *
     * @return array{fqn: string, name: string, attributes: array<string, string>, attributeKeys: list<string>, relations: array<string, string>}
     */
    public function generate(Model $model): array
    {
        $shortName = str($model->name)->afterLast('\\')->toString();

        $attributes = [];
        $relations = [];

        foreach ($model->getAttributes() as $key => $type) {
            $snakeKey = $model->snakeCaseAttributes() ? Str::snake($key) : $key;
            $attributes[$snakeKey] = SurveyorTypeConverter::convert($type);
        }

        foreach ($model->getRelations() as $key => $type) {
            $snakeKey = $model->snakeCaseAttributes() ? Str::snake($key) : $key;
            $relations[$snakeKey] = SurveyorTypeConverter::convert($type);
        }

        return [
            'fqn'           => $model->name,
            'name'          => $shortName,
            'attributes'    => $attributes,
            'attributeKeys' => array_keys($attributes),
            'relations'     => $relations,
        ];
    }
}
