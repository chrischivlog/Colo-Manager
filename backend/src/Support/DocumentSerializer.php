<?php

declare(strict_types=1);

namespace ColoManager\Support;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/** Wandelt BSON-spezifische Typen rekursiv in saubere JSON-Werte um. */
final class DocumentSerializer
{
    public static function serialize(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string) $value;
        }

        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if (is_array($value)) {
            $serialized = [];
            foreach ($value as $key => $item) {
                $serialized[$key === '_id' ? 'id' : $key] = self::serialize($item);
            }
            return $serialized;
        }

        if (is_object($value)) {
            return self::serialize((array) $value);
        }

        return $value;
    }
}
