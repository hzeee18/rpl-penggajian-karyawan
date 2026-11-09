<?php

namespace App\Http\Controllers;

use App\Models\Notifikasi;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function formLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['username' => 'required|string', 'password' => 'required|string']);
        $user = User::where('username', $data['username'])->first();

        if ($user && $user->is_locked) {
            return back()->withInput()->withErrors(['username' => 'Akun terkunci karena salah password ' . User::MAKS_GAGAL_LOGIN . ' kali. Hubungi Admin.']);
        }
        if ($user && ! $user->is_active) {
            return back()->withInput()->withErrors(['username' => 'Akun tidak aktif. Hubungi Admin.']);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            if ($user) {
                $user->increment('gagal_login');
                if ($user->gagal_login >= User::MAKS_GAGAL_LOGIN) {
                    $user->update(['is_locked' => true]);
                    Notifikasi::kirim(User::idsRole(Role::ADMIN), 'Akun terkunci',
                        "Akun {$user->username} terkunci karena salah password " . User::MAKS_GAGAL_LOGIN . ' kali.', 'user', $user->id);

                    return back()->withInput()->withErrors(['username' => 'Akun terkunci karena salah password ' . User::MAKS_GAGAL_LOGIN . ' kali. Hubungi Admin.']);
                }
                $sisa = User::MAKS_GAGAL_LOGIN - $user->gagal_login;

                return back()->withInput()->withErrors(['username' => "Username atau password salah. Sisa percobaan: {$sisa}."]);
            }

            return back()->withInput()->withErrors(['username' => 'Username atau password salah.']);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->update(['gagal_login' => 0, 'last_login' => now()]);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function formLupa()
    {
        return view('auth.lupa');
    }

    /** Lupa password: kirim permintaan reset ke Admin lewat notifikasi. */
    public function lupa(Request $request)
    {
        $data = $request->validate(['username' => 'required|string', 'email' => 'required|email']);
        $user = User::where('username', $data['username'])->where('email', $data['email'])->first();

        if ($user) {
            Notifikasi::kirim(User::idsRole(Role::ADMIN), 'Permintaan reset password',
                "User {$user->username} meminta reset password.", 'user', $user->id);
        }

        // pesan sama supaya tidak membocorkan username yang terdaftar
        return redirect()->route('login')->with('sukses', 'Permintaan reset password sudah dikirim ke Admin. Password baru akan diberikan oleh Admin.');
    }

    public function gantiPassword(Request $request)
    {
        $request->validate([
            'password_lama' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $request->user()->update(['password' => $request->password]);

        return back()->with('sukses', 'Password berhasil diganti.');
    }
}
