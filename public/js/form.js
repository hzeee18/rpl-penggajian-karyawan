/* Input sesuai komponen "Input" di Figma: tanggal dd/mm/yyyy + ikon kalender, Rupiah dengan titik ribuan,
   dropdown dengan panel + centang, dan pesan error merah di bawah kolom. Dipanggil ulang setelah konten AJAX dimuat. */
(function () {
    const rupiah = v => { const d = String(v ?? '').replace(/\D/g, ''); return d ? Number(d).toLocaleString('id-ID') : ''; };

    const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    const namaBulan = v => { const [y, m] = String(v || '').split('-').map(Number); return y && m ? BULAN[m - 1] + ' ' + y : ''; };
    // Popup pemilih bulan: tahun dengan panah, 12 bulan; klik bulan = isi nilai + event change (form auto-submit tetap jalan)
    document.addEventListener('click', e => {
        const lama = document.querySelector('.pop-bulan');
        const el = e.target.closest('input[data-bulan]');
        if (lama && !e.target.closest('.pop-bulan')) lama.remove();
        if (!el || (lama && lama._el === el)) return;
        e.preventDefault();
        let [y, m] = (el._simpan.value || '').split('-').map(Number);
        const pilihY = y, pilihM = m; y ||= new Date().getFullYear();
        const pop = document.createElement('div'); pop.className = 'pop-bulan'; pop._el = el;
        const render = () => {
            pop.innerHTML = '<div class="d-flex align-items-center mb-2"><button type="button" class="btn btn-light btn-sm" data-geser="-1" aria-label="Tahun sebelumnya">‹</button>'
                + '<div class="flex-grow-1 text-center fw-semibold">' + y + '</div><button type="button" class="btn btn-light btn-sm" data-geser="1" aria-label="Tahun berikutnya">›</button></div>'
                + '<div class="grid-bulan">' + BULAN.map((n, i) => '<button type="button" class="btn btn-sm ' + (y === pilihY && i + 1 === pilihM ? 'btn-primary' : 'btn-light') + '" data-bln="' + (i + 1) + '">' + n.slice(0, 3) + '</button>').join('') + '</div>';
        };
        render();
        pop.addEventListener('click', ev => {
            const b = ev.target.closest('button'); if (!b) return;
            if (b.dataset.geser) { y += Number(b.dataset.geser); render(); return; }
            el._simpan.value = y + '-' + String(b.dataset.bln).padStart(2, '0'); el.value = namaBulan(el._simpan.value);
            pop.remove(); el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        document.body.appendChild(pop);
        const r = (el.closest('label') && getComputedStyle(el).opacity === '0' ? el.closest('label') : el).getBoundingClientRect();
        pop.style.top = (r.bottom + window.scrollY + 4) + 'px';
        pop.style.left = Math.min(r.left + window.scrollX, window.scrollX + document.documentElement.clientWidth - 260) + 'px';
    });

    window.siapkanForm = function (root = document) {
        // Tanggal
        root.querySelectorAll('input[type=date]:not(.flatpickr-input):not([data-native])').forEach(el => {
            const wrap = document.createElement('div');
            wrap.className = 'input-ikon';
            el.parentNode.insertBefore(wrap, el); wrap.appendChild(el);
            const ic = document.createElement('i'); ic.setAttribute('data-lucide', 'calendar'); wrap.appendChild(ic);
            const auto = el.getAttribute('onchange');
            flatpickr(el, {
                locale: 'id', dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', allowInput: true,
                altInputClass: el.className + ' fp-alt',
                onChange: () => { if (auto) el.dispatchEvent(new Event('change', { bubbles: true })); },
            });
            const alt = el.nextElementSibling; if (alt) alt.placeholder = 'dd/mm/yyyy';
        });
        // Rentang tanggal: satu input, isi dua field tersembunyi (awal & akhir)
        root.querySelectorAll('input[data-rentang]:not(.flatpickr-input)').forEach(el => {
            const wrap = el.closest('[data-rentang-wrap]');
            const awal = wrap.querySelector('[data-rentang-awal]'), akhir = wrap.querySelector('[data-rentang-akhir]');
            const auto = el.closest('form[data-auto-submit]');
            const isi = (d1, d2, fp) => {
                const lama = awal.value + akhir.value;
                awal.value = d1 ? fp.formatDate(d1, 'Y-m-d') : ''; akhir.value = d2 ? fp.formatDate(d2, 'Y-m-d') : '';
                if (auto && d2 && lama !== awal.value + akhir.value) auto.requestSubmit();
            };
            flatpickr(el, {
                locale: Object.assign({}, flatpickr.l10ns.id, { rangeSeparator: ' – ' }), mode: 'range', dateFormat: 'd/m/Y',
                defaultDate: [awal.value, akhir.value].filter(Boolean).map(v => flatpickr.parseDate(v, 'Y-m-d')),
                disable: JSON.parse(el.dataset.nonaktif || '[]').map(r => ({ from: flatpickr.parseDate(r.from, 'Y-m-d'), to: flatpickr.parseDate(r.to, 'Y-m-d') })),
                onChange: (d, s, fp) => { if (d.length === 2) isi(d[0], d[1], fp); },
                // kalau cuma pilih satu tanggal lalu ditutup, anggap rentang satu hari
                onClose: (d, s, fp) => { if (d.length === 1) { fp.setDate([d[0], d[0]], false); isi(d[0], d[0], fp); } if (!d.length) isi(null, null, fp); },
            });
        });
        // Bulan: input type=month diganti pemilih bulan sendiri (Safari desktop tidak punya kalender bulan).
        // Nilai Y-m dikirim lewat input tersembunyi; kolom yang terlihat menampilkan "November 2026".
        root.querySelectorAll('input[type=month]:not([data-siap])').forEach(el => {
            el.dataset.siap = 1;
            const simpan = document.createElement('input'); simpan.type = 'hidden'; simpan.name = el.name; simpan.value = el.value;
            el.after(simpan); el.removeAttribute('name'); el.removeAttribute('onclick');
            el.type = 'text'; el.readOnly = true; el.dataset.bulan = 1; el._simpan = simpan;
            el.value = namaBulan(simpan.value); el.style.cursor = 'pointer';
        });
        // Select dengan keterangan: teks opsi dibuat pendek, keterangan opsi terpilih (data-ket) tampil di bawah select
        root.querySelectorAll('select:not([data-ket-siap])').forEach(el => {
            if (!el.querySelector('option[data-ket]')) return;
            el.dataset.ketSiap = 1;
            const ket = document.createElement('div'); ket.className = 'form-text ket-pilihan';
            el.after(ket);
            const isi = () => { const o = el.selectedOptions[0]; ket.textContent = o?.dataset.ket || ''; ket.hidden = !ket.textContent; };
            el.addEventListener('change', isi); isi();
        });
        // Rupiah: kolom dengan data-rupiah tampil "8.500.000", dikirim tanpa titik
        root.querySelectorAll('input[data-rupiah]:not([data-siap])').forEach(el => {
            el.dataset.siap = 1; el.type = 'text'; el.inputMode = 'numeric'; el.value = rupiah(/^\d+\.\d{1,2}$/.test(el.value) ? el.value.split('.')[0] : el.value);
            el.addEventListener('input', () => { const pos = el.value.length - el.selectionStart; el.value = rupiah(el.value); el.setSelectionRange(el.value.length - pos, el.value.length - pos); });
            el.form?.addEventListener('submit', () => { el.value = el.value.replace(/\./g, ''); }, true);
        });
        // Multi pilih: klik pilihan yang sudah terpilih = batal pilih (toggle). Bawaan Tom Select mengabaikan klik itu.
        const toggleMulti = ts => {
            const dd = ts.dropdown_content;
            dd.addEventListener('mousedown', e => { if (e.target.closest('.option.selected')) { e.preventDefault(); e.stopPropagation(); } }, true);
            dd.addEventListener('click', e => {
                const opt = e.target.closest('.option.selected');
                if (!opt) return;
                e.preventDefault(); e.stopPropagation();
                ts.removeItem(opt.dataset.value);
                ts.refreshOptions(false);
            }, true);
        };
        // Filter yang kirim otomatis: form biasa (halaman dimuat ulang, tanpa Turbo/ajax); posisi scroll dipulihkan app.js
        root.querySelectorAll('form[data-auto-submit]:not([data-siap-biasa])').forEach(f => {
            f.dataset.siapBiasa = 1; f.dataset.turbo = 'false';
            f.addEventListener('submit', () => sessionStorage.setItem('scrollPos', JSON.stringify({ p: location.pathname, y: scrollY })));
        });
        // Dropdown
        root.querySelectorAll('select.form-select[multiple][data-mode=chips]:not(.tomselected)').forEach(el => {
            const ts = new TomSelect(el, { create: false, maxOptions: 500, hideSelected: false, closeAfterSelect: false, placeholder: el.dataset.placeholder || 'Pilih', plugins: ['remove_button'],
                render: { item: (d, esc) => `<div>${esc(d.text.split(' · ')[0])}</div>`, option: (d, esc) => `<div class="d-flex justify-content-between align-items-center gap-2"><span>${esc(d.text)}</span><span class="cek">✓</span></div>` } });
            toggleMulti(ts);
        });
        root.querySelectorAll('select.form-select[multiple]:not(.tomselected):not([data-native])').forEach(el => {
            // Multi pilih ringkas: kolom cuma tampil "Semua karyawan" / "3 karyawan", pilihan detail ada di chip bawah filter
            const kosong = el.dataset.placeholder || 'Semua', satuan = el.dataset.satuan || 'dipilih';
            const form = el.closest('form[data-auto-submit]'); let awal = null;
            const ringkas = ts => { const n = ts.items.length; ts.control_input.placeholder = n === 0 ? kosong : n === 1 ? ts.options[ts.items[0]]?.text : `${n} ${satuan}`; ts.wrapper.classList.toggle('ada-pilihan', n > 0); };
            const ts = new TomSelect(el, {
                create: false, maxOptions: 500, hidePlaceholder: false, hideSelected: false, closeAfterSelect: false, placeholder: kosong,
                plugins: { clear_button: { title: 'Kosongkan' } },
                render: { item: () => '<div class="d-none"></div>', option: (d, esc) => `<div class="d-flex justify-content-between align-items-center gap-2"><span>${esc(d.text)}</span><span class="cek">✓</span></div>` },
                onInitialize() { this.wrapper.classList.add('ts-ringkas'); ringkas(this); },
                onChange() { ringkas(this); if (form && !this.isOpen) form.requestSubmit(); },
                onDropdownOpen() { awal = this.items.join(','); },
                onDropdownClose() { this.setTextboxValue(''); ringkas(this); if (form && awal !== null && awal !== this.items.join(',')) form.requestSubmit(); awal = null; },
            });
            toggleMulti(ts);
        });
        root.querySelectorAll('select.form-select:not(.tomselected):not([data-native])').forEach(el => {
            new TomSelect(el, {
                create: false, allowEmptyOption: true, maxOptions: 500,
                controlInput: el.options.length > 8 ? '<input>' : null,
                render: { option: (d, esc) => `<div class="d-flex justify-content-between align-items-center"><span>${esc(d.text)}</span><span class="cek">✓</span></div>` },
            });
        });
        // Error validasi dari server → border merah + pesan di bawah kolom
        Object.entries(window.__errors || {}).forEach(([nama, pesan]) => {
            const n = nama.replace(/\.(\w+)/g, '[$1]');
            root.querySelectorAll(`[name="${n}"], [name="${n}[]"]`).forEach(el => {
                const target = el.closest('[data-rentang-wrap]')?.querySelector('[data-rentang]') || (el.classList.contains('tomselected') ? el.nextElementSibling : (el.classList.contains('flatpickr-input') ? el.nextElementSibling : el));
                target?.classList.add('is-invalid');
                const host = el.closest('.input-group, .input-ikon') || target;
                if (host && !host.parentNode.querySelector('.pesan-error')) {
                    const m = document.createElement('div'); m.className = 'pesan-error'; m.textContent = pesan[0].charAt(0).toUpperCase() + pesan[0].slice(1);
                    host.insertAdjacentElement('afterend', m);
                }
            });
        });
        if (window.lucide) lucide.createIcons();
    };
})();
