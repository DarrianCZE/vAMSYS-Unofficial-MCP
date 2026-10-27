<?php

declare(strict_types=1);

namespace VamsysMcp\Tools;

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use VamsysMcp\Exception\VamsysMcpException;
use VamsysMcp\VamsysClient;

/**
 * Answers "which routes match X?" with a compact summary instead of the raw
 * route objects. Airport/tag filters are applied by vAMSYS; missing_field is
 * applied here while paging through the results.
 */
final class FindRoutesTool
{
    public const string NAME = 'find_routes';
    public const string DESCRIPTION = 'Find routes of a vAMSYS airline and return a compact summary (not the full route data). '
        . 'Filter by departure/arrival airport (ICAO or IATA code), tag, and/or a field that is empty. '
        . 'Tags are case-sensitive. At least one filter is recommended for large route networks.';

    /** RouteData fields that missing_field can check (null, empty string or empty list counts as missing). */
    public const array MISSING_FIELDS = [
        'callsign', 'flight_number', 'departure_time', 'arrival_time', 'flight_length', 'flight_distance',
        'altitude', 'cost_index', 'route', 'remarks', 'tag', 'service_days', 'start_date', 'end_date',
        'fleet_ids', 'loadfactor_ids', 'container_ids',
    ];
    public const int MAX_LIMIT = 50;

    public function __construct(private readonly VamsysClient $client)
    {
    }

    /**
     * @param string      $airline       Airline key from list_airlines
     * @param string|null $dep           Departure airport ICAO (4 letters) or IATA (3 letters) code
     * @param string|null $arr           Arrival airport ICAO (4 letters) or IATA (3 letters) code
     * @param string|null $tag           Only routes that have this tag (case-sensitive; comma-separated = must have all)
     * @param string|null $missing_field Only routes where this field is empty
     * @param int         $limit         Maximum number of routes to return (1-50)
     */
    public function __invoke(
        string $airline,
        ?string $dep = null,
        ?string $arr = null,
        ?string $tag = null,
        #[Schema(definition: [
            'type' => ['null', 'string'],
            'enum' => [null, ...self::MISSING_FIELDS],
            'description' => 'Only routes where this field is empty',
        ])]
        ?string $missing_field = null,
        #[Schema(minimum: 1, maximum: self::MAX_LIMIT)]
        int $limit = 20,
    ): CallToolResult {
        if ($missing_field !== null && !in_array($missing_field, self::MISSING_FIELDS, true)) {
            throw new ToolCallException('missing_field must be one of: ' . implode(', ', self::MISSING_FIELDS) . '.');
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        try {
            return ToolResult::of($this->find($airline, $dep, $arr, $tag, $missing_field, $limit));
        } catch (VamsysMcpException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function find(string $airline, ?string $dep, ?string $arr, ?string $tag, ?string $missingField, int $limit): array
    {
        $query = ['sort' => 'id'];
        $airportCodes = [];
        foreach (['departure_id' => $dep, 'arrival_id' => $arr] as $filter => $code) {
            if ($code === null || trim($code) === '') {
                continue;
            }
            [$id, $icao] = $this->resolveAirport($airline, $code);
            $query["filter[{$filter}]"] = (string) $id;
            $airportCodes[$id] = $icao;
        }
        if ($tag !== null && $tag !== '') {
            $query['filter[tag]'] = $tag; // case-sensitive: passed through unchanged
        }

        $routes = [];
        $scanned = 0;
        $hasMore = false;
        $pages = $this->client->paginate($airline, '/routes', $query);
        foreach ($pages as $route) {
            ++$scanned;
            if ($missingField !== null && !self::isEmpty($route[$missingField] ?? null)) {
                continue;
            }
            if (count($routes) === $limit) {
                $hasMore = true;
                break;
            }
            $routes[] = $this->summarise($route, $airportCodes);
        }
        $scanComplete = $hasMore ? false : $pages->getReturn();

        $result = [
            'airline' => $airline,
            'returned' => count($routes),
            'has_more' => $hasMore,
            'routes_scanned' => $scanned,
            'routes' => $routes,
        ];
        if (!$hasMore && !$scanComplete) {
            $result['note'] = 'Stopped after the configured page limit before reading all routes; narrow the filters for a complete answer.';
        }

        return $result;
    }

    /**
     * @return array{0: int, 1: string|null} airport ID and ICAO code
     */
    private function resolveAirport(string $airline, string $code): array
    {
        $code = strtoupper(trim($code));
        $filter = match (strlen($code)) {
            4 => 'filter[icao]',
            3 => 'filter[iata]',
            default => throw new ToolCallException("Airport code '{$code}' must be a 4-letter ICAO or 3-letter IATA code."),
        };
        $body = $this->client->get($airline, '/airports', [$filter => $code, 'page[size]' => 1]);
        $airport = $body['data'][0] ?? null;
        if (!is_array($airport) || !isset($airport['id'])) {
            throw new ToolCallException("Airport '{$code}' is not in airline '{$airline}'s airport list.");
        }

        return [(int) $airport['id'], isset($airport['icao']) ? (string) $airport['icao'] : $code];
    }

    /**
     * @param array<mixed>            $route
     * @param array<int, string|null> $airportCodes
     *
     * @return array<string, mixed>
     */
    private function summarise(array $route, array $airportCodes): array
    {
        $depId = (int) ($route['departure_id'] ?? 0);
        $arrId = (int) ($route['arrival_id'] ?? 0);
        $summary = [
            'id' => $route['id'] ?? null,
            'flight_number' => $route['flight_number'] ?? null,
            'callsign' => $route['callsign'] ?? null,
            'dep' => $airportCodes[$depId] ?? null,
            'dep_id' => isset($airportCodes[$depId]) ? null : $depId,
            'arr' => $airportCodes[$arrId] ?? null,
            'arr_id' => isset($airportCodes[$arrId]) ? null : $arrId,
            'dep_time' => $route['departure_time'] ?? null,
            'arr_time' => $route['arrival_time'] ?? null,
            'distance_nm' => $route['flight_distance'] ?? null,
            'tags' => $route['tag'] ?? null,
            'hidden' => ($route['hidden'] ?? false) === true ? true : null,
        ];

        // Drop empty keys to keep the output small.
        return array_filter($summary, static fn ($v): bool => !self::isEmpty($v));
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
