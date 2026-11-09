<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Menu;
use App\Models\Payslip;
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
            ->map(fn ($m) => ['judul' => $m->nama, 'sub' => 'Menu', 'ikon' => $m->icon ?: 'circle', 'url' => url($m->url)])->values();
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

        // Slip gaji (HR semua, lainnya milik sendiri)
        if ($user->bisa('slip')) {
            $semua = $user->isRole('Admin') || $user->actsAsHr();
            $dasar = Payslip::with(['payroll', 'karyawan'])->whereHas('payroll', fn ($w) => $w->where('status', 'final'))
                ->join('payroll', 'payroll.id', '=', 'payslip.payroll_id')->orderByDesc('payroll.periode')->select('payslip.*');
            $slip = ($semua
                ? $dasar->where(fn ($w) => $w->whereHas('karyawan', fn ($k) => $k->where('nama', 'like', "%$q%")->orWhere('nik', 'like', "%$q%"))
                    ->orWhere('payroll.periode', 'like', "%$q%"))->limit(5)->get()
                // slip milik sendiri: cocokkan dengan nama bulan, mis. "oktober" atau "2026-10"
                : $dasar->where('karyawan_id', $user->karyawan_id)->get()
                    ->filter(fn ($s) => str_contains(mb_strtolower('slip gaji ' . nama_periode($s->payroll->periode) . ' ' . $s->payroll->periode), mb_strtolower($q)))->take(4))
                ->map(fn ($s) => [
                    'judul' => 'Slip ' . nama_periode($s->payroll->periode), 'sub' => $s->karyawan->nama . ' · ' . rupiah($s->thp),
                    'ikon' => 'receipt-text', 'url' => route('slip.show', $s),
                ])->values();
            if ($slip->isNotEmpty()) {
                $grup[] = ['nama' => 'Slip gaji', 'item' => $slip];
            }
        }

        return response()->json(['grup' => $grup]);
    }
}
