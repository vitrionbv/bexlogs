<?php

declare(strict_types=1);

namespace App\Api\QueryExtension;

use ApiPlatform\Laravel\Eloquent\Extension\QueryExtensionInterface;
use ApiPlatform\Metadata\Operation;
use App\Models\Application;
use App\Models\BexSession;
use App\Models\LogMessage;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Org-scope every read query the API Platform Eloquent provider issues.
 *
 * The package wires every tagged {@see QueryExtensionInterface} into both
 * the collection and item providers (see vendor's
 * `ApiPlatformDeferredProvider`), so this single class is enough to
 * enforce isolation across every exposed resource — collection AND show
 * — for every HTTP verb (we only expose GET, but the same plumbing
 * would gate writes too if we opened them later).
 *
 * The contract here is simple: a request without an authenticated user
 * gets the empty set, full stop. The API routes are already gated by
 * `auth:sanctum` (see config/api-platform.php) so in practice this
 * branch should be unreachable — it's a belt-and-braces second layer so
 * a misconfigured middleware stack can't accidentally turn the API into
 * an org-wide data dump.
 *
 * Per-model scoping rules are kept in a single `match` for readability:
 * each branch encodes the canonical hop from row → owning user. If a new
 * #[ApiResource] is added that needs scoping, add a case here AND a test
 * that proves another user's row 404s — the default branch is
 * intentionally a no-op so an unhandled resource gets *open* read
 * access, which we want to notice loudly in CI rather than silently
 * locking ourselves out of a future global-scoped resource.
 */
final class OrganizationScopeExtension implements QueryExtensionInterface
{
    public function apply(Builder $builder, array $uriVariables, Operation $operation, $context = []): Builder
    {
        $user = Auth::user();

        if (! $user) {
            return $builder->whereRaw('1 = 0');
        }

        $model = $builder->getModel();
        $userId = $user->getAuthIdentifier();

        return match (true) {
            $model instanceof Organization => $builder->where('user_id', $userId),

            $model instanceof Application => $builder->whereHas(
                'organization',
                fn (Builder $q) => $q->where('user_id', $userId),
            ),

            $model instanceof Subscription => $builder->whereHas(
                'application.organization',
                fn (Builder $q) => $q->where('user_id', $userId),
            ),

            $model instanceof ScrapeJob => $builder->whereHas(
                'subscription.application.organization',
                fn (Builder $q) => $q->where('user_id', $userId),
            ),

            $model instanceof LogMessage => $builder->whereHas(
                'page.organization',
                fn (Builder $q) => $q->where('user_id', $userId),
            ),

            $model instanceof BexSession => $builder->where('user_id', $userId),

            default => $this->denyUnknownResource($builder, $model),
        };
    }

    /**
     * Last-line guard for any #[ApiResource] that someone exposes
     * without wiring an explicit scope above. We refuse to serve the
     * data outright — better a 404/empty than an accidental leak.
     */
    private function denyUnknownResource(Builder $builder, Model $model): Builder
    {
        report(new \LogicException(\sprintf(
            'OrganizationScopeExtension has no scoping rule for %s. Add a case (and a test) before exposing it via #[ApiResource].',
            $model::class,
        )));

        return $builder->whereRaw('1 = 0');
    }
}
