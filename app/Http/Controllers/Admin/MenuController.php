<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LogAktivitas;
use App\Models\Menu;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        // daftar datar (induk + anak) seperti tabel di Figma
        $daftar = Menu::whereNull('parent_id')->when(! $this->superAdmin(), fn ($q) => $q->untukPerusahaan())->orderBy('urutan')->with(['children.privileges', 'children.parent', 'privileges'])->get()
            ->flatMap(fn ($m) => collect([$m])->concat($m->children))
            ->when($request->q, fn ($c, $q) => $c->filter(fn ($m) => str_contains(strtolower($m->nama . ' ' . $m->url), strtolower($q))))
            ->values();
        $menus = new \Illuminate\Pagination\LengthAwarePaginator($daftar->forPage($request->integer('page', 1), 10)->values(), $daftar->count(), 10, $request->integer('page', 1), ['path' => $request->url(), 'query' => $request->query()]);
        $ubah = $request->ubah ? Menu::find($request->ubah) : null;
        $induk = Menu::whereNull('parent_id')->whereNull('url')->orderBy('urutan')->get();

        return view('admin.menus.index', compact('menus', 'ubah', 'induk'));
    }

    public function store(Request $request)
    {
        $m = Menu::create($this->validasi($request));
        LogAktivitas::catat('Tambah menu ' . $m->nama, 'Menu');

        return redirect()->route('menus.index')->with('sukses', 'Menu ditambahkan.');
    }

    public function update(Request $request, Menu $menu)
    {
        abort_if($menu->isPlatform() && ! $this->superAdmin(), 403, 'Menu platform hanya bisa diubah Super Admin.');
        $menu->update($this->validasi($request));
        LogAktivitas::catat('Ubah menu ' . $menu->nama, 'Menu');

        return redirect()->route('menus.index')->with('sukses', 'Menu diubah.');
    }

    public function destroy(Menu $menu)
    {
        abort_if($menu->isPlatform() && ! $this->superAdmin(), 403, 'Menu platform hanya bisa dihapus Super Admin.');
        LogAktivitas::catat('Hapus menu ' . $menu->nama, 'Menu');
        $menu->delete();

        return back()->with('sukses', 'Menu dihapus.');
    }

    /** Multi-tenant: menu platform (Perusahaan) hanya dikelola Super Admin. */
    private function superAdmin(): bool
    {
        return app(\App\Domain\Company\Tenancy\CurrentCompany::class)->isSuperAdmin();
    }

    private function validasi(Request $request): array
    {
        $data = $request->validate([
            'nama' => 'required|max:100',
            'url' => 'nullable|max:100',
            'icon' => 'nullable|max:50',
            'parent_id' => 'nullable|exists:menus,id',
            'urutan' => 'nullable|integer',
        ]);
        $data['urutan'] ??= 0;
        $data['url'] = isset($data['url']) ? ltrim($data['url'], '/') ?: null : null;

        return $data;
    }
}
