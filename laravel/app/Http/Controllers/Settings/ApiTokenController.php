<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ApiTokenStoreRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    /**
     * Show the API token management page.
     *
     * Lists the user's existing tokens (sans secret — there is no way to
     * recover the plaintext after creation) plus a copy-paste curl
     * example that points at the documented base URL. We surface
     * `last_used_at` so the operator can spot stale or never-used
     * tokens and prune them.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $tokens = $user->tokens()
            ->latest()
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
            ])
            ->all();

        return Inertia::render('settings/ApiTokens', [
            'tokens' => $tokens,
            // The just-created plaintext token comes back via a one-shot
            // flash key so it can be rendered in a modal and then
            // forgotten. We never store the plaintext anywhere — once
            // the response is rendered the operator's only copy is
            // whatever they pasted into their clipboard / password
            // manager.
            'plainTextToken' => $request->session()->get('plain_text_token'),
            // The API base URL is built from APP_URL so the operator
            // doesn't have to mentally template the hostname into the
            // curl example. Trailing slash stripped because the
            // OpenAPI/api-platform routes already begin with `/api`.
            'apiBaseUrl' => rtrim((string) config('app.url'), '/').'/api',
            'docsUrl' => rtrim((string) config('app.url'), '/').'/api/docs',
        ]);
    }

    /**
     * Mint a new personal access token.
     *
     * The plaintext returned by `createToken()` is shown to the user
     * exactly once via a session flash; Sanctum stores only the SHA-256
     * hash, so if the operator loses the value before copying it they
     * must mint a new one.
     */
    public function store(ApiTokenStoreRequest $request): RedirectResponse
    {
        // Single read-only ability for now. We deliberately don't expose
        // write abilities yet — the API is read-only across the board.
        // When/if writes open up, we can split abilities here.
        $newToken = $request->user()->createToken(
            name: $request->string('name')->toString(),
            abilities: ['read'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token created.')]);

        return back()->with('plain_text_token', $newToken->plainTextToken);
    }

    /**
     * Revoke a single token. Scoped to the authenticated user so the URL
     * id (which is a global PAT id) can't be used to nuke someone
     * else's token.
     */
    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()
            ->tokens()
            ->whereKey($token)
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return back();
    }
}
