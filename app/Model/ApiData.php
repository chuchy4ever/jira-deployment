<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Typed accessor for decoded JSON API responses.
 * Provides safe access to nested mixed data from json_decode().
 *
 * @implements \ArrayAccess<string|int, mixed>
 */
final class ApiData implements \ArrayAccess
{
	/** @var array<mixed> */
	private array $data;

	/**
	 * @param array<mixed> $data
	 */
	public function __construct(array $data)
	{
		$this->data = $data;
	}

	public static function from(mixed $data): self
	{
		return new self(is_array($data) ? $data : []);
	}

	public function string(string|int $key, string $default = ''): string
	{
		return isset($this->data[$key]) && is_scalar($this->data[$key]) ? (string) $this->data[$key] : $default;
	}

	public function stringOrNull(string|int $key): ?string
	{
		return isset($this->data[$key]) && is_scalar($this->data[$key]) ? (string) $this->data[$key] : null;
	}

	public function int(string|int $key, int $default = 0): int
	{
		return isset($this->data[$key]) && is_scalar($this->data[$key]) ? (int) $this->data[$key] : $default;
	}

	public function intOrNull(string|int $key): ?int
	{
		return isset($this->data[$key]) && is_scalar($this->data[$key]) ? (int) $this->data[$key] : null;
	}

	public function bool(string|int $key, bool $default = false): bool
	{
		return isset($this->data[$key]) ? (bool) $this->data[$key] : $default;
	}

	public function sub(string|int $key): self
	{
		$value = $this->data[$key] ?? [];
		return new self(is_array($value) ? $value : []);
	}

	/**
	 * @return list<self>
	 */
	public function list(string|int $key): array
	{
		$value = $this->data[$key] ?? [];
		if (!is_array($value)) {
			return [];
		}
		return array_map(fn(mixed $item): self => self::from($item), array_values($value));
	}

	/**
	 * @return list<self>
	 */
	public function asList(): array
	{
		return array_map(fn(mixed $item): self => self::from($item), array_values($this->data));
	}

	public function has(string|int $key): bool
	{
		return array_key_exists($key, $this->data);
	}

	public function isEmpty(): bool
	{
		return empty($this->data);
	}

	public function notNull(string|int $key): bool
	{
		return isset($this->data[$key]);
	}

	/**
	 * @return array<mixed>
	 */
	public function toArray(): array
	{
		return $this->data;
	}

	public function offsetExists(mixed $offset): bool
	{
		return isset($this->data[$offset]);
	}

	public function offsetGet(mixed $offset): mixed
	{
		return $this->data[$offset] ?? null;
	}

	public function offsetSet(mixed $offset, mixed $value): void
	{
		if ($offset === null) {
			$this->data[] = $value;
		} else {
			$this->data[$offset] = $value;
		}
	}

	public function offsetUnset(mixed $offset): void
	{
		unset($this->data[$offset]);
	}
}
