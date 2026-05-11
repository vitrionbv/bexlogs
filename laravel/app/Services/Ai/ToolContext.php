<?php

namespace App\Services\Ai;

/**
 * Read-only scope object passed to every tool invocation. The
 * controller constructs it from the authenticated user + the
 * route-bound Subscription, then never lets the LLM see or modify
 * it. Tools embed `userId` / `subscriptionId` directly into their
 * WHERE clauses — the LLM's JSON args can only narrow further.
 *
 * Declared `final readonly` so the contract is impossible to subvert
 * with a setter, child class, or `Reflection::set()`-via-trait.
 */
final readonly class ToolContext
{
    public function __construct(
        public int $userId,
        public string $subscriptionId,
    ) {}
}
