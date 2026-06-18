<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function __construct(private TaskRepository $repo)
    {
    }

    /** Atualiza nome e bio do usuário. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'bio'  => 'nullable|string|max:1000',
        ]);

        $user = Workspace::user();
        $user->fill($data)->save();

        return response()->json(['me' => $this->repo->meFor($user)]);
    }

    /** Substitui a foto de perfil do usuário. */
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $user = Workspace::user();
        $old = $user->avatar_path;

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->avatar_path = $path;
        $user->save();

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return response()->json(['avatarUrl' => $user->avatarUrl]);
    }
}
