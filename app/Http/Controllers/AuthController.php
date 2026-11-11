<?php

namespace App\Http\Controllers;

use App\Domain\Company\Tenancy\CompanyScope;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Models\Notifikasi;
use App\Models\Role;
use App\Models\User;
use Closure;
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
        // multi-tenant: username unik global, dicari tanpa scope; proses selanjutnya dalam konteks perusahaan user itu
        $user = User::withoutGlobalScope(CompanyScope::class)->where('username', $data['username'])->first();

        return $this->dalamPerusahaan($user, fn () => $this->prosesLogin($request, $data, $user));
    }

    private function prosesLogin(Request $request, array $data, ?User $user)
    {
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
                    if ($user->company_id !== null) { // akun platform (Super Admin) tidak dilaporkan ke Admin perusahaan
                        Notifikasi::kirim(User::idsRole(Role::ADMIN), 'Akun terkunci',
                            "Akun {$user->username} terkunci karena salah password " . User::MAKS_GAGAL_LOGIN . ' kali.', 'user', $user->id);
                    }

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
        $user = User::withoutGlobalScope(CompanyScope::class)->where('username', $data['username'])->where('email', $data['email'])->first();

        if ($user && $user->company_id !== null) { // akun platform (Super Admin) tidak dilaporkan ke Admin perusahaan
            $this->dalamPerusahaan($user, fn () => Notifikasi::kirim(User::idsRole(Role::ADMIN), 'Permintaan reset password',
                "User {$user->username} meminta reset password.", 'user', $user->id));
        }

        // pesan sama supaya tidak membocorkan username yang terdaftar
        return redirect()->route('login')->with('sukses', 'Permintaan reset password sudah dikirim ke Admin. Password baru akan diberikan oleh Admin.');
    }

    /** Jalankan dalam konteks perusahaan user (sebelum login belum ada konteks dari sesi). */
    private function dalamPerusahaan(?User $user, Closure $proses): mixed
    {
        if ($user === null) {
            return $proses();
        }

        $konteks = app(CurrentCompany::class);

        return $user->company_id !== null
            ? $konteks->runAs((int) $user->company_id, $proses)
            // lintas perusahaan: akun platform (Super Admin) tidak punya perusahaan; yang diubah hanya baris user itu sendiri
            : $konteks->lintasPerusahaan('login akun platform (Super Admin)', $proses);
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
