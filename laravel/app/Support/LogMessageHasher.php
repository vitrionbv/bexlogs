<?php

namespace App\Support;

/**
 * Compute a content-addressed sha256 hash for a log message.
 *
 * The hash is the dedup key inside `log_messages`. Two log entries with the
 * exact same payload (timestamp + type + action + method + status + JSON
 * bodies) produce the same 32-byte digest, so pagination overlap collapses
 * to a single row. Two genuinely distinct events that happen to share the
 * (timestamp, type, action, method, status) tuple — but differ in
 * parameters/request/response — produce different digests and remain as
 * separate rows. That is the entire point of the change: same-second
 * legitimate duplicates must remain visible.
 */
class LogMessageHasher
{
    /**
     * Compute the binary 32-byte sha256 hash for a single log message.
     *
     * @param  mixed  $parameters  array|object|scalar|null — JSON-encodable
     * @param  mixed  $request  same
     * @param  mixed  $response  same
     *
     * Note: `$path` was appended to the hash inputs after the original schema
     * stabilized. Pre-existing rows were inserted with `path = null` and
     * their content_hash was computed without the path term, so we MUST keep
     * that behavior backward-compatible: `$path === null` → omit entirely
     * (don't add an empty separator), so legacy rows still hash identically
     * to their stored content_hash. New rows that genuinely carry a path
     * append a normalized term.
     */
    public static function compute(
        string $timestamp,
        string $type,
        string $action,
        string $method,
        ?string $status,
        mixed $parameters,
        mixed $request,
        mixed $response,
        ?string $path = null,
    ): string {
        $parts = [
            self::norm($timestamp),
            self::norm($type),
            self::norm($action),
            self::norm($method),
            self::norm($status ?? ''),
            self::canonicalJson($parameters),
            self::canonicalJson($request),
            self::canonicalJson($response),
        ];

        if ($path !== null && $path !== '') {
            $parts[] = self::norm($path);
        }

        return hash('sha256', implode("\x1f", $parts), binary: true);
    }

    /**
     * Same as compute(), but accepts a raw row out of the `log_messages`
     * table (associative array). JSON columns may arrive either decoded
     * (PHP arrays — Eloquent casts) or as raw strings (DB::table queries),
     * so we normalize both shapes.
     *
     * @param  array<string, mixed>  $row
     */
    public static function computeForRow(array $row): string
    {
        $decode = static function (mixed $val): mixed {
            if ($val === null || $val === '') {
                return null;
            }
            if (is_string($val)) {
                $j = json_decode($val, true);

                // Distinguish "literal null" from "invalid JSON, treat as raw text".
                return $j === null && $val !== 'null' ? $val : $j;
            }

            return $val;
        };

        return self::compute(
            timestamp: (string) ($row['timestamp'] ?? ''),
            type: (string) ($row['type'] ?? ''),
            action: (string) ($row['action'] ?? ''),
            method: (string) ($row['method'] ?? ''),
            status: isset($row['status']) ? (string) $row['status'] : null,
            parameters: $decode($row['parameters'] ?? null),
            request: $decode($row['request'] ?? null),
            response: $decode($row['response'] ?? null),
            path: isset($row['path']) && $row['path'] !== null ? (string) $row['path'] : null,
        );
    }

    private static function norm(string $s): string
    {
        return trim($s);
    }

    /**
     * Canonical JSON: recursive ksort on associative arrays so
     * {a:1,b:2} hashes the same as {b:2,a:1}. Lists keep their order.
     */
    private static function canonicalJson(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }

        return json_encode(
            self::sortRecursive($v),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private static function sortRecursive(mixed $v): mixed
    {
        if (is_array($v)) {
            $isAssoc = $v !== [] && array_keys($v) !== range(0, count($v) - 1);
            $v = array_map([self::class, 'sortRecursive'], $v);
            if ($isAssoc) {
                ksort($v, SORT_STRING);
            }

            return $v;
        }

        return $v;
    }
}
