/* Input sesuai komponen "Input" di Figma: tanggal dd/mm/yyyy + ikon kalender, Rupiah dengan titik ribuan,
   dropdown dengan panel + centang, dan pesan error merah di bawah kolom. Dipanggil ulang setelah konten AJAX dimuat. */
(function () {
    const rupiah = v => { const d = String(v ?? '').replace(/\D/g, ''); return d ? Number(d).toLocaleString('id-ID') : ''; };

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
        // Rupiah: kolom dengan data-rupiah tampil "8.500.000", dikirim tanpa titik
        root.querySelectorAll('input[data-rupiah]:not([data-siap])').forEach(el => {
            el.dataset.siap = 1; el.type = 'text'; el.inputMode = 'numeric'; el.value = rupiah(/^\d+\.\d{1,2}$/.test(el.value) ? el.value.split('.')[0] : el.value);
            el.addEventListener('input', () => { const pos = el.value.length - el.selectionStart; el.value = rupiah(el.value); el.setSelectionRange(el.value.length - pos, el.value.length - pos); });
            el.form?.addEventListener('submit', () => { el.value = el.value.replace(/\./g, ''); }, true);
        });
        // Dropdown
        root.querySelectorAll('select.form-select[multiple][data-mode=chips]:not(.tomselected)').forEach(el => {
            new TomSelect(el, { create: false, maxOptions: 500, hideSelected: true, closeAfterSelect: true, placeholder: el.dataset.placeholder || 'Pilih', plugins: ['remove_button'],
                render: { item: (d, esc) => `<div>${esc(d.text.split(' · ')[0])}</div>` } });
        });
        root.querySelectorAll('select.form-select[multiple]:not(.tomselected):not([data-native])').forEach(el => {
            // Multi pilih ringkas: kolom cuma tampil "Semua karyawan" / "3 karyawan", pilihan detail ada di chip bawah filter
            const kosong = el.dataset.placeholder || 'Semua', satuan = el.dataset.satuan || 'dipilih';
            const form = el.closest('form[data-auto-submit]'); let awal = null;
            const ringkas = ts => { const n = ts.items.length; ts.control_input.placeholder = n === 0 ? kosong : n === 1 ? ts.options[ts.items[0]]?.text : `${n} ${satuan}`; ts.wrapper.classList.toggle('ada-pilihan', n > 0); };
            new TomSelect(el, {
                create: false, maxOptions: 500, hidePlaceholder: false, hideSelected: false, closeAfterSelect: false, placeholder: kosong,
                plugins: { clear_button: { title: 'Kosongkan' } },
                render: { item: () => '<div class="d-none"></div>', option: (d, esc) => `<div class="d-flex justify-content-between align-items-center gap-2"><span>${esc(d.text)}</span><span class="cek">✓</span></div>` },
                onInitialize() { this.wrapper.classList.add('ts-ringkas'); ringkas(this); },
                onChange() { ringkas(this); if (form && !this.isOpen) form.requestSubmit(); },
                onDropdownOpen() { awal = this.items.join(','); },
                onDropdownClose() { this.setTextboxValue(''); ringkas(this); if (form && awal !== null && awal !== this.items.join(',')) form.requestSubmit(); awal = null; },
            });
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
