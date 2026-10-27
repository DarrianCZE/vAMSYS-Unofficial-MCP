<?php

declare(strict_types=1);

namespace VamsysMcp\Tools;

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use VamsysMcp\Cache\RouteCache;
use VamsysMcp\Cache\RouteFilter;
use VamsysMcp\Cache\RouteRecord;
use VamsysMcp\Cache\RouteSet;
use VamsysMcp\Exception\VamsysMcpException;
use VamsysMcp\VamsysClient;

/**
 * Answers "which routes match X?" with compact summaries. Uses a fresh cached
 * set when there is one (complete answer, no API calls). With an airport,
 * flight number or callsign filter it reads the (small) filtered set
 * completely and caches it; otherwise it pages through vAMSYS with
 * server-side filters and stops as soon as the requested page of matches is
 * found.
 */
final class FindRoutesTool
{
    public const string NAME = 'find_routes';
    public const string DESCRIPTION = 'Find routes of a vAMSYS airline and return compact summaries (not full route data; use get_route for one route\'s details). '
        . 'Filters: dep/arr airport (ICAO or IATA; both_directions=true for both ways between dep and arr), tag (case-sensitive; comma-separated = must have all), '
        . 'flight_number, callsign (exact), hidden (true/false), missing_field (field is empty). '
        . 'Cost: dep/arr, flight_number and callsign are fast; other filters page through vAMSYS until enough matches are found. '
        . 'Each route has dep/arr as ICAO codes (dep_iata/arr_iata when known; dep_id/arr_id with *_unresolved=true if a code is unknown). '
        . 'Result fields: returned = routes in this answer; has_more = more matches exist, next_offset = the offset to pass for the next page; '
        . 'complete = true if every relevant route was examined (then matched = exact number of matches); scanned = routes examined; '
        . 'total_routes = size of the airline\'s whole route set (only when the full set was used). '
        . 'source = cache (recently read set), scan (full set read now because refresh=true without other filters), live (read now). '
        . 'If a scan paused, call again with the same arguments after retry_after_seconds. For "how many" questions use count_routes instead.';

    public function __construct(
        private readonly VamsysClient $client,
        private readonly RouteCache $routes,
        private readonly RouteQuery $query,
        private readonly int $maxPagesPerCall = 10,
    ) {
    }

    /**
     * @param string|null $airline         Airline key from list_airlines (may be omitted when only one airline is configured)
     * @param string|null $dep             Departure airport ICAO (4 letters) or IATA (3 letters) code
     * @param string|null $arr             Arrival airport ICAO (4 letters) or IATA (3 letters) code
     * @param bool        $both_directions Also routes arr→dep (needs dep and arr)
     * @param string|null $tag             Only routes that have this tag (case-sensitive; comma-separated = must have all)
     * @param string|null $flight_number   Only routes with this flight number (exact)
     * @param string|null $callsign        Only routes with this callsign (exact)
     * @param bool|null   $hidden          true = only hidden routes, false = only visible routes
     * @param string|null $missing_field   Only routes where this field is empty
     * @param int         $limit           Maximum number of routes to return (1-50)
     * @param int         $offset          Skip this many matches (use next_offset from the previous answer)
     * @param bool        $refresh         Don't use cached route sets; read from vAMSYS (without other filters this re-reads the full route set: slower, uses API quota)
     */
    public function __invoke(
        ?string $airline = null,
        ?string $dep = null,
        ?string $arr = null,
        bool $both_directions = false,
        ?string $tag = null,
        ?string $flight_number = null,
        ?string $callsign = null,
        ?bool $hidden = null,
        #[Schema(definition: [
            'type' => ['null', 'string'],
            'enum' => [null, ...RouteRecord::MISSING_FIELDS],
            'description' => 'Only routes where this field is empty',
        ])]
        ?string $missing_field = null,
        #[Schema(minimum: 1, maximum: 50)]
        int $limit = 20,
        #[Schema(minimum: 0)]
        int $offset = 0,
        bool $refresh = false,
    ): CallToolResult {
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $airline = $this->query->airline($airline);
        try {
            [$filter, $echo] = $this->query->filter($airline, $dep, $arr, $tag, $missing_field, $both_directions, $flight_number, $callsign, $hidden);
            $set = (!$refresh ? $this->routes->fresh($airline, $filter) : null) ?? match (true) {
                $filter->isNarrow() => $this->routes->forFilter($airline, $filter, $refresh),
                $refresh && $filter->serverQueries() === [] => $this->routes->scan($airline, [], true),
                default => null,
            };

            return ToolResult::of($set !== null
                ? $this->fromSet($airline, $echo, $set, $filter, $limit, $offset)
                : $this->live($airline, $echo, $filter, $limit, $offset));
        } catch (VamsysMcpException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $echo
     *
     * @return array<string, mixed>
     */
    private function fromSet(string $airline, array $echo, RouteSet $set, RouteFilter $filter, int $limit, int $offset): array
    {
        $matched = array_values(array_filter($set->routes, $filter->matches(...)));
        $result = [
            'airline' => $airline,
            'filters' => $echo,
            'source' => $set->fromCache ? 'cache' : ($set->filtered ? 'live' : 'scan'),
            'complete' => $set->complete,
            'scanned' => count($set->routes),
        ];
        if ($set->complete) {
            $result += ($set->filtered ? [] : ['total_routes' => count($set->routes)]) + ['matched' => count($matched), 'as_of' => $set->fetchedAt . ' UTC'];
        }
        $page = array_slice($matched, $offset, $limit);
        $hasMore = count($matched) > $offset + $limit;
        $result += ['returned' => count($page), 'has_more' => $hasMore];
        if ($hasMore) {
            $result['next_offset'] = $offset + $limit;
        }
        $result['routes'] = $this->query->summarise($airline, $page);
        if (!$set->complete) {
            $result += $set->pauseFields($this->routes->ttlSeconds());
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $echo
     *
     * @return array<string, mixed>
     */
    private function live(string $airline, array $echo, RouteFilter $filter, int $limit, int $offset): array
    {
        $found = [];
        $skipped = 0;
        $scanned = 0;
        $hasMore = false;
        $pages = $this->client->paginate($airline, '/routes', ['sort' => 'id'] + ($filter->serverQueries()[0] ?? []), $this->maxPagesPerCall);
        foreach ($pages as $raw) {
            ++$scanned;
            $route = RouteRecord::project((array) $raw);
            if (!$filter->matches($route)) {
                continue;
            }
            if ($skipped < $offset) {
                ++$skipped;
                continue;
            }
            if (count($found) === $limit) {
                $hasMore = true;
                break;
            }
            $found[] = $route;
        }
        $complete = !$hasMore && $pages->getReturn();

        $result = ['airline' => $airline, 'filters' => $echo, 'source' => 'live', 'complete' => $complete, 'scanned' => $scanned];
        if ($complete) {
            $result['matched'] = $skipped + count($found);
        }
        $result += ['returned' => count($found), 'has_more' => $hasMore];
        if ($hasMore) {
            $result['next_offset'] = $offset + $limit;
        }
        $result['routes'] = $this->query->summarise($airline, $found);
        if (!$complete && !$hasMore) {
            $result['note'] = 'Stopped at the page limit before examining all routes; use count_routes or narrow the filters for a complete answer.';
        }

        return $result;
    }
}
