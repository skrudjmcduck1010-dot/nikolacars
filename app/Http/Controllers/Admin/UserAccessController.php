<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserAccessController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::query()->orderBy('name')->orderBy('email')->get(),
            'roles' => config('permissions.roles', []),
            'permissions' => config('permissions.permissions', []),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $permissions = array_keys(config('permissions.permissions', []));

        $validated = $request->validate([
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => ['nullable', 'boolean'],
            'extra_permissions' => ['nullable', 'array'],
            'extra_permissions.*' => ['string', Rule::in($permissions)],
        ]);

        $isActive = $request->boolean('is_active');

        if ($user->is($request->user()) && ! $isActive) {
            return back()
                ->withErrors(['is_active' => 'Нельзя отключить свою учетную запись.'])
                ->withInput();
        }

        $user->forceFill([
            'role' => $validated['role'],
            'is_active' => $isActive,
            'extra_permissions' => array_values($validated['extra_permissions'] ?? []),
        ])->save();

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Доступы пользователя обновлены.');
    }
}
