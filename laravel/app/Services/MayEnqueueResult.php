<?php

namespace App\Services;

/**
 * Outcome of `ScrapeEnqueueGuard::mayEnqueue`. Three shapes:
 *
 *   - allowed:                 `allowed=true,  reason=null,           retryAfterSeconds=null`
 *   - denied (cap hit):        `allowed=false, reason='concurrency_cap_reached',
 *                              retryAfterSeconds=60`
 *   - denied (spacing):        `allowed=false, reason='within_spacing_window',
 *                              retryAfterSeconds=<remaining seconds>`
 *   - denied (queued, idle):   `allowed=false, reason='prior_job_not_yet_started',
 *                              retryAfterSeconds=30`
 *
 * Reasons mirror the keys used by `ManageController::enqueueScrape` to
 * flash a session status (`scrape-concurrency-cap`, `scrape-spacing-window`,
 * `scrape-queued-not-started`) and drive the toast copy.
 */
final class MayEnqueueResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly ?int $retryAfterSeconds,
        public readonly ?string $message,
    ) {}

    public static function allowed(): self
    {
        return new self(true, null, null, null);
    }

    public static function denied(string $reason, ?int $retryAfterSeconds, string $message): self
    {
        return new self(false, $reason, $retryAfterSeconds, $message);
    }
}
