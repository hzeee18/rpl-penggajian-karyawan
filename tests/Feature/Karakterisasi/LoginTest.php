<?php

declare(strict_types=1);

namespace Tests\Feature\Karakterisasi;

use App\Models\Notifikasi;
use App\Models\User;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;

/**
 * Alur login lama lewat form (bukan actingAs): sukses, salah password sampai terkunci,
 * akun nonaktif, sesi akun yang dikunci, dan lupa password.
 */
final class LoginTest extends KarakterisasiTestCase
{
    public function test_login_sukses_lalu_logout(): void
    {
        $login = $this->hasil($this->post('/login', ['username' => 'rizky', 'password' => 'password']));
        $dashboard = $this->get('/dashboard')->getStatusCode();
        $logout = $this->hasil($this->post('/logout'));
        $setelahLogout = $this->get('/dashboard');

        $this->cocokSnapshotJson('login/sukses.json', [
            'login' => $login,
            'dashboard' => $dashboard,
            'user' => $this->statusUser('rizky'),
            'logout' => $logout,
            'setelah_logout' => [$setelahLogout->getStatusCode(), $setelahLogout->headers->get('Location')],
        ]);
    }

    public function test_salah_password_lima_kali_terkunci_dan_admin_diberi_notifikasi(): void
    {
        $percobaan = [];
        for ($i = 1; $i <= 5; $i++) {
            $percobaan[] = $this->hasil($this->from('/login')->post('/login', ['username' => 'rizky', 'password' => 'salah']));
        }
        $passwordBenarSaatTerkunci = $this->hasil($this->from('/login')->post('/login', ['username' => 'rizky', 'password' => 'password']));
        $usernameTidakAda = $this->hasil($this->from('/login')->post('/login', ['username' => 'tidak.ada', 'password' => 'x']));

        $this->cocokSnapshotJson('login/terkunci.json', [
            'percobaan' => $percobaan,
            'password_benar_saat_terkunci' => $passwordBenarSaatTerkunci,
            'username_tidak_ada' => $usernameTidakAda,
            'user' => $this->statusUser('rizky'),
            'notifikasi_baru' => $this->notifikasiBaru(),
            'login' => auth()->check(),
        ]);
    }

    public function test_akun_nonaktif_tidak_bisa_login_dan_sesi_akun_terkunci_dikeluarkan(): void
    {
        User::where('username', 'agus')->update(['is_active' => false]);
        $nonaktif = $this->hasil($this->from('/login')->post('/login', ['username' => 'agus', 'password' => 'password']));

        $this->post('/login', ['username' => 'rizky', 'password' => 'password']);
        User::where('username', 'rizky')->update(['is_locked' => true]);
        $this->app['auth']->forgetGuards(); // request berikutnya memuat ulang user dari sesi, seperti request nyata
        $sesiTerkunci = $this->hasil($this->get('/dashboard'));

        $this->cocokSnapshotJson('login/nonaktif.json', [
            'nonaktif' => $nonaktif,
            'sesi_terkunci' => $sesiTerkunci,
            'login' => auth()->check(),
        ]);
    }

    public function test_lupa_password_mengirim_permintaan_ke_admin(): void
    {
        $email = User::where('username', 'rizky')->value('email');
        $cocok = $this->hasil($this->from('/lupa-password')->post('/lupa-password', ['username' => 'rizky', 'email' => (string) $email]));
        $notifCocok = $this->notifikasiBaru();
        $tidakCocok = $this->hasil($this->from('/lupa-password')->post('/lupa-password', ['username' => 'rizky', 'email' => 'lain@contoh.com']));

        $this->cocokSnapshotJson('login/lupa.json', [
            'email_terdaftar' => $email !== null,
            'cocok' => $cocok,
            'notifikasi_setelah_cocok' => $notifCocok,
            'tidak_cocok' => $tidakCocok,
            'notifikasi_setelah_tidak_cocok' => $this->notifikasiBaru(),
        ]);
    }

    // ---- helper ----

    /** @return array<string, mixed> */
    private function hasil(TestResponse $r): array
    {
        $errors = session('errors');

        return [
            'status' => $r->getStatusCode(),
            'ke' => $r->headers->get('Location'),
            'sukses' => session('sukses'),
            'errors' => $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : (is_array($errors) ? $errors : []),
        ];
    }

    /** @return array<string, mixed> */
    private function statusUser(string $username): array
    {
        $u = User::where('username', $username)->firstOrFail();

        return [
            'gagal_login' => $u->gagal_login,
            'is_locked' => $u->is_locked,
            'is_active' => $u->is_active,
            'last_login' => $u->last_login?->toDateTimeString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function notifikasiBaru(): array
    {
        return Notifikasi::where('is_read', false)->orderBy('id')->with('user:id,username')->get()
            ->map(fn ($n) => ['ke' => $n->user?->username, 'judul' => $n->judul, 'pesan' => $n->pesan, 'jenis_ref' => $n->jenis_ref])
            ->all();
    }
}
