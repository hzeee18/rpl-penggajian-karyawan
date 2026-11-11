<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Slip Gaji {{ $re->full_name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 16px; margin: 0; } .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; } td, th { padding: 4px 6px; }
        .garis td { border-bottom: 1px solid #e5e7eb; } .kanan { text-align: right; }
        .kotak { border: 1px solid #d1d5db; padding: 8px; margin-top: 10px; }
        .thp { font-size: 14px; font-weight: bold; }
    </style>
</head>
<body>
    <table><tr>
        <td><h1>{{ $company->name }}</h1><div class="muted">{{ $company->address }}</div></td>
        <td class="kanan"><strong>SLIP GAJI</strong><div class="muted">{{ $re->run->title }}</div>
            <div class="muted">Periode {{ $re->period_start->translatedFormat('j F Y') }} - {{ $re->period_end->translatedFormat('j F Y') }}</div></td>
    </tr></table>

    <div class="kotak"><table>
        <tr><td>Nama</td><td>: {{ $re->full_name }}</td><td>No. Karyawan</td><td>: {{ $re->employee_no }}</td></tr>
        <tr><td>Departemen</td><td>: {{ $re->department_name }}</td><td>Jabatan</td><td>: {{ $re->position_name }}</td></tr>
        <tr><td>Status PTKP</td><td>: {{ $re->ptkp_status }}</td><td>Bank</td><td>: {{ $re->bank_name }}</td></tr>
    </table></div>

    <table style="margin-top:12px"><tr valign="top">
        <td style="width:50%"><strong>Penambah</strong><table>
            @foreach ($penambah as $i)<tr class="garis"><td>{{ $i->name }}</td><td class="kanan">{{ rupiah($i->final_amount) }}</td></tr>@endforeach
            <tr><td><strong>Total penambah</strong></td><td class="kanan"><strong>{{ rupiah($re->total_earnings) }}</strong></td></tr></table></td>
        <td style="width:50%"><strong>Pengurang</strong><table>
            @foreach ($pengurang as $i)<tr class="garis"><td>{{ $i->name }}</td><td class="kanan">{{ rupiah($i->final_amount) }}</td></tr>@endforeach
            <tr><td><strong>Total pengurang</strong></td><td class="kanan"><strong>{{ rupiah($re->total_deductions) }}</strong></td></tr></table></td>
    </tr></table>

    <div class="kotak"><table><tr><td class="thp">Take Home Pay</td><td class="kanan thp">{{ rupiah($re->take_home_pay) }}</td></tr></table></div>

    <table style="margin-top:30px"><tr><td></td><td class="kanan" style="width:40%">
        <div>{{ $company->signer_title }}</div>
        @if ($tandaTangan)<img src="{{ $tandaTangan }}" style="height:60px" alt="tanda tangan">@else<div style="height:60px"></div>@endif
        <div><strong>{{ $company->signer_name }}</strong></div>
    </td></tr></table>
    <div class="muted" style="margin-top:20px">Dokumen ini dibuat otomatis oleh sistem payroll dan bersifat rahasia.</div>
</body>
</html>
