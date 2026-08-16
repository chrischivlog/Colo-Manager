<?php

declare(strict_types=1);

namespace ColoManager\Support;

use ColoManager\Http\ApiException;

/**
 * Normalisiert eine vollständige Rechnungsanschrift an einer zentralen Stelle.
 * Dadurch gelten für öffentliche Anfragen, Kunden und Verträge dieselben Regeln.
 */
final class BillingAddress
{
    /** @return array{street: string, postalCode: string, city: string, country: string} */
    public static function normalize(mixed $value, string $field = 'billingAddress'): array
    {
        if (!is_array($value)) {
            throw new ApiException(422, 'Bitte geben Sie eine vollständige Rechnungsanschrift an.', 'validation_failed', ['field' => $field]);
        }

        $address = [];
        foreach (['street', 'postalCode', 'city', 'country'] as $part) {
            $address[$part] = trim(strip_tags((string) ($value[$part] ?? '')));
        }
        $address['country'] = strtoupper($address['country']);

        if (mb_strlen($address['street']) < 3 || mb_strlen($address['street']) > 160) {
            self::invalid($field . '.street', 'Bitte geben Sie Straße und Hausnummer vollständig an.');
        }
        if (mb_strlen($address['postalCode']) < 2 || mb_strlen($address['postalCode']) > 16) {
            self::invalid($field . '.postalCode', 'Bitte geben Sie eine gültige Postleitzahl an.');
        }
        if (mb_strlen($address['city']) < 2 || mb_strlen($address['city']) > 100) {
            self::invalid($field . '.city', 'Bitte geben Sie einen gültigen Ort an.');
        }
        if (preg_match('/^[A-Z]{2}$/', $address['country']) !== 1) {
            self::invalid($field . '.country', 'Bitte wählen Sie ein gültiges Land aus.');
        }

        return $address;
    }

    public static function isComplete(mixed $value): bool
    {
        try {
            self::normalize($value);
            return true;
        } catch (ApiException) {
            return false;
        }
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ApiException(422, $message, 'validation_failed', ['field' => $field]);
    }
}
