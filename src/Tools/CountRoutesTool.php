<?php

declare(strict_types=1);

namespace VamsysMcp\Tools;

use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use VamsysMcp\Cache\RouteCache;
use VamsysMcp\Cache\RouteRecord;
use VamsysMcp\Exception\VamsysMcpException;

/**
 * Answers "how many routes …?" without listing them. Needs the airline's full
 * route set, which comes from the short-lived route cache or a resumable scan
 * that respects the per-airline request cap.
 */
final class CountRoutesTool
{
    public const string NAME = 'count_routes';
    public const string DESCRIPTION = 'Count routes of a vAMSYS airline matching filters, without listing them. Same filters as find_routes '
        . '(dep/arr ICAO or IATA, tag case-sensitive with comma = all, missing_field). '
        . 'Result: matched = routes matching the filters; scanned = routes examined; total_routes = size of the whole route set (null if not complete); '
        . 'complete = true when every route was examined, so matched is exact. If complete is false the scan paused (request cap or time limit) '
        . 'and matched only covers the scanned routes: call count_routes again to continue (progress is kept). '
        . 'Optional breakdown: "tag" (matched routes per tag, top 15) or "visibility" (hidden vs visible). '
        . 'Uses a cached route set for a few minutes; refresh=true re-reads it from vAMSYS.';
    private const int MAX_TAGS = 15;

    public function __construct(
        private readonly RouteCache $routes,
        private readonly RouteQuery $query,
        private readonly int $routeCacheTtl = 600,
    ) {
    }

    /**
     * @param string      $airline       Airline key from list_airlines
     * @param string|null $dep           Departure airport ICAO (4 letters) or IATA (3 letters) code
     * @param string|null $arr           Arrival airport ICAO (4 letters) or IATA (3 letters) code
     * @param string|null $tag           Only routes that have this tag (case-sensitive; comma-separated = must have all)
     * @param string|null $missing_field Only routes where this field is empty
     * @param string|null $breakdown     Optional breakdown of the matched routes: "tag" or "visibility"
     * @param bool        $refresh       Re-read the full route set from vAMSYS instead of using the cache
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
        #[Schema(definition: [
            'type' => ['null', 'string'],
            'enum' => [null, 'tag', 'visibility'],
            'description' => 'Optional breakdown of the matched routes: "tag" or "visibility"',
        ])]
        ?string $breakdown = null,
        bool $refresh = false,
    ): CallToolResult {
        if ($breakdown !== null && !in_array($breakdown, ['tag', 'visibility'], true)) {
            throw new ToolCallException('breakdown must be "tag" or "visibility".');
        }
        try {
            [$filter, $echo] = $this->query->filter($airline, $dep, $arr, $tag, $missing_field);
            $set = $this->routes->get($airline, $refresh);
        } catch (VamsysMcpException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }

        $matched = array_values(array_filter($set->routes, $filter->matches(...)));
        $result = [
            'airline' => $airline,
            'filters' => $echo,
            'matched' => count($matched),
            'scanned' => count($set->routes),
            'total_routes' => $set->complete ? count($set->routes) : null,
            'complete' => $set->complete,
            'source' => $set->fromCache ? 'cache' : 'scan',
        ];
        if ($set->complete) {
            $result['as_of'] = $set->fetchedAt . ' UTC';
        }
        if ($breakdown === 'tag') {
            $result['by_tag'] = self::byTag($matched);
        } elseif ($breakdown === 'visibility') {
            $hidden = count(array_filter($matched, static fn (array $r): bool => $r['hidden']));
            $result['by_visibility'] = ['visible' => count($matched) - $hidden, 'hidden' => $hidden];
        }
        if (!$set->complete) {
            $result['note'] = $set->pauseNote($this->routeCacheTtl);
        }

        return ToolResult::of($result);
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return array<string, int>
     */
    private static function byTag(array $routes): array
    {
        $counts = [];
        $untagged = 0;
        foreach ($routes as $r) {
            if ($r['tag'] === []) {
                ++$untagged;
            }
            foreach (array_unique($r['tag']) as $t) {
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            }
        }
        arsort($counts);
        $top = array_slice($counts, 0, self::MAX_TAGS, true);
        if (count($counts) > self::MAX_TAGS) {
            $top['(other tags)'] = array_sum(array_slice($counts, self::MAX_TAGS));
        }
        if ($untagged > 0) {
            $top['(no tag)'] = $untagged;
        }

        return $top;
    }
}
