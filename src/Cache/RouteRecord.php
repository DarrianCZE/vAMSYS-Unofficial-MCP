<?php

declare(strict_types=1);

namespace VamsysMcp\Cache;

/**
 * Compact projection of a vAMSYS RouteData object: the fields the tools show
 * or filter on, plus the names of fields that are empty (for missing_field).
 * This is what the route cache stores; internal remarks, SimBrief options
 * etc. are never kept.
 */
final class RouteRecord
{
    /** RouteData fields that missing_field can check (null, empty string or empty list counts as missing). */
    public const array MISSING_FIELDS = [
        'callsign', 'flight_number', 'departure_time', 'arrival_time', 'flight_length', 'flight_distance',
        'altitude', 'cost_index', 'route', 'remarks', 'tag', 'service_days', 'start_date', 'end_date',
        'fleet_ids', 'loadfactor_ids', 'container_ids',
    ];

    /**
     * @param array<mixed> $route RouteData from GET /routes
     *
     * @return array{id: int, flight_number: string|null, callsign: string|null, departure_id: int, arrival_id: int,
     *               departure_time: string|null, arrival_time: string|null, flight_distance: int|null,
     *               tag: list<string>, hidden: bool, empty: list<string>}
     */
    public static function project(array $route): array
    {
        $empty = [];
        foreach (self::MISSING_FIELDS as $field) {
            if (self::isEmpty($route[$field] ?? null)) {
                $empty[] = $field;
            }
        }

        return [
            'id' => (int) ($route['id'] ?? 0),
            'flight_number' => self::str($route['flight_number'] ?? null),
            'callsign' => self::str($route['callsign'] ?? null),
            'departure_id' => (int) ($route['departure_id'] ?? 0),
            'arrival_id' => (int) ($route['arrival_id'] ?? 0),
            'departure_time' => self::str($route['departure_time'] ?? null),
            'arrival_time' => self::str($route['arrival_time'] ?? null),
            'flight_distance' => isset($route['flight_distance']) ? (int) $route['flight_distance'] : null,
            'tag' => array_values(array_map('strval', array_filter((array) ($route['tag'] ?? []), 'is_scalar'))),
            'hidden' => ($route['hidden'] ?? false) === true,
            'empty' => $empty,
        ];
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
