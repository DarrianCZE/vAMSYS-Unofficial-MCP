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
 * Answers "which routes match X?" with compact summaries. Uses the cached full
 * route set when it is fresh (complete answer, no API calls); otherwise pages
 * through vAMSYS with server-side airport/tag filters and stops as soon as
 * `limit` matches are found.
 */
final class FindRoutesTool
{
    public const string NAME = 'find_routes';
    public const string DESCRIPTION = 'Find routes of a vAMSYS airline and return compact summaries (not full route data). '
        . 'Filters: dep/arr airport (ICAO or IATA), tag (case-sensitive; comma-separated = must have all), missing_field (field is empty). '
        . 'Each route has dep/arr as ICAO codes (dep_iata/arr_iata when known; dep_id/arr_id with *_unresolved=true if a code is unknown). '
        . 'Result fields: returned = routes in this answer; has_more = more matches exist beyond limit; '
        . 'complete = true if every relevant route was examined (then matched = exact number of matches); '
        . 'scanned = routes examined; total_routes = size of the airline\'s whole route set (only when the cached full set was used). '
        . 'source = cache (recent full set), scan (full set read now because refresh=true without dep/arr) or live (paged search that stops early). '
        . 'If a scan paused, follow the note (retry_after_seconds says when to call again). '
        . 'For "how many" questions use count_routes instead.';

    public function __construct(
        private readonly VamsysClient $client,
        private readonly RouteCache $routes,
        private readonly RouteQuery $query,
        private readonly int $maxPagesPerCall = 10,
        private readonly int $routeCacheTtl = 600,
    ) {
    }

    /**
     * @param string      $airline       Airline key from list_airlines
     * @param string|null $dep           Departure airport ICAO (4 letters) or IATA (3 letters) code
     * @param string|null $arr           Arrival airport ICAO (4 letters) or IATA (3 letters) code
     * @param string|null $tag           Only routes that have this tag (case-sensitive; comma-separated = must have all)
     * @param string|null $missing_field Only routes where this field is empty
     * @param int         $limit         Maximum number of routes to return (1-50)
     * @param bool        $refresh       Don't use the cached route set; read from vAMSYS (with dep/arr a quick live search, otherwise the full route set: slower, uses API quota)
     */
    public function __invoke(
        string $airline,
        ?string $dep = null,
        ?string $arr = null,
        ?string $tag = null,
        #[Schema(definition: [
            'type' => ['null', 'string'],
            'enum' => [null, ...RouteRecord::MISSING_FIELDS],
            'description' => 'Only routes where this field is empty',
        ])]
        ?string $missing_field = null,
        #[Schema(minimum: 1, maximum: 50)]
        int $limit = 20,
        bool $refresh = false,
    ): CallToolResult {
        $limit = max(1, min(50, $limit));
        try {
            [$filter, $echo] = $this->query->filter($airline, $dep, $arr, $tag, $missing_field);
            // refresh=true re-reads the full set only when vAMSYS can't filter by airport: a live search is fresh anyway.
            $set = match (true) {
                !$refresh => $this->routes->fresh($airline),
                $filter->isNarrow() => null,
                default => $this->routes->get($airline, true),
            };

            return ToolResult::of($set !== null
                ? $this->fromSet($airline, $echo, $set, $filter, $limit)
                : $this->live($airline, $echo, $filter, $limit));
        } catch (VamsysMcpException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, string> $echo
     *
     * @return array<string, mixed>
     */
    private function fromSet(string $airline, array $echo, RouteSet $set, RouteFilter $filter, int $limit): array
    {
        $matched = array_values(array_filter($set->routes, $filter->matches(...)));
        $result = ['airline' => $airline, 'filters' => $echo, 'source' => $set->fromCache ? 'cache' : 'scan', 'complete' => $set->complete, 'scanned' => count($set->routes)];
        if ($set->complete) {
            $result += ['total_routes' => count($set->routes), 'matched' => count($matched), 'as_of' => $set->fetchedAt . ' UTC'];
        }
        $result += ['returned' => min($limit, count($matched)), 'has_more' => count($matched) > $limit, 'routes' => $this->query->summarise($airline, array_slice($matched, 0, $limit))];
        if (!$set->complete) {
            if ($set->retryAfterSeconds !== null) {
                $result['retry_after_seconds'] = $set->retryAfterSeconds;
            }
            $result['note'] = $set->pauseNote($this->routeCacheTtl);
        }

        return $result;
    }

    /**
     * @param array<string, string> $echo
     *
     * @return array<string, mixed>
     */
    private function live(string $airline, array $echo, RouteFilter $filter, int $limit): array
    {
        $found = [];
        $scanned = 0;
        $hasMore = false;
        $pages = $this->client->paginate($airline, '/routes', ['sort' => 'id'] + $filter->toQuery(), $this->maxPagesPerCall);
        foreach ($pages as $raw) {
            ++$scanned;
            $route = RouteRecord::project((array) $raw);
            if (!$filter->matches($route)) {
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
            $result['matched'] = count($found);
        }
        $result += ['returned' => count($found), 'has_more' => $hasMore, 'routes' => $this->query->summarise($airline, $found)];
        if (!$complete && !$hasMore) {
            $result['note'] = 'Stopped at the page limit before examining all routes; use count_routes or narrow the filters for a complete answer.';
        }

        return $result;
    }
}
