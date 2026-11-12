<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Models\LogAktivitas;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with(['role', 'karyawan.departemen'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($q) => $q->where('username', 'like', "%$s%")->orWhere('email', 'like', "%$s%")
                ->orWhereHas('karyawan', fn ($q) => $q->where('nama', 'like', "%$s%"))))
            ->when($request->role_id, fn ($q, $r) => $q->where('role_id', $r))
            ->when($request->status === 'aktif', fn ($q) => $q->where('is_active', true)->where('is_locked', false))
            ->when($request->status === 'nonaktif', fn ($q) => $q->where('is_active', false))
            ->when($request->status === 'terkunci', fn ($q) => $q->where('is_locked', true))
            ->orderBy('role_id')->orderBy('username')->paginate(10)->withQueryString();
        $roles = Role::orderBy('id')->get();
        $tanpaAkun = Karyawan::where('is_active', true)->doesntHave('user')->count();

        return view('admin.users.index', compact('users', 'roles', 'tanpaAkun'));
    }

    public function create(Request $request)
    {
        return view('admin.users.form', [
            'user' => new User(['is_active' => true, 'karyawan_id' => $request->karyawan_id]),
            'roles' => Role::orderBy('id')->get(),
            'karyawan' => Karyawan::where('is_active', true)->doesntHave('user')->orderBy('nama')->get(),
        ] + $this->dataApprover());
    }

    public function store(Request $request)
    {
        $data = $this->validasi($request);
        $u = User::create($data);
        $this->simpanApprover($u, $data);
        LogAktivitas::catat('Buat akun ' . $u->namaTampil(), 'User');

        return redirect()->route('users.index')->with('sukses', 'User berhasil dibuat.');
    }

    public function edit(User $user)
    {
        return view('admin.users.form', [
            'user' => $user,
            'roles' => Role::orderBy('id')->get(),
            'karyawan' => Karyawan::where('is_active', true)
                ->where(fn ($q) => $q->doesntHave('user')->orWhere('id', $user->karyawan_id))->orderBy('nama')->get(),
        ] + $this->dataApprover($user));
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validasi($request, $user);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        $this->simpanApprover($user, $data);
        LogAktivitas::catat('Ubah akun ' . $user->namaTampil(), 'User');

        return redirect()->route('users.index')->with('sukses', 'User berhasil diubah.');
    }

    public function bukaKunci(User $user)
    {
        $user->update(['is_locked' => false, 'gagal_login' => 0]);
        LogAktivitas::catat('Buka kunci akun ' . $user->namaTampil(), 'User');

        return back()->with('sukses', "Akun {$user->username} sudah dibuka.");
    }

    public function resetPassword(User $user)
    {
        $baru = Str::password(10, symbols: false);
        $user->update(['password' => $baru, 'is_locked' => false, 'gagal_login' => 0]);
        LogAktivitas::catat('Reset sandi ' . $user->namaTampil(), 'User');

        return back()->with('sukses', "Password baru untuk {$user->username}: {$baru} (berikan ke user, minta segera diganti).");
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('gagal', 'Tidak bisa menonaktifkan akun sendiri.');
        }
        $user->update(['is_active' => false]);
        LogAktivitas::catat('Nonaktifkan ' . $user->namaTampil(), 'User');

        return back()->with('sukses', "Akun {$user->username} dinonaktifkan.");
    }

    private function validasi(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'username' => ['required', 'alpha_dash', 'max:50', Rule::unique('users')->ignore($user)],
            'email' => ['nullable', 'email', 'max:100'],
            'role_id' => ['required', \App\Domain\Company\Tenancy\TenantRule::exists('roles')],
            'karyawan_id' => ['nullable', \App\Domain\Company\Tenancy\TenantRule::exists('karyawan'), Rule::unique('users')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', Password::min(8)],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        // payroll baru: approver payroll diisi langsung di form user (khusus role HR), AUDIT.md §42
        if (\App\Domain\Payroll\ModulPayroll::aktif() && $request->has('form_approver')) {
            $approver = $request->validate(['approver_user_id' => ['nullable', 'integer', \App\Domain\Company\Tenancy\TenantRule::exists('users')]])['approver_user_id'] ?? null;
            $roleHr = Role::whereKey($data['role_id'])->value('nama') === Role::HR;
            if ($approver !== null && $roleHr) {
                $m = User::with('role')->find($approver);
                if (($user && (int) $m->id === (int) $user->id) || ! $m->is_active || $m->role?->nama !== Role::MANAGER) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['approver_user_id' => 'Approver harus user Manager aktif dan bukan user itu sendiri.']);
                }
            }
            $data['approver_user_id'] = $roleHr ? $approver : null;
        }

        return $data;
    }

    /** approver_user_id bukan kolom fillable User (model lama tidak diubah): disimpan eksplisit bila form mengirimnya. */
    private function simpanApprover(User $user, array $data): void
    {
        if (array_key_exists('approver_user_id', $data)) {
            $user->forceFill(['approver_user_id' => $data['approver_user_id']])->save();
        }
    }

    /** Data pilihan approver payroll untuk form user (hanya perusahaan yang memakai payroll baru). */
    private function dataApprover(?User $user = null): array
    {
        $aktif = \App\Domain\Payroll\ModulPayroll::aktif();

        return [
            'payrollBaru' => $aktif,
            'managerApprover' => $aktif ? User::with('karyawan')->where('is_active', true)->whereHas('role', fn ($q) => $q->where('nama', Role::MANAGER))
                ->when($user?->id, fn ($q, $id) => $q->where('id', '!=', $id))->orderBy('username')->get() : collect(),
            'roleHrId' => $aktif ? Role::where('nama', Role::HR)->value('id') : null,
        ];
    }
}
