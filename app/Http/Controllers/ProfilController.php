<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfilController extends Controller
{
    public const PTKP = [
        'TK/0' => 'tidak kawin, tanpa tanggungan', 'TK/1' => 'tidak kawin, 1 tanggungan', 'TK/2' => 'tidak kawin, 2 tanggungan',
        'TK/3' => 'tidak kawin, 3 tanggungan', 'K/0' => 'kawin, tanpa tanggungan', 'K/1' => 'kawin, 1 tanggungan',
        'K/2' => 'kawin, 2 tanggungan', 'K/3' => 'kawin, 3 tanggungan',
    ];

    public function index(Request $request)
    {
        $karyawan = $request->user()->karyawan?->load(['departemen.manager', 'pajak', 'position', 'kontrakPayroll.components.component']);
        $payrollBaru = true;

        return view('profil.index', compact('karyawan', 'payrollBaru'));
    }
}
