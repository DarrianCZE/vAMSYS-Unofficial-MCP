<?php

declare(strict_types=1);

namespace VamsysMcp\Tools;

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use VamsysMcp\Cache\RouteRecord;
use VamsysMcp\Exception\VamsysMcpException;
use VamsysMcp\VamsysClient;

/**
 * Full details of one route (GET /routes/{id}), with airport codes. Internal
 * remarks and SimBrief/callsign option blobs are left out.
 */
final class GetRouteTool
{
    public const string NAME = 'get_route';
    public const string DESCRIPTION = 'Get the details of one route by its ID (the id from find_routes or find_duplicates): flight number, callsign, '
        . 'dep/arr as ICAO codes, times, flight length, distance, altitude, cost index, routing, remarks, tags, service days, start/end dates, '
        . 'fleet/load factor/container IDs, hidden, created/updated. One API request.';

    public function __construct(
        private readonly VamsysClient $client,
        private readonly RouteQuery $query,
    ) {
    }

    /**
     * @param int         $id      Route ID
     * @param string|null $airline Airline key from list_airlines (may be omitted when only one airline is configured)
     */
    public function __invoke(
        #[Schema(minimum: 1)]
        int $id,
        ?string $airline = null,
    ): CallToolResult {
        if ($id < 1) {
            throw new ToolCallException('id must be a positive route ID.');
        }
        $airline = $this->query->airline($airline);
        try {
            $route = (array) ($this->client->get($airline, "/routes/{$id}")['data'] ?? []);
        } catch (VamsysMcpException $e) {
            $message = str_contains($e->getMessage(), 'HTTP 404') ? "Route {$id} does not exist in airline '{$airline}'." : $e->getMessage();
            throw new ToolCallException($message, 0, $e);
        }
        if (!isset($route['id'])) {
            throw new ToolCallException("vAMSYS returned no data for route {$id}.");
        }

        $details = ['id' => (int) $route['id'], 'type' => $route['type'] ?? null, 'flight_number' => $route['flight_number'] ?? null, 'callsign' => $route['callsign'] ?? null]
            + $this->query->endpoints($airline, (int) ($route['departure_id'] ?? 0), (int) ($route['arrival_id'] ?? 0))
            + [
                'dep_time' => $route['departure_time'] ?? null,
                'arr_time' => $route['arrival_time'] ?? null,
                'flight_length' => $route['flight_length'] ?? null,
                'distance_nm' => $route['flight_distance'] ?? null,
                'altitude' => $route['altitude'] ?? null,
                'cost_index' => $route['cost_index'] ?? null,
                'routing' => $route['route'] ?? null,
                'remarks' => $route['remarks'] ?? null,
                'tags' => $route['tag'] ?? null,
                'service_days' => $route['service_days'] ?? null,
                'start_date' => $route['start_date'] ?? null,
                'end_date' => $route['end_date'] ?? null,
                'fleet_ids' => $route['fleet_ids'] ?? null,
                'loadfactor_ids' => $route['loadfactor_ids'] ?? null,
                'container_ids' => $route['container_ids'] ?? null,
                'hidden' => ($route['hidden'] ?? false) === true ? true : null,
                'created_at' => $route['created_at'] ?? null,
                'updated_at' => $route['updated_at'] ?? null,
            ];

        return ToolResult::of(['airline' => $airline, 'route' => array_filter($details, static fn ($v): bool => !RouteRecord::isEmpty($v))]);
    }
}
