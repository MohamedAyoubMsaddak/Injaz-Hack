<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', Rule::in(['student', 'owner'])],
        ]);

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
        ]);
        $user->role = $validated['role'];
        $user->save();

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => $this->userData($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        if (Auth::check()) {
            return response()->json([
                'message' => 'You are already signed in. Log out before signing in to another account.',
            ], 409);
        }

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'The email or password is incorrect.',
                'errors' => [
                    'email' => ['The email or password is incorrect.'],
                ],
            ], 422);
        }

        $request->session()->regenerate();

        return response()->json([
            'user' => $this->userData($request->user()),
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userData($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'You have been logged out.',
        ]);
    }

    private function userData(User $user): array
    {
        $data = $user->only(['id', 'name', 'email', 'phone', 'role']);

        if ($user->role === 'worker') {
            $data['governorate'] = $user->governorate;
        }

        return $data;
    }
}
