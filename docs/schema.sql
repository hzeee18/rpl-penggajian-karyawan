-- =====================================================================
-- Sistem Penggajian Dinamis (multi-tenant) -- DDL MySQL 8.0+
-- Engine : InnoDB, utf8mb4
-- Catatan: dibuat untuk dijadikan acuan migration Laravel (lihat AGENTS.md).
--          Tabel infrastruktur Laravel (jobs, failed_jobs, cache, sessions,
--          password_reset_tokens) dibuat lewat artisan, tidak ada di sini.
-- Kolom sensitif (npwp, bank_account_no) disimpan terenkripsi di level
-- aplikasi (cast `encrypted` Laravel), jadi tipenya VARCHAR(255).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. TENANT & AKSES
-- ---------------------------------------------------------------------

CREATE TABLE companies (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(32)  NOT NULL,
    name            VARCHAR(191) NOT NULL,
    address         TEXT NULL,
    logo_path       VARCHAR(255) NULL,
    signer_name     VARCHAR(191) NULL,              -- penanda tangan slip
    signer_title    VARCHAR(191) NULL,
    signature_path  VARCHAR(255) NULL,              -- gambar tanda tangan di slip
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_companies_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- company_id NULL = role level platform (Super Admin)
CREATE TABLE roles (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  BIGINT UNSIGNED NULL,
    company_key BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(company_id, 0)) STORED,
    code        VARCHAR(32)  NOT NULL,              -- super_admin, administrator, hr, manager, employee
    name        VARCHAR(100) NOT NULL,
    is_system   TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_company_code (company_key, code),
    CONSTRAINT fk_roles_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Menu bersifat global (dikelola Super Admin / lewat seeder)
CREATE TABLE menus (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id   BIGINT UNSIGNED NULL,
    code        VARCHAR(64)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    route       VARCHAR(191) NULL,
    icon        VARCHAR(64)  NULL,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    is_platform TINYINT(1) NOT NULL DEFAULT 0,      -- hanya untuk Super Admin
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menus_code (code),
    CONSTRAINT fk_menus_parent FOREIGN KEY (parent_id) REFERENCES menus (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Privilege per menu dan per aksi
CREATE TABLE role_menu_permissions (
    role_id     BIGINT UNSIGNED NOT NULL,
    menu_id     BIGINT UNSIGNED NOT NULL,
    can_view    TINYINT(1) NOT NULL DEFAULT 0,
    can_create  TINYINT(1) NOT NULL DEFAULT 0,
    can_update  TINYINT(1) NOT NULL DEFAULT 0,
    can_delete  TINYINT(1) NOT NULL DEFAULT 0,
    can_approve TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (role_id, menu_id),
    CONSTRAINT fk_rmp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rmp_menu FOREIGN KEY (menu_id) REFERENCES menus (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1 user = 1 role. company_id NULL = user platform (Super Admin).
-- approver_user_id: untuk user HR, manager yang menyetujui payroll buatannya.
-- FK employee_id ditambahkan lewat ALTER setelah tabel employees dibuat.
CREATE TABLE users (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id       BIGINT UNSIGNED NULL,
    role_id          BIGINT UNSIGNED NOT NULL,
    employee_id      BIGINT UNSIGNED NULL,
    approver_user_id BIGINT UNSIGNED NULL,
    name             VARCHAR(191) NOT NULL,
    email            VARCHAR(191) NOT NULL,
    password         VARCHAR(255) NOT NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at    TIMESTAMP NULL,
    remember_token   VARCHAR(100) NULL,
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_employee (employee_id),
    KEY idx_users_company (company_id),
    CONSTRAINT fk_users_company  FOREIGN KEY (company_id) REFERENCES companies (id),
    CONSTRAINT fk_users_role     FOREIGN KEY (role_id) REFERENCES roles (id),
    CONSTRAINT fk_users_approver FOREIGN KEY (approver_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. DATA MASTER PER PERUSAHAAN
-- ---------------------------------------------------------------------

-- Cut off. Satu konfigurasi per perusahaan.
-- Contoh lintas bulan: start_day=26, end_day=25, start_in_previous_month=1
--   => periode "November" = 26 Okt s/d 25 Nov.
CREATE TABLE payroll_period_configs (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id              BIGINT UNSIGNED NOT NULL,
    start_day               TINYINT UNSIGNED NOT NULL,
    end_day                 TINYINT UNSIGNED NOT NULL,
    start_in_previous_month TINYINT(1) NOT NULL DEFAULT 0,
    created_at              TIMESTAMP NULL,
    updated_at              TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ppc_company (company_id),
    CONSTRAINT fk_ppc_company FOREIGN KEY (company_id) REFERENCES companies (id),
    CONSTRAINT ck_ppc_days CHECK (start_day BETWEEN 1 AND 31 AND end_day BETWEEN 1 AND 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE holidays (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id   BIGINT UNSIGNED NOT NULL,
    holiday_date DATE NOT NULL,
    name         VARCHAR(191) NOT NULL,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_holidays_company_date (company_id, holiday_date),
    CONSTRAINT fk_holidays_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    code       VARCHAR(32)  NOT NULL,
    name       VARCHAR(191) NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_company_code (company_id, code),
    CONSTRAINT fk_departments_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE positions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    code       VARCHAR(32)  NOT NULL,
    name       VARCHAR(191) NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_positions_company_code (company_id, code),
    CONSTRAINT fk_positions_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Karyawan tidak pernah dihapus, hanya dinonaktifkan (is_active = 0).
-- employee_no dipakai untuk password PDF slip (2 digit pertama).
CREATE TABLE employees (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      BIGINT UNSIGNED NOT NULL,
    employee_no     VARCHAR(32)  NOT NULL,
    nik             VARCHAR(32)  NOT NULL,          -- kunci pencocokan saat import CSV
    full_name       VARCHAR(191) NOT NULL,
    email           VARCHAR(191) NULL,
    department_id   BIGINT UNSIGNED NULL,
    position_id     BIGINT UNSIGNED NULL,
    join_date       DATE NOT NULL,
    resign_date     DATE NULL,
    ptkp_status     VARCHAR(10)  NOT NULL,          -- TK/0, K/1, dst. (dipetakan ke kategori TER via tabel referensi)
    has_npwp        TINYINT(1) NOT NULL DEFAULT 0,
    npwp            VARCHAR(255) NULL,              -- terenkripsi
    bank_name       VARCHAR(100) NULL,
    bank_account_no VARCHAR(255) NULL,              -- terenkripsi; 4 digit terakhir untuk password slip
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employees_company_no (company_id, employee_no),
    UNIQUE KEY uq_employees_company_nik (company_id, nik),
    KEY idx_employees_department (department_id),
    CONSTRAINT fk_employees_company    FOREIGN KEY (company_id)    REFERENCES companies (id),
    CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments (id),
    CONSTRAINT fk_employees_position   FOREIGN KEY (position_id)   REFERENCES positions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees (id);

CREATE TABLE leave_types (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    code       VARCHAR(32)  NOT NULL,               -- cuti, izin, sakit, sakit_bersurat, ...
    name       VARCHAR(100) NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_leave_types_company_code (company_id, code),
    CONSTRAINT fk_leave_types_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Katalog komponen. Hanya dinonaktifkan, tidak dihapus.
-- is_taxable melekat di komponen, tidak bisa diubah per karyawan.
-- is_contractable = 1 -> boleh dimasukkan ke kontrak (Gaji Pokok, Uang Makan, Tunjangan).
-- is_contractable = 0 -> komponen hasil rumus saja (Lembur, PPh21, THR).
CREATE TABLE components (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(64)  NOT NULL,          -- slug dipakai di rumus, a-z 0-9 _
    name            VARCHAR(191) NOT NULL,
    type            ENUM('earning','deduction') NOT NULL,
    is_taxable      TINYINT(1) NOT NULL DEFAULT 0,
    is_contractable TINYINT(1) NOT NULL DEFAULT 1,
    default_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_components_company_code (company_id, code),
    CONSTRAINT fk_components_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. KONTRAK
-- ---------------------------------------------------------------------

-- Update kontrak = buat baris baru, baris lama is_active = 0
-- (previous_contract_id menyambung riwayat).
-- active_employee_id memastikan 1 karyawan hanya punya 1 kontrak aktif.
CREATE TABLE contracts (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id           BIGINT UNSIGNED NOT NULL,
    employee_id          BIGINT UNSIGNED NOT NULL,
    previous_contract_id BIGINT UNSIGNED NULL,
    contract_name        VARCHAR(191) NOT NULL,     -- mis. "PKWT ABCD"
    start_date           DATE NOT NULL,
    end_date             DATE NULL,
    is_active            TINYINT(1) NOT NULL DEFAULT 1,
    active_employee_id   BIGINT UNSIGNED GENERATED ALWAYS AS (IF(is_active = 1, employee_id, NULL)) STORED,
    info_thp             DECIMAL(15,2) NULL,        -- hanya informasi, bukan acuan hitung
    created_by           BIGINT UNSIGNED NULL,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contracts_one_active (active_employee_id),
    KEY idx_contracts_employee (employee_id),
    CONSTRAINT fk_contracts_company  FOREIGN KEY (company_id)  REFERENCES companies (id),
    CONSTRAINT fk_contracts_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_contracts_previous FOREIGN KEY (previous_contract_id) REFERENCES contracts (id),
    CONSTRAINT fk_contracts_creator  FOREIGN KEY (created_by)  REFERENCES users (id),
    CONSTRAINT ck_contracts_dates CHECK (end_date IS NULL OR end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nilai default komponen DISALIN saat kontrak dibuat; boleh diubah per karyawan.
CREATE TABLE contract_components (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id  BIGINT UNSIGNED NOT NULL,
    component_id BIGINT UNSIGNED NOT NULL,
    amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cc_contract_component (contract_id, component_id),
    CONSTRAINT fk_cc_contract  FOREIGN KEY (contract_id)  REFERENCES contracts (id) ON DELETE CASCADE,
    CONSTRAINT fk_cc_component FOREIGN KEY (component_id) REFERENCES components (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. ABSENSI, LEAVE, LEMBUR + IMPORT CSV
-- ---------------------------------------------------------------------

CREATE TABLE import_batches (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  BIGINT UNSIGNED NOT NULL,
    type        ENUM('employees','attendances','leaves','overtimes') NOT NULL,
    file_name   VARCHAR(255) NOT NULL,
    total_rows  INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows  INT UNSIGNED NOT NULL DEFAULT 0,
    status      ENUM('previewing','committed','cancelled') NOT NULL DEFAULT 'previewing',
    created_by  BIGINT UNSIGNED NULL,
    committed_at TIMESTAMP NULL,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_import_batches_company (company_id, type, status),
    CONSTRAINT fk_ib_company FOREIGN KEY (company_id) REFERENCES companies (id),
    CONSTRAINT fk_ib_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Baris staging untuk preview (merah = error). Diperbaiki / dihapus, baru di-insert.
CREATE TABLE import_batch_rows (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id      BIGINT UNSIGNED NOT NULL,
    row_no        INT UNSIGNED NOT NULL,
    payload       JSON NOT NULL,
    is_valid      TINYINT(1) NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_ibr_batch (batch_id, is_valid),
    CONSTRAINT fk_ibr_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satu baris = hadir pada hari itu. late_hours dari kolom CSV.
CREATE TABLE attendances (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      BIGINT UNSIGNED NOT NULL,
    employee_id     BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    check_in        TIME NULL,
    check_out       TIME NULL,
    late_hours      DECIMAL(5,2) NOT NULL DEFAULT 0,
    import_batch_id BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendances_employee_date (employee_id, attendance_date),
    KEY idx_attendances_company_date (company_id, attendance_date),
    CONSTRAINT fk_att_company  FOREIGN KEY (company_id)  REFERENCES companies (id),
    CONSTRAINT fk_att_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_att_batch    FOREIGN KEY (import_batch_id) REFERENCES import_batches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satu baris = satu hari leave (rentang di-expand saat input / import).
-- Bentrok dengan absensi di hari yang sama ditolak di level aplikasi.
CREATE TABLE leaves (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      BIGINT UNSIGNED NOT NULL,
    employee_id     BIGINT UNSIGNED NOT NULL,
    leave_type_id   BIGINT UNSIGNED NOT NULL,
    leave_date      DATE NOT NULL,
    note            VARCHAR(255) NULL,
    import_batch_id BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_leaves_employee_date (employee_id, leave_date),
    KEY idx_leaves_company_date (company_id, leave_date),
    CONSTRAINT fk_leaves_company  FOREIGN KEY (company_id)    REFERENCES companies (id),
    CONSTRAINT fk_leaves_employee FOREIGN KEY (employee_id)   REFERENCES employees (id),
    CONSTRAINT fk_leaves_type     FOREIGN KEY (leave_type_id) REFERENCES leave_types (id),
    CONSTRAINT fk_leaves_batch    FOREIGN KEY (import_batch_id) REFERENCES import_batches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lembur dihitung di hari apa pun; yang penting total jam.
CREATE TABLE overtimes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      BIGINT UNSIGNED NOT NULL,
    employee_id     BIGINT UNSIGNED NOT NULL,
    overtime_date   DATE NOT NULL,
    duration_hours  DECIMAL(5,2) NOT NULL,
    note            VARCHAR(255) NULL,
    import_batch_id BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_overtimes_employee_date (employee_id, overtime_date),
    KEY idx_overtimes_company_date (company_id, overtime_date),
    CONSTRAINT fk_ot_company  FOREIGN KEY (company_id)  REFERENCES companies (id),
    CONSTRAINT fk_ot_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_ot_batch    FOREIGN KEY (import_batch_id) REFERENCES import_batches (id) ON DELETE SET NULL,
    CONSTRAINT ck_ot_duration CHECK (duration_hours > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. TABEL REFERENSI BERVERSI (TER, kategori PTKP, tarif lembur, dst.)
-- ---------------------------------------------------------------------

-- company_id NULL = nasional (tabel TER), dikelola Super Admin.
CREATE TABLE reference_tables (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  BIGINT UNSIGNED NULL,
    company_key BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(company_id, 0)) STORED,
    code        VARCHAR(64)  NOT NULL,              -- dipakai di rumus: LOOKUP('ter_bulanan', ...)
    name        VARCHAR(191) NOT NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reftables_company_code (company_key, code),
    CONSTRAINT fk_reftables_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Isi tabel yang sudah dipakai payroll final tidak boleh diubah: buat versi baru.
CREATE TABLE reference_table_versions (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_table_id BIGINT UNSIGNED NOT NULL,
    version_no         INT UNSIGNED NOT NULL,
    status             ENUM('draft','published') NOT NULL DEFAULT 'draft',
    effective_from     DATE NULL,
    published_at       TIMESTAMP NULL,
    locked_at          TIMESTAMP NULL,
    created_by         BIGINT UNSIGNED NULL,
    created_at         TIMESTAMP NULL,
    updated_at         TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rtv_table_version (reference_table_id, version_no),
    CONSTRAINT fk_rtv_table   FOREIGN KEY (reference_table_id) REFERENCES reference_tables (id),
    CONSTRAINT fk_rtv_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cara baca: cocokkan key_value (jika terisi), lalu nilai di antara
-- range_from dan range_to (NULL = tak terbatas). Hasil di result_number / result_text.
-- Contoh TER : key_value='A',    range 5400001-5650000, result_number=0.0025
-- Contoh PTKP: key_value='TK/0', result_text='A'
CREATE TABLE reference_table_rows (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_id    BIGINT UNSIGNED NOT NULL,
    key_value     VARCHAR(64) NULL,
    range_from    DECIMAL(18,2) NULL,
    range_to      DECIMAL(18,2) NULL,
    result_number DECIMAL(18,6) NULL,
    result_text   VARCHAR(64) NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_rtr_lookup (version_id, key_value, range_from),
    CONSTRAINT fk_rtr_version FOREIGN KEY (version_id) REFERENCES reference_table_versions (id) ON DELETE CASCADE,
    CONSTRAINT ck_rtr_range CHECK (range_from IS NULL OR range_to IS NULL OR range_to >= range_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. MASTER PAYROLL BERVERSI (variabel, parameter, komponen + rumus)
-- ---------------------------------------------------------------------

CREATE TABLE payroll_masters (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id         BIGINT UNSIGNED NOT NULL,
    code               VARCHAR(64)  NOT NULL,
    name               VARCHAR(191) NOT NULL,       -- "Payroll Usual", "Payroll THR", "Payroll Pro Rata"
    description        VARCHAR(255) NULL,
    current_version_id BIGINT UNSIGNED NULL,        -- FK ditambah lewat ALTER
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at         TIMESTAMP NULL,
    updated_at         TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pm_company_code (company_id, code),
    CONSTRAINT fk_pm_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versi yang sudah dipakai payroll final (locked_at terisi) tidak bisa diedit.
-- Variabel, parameter, dan item komponen dimiliki per versi (disalin saat versi baru).
CREATE TABLE payroll_master_versions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    master_id    BIGINT UNSIGNED NOT NULL,
    version_no   INT UNSIGNED NOT NULL,
    status       ENUM('draft','published') NOT NULL DEFAULT 'draft',
    note         VARCHAR(255) NULL,
    published_at TIMESTAMP NULL,
    locked_at    TIMESTAMP NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pmv_master_version (master_id, version_no),
    CONSTRAINT fk_pmv_master  FOREIGN KEY (master_id)  REFERENCES payroll_masters (id),
    CONSTRAINT fk_pmv_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_masters
    ADD CONSTRAINT fk_pm_current_version FOREIGN KEY (current_version_id) REFERENCES payroll_master_versions (id);

-- Variabel:
--   base    = agregasi dari sumber data pada periode cut off
--             (source + filter_expression per baris + value_expression per baris + aggregate)
--   derived = rumus dari variabel / parameter lain (formula)
-- Dievaluasi berurutan sesuai sort_order; hanya boleh merujuk yang di atasnya.
CREATE TABLE payroll_variables (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    master_version_id BIGINT UNSIGNED NOT NULL,
    code              VARCHAR(64)  NOT NULL,
    name              VARCHAR(191) NOT NULL,
    kind              ENUM('base','derived') NOT NULL,
    source            ENUM('attendances','leaves','overtimes','employees','contracts','holidays') NULL,
    filter_expression TEXT NULL,
    value_expression  VARCHAR(500) NULL,
    aggregate         ENUM('count','sum','avg','min','max','count_distinct_date') NULL,
    formula           TEXT NULL,
    sort_order        INT NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pv_version_code (master_version_id, code),
    KEY idx_pv_order (master_version_id, sort_order),
    CONSTRAINT fk_pv_version FOREIGN KEY (master_version_id) REFERENCES payroll_master_versions (id) ON DELETE CASCADE,
    CONSTRAINT ck_pv_kind CHECK (
        (kind = 'base' AND source IS NOT NULL AND aggregate IS NOT NULL)
        OR (kind = 'derived' AND formula IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parameter master (tarif lembur, pembagi, konstanta) diisi HR.
CREATE TABLE payroll_params (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    master_version_id BIGINT UNSIGNED NOT NULL,
    code              VARCHAR(64)  NOT NULL,
    name              VARCHAR(191) NOT NULL,
    value             DECIMAL(18,4) NOT NULL,
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pp_version_code (master_version_id, code),
    CONSTRAINT fk_pp_version FOREIGN KEY (master_version_id) REFERENCES payroll_master_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Komponen yang tampil di run. type/is_taxable disalin dari katalog saat item dibuat
-- supaya versi lama tidak berubah bila katalog diubah.
--   calc_type 'contract' = nilai kontrak (komponen tanpa kontrak bernilai 0)
--   calc_type 'formula'  = hasil formula
--   item_role 'pph21'    = komponen PPh21 TER, otomatis diurutkan paling akhir
CREATE TABLE payroll_items (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    master_version_id BIGINT UNSIGNED NOT NULL,
    component_id      BIGINT UNSIGNED NOT NULL,
    code              VARCHAR(64)  NOT NULL,
    name              VARCHAR(191) NOT NULL,
    type              ENUM('earning','deduction') NOT NULL,
    is_taxable        TINYINT(1) NOT NULL DEFAULT 0,
    calc_type         ENUM('contract','formula') NOT NULL,
    formula           TEXT NULL,
    item_role         ENUM('normal','pph21') NOT NULL DEFAULT 'normal',
    sort_order        INT NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pi_version_code (master_version_id, code),
    UNIQUE KEY uq_pi_version_component (master_version_id, component_id),
    KEY idx_pi_order (master_version_id, sort_order),
    CONSTRAINT fk_pi_version   FOREIGN KEY (master_version_id) REFERENCES payroll_master_versions (id) ON DELETE CASCADE,
    CONSTRAINT fk_pi_component FOREIGN KEY (component_id) REFERENCES components (id),
    CONSTRAINT ck_pi_formula CHECK (calc_type = 'contract' OR formula IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. PAYROLL RUN, PREVIEW, APPROVAL, SLIP
-- ---------------------------------------------------------------------

-- status : draft -> pending_approval -> approved | rejected
-- Preview dan generate berjalan di queue (job_status). Draft dihapus fisik.
CREATE TABLE payroll_runs (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id         BIGINT UNSIGNED NOT NULL,
    title              VARCHAR(191) NOT NULL,       -- "Payroll November 2026"
    period_start       DATE NOT NULL,
    period_end         DATE NOT NULL,
    master_version_id  BIGINT UNSIGNED NULL,        -- terisi saat preview
    employee_scope     ENUM('all','department','individual') NULL,
    employee_selection JSON NULL,                   -- id departemen / karyawan terpilih
    status             ENUM('draft','pending_approval','approved','rejected') NOT NULL DEFAULT 'draft',
    job_type           ENUM('preview','generate') NULL,
    job_status         ENUM('idle','queued','running','failed') NOT NULL DEFAULT 'idle',
    job_error          TEXT NULL,
    job_started_at     TIMESTAMP NULL,
    job_finished_at    TIMESTAMP NULL,
    skipped_employees  JSON NULL,                   -- karyawan yang di-skip (sudah ada payroll / kontrak tidak aktif)
    created_by         BIGINT UNSIGNED NOT NULL,
    approver_user_id   BIGINT UNSIGNED NULL,
    generated_at       TIMESTAMP NULL,
    approved_at        TIMESTAMP NULL,
    rejected_at        TIMESTAMP NULL,
    reject_reason      VARCHAR(500) NULL,
    created_at         TIMESTAMP NULL,
    updated_at         TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_pr_company_period (company_id, period_start, period_end),
    KEY idx_pr_approver_status (approver_user_id, status),
    CONSTRAINT fk_pr_company  FOREIGN KEY (company_id)        REFERENCES companies (id),
    CONSTRAINT fk_pr_version  FOREIGN KEY (master_version_id) REFERENCES payroll_master_versions (id),
    CONSTRAINT fk_pr_creator  FOREIGN KEY (created_by)        REFERENCES users (id),
    CONSTRAINT fk_pr_approver FOREIGN KEY (approver_user_id)  REFERENCES users (id),
    CONSTRAINT ck_pr_period CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satu baris per karyawan per run. Berisi snapshot data karyawan saat run dibuat.
-- is_blocking = 1 hanya setelah generate (pending_approval / approved); jadi 0 bila ditolak.
-- Unique key memastikan 1 karyawan hanya punya 1 payroll aktif per periode.
CREATE TABLE payroll_run_employees (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id             BIGINT UNSIGNED NOT NULL,
    company_id         BIGINT UNSIGNED NOT NULL,
    employee_id        BIGINT UNSIGNED NOT NULL,
    contract_id        BIGINT UNSIGNED NOT NULL,
    period_start       DATE NOT NULL,
    period_end         DATE NOT NULL,
    is_blocking        TINYINT(1) NOT NULL DEFAULT 0,
    blocking_key       TINYINT GENERATED ALWAYS AS (IF(is_blocking = 1, 1, NULL)) STORED,
    employee_no        VARCHAR(32)  NOT NULL,
    nik                VARCHAR(32)  NOT NULL,
    full_name          VARCHAR(191) NOT NULL,
    department_name    VARCHAR(191) NULL,
    position_name      VARCHAR(191) NULL,
    ptkp_status        VARCHAR(10)  NOT NULL,
    bank_name          VARCHAR(100) NULL,
    bank_account_no    VARCHAR(255) NULL,           -- snapshot terenkripsi
    variables_snapshot JSON NULL,                   -- nilai semua variabel saat dihitung
    total_earnings     DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_deductions   DECIMAL(15,2) NOT NULL DEFAULT 0,
    gross_taxable      DECIMAL(15,2) NOT NULL DEFAULT 0,
    take_home_pay      DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at         TIMESTAMP NULL,
    updated_at         TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pre_run_employee (run_id, employee_id),
    UNIQUE KEY uq_pre_one_active_per_period (employee_id, period_start, period_end, blocking_key),
    KEY idx_pre_employee (employee_id),
    CONSTRAINT fk_pre_run      FOREIGN KEY (run_id)      REFERENCES payroll_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_pre_company  FOREIGN KEY (company_id)  REFERENCES companies (id),
    CONSTRAINT fk_pre_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_pre_contract FOREIGN KEY (contract_id) REFERENCES contracts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rincian komponen per karyawan.
-- calculated_amount = hasil rumus, final_amount = nilai akhir (bisa hasil edit/override).
-- Edit sel di preview tanpa audit log; is_overridden = 1 melindungi dari hitung ulang.
CREATE TABLE payroll_run_items (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_employee_id   BIGINT UNSIGNED NOT NULL,
    payroll_item_id   BIGINT UNSIGNED NULL,
    component_id      BIGINT UNSIGNED NULL,
    code              VARCHAR(64)  NOT NULL,
    name              VARCHAR(191) NOT NULL,
    type              ENUM('earning','deduction') NOT NULL,
    is_taxable        TINYINT(1) NOT NULL DEFAULT 0,
    sort_order        INT NOT NULL DEFAULT 0,
    calculated_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    final_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
    is_overridden     TINYINT(1) NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pri_run_employee_code (run_employee_id, code),
    CONSTRAINT fk_pri_run_employee FOREIGN KEY (run_employee_id) REFERENCES payroll_run_employees (id) ON DELETE CASCADE,
    CONSTRAINT fk_pri_item         FOREIGN KEY (payroll_item_id) REFERENCES payroll_items (id) ON DELETE SET NULL,
    CONSTRAINT fk_pri_component    FOREIGN KEY (component_id)    REFERENCES components (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versi tabel referensi yang dipakai run saat generate (agar hasil bisa direproduksi).
CREATE TABLE payroll_run_reference_versions (
    run_id               BIGINT UNSIGNED NOT NULL,
    reference_table_id   BIGINT UNSIGNED NOT NULL,
    reference_version_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (run_id, reference_table_id),
    CONSTRAINT fk_prrv_run     FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_prrv_table   FOREIGN KEY (reference_table_id) REFERENCES reference_tables (id),
    CONSTRAINT fk_prrv_version FOREIGN KEY (reference_version_id) REFERENCES reference_table_versions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Terbit otomatis setelah Manager approve. File PDF disimpan di storage.
CREATE TABLE payslips (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id      BIGINT UNSIGNED NOT NULL,
    run_employee_id BIGINT UNSIGNED NOT NULL,
    employee_id     BIGINT UNSIGNED NOT NULL,
    file_path       VARCHAR(255) NULL,
    generated_at    TIMESTAMP NULL,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payslips_run_employee (run_employee_id),
    KEY idx_payslips_employee (employee_id, generated_at),
    CONSTRAINT fk_ps_company      FOREIGN KEY (company_id)      REFERENCES companies (id),
    CONSTRAINT fk_ps_run_employee FOREIGN KEY (run_employee_id) REFERENCES payroll_run_employees (id),
    CONSTRAINT fk_ps_employee     FOREIGN KEY (employee_id)     REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat bruto kena pajak dan PPh21 per bulan (bahan perhitungan Desember di fase 2).
CREATE TABLE tax_monthly_histories (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id    BIGINT UNSIGNED NOT NULL,
    employee_id   BIGINT UNSIGNED NOT NULL,
    run_id        BIGINT UNSIGNED NOT NULL,
    tax_year      SMALLINT UNSIGNED NOT NULL,
    tax_month     TINYINT UNSIGNED NOT NULL,
    ter_category  VARCHAR(8) NULL,
    ter_rate      DECIMAL(8,6) NULL,
    gross_taxable DECIMAL(15,2) NOT NULL DEFAULT 0,
    pph21         DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tmh_employee_run (employee_id, run_id),
    KEY idx_tmh_employee_year (employee_id, tax_year, tax_month),
    CONSTRAINT fk_tmh_company  FOREIGN KEY (company_id)  REFERENCES companies (id),
    CONSTRAINT fk_tmh_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_tmh_run      FOREIGN KEY (run_id)      REFERENCES payroll_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. AUDIT TRAIL
-- ---------------------------------------------------------------------

-- Dicatat: generate, approve/reject, perubahan kontrak, master payroll, variabel,
-- tabel referensi, master data. Edit sel di preview TIDAK dicatat.
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id  BIGINT UNSIGNED NULL,
    user_id     BIGINT UNSIGNED NULL,
    action      VARCHAR(64)  NOT NULL,              -- created, updated, deleted, generated, approved, rejected, ...
    entity_type VARCHAR(100) NOT NULL,
    entity_id   BIGINT UNSIGNED NULL,
    old_values  JSON NULL,
    new_values  JSON NULL,
    ip_address  VARCHAR(45) NULL,
    created_at  TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_company_date (company_id, created_at),
    KEY idx_audit_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;