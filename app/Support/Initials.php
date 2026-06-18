<?php

namespace App\Support;

use Illuminate\Support\Str;

class Initials
{
    /** Duas primeiras iniciais de um nome (ex.: "Maria Silva" -> "MS"). */
    public static function of(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name);
        $first = mb_substr($parts[0], 0, 1);
        $second = isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '';

        return Str::upper($first . $second);
    }
}
