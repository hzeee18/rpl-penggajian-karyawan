<?php

namespace App\Providers;

use App\Models\Menu;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Carbon::setLocale('id');
        Paginator::useBootstrapFive();

        // sidebar sesuai hak akses + tampilan per role seperti di Figma
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            if (! $user) {
                return;
            }
            $role = $user->isManagerHr() ? 'Manager HR' : $user->role->nama;
            // multi-tenant: Super Admin melihat perusahaan yang sedang dikerjakan
            $labelPeran = $role;
            $konteks = app(\App\Domain\Company\Tenancy\CurrentCompany::class);
            if ($konteks->isSuperAdmin()) {
                $kode = \App\Domain\Company\Models\Company::whereKey($konteks->perusahaanDipilih())->value('code');
                $labelPeran = $role . ($kode ? ' · ' . $kode : '');
            }

            // Figma: HR & Karyawan punya satu menu "Absensi, Cuti & Lembur" (pakai tab),
            // Manager punya "Absensi Tim" (absensi + cuti) dan "Lembur Tim".
            $sembunyi = match ($role) {
                'HR' => ['cuti', 'lembur', 'slip', 'profil'],
                'Manager HR' => ['cuti', 'lembur', 'profil'],
                'Karyawan' => ['cuti', 'lembur'],
                'Manager' => ['cuti', 'profil'],
                default => [],
            };
            $label = match ($role) {
                'HR', 'Karyawan', 'Manager HR' => ['absensi' => 'Absensi, Cuti & Lembur'],
                'Manager' => ['karyawan' => 'Tim Saya', 'absensi' => 'Absensi Tim', 'lembur' => 'Lembur Tim'],
                default => [],
            };
            $induk = ['cuti' => 'absensi'] + (in_array('lembur', $sembunyi) ? ['lembur' => 'absensi'] : []);

            $boleh = fn ($m) => $user->bisa($m->url) && ! in_array($m->url, $sembunyi);
            $rapikan = function ($m) use ($label) {
                $m->nama = $label[$m->url] ?? $m->nama;

                return $m;
            };
            $menus = Menu::with(['privileges', 'children.privileges'])->whereNull('parent_id')->orderBy('urutan')->get()
                ->map(function ($m) use ($boleh, $rapikan) {
                    $m->setRelation('children', $m->children->filter($boleh)->map($rapikan)->values());

                    return $rapikan($m);
                })
                ->filter(fn ($m) => $m->url ? $boleh($m) : $m->children->isNotEmpty());

            // menu aktif: segmen pertama URL (cuti/lembur yang disembunyikan ikut ke absensi)
            $segmen = request()->segment(1);
            $aktif = $induk[$segmen] ?? $segmen;

            $view->with('sidebarUtama', $menus->whereNotNull('url'))
                ->with('sidebarGrup', $menus->whereNull('url'))
                ->with('menuAktif', $aktif)
                ->with('labelRole', $labelPeran)
                ->with('jumlahNotif', $user->notifikasi()->where('is_read', false)->count())
                ->with('notifTerbaru', $user->notifikasi()->take(6)->get());
        });
    }
}
