<div class="row g-3 mb-4">
    @foreach ($stat as [$label, $angka, $ket])
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card h-100"><div class="card-body">
                <div class="label">{{ $label }}</div>
                <div class="angka">{{ $angka }}</div>
                <div class="ket">{{ $ket }}</div>
            </div></div>
        </div>
    @endforeach
</div>
