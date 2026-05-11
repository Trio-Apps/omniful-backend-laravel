<?php

namespace App\Support;

class Utf8
{
    public static function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::sanitize($item);
            }

            return $value;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = self::sanitize($item);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return self::sanitizeString($value);
    }

    public static function sanitizeString(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }

    public static function jsonEncode(mixed $value, int $flags = 0): string
    {
        $json = json_encode(
            self::sanitize($value),
            $flags | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($json) ? $json : '';
    }
}
