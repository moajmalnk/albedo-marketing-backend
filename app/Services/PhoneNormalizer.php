<?php

namespace App\Services;

class PhoneNormalizer
{
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return $digits;
        }

        if (strlen($digits) === 10) {
            return '91'.$digits;
        }

        return $digits;
    }
}
