<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Support\RolePermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Role::class, 'role', [
            'except' => ['index', 'create', 'store'],
        ]);
    }

    public function index(): View
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->with('permissions:id,slug')
            ->withCount(['users', 'permissions'])
            ->orderBy('name')
            ->get();

        return view('roles.index', compact('roles'));
    }

    public function create(): View
    {
        $this->authorize('create', Role::class);

        return view('roles.create', [
            'modules' => RolePermissionCatalog::forEditor(),
            'selectedPermissionIds' => $this->selectedFromOld([]),
            'canAssignPermissions' => $this->canAssignPermissions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $data = $this->validated($request);

        $role = Role::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
        ]);

        if ($this->canAssignPermissions()) {
            $role->permissions()->sync($this->permissionIds($data));
        }

        return redirect()->route('roles.index')->with('status', 'Role created.');
    }

    public function edit(Role $role): View
    {
        $role->load('permissions:id,slug');

        return view('roles.edit', [
            'role' => $role,
            'modules' => RolePermissionCatalog::forEditor(),
            'selectedPermissionIds' => $this->selectedFromOld($role->permissions->pluck('id')->all()),
            'canAssignPermissions' => $this->canAssignPermissions(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);

        $role->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
        ]);

        if ($this->canAssignPermissions()) {
            $role->permissions()->sync($this->permissionIds($data));
        }

        return redirect()->route('roles.index')->with('status', 'Role updated.');
    }

    private function canAssignPermissions(): bool
    {
        return auth()->user()?->can('assignPermissions', Role::class) ?? false;
    }

    /**
     * @return array{name: string, slug: string, permission_ids: list<int>}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        $unique = 'unique:roles,slug'.($role !== null ? ','.$role->id : '');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', $unique],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $data['permission_ids'] = array_values(array_unique(array_map('intval', $data['permission_ids'] ?? [])));

        return $data;
    }

    /**
     * The form posts exactly what is ticked, so the role ends up holding exactly
     * that — no bundle expansion and nothing silently carried over.
     *
     * @param  array{permission_ids: list<int>}  $data
     * @return list<int>
     */
    private function permissionIds(array $data): array
    {
        if ($data['permission_ids'] === []) {
            return [];
        }

        return Permission::query()
            ->whereIn('id', $data['permission_ids'])
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<int>  $fallback
     * @return list<int>
     */
    private function selectedFromOld(array $fallback): array
    {
        $old = old('permission_ids');
        if (! is_array($old)) {
            return $fallback;
        }

        return array_values(array_unique(array_map('intval', array_filter($old))));
    }
}
