<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class Accounts
{
    public function publicUser(object $row): array
    {
        return ['username' => $row->username, 'name' => $row->name, 'role' => $row->role, 'active' => (bool) $row->active,
            'permissions' => Permissions::normalize($row->role, json_decode($row->permissions, true) ?: [])];
    }

    public function all(): array
    {
        return DB::table('production_users')->orderBy('username')->get()->map($this->publicUser(...))->all();
    }

    public function login(Request $request): array
    {
        $username = mb_strtolower(trim((string) $request->input('username')));
        $key = 'production-login:'.hash('sha256', $username);
        Permissions::check(! RateLimiter::tooManyAttempts($key, 5), 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.');
        $row = DB::table('production_users')->where('username', $username)->first();
        if (! $row || ! $row->active || ! Hash::check((string) $request->input('password'), $row->password)) {
            RateLimiter::hit($key, 900);
            Permissions::check(false, 'Username atau password salah.');
        }
        RateLimiter::clear($key);
        $token = bin2hex(random_bytes(32));
        DB::table('production_tokens')->where('expires_at', '<=', now())->delete();
        DB::table('production_tokens')->insert(['hash' => hash('sha256', $token), 'username' => $username, 'expires_at' => now()->addHours(12)]);

        return ['token' => $token, 'user' => $this->publicUser($row)];
    }

    public function authenticate(Request $request): array
    {
        $token = $request->bearerToken() ?: (string) $request->input('token');
        $session = DB::table('production_tokens')->where('hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        $row = $session ? DB::table('production_users')->where('username', $session->username)->where('active', true)->first() : null;
        Permissions::check((bool) $row, 'Sesi berakhir. Silakan login kembali.');

        return $this->publicUser($row);
    }

    public function add(array $data): void
    {
        $data['username'] = mb_strtolower(trim($data['username'] ?? ''));
        Validator::make($data, ['username' => 'required|regex:/^[a-z0-9_.-]+$/|max:100|unique:production_users,username', 'name' => 'required|string|max:255', 'password' => 'required|string|min:8|max:255', 'role' => 'required|in:user,superuser'])->validate();
        DB::table('production_users')->insert(['username' => $data['username'], 'name' => $data['name'], 'password' => Hash::make($data['password']), 'role' => $data['role'], 'active' => true, 'permissions' => json_encode(Permissions::normalize($data['role'])), 'created_at' => now()]);
    }

    public function change(string $action, string $username, Request $request, array $actor): void
    {
        $row = DB::table('production_users')->where('username', $username)->first();
        Permissions::check((bool) $row, 'User tidak ditemukan.');
        if ($action === 'user.remove') {
            Permissions::check($username !== $actor['username'], 'Tidak dapat menghapus akun sendiri.');
            Permissions::check($row->role !== 'superuser' || DB::table('production_users')->where('role', 'superuser')->where('active', true)->count() > 1, 'Super User terakhir tidak dapat dihapus.');
            DB::table('production_users')->where('username', $username)->delete();
        } elseif ($action === 'user.password.reset') {
            $request->validate(['password' => 'required|string|min:8|max:255']);
            DB::table('production_users')->where('username', $username)->update(['password' => Hash::make($request->input('password'))]);
        } else {
            $permissions = $request->input('permissions');
            if (is_string($permissions)) {
                $permissions = json_decode($permissions, true, 512, JSON_THROW_ON_ERROR);
            }
            Permissions::check(is_array($permissions), 'Hak akses tidak valid.');
            DB::table('production_users')->where('username', $username)->update(['permissions' => json_encode(Permissions::normalize($row->role, $permissions))]);
        }
        DB::table('production_tokens')->where('username', $username)->delete();
    }
}
