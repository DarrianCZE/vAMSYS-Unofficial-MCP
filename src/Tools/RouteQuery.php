<?php

declare(strict_types=1);

namespace VamsysMcp\Tools;

use Mcp\Exception\ToolCallException;
use VamsysMcp\Cache\AirportCache;
use VamsysMcp\Cache\RouteFilter;
use VamsysMcp\Cache\RouteRecord;
use VamsysMcp\Config;
use VamsysMcp\Exception\VamsysMcpException;

/**
 * Shared by the route tools: resolves the airline argument, turns tool
 * arguments into a RouteFilter (airport codes resolved through the airport
 * cache) and turns routes into compact summaries with airport codes.
 */
final class RouteQuery
{
    public function __construct(
        private readonly AirportCache $airports,
        private readonly Config $config,
    ) {
    }

    /**
     * The airline to use: the argument, or the only configured airline.
     *
     * @throws ToolCallException
     */
    public function airline(?string $airline): string
    {
        try {
            return $this->config->airlineKey($airline);
        } catch (VamsysMcpException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{0: RouteFilter, 1: array<string, mixed>} the filter and the filters as understood, for the output
     */
    public function filter(
        string $airline,
        ?string $dep = null,
        ?string $arr = null,
        ?string $tag = null,
        ?string $missingField = null,
        bool $bothDirections = false,
        ?string $flightNumber = null,
        ?string $callsign = null,
        ?bool $hidden = null,
    ): array {
        if ($missingField !== null && !in_array($missingField, RouteRecord::MISSING_FIELDS, true)) {
            throw new ToolCallException('missing_field must be one of: ' . implode(', ', RouteRecord::MISSING_FIELDS) . '.');
        }
        $echo = [];
        $depId = $this->resolve($airline, $dep, 'dep', $echo);
        $arrId = $this->resolve($airline, $arr, 'arr', $echo);
        if ($bothDirections) {
            if ($depId === null || $arrId === null) {
                throw new ToolCallException('both_directions needs both dep and arr.');
            }
            $echo['both_directions'] = true;
        }
        $tags = RouteFilter::parseTags($tag);
        if ($tags !== []) {
            $echo['tag'] = implode(',', $tags);
        }
        if ($missingField !== null) {
            $echo['missing_field'] = $missingField;
        }
        $flightNumber = self::text($flightNumber);
        $callsign = self::text($callsign);
        $echo += array_filter(['flight_number' => $flightNumber, 'callsign' => $callsign, 'hidden' => $hidden], static fn (mixed $v): bool => $v !== null);

        return [new RouteFilter($depId, $arrId, $tags, $missingField, $bothDirections, $flightNumber, $callsign, $hidden), $echo];
    }

    /**
     * Airport fields for a pair of IDs (dep/arr codes, see endpoint()).
     *
     * @return array<string, mixed>
     */
    public function endpoints(string $airline, int $depId, int $arrId): array
    {
        $airports = $this->codes($airline, [$depId, $arrId]);

        return self::endpoint('dep', $depId, $airports[$depId] ?? null) + self::endpoint('arr', $arrId, $airports[$arrId] ?? null);
    }

    /**
     * ICAO (else IATA) code per airport ID; IDs that cannot be resolved are missing.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function codeMap(string $airline, array $ids): array
    {
        return array_filter(array_map(static fn (array $a): ?string => $a['icao'] ?? $a['iata'], $this->codes($airline, $ids)));
    }

    /**
     * Compact summaries: dep/arr as ICAO (plus *_iata when known); the raw ID with
     * *_unresolved only if the airport cannot be resolved. Empty fields are dropped.
     *
     * @param list<array<string, mixed>> $routes projected routes
     *
     * @return list<array<string, mixed>>
     */
    public function summarise(string $airline, array $routes): array
    {
        $ids = [];
        foreach ($routes as $r) {
            $ids[] = $r['departure_id'];
            $ids[] = $r['arrival_id'];
        }
        $airports = $this->codes($airline, $ids);

        return array_map(static function (array $r) use ($airports): array {
            $summary = ['id' => $r['id'], 'flight_number' => $r['flight_number'], 'callsign' => $r['callsign']]
                + self::endpoint('dep', $r['departure_id'], $airports[$r['departure_id']] ?? null)
                + self::endpoint('arr', $r['arrival_id'], $airports[$r['arrival_id']] ?? null)
                + [
                    'dep_time' => $r['departure_time'],
                    'arr_time' => $r['arrival_time'],
                    'distance_nm' => $r['flight_distance'],
                    'tags' => $r['tag'],
                    'hidden' => $r['hidden'] ? true : null,
                ];

            return array_filter($summary, static fn ($v): bool => !RouteRecord::isEmpty($v));
        }, $routes);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{id: int, icao: string|null, iata: string|null, name: string}>
     */
    private function codes(string $airline, array $ids): array
    {
        try {
            return $this->airports->lookup($airline, $ids);
        } catch (VamsysMcpException) {
            return []; // codes are a convenience; never fail the answer because of them
        }
    }

    private static function text(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * @param array<string, mixed> $echo
     */
    private function resolve(string $airline, ?string $code, string $label, array &$echo): ?int
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        try {
            $airport = $this->airports->findByCode($airline, $code);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage());
        }
        if ($airport === null) {
            throw new ToolCallException('Airport ' . strtoupper(trim($code)) . " is not in airline '{$airline}'s airport list.");
        }
        $echo[$label] = (string) ($airport['icao'] ?? $airport['iata']);

        return $airport['id'];
    }

    /**
     * @param array{id: int, icao: string|null, iata: string|null, name: string}|null $airport
     *
     * @return array<string, mixed>
     */
    private static function endpoint(string $prefix, int $id, ?array $airport): array
    {
        $code = $airport['icao'] ?? $airport['iata'] ?? null;
        if ($code === null) {
            return ["{$prefix}_id" => $id, "{$prefix}_unresolved" => true];
        }

        return [$prefix => $code, "{$prefix}_iata" => $airport['icao'] !== null ? $airport['iata'] : null];
    }
}
