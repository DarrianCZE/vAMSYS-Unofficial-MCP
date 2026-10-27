<?php

declare(strict_types=1);

namespace VamsysMcp\Cache;

/**
 * Filters for projected routes (RouteRecord::project), with the same semantics
 * as the vAMSYS /routes filters: tags are case-sensitive and a comma-separated
 * list means the route must have all of them.
 *
 * Everything vAMSYS can filter is also sent as filter[...] (serverQueries()),
 * but matches() always re-checks locally, so a looser server-side filter
 * (e.g. case-insensitive) can never change an answer.
 */
final class RouteFilter
{
    /**
     * @param list<string> $tags
     * @param bool         $bothDirections dep ↔ arr in either direction (needs both)
     */
    public function __construct(
        public readonly ?int $departureId = null,
        public readonly ?int $arrivalId = null,
        public readonly array $tags = [],
        public readonly ?string $missingField = null,
        public readonly bool $bothDirections = false,
        public readonly ?string $flightNumber = null,
        public readonly ?string $callsign = null,
        public readonly ?bool $hidden = null,
    ) {
        if ($bothDirections && ($departureId === null || $arrivalId === null)) {
            throw new \InvalidArgumentException('both_directions needs both dep and arr.');
        }
    }

    /**
     * @return list<string>
     */
    public static function parseTags(?string $tag): array
    {
        return $tag === null ? [] : array_values(array_filter(array_map('trim', explode(',', $tag)), static fn (string $t): bool => $t !== ''));
    }

    /**
     * @param array<string, mixed> $route projected route
     */
    public function matches(array $route): bool
    {
        return $this->matchesAirports($route['departure_id'], $route['arrival_id'])
            && array_diff($this->tags, $route['tag']) === []
            && ($this->missingField === null || in_array($this->missingField, $route['empty'], true))
            && ($this->flightNumber === null || strcasecmp((string) $route['flight_number'], $this->flightNumber) === 0)
            && ($this->callsign === null || strcasecmp((string) $route['callsign'], $this->callsign) === 0)
            && ($this->hidden === null || $route['hidden'] === $this->hidden);
    }

    /**
     * An airport, flight number or callsign filter is set, so vAMSYS returns a
     * small set and reading it completely beats reading the full route set.
     */
    public function isNarrow(): bool
    {
        return $this->departureId !== null || $this->arrivalId !== null || $this->flightNumber !== null || $this->callsign !== null;
    }

    /**
     * The vAMSYS queries (filter[...] parameters) whose results together contain
     * every matching route: none without server-side filters, two for both
     * directions, else one.
     *
     * @return list<array<string, string>>
     */
    public function serverQueries(): array
    {
        $common = array_filter([
            'filter[tag]' => $this->tags !== [] ? implode(',', $this->tags) : null,
            'filter[flight_number]' => $this->flightNumber,
            'filter[callsign]' => $this->callsign,
            'filter[hidden]' => $this->hidden === null ? null : ($this->hidden ? '1' : '0'),
        ], static fn (?string $v): bool => $v !== null);
        $airports = static fn (?int $dep, ?int $arr): array => array_filter([
            'filter[departure_id]' => $dep !== null ? (string) $dep : null,
            'filter[arrival_id]' => $arr !== null ? (string) $arr : null,
        ], static fn (?string $v): bool => $v !== null);

        $queries = [$airports($this->departureId, $this->arrivalId) + $common];
        if ($this->bothDirections) {
            $queries[] = $airports($this->arrivalId, $this->departureId) + $common;
        }

        return $queries[0] === [] ? [] : $queries;
    }

    private function matchesAirports(int $dep, int $arr): bool
    {
        $forward = ($this->departureId === null || $dep === $this->departureId) && ($this->arrivalId === null || $arr === $this->arrivalId);

        return $forward || ($this->bothDirections && $dep === $this->arrivalId && $arr === $this->departureId);
    }
}
