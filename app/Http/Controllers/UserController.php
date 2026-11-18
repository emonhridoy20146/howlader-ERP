<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SyncsPublicPhotoUpload;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    use SyncsPublicPhotoUpload;

    public function __construct()
    {
        $this->authorizeResource(User::class, 'user');
    }

    public function index(Request $request): View
    {
        $users = User::query()
            ->with(['roles:id,name', 'accessibleLocations:id,name'])
            ->latest()
            ->paginate($this->resolvePerPage($request, 20))
            ->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        return view('users.create', [
            'roles' => Role::query()->orderBy('name')->get(),
            'locations' => Location::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->mergeIfMissing([
            'role_ids' => [],
            'location_ids' => [],
        ]);

        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['nullable', 'boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'location_ids' => ['array'],
            'location_ids.*' => ['integer', 'exists:locations,id'],
        ], $this->photoUploadRules()));

        $user = User::query()->create([
            'name' => $data['name'],
            'full_name' => $data['full_name'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $user->roles()->sync($data['role_ids'] ?? []);
        $user->accessibleLocations()->sync($data['location_ids'] ?? []);

        $photoState = $this->resolvedPhotoPathState($request, 'photos/users/'.$user->id, null);
        $user->forceFill($photoState);
        $user->save();

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function edit(User $user): View
    {
        $user->load(['roles:id', 'accessibleLocations:id']);

        return view('users.edit', [
            'user' => $user,
            'roles' => Role::query()->orderBy('name')->get(),
            'locations' => Location::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $request->mergeIfMissing([
            'role_ids' => [],
            'location_ids' => [],
        ]);

        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['nullable', 'boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'location_ids' => ['array'],
            'location_ids.*' => ['integer', 'exists:locations,id'],
        ], $this->photoUploadRules()));

        $payload = [
            'name' => $data['name'],
            'full_name' => $data['full_name'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        $payload = array_merge(
            $payload,
            $this->resolvedPhotoPathState($request, 'photos/users/'.$user->id, $user->photo_path),
        );

        $user->update($payload);
        $user->roles()->sync($data['role_ids'] ?? []);
        $user->accessibleLocations()->sync($data['location_ids'] ?? []);

        return redirect()->route('users.index')->with('status', 'User updated.');
    }
}
