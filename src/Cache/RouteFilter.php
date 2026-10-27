<?php

declare(strict_types=1);

namespace VamsysMcp\Cache;

/**
 * Filters for projected routes (RouteRecord::project), with the same semantics
 * as the vAMSYS /routes filters: tags are case-sensitive and a comma-separated
 * list means the route must have all of them.
 */
final class RouteFilter
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly ?int $departureId = null,
        public readonly ?int $arrivalId = null,
        public readonly array $tags = [],
        public readonly ?string $missingField = null,
    ) {
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
        return ($this->departureId === null || $route['departure_id'] === $this->departureId)
            && ($this->arrivalId === null || $route['arrival_id'] === $this->arrivalId)
            && array_diff($this->tags, $route['tag']) === []
            && ($this->missingField === null || in_array($this->missingField, $route['empty'], true));
    }

    /**
     * Equivalent vAMSYS query filters, for live (uncached) scans.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'filter[departure_id]' => $this->departureId !== null ? (string) $this->departureId : null,
            'filter[arrival_id]' => $this->arrivalId !== null ? (string) $this->arrivalId : null,
            'filter[tag]' => $this->tags !== [] ? implode(',', $this->tags) : null,
        ], static fn (?string $v): bool => $v !== null);
    }
}
