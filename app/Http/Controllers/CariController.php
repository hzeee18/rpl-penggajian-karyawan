<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Menu;
use Illuminate\Http\Request;

/** Pencarian cepat di header: menu, karyawan, dan slip gaji sesuai hak akses role. */
class CariController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $q = trim((string) $request->q);
        if (mb_strlen($q) < 2) {
            return response()->json(['grup' => []]);
        }
        $grup = [];

        // Menu yang boleh dibuka
        $menu = Menu::whereNotNull('url')->where('nama', 'like', "%$q%")->orderBy('urutan')->get()
            ->filter(fn ($m) => $user->bisa($m->url))->take(4)
            ->map(fn ($m) => ['judul' => $m->nama, 'sub' => 'Menu', 'ikon' => $m->icon ?: 'circle', 'url' => url($m->alamat())])->values();
        if ($menu->isNotEmpty()) {
            $grup[] = ['nama' => 'Menu', 'item' => $menu];
        }

        // Karyawan (HR semua, Manager hanya timnya)
        if ($user->bisa('karyawan')) {
            $hr = $user->isRole('Admin') || $user->actsAsHr();
            $karyawan = $user->batasi(Karyawan::with('departemen'), 'id')
                ->where(fn ($w) => $w->where('nama', 'like', "%$q%")->orWhere('nik', 'like', "%$q%")->orWhere('jabatan', 'like', "%$q%"))
                ->orderBy('nama')->limit(6)->get()
                ->map(fn ($k) => [
                    'judul' => $k->nama, 'sub' => $k->nik . ' · ' . ($k->jabatan ?: '-') . ' · ' . ($k->departemen?->nama ?? '-'),
                    'inisial' => inisial($k->nama),
                    'url' => route('karyawan.index', ['q' => $k->nik, $hr ? 'ubah' : 'lihat' => $k->id]),
                ]);
            if ($karyawan->isNotEmpty()) {
                $grup[] = ['nama' => 'Karyawan', 'item' => $karyawan];
            }
        }

        // Slip gaji milik sendiri (modul payroll), cocokkan dengan judul payroll, mis. "oktober"
        if ($user->bisa('pay-slip') && $user->karyawan_id) {
            $slip = \App\Domain\Payroll\Models\Payslip::with('runEmployee.run')->where('employee_id', $user->karyawan_id)
                ->whereHas('runEmployee.run', fn ($r) => $r->where('status', \App\Domain\Payroll\Models\PayrollRun::APPROVED))
                ->orderByDesc('id')->get()
                ->filter(fn ($s) => str_contains(mb_strtolower('slip gaji ' . $s->runEmployee->run->title), mb_strtolower($q)))->take(4)
                ->map(fn ($s) => [
                    'judul' => 'Slip ' . $s->runEmployee->run->title, 'sub' => rupiah($s->runEmployee->take_home_pay),
                    'ikon' => 'receipt-text', 'url' => route('pay.slip.show', $s->id),
                ])->values();
            if ($slip->isNotEmpty()) {
                $grup[] = ['nama' => 'Slip gaji', 'item' => $slip];
            }
        }

        return response()->json(['grup' => $grup]);
    }
}
