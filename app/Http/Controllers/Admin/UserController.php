<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $showInactive = $request->boolean('inactive');

        $users = User::query()
            ->with('cardIdentifiers:id,user_id,last4,cardholder_name')
            ->when(! $showInactive, fn ($query) => $query->active())
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'filters' => ['q' => $search, 'inactive' => $showInactive],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Form', ['user' => null]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = User::create($request->userAttributes());
        $user->syncCardIdentifiers($request->cardIdentifiers());

        return to_route('admin.users.index')->with('success', "{$user->name} has been added.");
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/Form', [
            'user' => [
                ...$user->only(['id', 'name', 'email', 'is_admin', 'is_cardholder', 'use_microsoft', 'active']),
                'card_identifiers' => $user->cardIdentifiers()->get(['id', 'last4', 'cardholder_name']),
                'has_password' => $user->password !== null,
            ],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->userAttributes());
        $user->syncCardIdentifiers($request->cardIdentifiers());

        return to_route('admin.users.index')->with('success', "{$user->name} has been updated.");
    }
}
