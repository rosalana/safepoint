<?php

namespace App\Enums;

enum RequireStatus: string
{
    case REQUIRED = 'required';
    case OPTIONAL = 'optional';
    case IGNORED = 'ignored';
}