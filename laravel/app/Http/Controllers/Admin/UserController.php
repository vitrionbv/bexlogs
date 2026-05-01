<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->orderByDesc('created_at')
            ->paginate(50)
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_admin' => (bool) $u->is_admin,
                'email_verified_at' => $u->email_verified_at?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
                'updated_at' => $u->updated_at?->toIso8601String(),
                'is_self' => $u->id === $request->user()->id,
            ]);

        $session = $request->session();
        $flash = array_filter([
            'status' => $session->get('status'),
            'error' => $session->get('error'),
            'generated_password' => $session->get('generated_password'),
            'generated_password_for' => $session->get('generated_password_for'),
        ], fn ($v) => $v !== null);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'allowRegistration' => (bool) config('auth.allow_registration'),
            'flash' => (object) $flash,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $generatedPassword = null;
        $password = $data['password'] ?? null;
        if ($password === null || $password === '') {
            $generatedPassword = Str::random(16);
            $password = $generatedPassword;
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $password,
            'is_admin' => (bool) ($data['is_admin'] ?? false),
        ]);
        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $flash = ['status' => 'user-created'];
        if ($generatedPassword !== null) {
            $flash['generated_password'] = $generatedPassword;
            $flash['generated_password_for'] = $user->email;
        }

        return redirect()
            ->route('admin.users.index')
            ->with($flash);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        // Block demoting yourself or demoting the last remaining admin.
        if (array_key_exists('is_admin', $data) && $data['is_admin'] === false && $user->is_admin) {
            if ($user->id === $request->user()->id) {
                throw ValidationException::withMessages([
                    'is_admin' => "You can't remove your own admin status.",
                ])->status(422);
            }

            $otherAdminsExist = User::query()
                ->where('is_admin', true)
                ->where('id', '!=', $user->id)
                ->exists();
            if (! $otherAdminsExist) {
                throw ValidationException::withMessages([
                    'is_admin' => 'At least one admin must remain.',
                ])->status(422);
            }
        }

        $user->update($data);

        return back()->with('status', 'user-updated');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => "You can't delete your own account.",
            ])->status(422);
        }

        if ($user->is_admin) {
            $otherAdminsExist = User::query()
                ->where('is_admin', true)
                ->where('id', '!=', $user->id)
                ->exists();
            if (! $otherAdminsExist) {
                throw ValidationException::withMessages([
                    'user' => 'At least one admin must remain — promote another user first.',
                ])->status(422);
            }
        }

        // Related rows (organizations, bex_sessions, pairing_tokens,
        // saved_filters) cascade-delete via their foreign-key constraints.
        $user->delete();

        return back()->with('status', 'user-deleted');
    }

    public function sendPasswordResetLink(Request $request, User $user): RedirectResponse
    {
        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', 'password-reset-sent');
        }

        return back()->with('error', __($status));
    }
}
