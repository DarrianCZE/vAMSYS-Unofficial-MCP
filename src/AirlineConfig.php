<?php

declare(strict_types=1);

namespace VamsysMcp;

use VamsysMcp\Exception\ConfigException;

/**
 * Settings for one vAMSYS airline. Holds the OAuth client secret, so it must
 * never be dumped, logged or serialised.
 */
final class AirlineConfig
{
    public const array WEIGHT_UNITS = ['kg', 'lbs'];
    public const int DEFAULT_RATE_CAP = 60;
    /** vAMSYS allows 100 requests/min per airline, shared by all its OAuth clients. */
    public const int MAX_RATE_CAP = 100;

    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        public readonly string $weightUnit,
        public readonly int $rateCap = self::DEFAULT_RATE_CAP,
        public readonly bool $pilotsApiEnabled = false,
    ) {
        if (preg_match('/^[a-z0-9_-]{2,32}$/', $key) !== 1) {
            throw new ConfigException("Airline key '{$key}' must be 2-32 characters of a-z, 0-9, '_' or '-'.");
        }
        if ($clientId === '' || $clientSecret === '') {
            throw new ConfigException("Airline '{$key}': client_id and client_secret are required.");
        }
        if (!in_array($weightUnit, self::WEIGHT_UNITS, true)) {
            throw new ConfigException("Airline '{$key}': weight_unit must be 'kg' or 'lbs'.");
        }
        if ($rateCap < 1 || $rateCap > self::MAX_RATE_CAP) {
            throw new ConfigException("Airline '{$key}': rate_cap must be between 1 and " . self::MAX_RATE_CAP . '.');
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(string $key, array $raw): self
    {
        $pilots = $raw['pilots_api'] ?? false;
        if (!is_bool($pilots)) {
            throw new ConfigException("Airline '{$key}': pilots_api must be true or false.");
        }

        return new self(
            key: $key,
            name: (string) ($raw['name'] ?? $key),
            clientId: (string) ($raw['client_id'] ?? ''),
            clientSecret: (string) ($raw['client_secret'] ?? ''),
            weightUnit: (string) ($raw['weight_unit'] ?? ''),
            rateCap: (int) ($raw['rate_cap'] ?? self::DEFAULT_RATE_CAP),
            pilotsApiEnabled: $pilots,
        );
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'clientId' => $this->clientId,
            'clientSecret' => '***',
            'weightUnit' => $this->weightUnit,
            'rateCap' => $this->rateCap,
            'pilotsApiEnabled' => $this->pilotsApiEnabled,
        ];
    }

    public function __serialize(): array
    {
        throw new \LogicException('AirlineConfig contains secrets and must not be serialised.');
    }
}
