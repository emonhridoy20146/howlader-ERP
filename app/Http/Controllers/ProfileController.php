<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SyncsPublicPhotoUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    use SyncsPublicPhotoUpload;

    public function edit(Request $request): View
    {
        $user = $request->user()->load([
            'roles:id,name',
            'branch:id,code,name',
            'accessibleLocations:id,code,name',
        ]);

        return view('profile.edit', compact('user'));
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:255'],
        ], $this->photoUploadRules()));

        $photoState = $this->resolvedPhotoPathState(
            $request,
            'photos/users/'.$user->id,
            $user->photo_path,
        );

        unset($data['photo'], $data['remove_photo']);

        $user->update(array_merge($data, $photoState));

        return redirect()->route('profile.edit')->with('status', 'Your profile was updated.');
    }

    public function editPassword(Request $request): View
    {
        return view('profile.password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update([
            'password' => $validated['password'],
        ]);

        return redirect()->route('profile.password.edit')->with('status', 'Your password was changed.');
    }
}
