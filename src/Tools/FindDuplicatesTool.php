<?php

declare(strict_types=1);

namespace VamsysMcp\Tools;

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use VamsysMcp\Cache\RouteCache;
use VamsysMcp\Exception\VamsysMcpException;

/**
 * Finds routes that look like duplicates: same dep/arr, flight number and
 * times (by=schedule), or the same flight number on several routes
 * (by=flight_number). Works on the cached/scanned route set, narrowed by the
 * usual filters.
 */
final class FindDuplicatesTool
{
    public const string NAME = 'find_duplicates';
    public const string DESCRIPTION = 'Find duplicate routes of a vAMSYS airline. by=schedule (default): routes with the same dep, arr, flight number, departure and arrival time. '
        . 'by=flight_number: flight numbers used by more than one route (may be legitimate, e.g. multi-leg). '
        . 'Optional dep/arr/tag/hidden narrow the routes checked (dep/arr make it fast); without them the airline\'s whole route set is read, '
        . 'which for large airlines can take 2 or more calls (same complete/scanned/retry_after_seconds fields as count_routes; call again with the same arguments to continue). '
        . 'Result: groups_found, duplicate_routes (routes in all groups), groups (largest first, up to limit) with route IDs for get_route.';

    public function __construct(
        private readonly RouteCache $routes,
        private readonly RouteQuery $query,
    ) {
    }

    /**
     * @param string|null $airline Airline key from list_airlines (may be omitted when only one airline is configured)
     * @param string      $by      schedule (same dep, arr, flight number and times) or flight_number
     * @param string|null $dep     Only routes departing here (ICAO or IATA)
     * @param string|null $arr     Only routes arriving here (ICAO or IATA)
     * @param string|null $tag     Only routes that have this tag (case-sensitive; comma-separated = must have all)
     * @param bool|null   $hidden  true = only hidden routes, false = only visible routes
     * @param int         $limit   Maximum number of groups to return (1-50)
     * @param bool        $refresh Ignore cached route sets and read from vAMSYS
     */
    public function __invoke(
        ?string $airline = null,
        #[Schema(definition: ['type' => 'string', 'enum' => ['schedule', 'flight_number'], 'default' => 'schedule', 'description' => 'schedule (same dep, arr, flight number and times) or flight_number'])]
        string $by = 'schedule',
        ?string $dep = null,
        ?string $arr = null,
        ?string $tag = null,
        ?bool $hidden = null,
        #[Schema(minimum: 1, maximum: 50)]
        int $limit = 20,
        bool $refresh = false,
    ): CallToolResult {
        if (!in_array($by, ['schedule', 'flight_number'], true)) {
            throw new ToolCallException('by must be schedule or flight_number.');
        }
        $limit = max(1, min(50, $limit));
        $airline = $this->query->airline($airline);
        try {
            [$filter, $echo] = $this->query->filter($airline, $dep, $arr, $tag, hidden: $hidden);
            $set = $this->routes->forFilter($airline, $filter, $refresh);
        } catch (VamsysMcpException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }

        $groups = [];
        foreach (array_filter($set->routes, $filter->matches(...)) as $r) {
            $fn = strtoupper((string) $r['flight_number']);
            if ($by === 'flight_number' && $fn === '') {
                continue;
            }
            $key = $by === 'schedule'
                ? implode('|', [$r['departure_id'], $r['arrival_id'], $fn, $r['departure_time'], $r['arrival_time']])
                : $fn;
            $groups[$key][] = $r;
        }
        $groups = array_values(array_filter($groups, static fn (array $g): bool => count($g) > 1));
        usort($groups, static fn (array $a, array $b): int => [count($b), (string) $a[0]['flight_number']] <=> [count($a), (string) $b[0]['flight_number']]);

        $result = [
            'airline' => $airline,
            'filters' => $echo,
            'by' => $by,
            'complete' => $set->complete,
            'scanned' => count($set->routes),
            'source' => $set->fromCache ? 'cache' : ($set->filtered ? 'live' : 'scan'),
        ];
        if ($set->complete && !$set->filtered) {
            $result['total_routes'] = count($set->routes);
        }
        $result += [
            'groups_found' => count($groups),
            'duplicate_routes' => array_sum(array_map('count', $groups)),
            'has_more' => count($groups) > $limit,
            'groups' => array_map(fn (array $g): array => $this->group($airline, $by, $g), array_slice($groups, 0, $limit)),
        ];
        if (!$set->complete) {
            $result += $set->pauseFields($this->routes->ttlSeconds());
        }

        return ToolResult::of($result);
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return array<string, mixed>
     */
    private function group(string $airline, string $by, array $routes): array
    {
        if ($by === 'schedule') {
            $first = $routes[0];

            return array_filter(['flight_number' => $first['flight_number']]
                + $this->query->endpoints($airline, $first['departure_id'], $first['arrival_id'])
                + ['dep_time' => $first['departure_time'], 'arr_time' => $first['arrival_time'], 'route_ids' => array_column($routes, 'id')], static fn ($v): bool => $v !== null);
        }

        return [
            'flight_number' => $routes[0]['flight_number'],
            'count' => count($routes),
            'routes' => array_map(static fn (array $s): array => array_intersect_key($s, array_flip(['id', 'dep', 'arr', 'dep_id', 'arr_id', 'dep_time', 'arr_time'])), $this->query->summarise($airline, $routes)),
        ];
    }
}
