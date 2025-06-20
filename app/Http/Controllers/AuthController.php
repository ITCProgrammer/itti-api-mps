<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
     public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required'],
            'password' => ['required']
        ]);
        $result = DB::select('EXEC sp_get_user @Username = ?', [$credentials['username']]);

        if (count($result) === 0) {
            return response()->json([
                'status' => false,
                'message' => 'Email tidak ditemukan.'
            ], 401);
        }

        $userData = (array) $result[0];

        if (!Hash::check($credentials['password'], $userData['password'])) {
            return response()->json([
                'status' => false,
                'message' => 'Password salah.'
            ], 401);
        }

        $user = User::find($userData['id']);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User tidak ditemukan di sistem.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Login berhasil',
            'token' => $user->createToken('api-token')->plainTextToken,
            'user' => $user
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil'
        ]);
    }
}
