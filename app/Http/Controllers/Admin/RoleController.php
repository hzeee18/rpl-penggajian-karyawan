<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LogAktivitas;
use App\Models\Menu;
use App\Models\Privilege;
use App\Models\Role;
use Illuminate\Http\Request;

/** Role & Privilege: daftar role di kiri, matriks hak akses role terpilih di kanan (sesuai Figma). */
class RoleController extends Controller
{
    public const KETERANGAN = [
        'Admin' => 'Kelola user, role, menu, privilege',
        'HR' => 'Karyawan, kontrak, absensi, payroll',
        'Manager' => 'Approval kontrak, input lembur tim',
        'Karyawan' => 'Data diri, absensi, slip gaji',
    ];

    public function index(Request $request)
    {
        $roles = Role::withCount('users')->orderBy('id')->get();
        $role = $roles->firstWhere('id', $request->integer('role')) ?? $roles->firstWhere('nama', Role::MANAGER) ?? $roles->first();
        $menus = Menu::whereNull('parent_id')->with('children')->orderBy('urutan')->get();
        $priv = $role ? $role->privileges()->get()->keyBy('menu_id') : collect();

        return view('admin.roles.index', compact('roles', 'role', 'menus', 'priv'));
    }

    public function hakAkses(Role $role)
    {
        return redirect()->route('roles.index', ['role' => $role->id]);
    }

    public function store(Request $request)
    {
        $request->validate(['nama' => 'required|max:50|unique:roles,nama']);
        $role = Role::create(['nama' => $request->nama]);
        LogAktivitas::catat('Tambah role ' . $role->nama, 'Privilege');

        return redirect()->route('roles.index', ['role' => $role->id])->with('sukses', 'Role ditambahkan. Atur hak aksesnya di kanan.');
    }

    public function simpanHakAkses(Request $request, Role $role)
    {
        $input = $request->input('akses', []);
        foreach (Menu::whereNotNull('url')->get() as $menu) {
            $nilai = [];
            foreach (Privilege::AKSI as $aksi) {
                $nilai['can_' . $aksi] = ! empty($input[$menu->id][$aksi]);
            }
            Privilege::updateOrCreate(['role_id' => $role->id, 'menu_id' => $menu->id], $nilai);
        }
        LogAktivitas::catat('Ubah privilege ' . $role->nama, 'Privilege');

        return redirect()->route('roles.index', ['role' => $role->id])->with('sukses', "Hak akses role {$role->nama} disimpan.");
    }

    public function destroy(Role $role)
    {
        if ($role->users()->exists() || in_array($role->nama, [Role::ADMIN, Role::HR, Role::MANAGER, Role::KARYAWAN])) {
            return back()->with('gagal', 'Role bawaan atau role yang masih dipakai user tidak bisa dihapus.');
        }
        LogAktivitas::catat('Hapus role ' . $role->nama, 'Privilege');
        $role->delete();

        return redirect()->route('roles.index')->with('sukses', 'Role dihapus.');
    }
}
