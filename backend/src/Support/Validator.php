<?php

declare(strict_types=1);

namespace ColoManager\Support;

use ColoManager\Http\ApiException;

/** Kleine Sammlung wiederverwendbarer Eingabeprüfungen für die Service-Schicht. */
final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param list<string> $fields
     */
    public static function required(array $data, array $fields): void
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new ApiException(422, 'Pflichtfelder fehlen.', 'validation_failed', ['missing' => $missing]);
        }
    }

    /** @param array<string, mixed> $data */
    public static function email(array $data, string $field): void
    {
        if (isset($data[$field]) && filter_var($data[$field], FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'Die E-Mail-Adresse ist ungültig.', 'validation_failed', ['field' => $field]);
        }
    }

    /** @param array<string, mixed> $data */
    public static function enum(array $data, string $field, array $allowed): void
    {
        if (isset($data[$field]) && !in_array($data[$field], $allowed, true)) {
            throw new ApiException(422, sprintf('Ungültiger Wert für %s.', $field), 'validation_failed', [
                'field' => $field,
                'allowed' => $allowed,
            ]);
        }
    }

    /** Prüft optionale Zahlenfelder inklusive fachlicher Unter- und Obergrenzen. */
    public static function number(array $data, string $field, float $minimum, ?float $maximum = null): void
    {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            return;
        }
        $value = $data[$field];
        if (!is_numeric($value) || (float) $value < $minimum || ($maximum !== null && (float) $value > $maximum)) {
            throw new ApiException(422, sprintf('Ungültiger Zahlenwert für %s.', $field), 'validation_failed', [
                'field' => $field,
                'minimum' => $minimum,
                'maximum' => $maximum,
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    public static function only(array $data, array $allowed): array
    {
        return array_intersect_key($data, array_flip($allowed));
    }
}
