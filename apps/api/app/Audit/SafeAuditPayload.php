<?php

namespace App\Audit;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SafeAuditPayload implements JsonSerializable
{
    private const REDACTED = '[redacted]';

    /** @var list<string> */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization', 'cookie', 'credential', 'password', 'recovery', 'secret',
        'session', 'token', 'two_factor', 'private_key', 'ciphertext',
    ];

    /** @var array<string, mixed> */
    private array $value;

    /** @param array<string, mixed> $value
     * @param  list<string>  $allowedKeys
     */
    private function __construct(array $value, array $allowedKeys)
    {
        $unknown = array_diff(array_keys($value), $allowedKeys);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Audit payload contains undeclared keys: '.implode(', ', $unknown));
        }
        $this->value = self::sanitizeMap($value, 0);

        $encoded = json_encode($this->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > 32768) {
            throw new InvalidArgumentException('Audit payloads may not exceed 32 KiB.');
        }
    }

    /** @param array<string, mixed> $value
     * @param  list<string>  $allowedKeys
     */
    public static function from(array $value, array $allowedKeys): self
    {
        return new self($value, $allowedKeys);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->value;
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function sanitizeMap(array $value, int $depth): array
    {
        if ($depth > 6) {
            throw new InvalidArgumentException('Audit payload nesting may not exceed six levels.');
        }

        $safe = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || $key === '' || strlen($key) > 100) {
                throw new InvalidArgumentException('Audit payload keys must be non-empty strings of at most 100 bytes.');
            }

            $normalizedKey = strtolower($key);
            $safe[$key] = collect(self::SENSITIVE_KEY_FRAGMENTS)
                ->contains(fn (string $fragment): bool => str_contains($normalizedKey, $fragment))
                    ? self::REDACTED
                    : self::sanitizeValue($item, $depth + 1);
        }

        return $safe;
    }

    private static function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Audit payload numbers must be finite.');
            }

            return $value;
        }

        if (is_string($value)) {
            $clean = mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '', 0, 2000);
            if (preg_match('/(?:bearer\s+|password|plaintext.?token|secret|credential|recovery.?code|api.?key)/iu', $clean) === 1
                || preg_match('/\b[A-Za-z0-9_-]{40,}\b/u', $clean) === 1
                || preg_match('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/u', $clean) === 1) {
                return self::REDACTED;
            }

            return $clean;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                if (count($value) > 100) {
                    throw new InvalidArgumentException('Audit payload lists may not exceed 100 values.');
                }

                return array_map(fn (mixed $item): mixed => self::sanitizeValue($item, $depth + 1), $value);
            }

            return self::sanitizeMap($value, $depth + 1);
        }

        throw new InvalidArgumentException('Audit payload values must be JSON scalars, lists, or objects.');
    }
}
