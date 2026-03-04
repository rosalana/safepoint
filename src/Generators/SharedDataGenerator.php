<?php

namespace Rosalana\Safepoint\Generators;

use Laravel\Ranger\Components\InertiaSharedData;
use Rosalana\Safepoint\Support\SurveyorTypeConverter;

class SharedDataGenerator
{
    /**
     * Convert the InertiaSharedData component into a TS type string.
     * The returned string is the content *inside* the SharedData interface body.
     */
    public function generate(InertiaSharedData $data): string
    {
        // $data->data is an ArrayType with an associative value array
        $properties = $data->data->value;

        return SurveyorTypeConverter::convertObjectShape($properties);
    }
}
