<?php

namespace App\Http\Controllers;

use App\Models\Notifikasi;
use Illuminate\Http\Request;

class NotifikasiController extends Controller
{
    public function index(Request $request)
    {
        $notifikasi = $request->user()->notifikasi()->paginate(20);

        return view('notifikasi.index', compact('notifikasi'));
    }

    public function buka(Request $request, Notifikasi $notifikasi)
    {
        abort_unless($notifikasi->user_id === $request->user()->id, 404);
        $notifikasi->tandaiDibaca();

        return redirect($notifikasi->link());
    }

    public function bacaSemua(Request $request)
    {
        $request->user()->notifikasi()->update(['is_read' => true]);

        return back()->with('sukses', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
