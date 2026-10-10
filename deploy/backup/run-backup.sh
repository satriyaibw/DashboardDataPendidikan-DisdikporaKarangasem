#!/usr/bin/env bash
# =============================================================================
# Fase 6 — backup harian
#
# Specifikasi: MasterPlan.md §10 (Fase 6), Issue #11
#
# Yang di-backup — dan HANYA dua hal ini:
#   1. Database aplikasi `app_db` (akun admin & VIP, sesi, audit log).
#   2. Database metadata Metabase (definisi dashboard, kartu, cache).
#
# Yang SENGAJA tidak di-backup: database backbone `backbone_client`. Itu milik
# Pusdatin dan sumber kebenaran eksternal (MasterPlan §4 prinsip 1) — salinan
# di sini justru berisiko tidak dapat dipertanggungjawabkan kalau berbeda
# dengan aslinya.
#
# Prinsip yang tidak bisa ditawar:
#   - Kegagalan diam-diam lebih berbahaya daripada tidak ada backup, karena
#     monitoring dashboard tetap hijau padahal data sudah hilang berbulan-bulan.
#     Karena itu `set -euo pipefail` + verifikasi isi dump + exit code.
#   - Password tidak pernah muncul sebagai argumen baris perintah.
#   - Tidak ada nama container, path, atau kredensial produksi yang di-hardcode.
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# shellcheck source=deploy/backup/lib.sh
source "${SCRIPT_DIR}/lib.sh"

# -----------------------------------------------------------------------------
# Konfigurasi
# -----------------------------------------------------------------------------

# Lokasi file .env aplikasi dan .env Metabase. Path relatif terhadap repo root
# dan dapat di-override lewat variabel lingkungan.
APP_ENV_FILE="${APP_ENV_FILE:-${REPO_ROOT}/dashboard-karangasem/.env}"
METABASE_ENV_FILE="${METABASE_ENV_FILE:-${REPO_ROOT}/deploy/metabase/.env}"

# Direktori tujuan backup. Default di dalam `storage/` aplikasi agar ikut
# ter-version-control-kan oleh .gitignore yang sudah ada.
BACKUP_DIR="${BACKUP_DIR:-${REPO_ROOT}/dashboard-karangasem/storage/app/backups}"

# Retensi: 14 dump harian + 4 dump mingguan.
BACKUP_DAILY_RETENTION_DAYS="${BACKUP_DAILY_RETENTION_DAYS:-14}"
BACKUP_WEEKLY_RETENTION_COUNT="${BACKUP_WEEKLY_RETENTION_COUNT:-4}"

# Hari numerik (1=Senin … 7=Minggu) yang kopinya disimpan sebagai arsip
# mingguan. Backup harian tetap jalan di hari yang sama; yang berbeda hanya
# salinan jangka panjangnya.
BACKUP_WEEKLY_DAY="${BACKUP_WEEKLY_DAY:-7}"

# Nama container database. Kosong berarti "deteksi otomatis dari compose".
# Di produksi nilainya biasanya diisi lewat environment crontab.
APP_DB_CONTAINER="${APP_DB_CONTAINER:-}"
METABASE_DB_CONTAINER="${METABASE_DB_CONTAINER:-metabase_postgres}"

# -----------------------------------------------------------------------------
# Pembacaan .env
# -----------------------------------------------------------------------------

# Baca satu nilai dari file .env.
#
# Format yang didukung sengaja dibatasi pada `KEY=value` polos: sudah cukup
# untuk semua nilai yang dibutuhkan, dan menghindari metakarakter yang bisa
# membuat parsing ala-`source` mengeksekusi isi berkas.
read_env_value() {
    local env_file="$1"
    local key="$2"

    if [[ ! -f "${env_file}" ]]; then
        backup_log WARN "Berkas env '${env_file}' tidak ada — melewati '${key}'."
        return 1
    fi

    local line
    line="$(grep -E "^${key}=" "${env_file}" | tail -n 1 || true)"

    if [[ -z "${line}" ]]; then
        return 1
    fi

    local value="${line#*=}"

    # Buang tanda kutip luar bila ada.
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"

    # Buang komentar di akhir baris untuk nilai tanpa kutip.
    if [[ "${line}" != *'"'* && "${line}" != *"'"* ]]; then
        value="${value%%[[:space:]]#*}"
        value="${value%"${value##*[![:space:]]}"}"
    fi

    printf '%s' "${value}"
    return 0
}

# -----------------------------------------------------------------------------
# Deteksi container database aplikasi
# -----------------------------------------------------------------------------

# Temukan container Postgres aplikasi.
#
# Urutan pencarian: variabel eksplisit, lalu container yang sedang berjalan
# pada service `pgsql` di compose.yaml. Nama TIDAK pernah dikarang: kalau
# tidak ditemukan, skrip berhenti dan meminta operator mengisinya.
detect_app_db_container() {
    if [[ -n "${APP_DB_CONTAINER}" ]]; then
        printf '%s' "${APP_DB_CONTAINER}"
        return 0
    fi

    local compose_file="${REPO_ROOT}/dashboard-karangasem/compose.yaml"

    if [[ -f "${compose_file}" ]]; then
        local found
        found="$(docker compose --file "${compose_file}" ps --quiet --status running pgsql 2>/dev/null | head -n 1 || true)"

        if [[ -n "${found}" ]]; then
            printf '%s' "${found}"
            return 0
        fi
    fi

    backup_log WARN "Tidak dapat mendeteksi container database aplikasi."
    backup_log WARN "Set APP_DB_CONTAINER secara eksplisit (contoh: APP_DB_CONTAINER=\$(docker compose -f dashboard-karangasem/compose.yaml ps -q pgsql))."
    return 1
}

# -----------------------------------------------------------------------------
# Pembuatan dump
# -----------------------------------------------------------------------------

# Ambil dump format custom satu database dari container yang berjalan.
#
# Password diteruskan sebagai variabel lingkungan DI DALAM container lewat
# ekspansi `sh -c`, bukan sebagai argumen `docker exec -e`: argumen `docker
# exec` terlihat di tabel proses host dan dapat dibaca user lain.
# Postgres image sudah menyetel POSTGRES_USER/POSTGRES_PASSWORD/POSTGRES_DB,
# sehingga kredensial tidak perlu ada di host sama sekali.
dump_database() {
    local container="$1"
    local db_name="$2"
    local db_user="$3"
    local destination="$4"

    backup_log INFO "Membuat dump '${db_name}' dari container '${container}'."

    # `-Fc` = format custom (terkompresi, bisa dipulihkan per objek).
    # `--clean --if-exists` tidak dipakai: ini dump database, bukan skrip SQL.
    #
    # `${db_user}` di-expand oleh shell host dan tertanam sebagai nilai
    # cadangan di dalam ekspresi container, sehingga nama user sebenarnya
    # tetap mengikuti apa yang dikonfigurasi pada image Postgres.
    if ! docker exec "${container}" sh -c \
        "PGPASSWORD=\"\${POSTGRES_PASSWORD}\" pg_dump -Fc -U \"\${POSTGRES_USER:-${db_user}}\" '${db_name}'" \
        >"${destination}.partial"; then
        rm -f "${destination}.partial"
        backup_die "pg_dump untuk '${db_name}' gagal. Tidak ada dump yang dipakai."
    fi

    # File dump hanya dipindah ke nama akhir sebelum seluruh verifikasi lolos.
    # Kalau verifikasinya dilakukan setelah pemindahan, file rusak akan
    # ikut terhitung sebagai backup yang berhasil dan bertahan selama masa
    # retensi penuh.
    mv "${destination}.partial" "${destination}"
}

# -----------------------------------------------------------------------------
# Rotasi
# -----------------------------------------------------------------------------

rotate_daily() {
    local directory="${BACKUP_DIR}/daily"
    local keep_days="$1"

    prune_older_than_days "${directory}" "${keep_days}" "*.dump"
}

rotate_weekly() {
    local weekly_dir="${BACKUP_DIR}/weekly"

    if [[ ! -d "${weekly_dir}" ]]; then
        return 0
    fi

    prune_older_than_days "${weekly_dir}" 3650 "*.dump"
    keep_newest_files "${weekly_dir}" "${BACKUP_WEEKLY_RETENTION_COUNT}" "*.dump"
}

# Buat satu salinan mingguan dari dump harian hari ini.
create_weekly_copy() {
    local today="$1"
    local source_file="${BACKUP_DIR}/daily/app_db-${today}.dump"
    local weekly_dir="${BACKUP_DIR}/weekly"

    if [[ "$(date '+%u')" != "${BACKUP_WEEKLY_DAY}" ]]; then
        return 0
    fi

    if [[ ! -f "${source_file}" ]]; then
        backup_log WARN "Dump harian ${source_file} tidak ada — salinan mingguan dilewati."
        return 0
    fi

    mkdir -p "${weekly_dir}"
    cp "${source_file}" "${weekly_dir}/app_db-${today}.dump"

    backup_log INFO "Salinan mingguan dibuat: app_db-${today}.dump"
}

# -----------------------------------------------------------------------------
# Alur utama
# -----------------------------------------------------------------------------

main() {
    require_command docker
    require_command find

    BACKUP_LOG_FILE="${BACKUP_LOG_FILE:-${REPO_ROOT}/dashboard-karangasem/storage/logs/backup.log}"
    mkdir -p "$(dirname "${BACKUP_LOG_FILE}")" "${BACKUP_DIR}/daily" "${BACKUP_DIR}/weekly"

    # Isi backup berupa data akun (email, hash password, catatan VIP) dan
    # definisi dashboard. Mode 700 mencegah user lain di mesin yang sama
    # membacanya, mode 600 mencegah file ikut ter-baca user lain.
    chmod 700 "${BACKUP_DIR}" "${BACKUP_DIR}/daily" "${BACKUP_DIR}/weekly"

    local today
    today="$(date '+%F')"

    backup_log INFO "=== Mulai backup harian ${today} ==="

    local app_container
    app_container="$(detect_app_db_container)" || backup_die "Backup gagal: container database aplikasi tidak diketahui."

    require_container "${app_container}"
    require_container "${METABASE_DB_CONTAINER}"

    local app_db app_user metabase_db metabase_user

    app_db="$(read_env_value "${APP_ENV_FILE}" DB_DATABASE || true)"
    app_db="${app_db:-app_db}"
    app_user="$(read_env_value "${APP_ENV_FILE}" DB_USERNAME || true)"
    app_user="${app_user:-app}"

    metabase_db="$(read_env_value "${METABASE_ENV_FILE}" METABASE_MB_DB_DBNAME || true)"
    metabase_db="${metabase_db:-metabase}"
    metabase_user="$(read_env_value "${METABASE_ENV_FILE}" METABASE_MB_DB_USER || true)"
    metabase_user="${metabase_user:-metabase}"

    backup_log INFO "Target: app_db='${app_db}' (${app_container}), metabase='${metabase_db}' (${METABASE_DB_CONTAINER})."

    # --- 1. Database aplikasi -------------------------------------------------
    local app_dump="${BACKUP_DIR}/daily/app_db-${today}.dump"
    dump_database "${app_container}" "${app_db}" "${app_user}" "${app_dump}"
    require_non_empty_dump "${app_dump}"
    verify_dump_readable "${app_container}" "${app_dump}"

    # --- 2. Database metadata Metabase ---------------------------------------
    local metabase_dump="${BACKUP_DIR}/daily/metabase_db-${today}.dump"
    dump_database "${METABASE_DB_CONTAINER}" "${metabase_db}" "${metabase_user}" "${metabase_dump}"
    require_non_empty_dump "${metabase_dump}"
    verify_dump_readable "${METABASE_DB_CONTAINER}" "${metabase_dump}"

    # --- 3. Rotasi ------------------------------------------------------------
    create_weekly_copy "${today}"
    rotate_daily "${BACKUP_DAILY_RETENTION_DAYS}"
    rotate_weekly

    chmod 600 "${BACKUP_DIR}/daily/"*.dump "${BACKUP_DIR}/weekly/"*.dump 2>/dev/null || true

    backup_log INFO "=== Backup harian ${today} selesai ==="
}

main "$@"