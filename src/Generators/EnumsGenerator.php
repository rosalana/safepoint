<?php

namespace Rosalana\Safepoint\Generators;

use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Enum;

class EnumsGenerator
{
    public function generate(Collection $enums): array
    {
        $result = [];

        foreach ($enums as $enum) {
            array_push($result, $this->processEnum($enum));
        }

        return $result;
    }

    private function processEnum(Enum $enum): array
    {
        $name = str($enum->name)->afterLast('\\')->toString() . 'Enum';

        return [
            'fqn' => $enum->name,
            'name' => $name,
            'cases' => $enum->cases,
        ];
    }
}
