<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Susunan menu (tabel global `menus`) untuk aplikasi yang memakai payroll baru, keputusan pemilik AUDIT.md §35.
 * Idempoten: hanya mengubah parent_id, nama, urutan, ikon. Tidak menghapus menu; menu lama yang digantikan
 * dipindah ke grup "Modul Lama" (tanpa hak akses sehingga tidak tampil). Hak akses tetap di tabel privileges.
 */
final class TataMenu
{
    /** grup => [ikon, urutan, [url => nama]] (urutan menu = urutan di array) */
    public const GRUP = [
        'Data HRIS' => ['users', 20, [
            'karyawan' => 'Karyawan', 'pay-kontrak' => 'Kontrak', 'pay-absensi' => 'Absensi', 'pay-leave' => 'Leave',
            'pay-lembur' => 'Lembur', 'pay-import' => 'Import CSV', 'pay-approver' => 'Approver',
        ]],
        'Payroll' => ['wallet-cards', 30, [
            'pay-master' => 'Master Payroll', 'pay-run' => 'Generate Payroll', 'pay-approval' => 'Approval',
        ]],
        'Konfigurasi' => ['settings', 40, [
            'pay-referensi' => 'Tabel Referensi', 'pay-cutoff' => 'Cut Off', 'pay-komponen' => 'Katalog Komponen', 'pay-libur' => 'Kalender Libur',
        ]],
        'Master Data' => ['database', 50, [
            'pay-jabatan' => 'Jabatan', 'departemen' => 'Departemen', 'pay-tipe-leave' => 'Tipe Leave', 'perusahaan' => 'Company',
            'users' => 'Manajemen User', 'roles' => 'Role dan Privilege', 'menus' => 'Manajemen Menu',
        ]],
        // menu lama yang sudah digantikan modul payroll baru: tidak dihapus, hak aksesnya dicabut
        'Modul Lama' => ['archive', 999, [
            'kontrak' => 'Kontrak (lama)', 'approval' => 'Approval (lama)', 'cuti' => 'Cuti, Izin, Sakit (lama)', 'lembur' => 'Lembur (lama)',
            'import' => 'Import Excel (lama)', 'payroll' => 'Proses Payroll (lama)', 'laporan' => 'Riwayat Payroll (lama)', 'slip' => 'Slip Gaji (lama)',
            'pajak' => 'Pajak & Tarif TER (lama)', 'komponen' => 'Komponen Gaji (lama)', 'master-payroll' => 'Periode (lama)', 'pay-karyawan' => 'Karyawan Payroll (lama)',
        ]],
    ];

    /** Menu tanpa grup (tampil paling atas): url => nama. `absensi` = riwayat kehadiran milik karyawan sendiri. */
    public const UTAMA = ['dashboard' => 'Dashboard', 'profil' => 'Profil', 'absensi' => 'Absensi Saya', 'pay-slip' => 'Slip Gaji'];

    /** Nama grup lama yang dipakai ulang (tidak membuat grup ganda). */
    private const NAMA_LAMA = ['Payroll' => 'Payroll Baru'];

    public function handle(): void
    {
        $now = now();
        DB::transaction(function () use ($now) {
            $urutan = 0;
            foreach (self::UTAMA as $url => $nama) {
                DB::table('menus')->where('url', $url)->update(['parent_id' => null, 'nama' => $nama, 'urutan' => ++$urutan, 'updated_at' => $now]);
            }

            foreach (self::GRUP as $grup => [$ikon, $urutanGrup, $menu]) {
                $id = $this->grup($grup);
                DB::table('menus')->where('id', $id)->update(['nama' => $grup, 'icon' => $ikon, 'urutan' => $urutanGrup, 'updated_at' => $now]);
                $urutan = 0;
                foreach ($menu as $url => $nama) {
                    DB::table('menus')->where('url', $url)->update(['parent_id' => $id, 'nama' => $nama, 'urutan' => ++$urutan, 'updated_at' => $now]);
                }
            }
        });
    }

    /** id grup (menu tanpa url): pakai yang ada (atau nama lamanya), buat bila belum ada. */
    private function grup(string $nama): int
    {
        $cari = fn (string $n) => DB::table('menus')->whereNull('url')->whereNull('parent_id')->where('nama', $n)->value('id');
        $id = $cari($nama) ?? (isset(self::NAMA_LAMA[$nama]) ? $cari(self::NAMA_LAMA[$nama]) : null);

        return (int) ($id ?? DB::table('menus')->insertGetId(['nama' => $nama, 'url' => null, 'icon' => 'folder', 'urutan' => 0, 'created_at' => now(), 'updated_at' => now()]));
    }
}
