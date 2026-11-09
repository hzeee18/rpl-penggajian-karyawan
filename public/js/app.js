/* Navigasi tanpa reload penuh (Turbo Drive) + perilaku global aplikasi.
   Listener global dipasang sekali; inisialisasi per halaman dijalankan di setiap turbo:load. */
(function () {
    if (window.__appInit) return;
    window.__appInit = true;

    // ---- pertahankan posisi scroll kalau masih di halaman (path) yang sama: filter, simpan, buka drawer ----
    document.addEventListener('turbo:before-visit', () => sessionStorage.setItem('scrollPos', JSON.stringify({ p: location.pathname, y: scrollY })));
    document.addEventListener('turbo:submit-start', () => sessionStorage.setItem('scrollPos', JSON.stringify({ p: location.pathname, y: scrollY })));

    // ---- bersihkan modal/dropdown/backdrop sebelum Turbo menyimpan snapshot halaman.
    //      Tanpa ini, tombol Back memulihkan halaman dengan modal "terbuka" tak terlihat yang menutupi header. ----
    const bersihkanOverlay = () => {
        document.querySelectorAll('.modal.show').forEach(m => { bootstrap.Modal.getInstance(m)?.dispose(); m.classList.remove('show'); m.style.display = 'none'; m.removeAttribute('aria-modal'); m.setAttribute('aria-hidden', 'true'); });
        document.querySelectorAll('.dropdown-menu.show, [data-bs-toggle=dropdown].show').forEach(el => el.classList.remove('show'));
        document.querySelectorAll('.offcanvas.show').forEach(o => bootstrap.Offcanvas.getInstance(o)?.hide());
        document.querySelectorAll('.modal-backdrop, .offcanvas-backdrop').forEach(b => b.remove());
        document.querySelectorAll('.flatpickr-calendar.open').forEach(c => c.classList.remove('open'));
        document.body.classList.remove('modal-open'); document.body.style.removeProperty('overflow'); document.body.style.removeProperty('padding-right');
    };
    document.addEventListener('turbo:before-cache', bersihkanOverlay);
    document.addEventListener('turbo:load', () => { if (!document.querySelector('.modal.show')) { document.querySelectorAll('.modal-backdrop').forEach(b => b.remove()); document.body.classList.remove('modal-open'); document.body.style.removeProperty('overflow'); } });

    // ---- pencarian cepat di header ----
    let cariTimer, cariCtl;
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const tutupCari = f => { const h = f?.querySelector('.cari-hasil'); if (h) h.hidden = true; };
    const pilihCari = (f, arah) => {
        const items = [...f.querySelectorAll('.cari-item')]; if (!items.length) return;
        let i = items.findIndex(x => x.classList.contains('aktif')); items[i]?.classList.remove('aktif');
        i = (i + arah + items.length) % items.length; items[i].classList.add('aktif'); items[i].scrollIntoView({ block: 'nearest' });
    };
    document.addEventListener('input', e => {
        const f = e.target.closest('form[data-cari]'); if (!f || e.target.name !== 'q') return;
        const q = e.target.value.trim(), h = f.querySelector('.cari-hasil');
        clearTimeout(cariTimer); cariCtl?.abort();
        if (q.length < 2) { h.hidden = true; return; }
        cariTimer = setTimeout(async () => {
            cariCtl = new AbortController();
            h.hidden = false; h.innerHTML = '<div class="cari-kosong">Mencari…</div>';
            try {
                const r = await fetch(f.dataset.cari + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: cariCtl.signal });
                const { grup } = await r.json();
                h.innerHTML = grup.length ? grup.map(g => `<div class="cari-grup">${esc(g.nama)}</div>` + g.item.map(it =>
                    `<a class="cari-item" href="${esc(it.url)}">${it.inisial ? `<span class="avatar avatar-sm">${esc(it.inisial)}</span>` : `<span class="cari-ikon"><i data-lucide="${esc(it.ikon)}"></i></span>`}<span class="min-w-0"><span class="d-block fw-semibold text-truncate">${esc(it.judul)}</span><span class="d-block small text-muted text-truncate">${esc(it.sub)}</span></span></a>`).join('')).join('')
                    : `<div class="cari-kosong">Tidak ada hasil untuk “${esc(q)}”.</div>`;
                window.lucide && lucide.createIcons();
            } catch (err) { if (err.name !== 'AbortError') h.innerHTML = '<div class="cari-kosong">Pencarian gagal, coba lagi.</div>'; }
        }, 200);
    });
    document.addEventListener('keydown', e => {
        const f = e.target.closest?.('form[data-cari]'); if (!f) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); pilihCari(f, e.key === 'ArrowDown' ? 1 : -1); }
        else if (e.key === 'Escape') tutupCari(f);
        else if (e.key === 'Enter') {
            const a = f.querySelector('.cari-item.aktif') || (!f.querySelector('.cari-hasil').hidden && f.querySelector('.cari-item'));
            if (a) { e.preventDefault(); tutupCari(f); Turbo.visit(a.href); }
        }
    });
    document.addEventListener('focusin', e => { const f = e.target.closest?.('form[data-cari]'); if (f && f.querySelector('.cari-item') && e.target.value.trim().length >= 2) f.querySelector('.cari-hasil').hidden = false; });
    document.addEventListener('click', e => { document.querySelectorAll('form[data-cari]').forEach(f => { if (!f.contains(e.target)) tutupCari(f); else if (e.target.closest('.cari-item')) tutupCari(f); }); });

    // ---- modal riwayat: pindah daftar <-> detail (baca saja) ----
    const riwayatKe = (modal, target, judul) => {
        modal.querySelector('.riwayat-daftar').classList.toggle('d-none', !!target);
        modal.querySelectorAll('.riwayat-detail').forEach(d => d.classList.toggle('d-none', d !== target));
        modal.querySelector('[data-riwayat-kembali]').classList.toggle('d-none', !target);
        modal.querySelector('[data-riwayat-judul]').textContent = judul || 'Riwayat Peraturan Pajak';
        modal.querySelector('.modal-body').scrollTop = 0;
    };
    document.addEventListener('click', e => {
        const buka = e.target.closest('[data-riwayat-buka]'); const kembali = e.target.closest('[data-riwayat-kembali]');
        if (buka) { e.preventDefault(); const m = buka.closest('.modal'); riwayatKe(m, m.querySelector(buka.dataset.riwayatBuka), buka.dataset.judul); }
        if (kembali) { riwayatKe(kembali.closest('.modal'), null); }
    });
    document.addEventListener('hidden.bs.modal', e => { if (e.target.querySelector('.riwayat-daftar')) riwayatKe(e.target, null); });

    // ---- komponen gaji: sisip variabel ke rumus + pratinjau hasil ----
    let rumusTimer;
    const pratinjauRumus = () => {
        const box = document.querySelector('.pratinjau-rumus'), ta = document.getElementById('rumusKomp'); if (!box || !ta) return;
        clearTimeout(rumusTimer);
        rumusTimer = setTimeout(async () => {
            const hasil = box.querySelector('.hasil');
            if (!ta.value.trim()) { hasil.innerHTML = '<span class="text-muted small">Ketik rumus untuk melihat hasilnya.</span>'; return; }
            const fd = new FormData(); fd.append('rumus', ta.value); fd.append('kode', document.getElementById('kodeKomp')?.value || ''); fd.append('id', box.dataset.id || '');
            fd.append('karyawan_id', document.getElementById('contohKaryawan')?.value || ''); fd.append('nilai_default', ta.form.querySelector('[name=nilai_default]')?.value || 0);
            fd.append('_token', ta.form.querySelector('[name=_token]').value);
            try {
                const r = await (await fetch(box.dataset.url, { method: 'POST', body: fd, headers: { Accept: 'application/json' } })).json();
                ta.classList.toggle('is-invalid', !r.ok);
                hasil.innerHTML = r.ok
                    ? `<div class="d-flex justify-content-between align-items-baseline"><span class="small text-muted">${esc(r.karyawan)}</span><span class="fs-5 fw-bold text-primary">${esc(r.nilai)}</span></div>${r.rincian.length ? `<div class="small text-muted mt-1">${r.rincian.map(esc).join(' · ')}</div>` : ''}`
                    : `<div class="small text-danger"><i data-lucide="circle-alert" style="width:14px;height:14px"></i> ${esc(r.pesan)}</div>`;
                window.lucide && lucide.createIcons();
            } catch (e) { hasil.innerHTML = '<span class="small text-danger">Pratinjau gagal dimuat.</span>'; }
        }, 300);
    };
    document.addEventListener('input', e => { if (e.target.id === 'rumusKomp' || e.target.id === 'kodeKomp' || e.target.name === 'nilai_default') pratinjauRumus(); });
    document.addEventListener('change', e => { if (e.target.id === 'contohKaryawan') pratinjauRumus(); });
    // tombol kalkulator rumus: sisip operator / fungsi / variabel di posisi kursor, atau hapus bagian terakhir
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-sisip], [data-hapus]'); if (!b) return;
        const ta = document.getElementById('rumusKomp'); if (!ta) return;
        const [a, z] = [ta.selectionStart ?? ta.value.length, ta.selectionEnd ?? ta.value.length];
        let kiri = ta.value.slice(0, a).replace(/\s+$/, ''), kanan = ta.value.slice(z);
        if (b.hasAttribute('data-hapus')) {
            kiri = kiri.replace(/([A-Z_][A-Z0-9_]*\(?|[0-9.]+%?|.)$/i, '').replace(/\s+$/, '');
        } else {
            const isi = b.dataset.sisip;
            if (isi === ')' || isi === '%') kiri += isi;
            else if (isi === ',') kiri += ', ';
            else if (b.hasAttribute('data-op') && isi !== '(') kiri += (kiri ? ' ' : '') + isi + ' ';
            else kiri += (kiri && !/[(\s]$/.test(kiri) ? ' ' : '') + isi;
            if (b.hasAttribute('data-fungsi')) kanan = ')' + kanan;
        }
        ta.value = kiri + kanan; ta.focus(); ta.setSelectionRange(kiri.length, kiri.length); pratinjauRumus();
    });
    document.addEventListener('turbo:load', () => pratinjauRumus());

    // ---- drawer kanan ----
    const bukaDrawer = sel => { const d = document.querySelector(sel); if (!d) return; d.classList.add('buka'); document.body.classList.add('drawer-terbuka'); };
    const tutupDrawer = () => {
        document.querySelectorAll('.drawer.buka').forEach(d => d.classList.remove('buka'));
        document.body.classList.remove('drawer-terbuka');
        const u = new URL(location.href); ['ubah', 'lihat', 'baru', 'perbarui'].forEach(k => u.searchParams.delete(k));
        history.replaceState(history.state, '', u);
    };
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-drawer]'); if (b) { e.preventDefault(); bukaDrawer(b.dataset.drawer); }
        if (e.target.closest('[data-tutup-drawer]')) { e.preventDefault(); tutupDrawer(); }
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && document.querySelector('.drawer.buka')) tutupDrawer(); });

    // ---- konfirmasi sebelum aksi yang tidak bisa dibatalkan (modal seperti Figma) ----
    document.addEventListener('submit', e => {
        const f = e.target, msg = f.dataset.confirm;
        if (!msg || f.dataset.ok) return;
        e.preventDefault(); e.stopImmediatePropagation();
        const hapus = (f.querySelector('input[name=_method]')?.value || '').toUpperCase() === 'DELETE';
        document.getElementById('konfJudul').textContent = f.dataset.confirmJudul || (hapus ? 'Hapus data ini?' : 'Lanjutkan?');
        document.getElementById('konfPesan').textContent = msg;
        const ok = document.getElementById('konfOk');
        ok.textContent = f.dataset.confirmOk || (hapus ? 'Ya, Hapus' : 'Ya, Lanjutkan');
        ok.className = 'btn ' + (hapus ? 'btn-danger' : 'btn-primary');
        const modal = bootstrap.Modal.getOrCreateInstance('#modalKonfirmasi');
        ok.onclick = () => { f.dataset.ok = 1; modal.hide(); f.requestSubmit(e.submitter || undefined); delete f.dataset.ok; };
        modal.show();
    }, true);

    // ---- per halaman ----
    document.addEventListener('turbo:load', () => {
        if (window.lucide) lucide.createIcons();
        if (document.querySelector('.drawer.buka')) document.body.classList.add('drawer-terbuka'); else document.body.classList.remove('drawer-terbuka');
        // unduhan file (PDF/Excel) jangan lewat Turbo
        document.querySelectorAll('a[href]').forEach(a => { if (/\/(unduh|excel|pdf|template|kontrak-pdf)\b|export=1/.test(a.getAttribute('href'))) a.dataset.turbo = 'false'; });
        if (window.siapkanForm) siapkanForm();
        if (window.__bukaModalPassword) bootstrap.Modal.getOrCreateInstance('#modalPassword').show();
        try {
            const s = JSON.parse(sessionStorage.getItem('scrollPos') || 'null');
            if (s && s.p === location.pathname) scrollTo(0, s.y);
            sessionStorage.removeItem('scrollPos');
        } catch (e) {}
    });
})();
