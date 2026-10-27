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
 * Answers "how many routes …?" without listing them. Filters vAMSYS supports
 * are applied server-side, so only matching routes are read; questions it
 * can't filter (missing_field, none) need the airline's full route set. Every
 * set comes from the short-lived cache or a resumable scan that respects the
 * per-airline request cap.
 */
final class CountRoutesTool
{
    public const string NAME = 'count_routes';
    public const string DESCRIPTION = 'Count routes of a vAMSYS airline matching filters, without listing them. Same filters as find_routes. '
        . 'Cost: dep/arr, flight_number and callsign are fast (vAMSYS filters them; usually one request). tag and hidden are filtered by vAMSYS too, '
        . 'but a common tag can still mean many routes. Only missing_field or no filter at all needs the airline\'s whole route set, '
        . 'which for large airlines can take 2 or more calls. Any recently read set is reused for a few minutes. Don\'t run several counts for one airline in parallel. '
        . 'Result: matched = routes matching the filters; scanned = routes examined; total_routes = size of the whole route set (only when it was read completely); '
        . 'complete = true when every relevant route was examined, so matched is exact. source = cache (recently read), scan (whole set read now) or live (filtered set read now). '
        . 'If complete is false, the scan paused: matched only covers the scanned routes; call again with the same arguments after retry_after_seconds (progress is kept; '
        . 'progress = estimated share done). Optional breakdown: tag (top 15 tags), visibility (hidden vs visible), dep or arr (top 15 airports). '
        . 'With both_directions the result also has by_direction. refresh=true ignores cached sets.';
    private const int TOP = 15;

    public function __construct(
        private readonly RouteCache $routes,
        private readonly RouteQuery $query,
    ) {
    }

    /**
     * @param string|null $airline         Airline key from list_airlines (may be omitted when only one airline is configured)
     * @param string|null $dep             Departure airport ICAO (4 letters) or IATA (3 letters) code
     * @param string|null $arr             Arrival airport ICAO (4 letters) or IATA (3 letters) code
     * @param bool        $both_directions Count dep→arr and arr→dep together (needs dep and arr)
     * @param string|null $tag             Only routes that have this tag (case-sensitive; comma-separated = must have all)
     * @param string|null $flight_number   Only routes with this flight number (exact)
     * @param string|null $callsign        Only routes with this callsign (exact)
     * @param bool|null   $hidden          true = only hidden routes, false = only visible routes
     * @param string|null $missing_field   Only routes where this field is empty
     * @param string|null $breakdown       Optional breakdown of the matched routes: tag, visibility, dep or arr
     * @param bool        $refresh         Ignore cached route sets and read from vAMSYS
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
        #[Schema(definition: [
            'type' => ['null', 'string'],
            'enum' => [null, 'tag', 'visibility', 'dep', 'arr'],
            'description' => 'Optional breakdown of the matched routes: tag, visibility, dep or arr',
        ])]
        ?string $breakdown = null,
        bool $refresh = false,
    ): CallToolResult {
        if ($breakdown !== null && !in_array($breakdown, ['tag', 'visibility', 'dep', 'arr'], true)) {
            throw new ToolCallException('breakdown must be tag, visibility, dep or arr.');
        }
        $airline = $this->query->airline($airline);
        try {
            [$filter, $echo] = $this->query->filter($airline, $dep, $arr, $tag, $missing_field, $both_directions, $flight_number, $callsign, $hidden);
            $set = $this->routes->forFilter($airline, $filter, $refresh);
        } catch (VamsysMcpException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }

        $matched = array_values(array_filter($set->routes, $filter->matches(...)));
        $result = [
            'airline' => $airline,
            'filters' => $echo,
            'matched' => count($matched),
            'scanned' => count($set->routes),
            'total_routes' => $set->complete && !$set->filtered ? count($set->routes) : null,
            'complete' => $set->complete,
            'source' => $set->fromCache ? 'cache' : ($set->filtered ? 'live' : 'scan'),
        ];
        if ($set->complete) {
            $result['as_of'] = $set->fetchedAt . ' UTC';
        }
        if ($both_directions) {
            $forward = count(array_filter($matched, static fn (array $r): bool => $r['departure_id'] === $filter->departureId));
            $result['by_direction'] = [
                "{$echo['dep']}-{$echo['arr']}" => $forward,
                "{$echo['arr']}-{$echo['dep']}" => count($matched) - $forward,
            ];
        }
        $result += match ($breakdown) {
            'tag' => ['by_tag' => self::byTag($matched)],
            'visibility' => ['by_visibility' => [
                'visible' => count(array_filter($matched, static fn (array $r): bool => !$r['hidden'])),
                'hidden' => count(array_filter($matched, static fn (array $r): bool => $r['hidden'])),
            ]],
            'dep', 'arr' => ["by_{$breakdown}" => $this->byAirport($airline, $matched, $breakdown === 'dep' ? 'departure_id' : 'arrival_id')],
            default => [],
        };
        if (!$set->complete) {
            $result += $set->pauseFields($this->routes->ttlSeconds());
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
        $top = self::top($counts, '(other tags)');
        if ($untagged > 0) {
            $top['(no tag)'] = $untagged;
        }

        return $top;
    }

    /**
     * Top airports by route count, as ICAO codes (#ID if a code is unknown).
     *
     * @param list<array<string, mixed>> $routes
     *
     * @return array<string, int>
     */
    private function byAirport(string $airline, array $routes, string $field): array
    {
        $counts = array_count_values(array_map(static fn (array $r): int => $r[$field], $routes));
        arsort($counts);
        $codes = $this->query->codeMap($airline, array_slice(array_keys($counts), 0, self::TOP));
        $named = [];
        foreach ($counts as $id => $n) {
            $named[$codes[$id] ?? "#{$id}"] = $n;
        }

        return self::top($named, '(other airports)');
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private static function top(array $counts, string $otherLabel): array
    {
        arsort($counts);
        $top = array_slice($counts, 0, self::TOP, true);
        if (count($counts) > self::TOP) {
            $top[$otherLabel] = array_sum(array_slice($counts, self::TOP));
        }

        return $top;
    }
}
