#!/usr/bin/env bash
# =============================================================================
# Fase 6 — uji restore (restore drill)
#
# Tujuannya satu: membuktikan bahwa dump yang dihasilkan run-backup.sh
# benar-benar bisa dipulihkan. Backup yang tak pernah dipulihkan bukan backup
# — ia hanya kumpulan file yang terlihat meyakinkan.
#
# Aturan yang tidak bisa ditawar:
#   - Selalu memulihkan ke database SEMENTARA yang dibuat lalu dibuang di dalam
#     skrip ini. Tidak pernah menyentuh database aplikasi yang sedang jalan.
#   - Nama database sementara selalu unik per eksekusi (suffix PID dan waktu),
#     sehingga dua drill yang berjalan bersamaan tidak saling menimpa.
#   - Penghapusan hanya boleh menyentuh database berawalan `restore_drill_`.
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# shellcheck source=deploy/backup/lib.sh
source "${SCRIPT_DIR}/lib.sh"

BACKUP_DIR="${BACKUP_DIR:-${REPO_ROOT}/dashboard-karangasem/storage/app/backups}"
APP_DB_CONTAINER="${APP_DB_CONTAINER:-}"

# Awalan nama database sementara. Penghapusan hanya boleh menyentuh database
# yang namanya diawali string ini — safeguard terakhir terhadap salah sasaran.
DRILL_DB_PREFIX="restore_drill_"

DUMP_FILE=""
KEEP_DATABASE=0
CONTAINER=""

# Nama database sementara dan container yang dipakai.
#
# Keduanya sengaja variabel global, bukan `local` di dalam main(): blok
# `cleanup` dijalankan sebagai EXIT trap yang berjalan setelah main()
# kembali, sehingga variabel `local` sudah tidak ada cakupannya lagi dan
# `set -u` akan menghentikan skrip justru pada langkah pembersihannya.
DRILL_DB=""
DRILL_CONTAINER=""
DRILL_SUPERUSER=""

usage() {
    cat <<'USAGE'
Pakai: restore-drill.sh [opsi]

Memulihkan satu dump ke database sementara, memverifikasi isinya, lalu
menghapus database tersebut. Tidak pernah menyentuh database yang berjalan.

Opsi:
  --file=<path>    Dump yang dipulihkan. Tanpa opsi ini, dump harian
                   `app_db` terbaru yang ada.
  --container=<n>  Nama container Postgres. Kosong berarti dicari lewat
                   `docker compose` pada compose.yaml.
  --keep           Jangan hapus database sementara (untuk pemeriksaan manual).
  -h, --help       Tampilkan bantuan ini.

Contoh:
  ./restore-drill.sh
  ./restore-drill.sh --file=backups/daily/app_db-2026-10-10.dump
  ./restore-drill.sh --keep
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --file=*)      DUMP_FILE="${1#*=}" ;;
        --container=*) CONTAINER="${1#*=}" ;;
        --keep)        KEEP_DATABASE=1 ;;
        -h|--help)     usage; exit 0 ;;
        *)
            backup_log ERROR "Opsi tidak dikenal: $1"
            usage
            exit 1
            ;;
    esac
    shift
done

# -----------------------------------------------------------------------------
# Helper
# -----------------------------------------------------------------------------

# Nama user Postgres di dalam container.
#
# Postgres image menyetel POSTGRES_USER, jadi nama user tidak perlu ada di
# host dan tidak pernah muncul sebagai argumen yang bisa dibaca user lain.
container_user() {
    docker exec "$1" sh -c 'echo "${POSTGRES_USER:-app}"'
}

# Database maintenance yang dipakai untuk kueri administrasi (mis. melihat
# daftar seluruh database). Koneksi ke database ini tidak perlu tahu nama
# database aplikasi, sehingga tidak pernah gagal hanya karena nama database
# berbeda antar image.
container_maintenance_db() {
    docker exec "$1" sh -c 'echo "${POSTGRES_DB:-postgres}"'
}

# Jalankan psql di dalam container dengan database tertentu.
container_psql() {
    local container="$1"
    local database="$2"
    local sql="$3"

    docker exec "${container}" psql -U "$(container_user "${container}")" -d "${database}" -tAc "${sql}"
}

detect_app_db_container() {
    if [[ -n "${CONTAINER}" ]]; then
        printf '%s' "${CONTAINER}"
        return 0
    fi

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

    return 1
}

# Dump harian `app_db` terbaru.
latest_daily_dump() {
    find "${BACKUP_DIR}/daily" -maxdepth 1 -type f -name 'app_db-*.dump' -printf '%T@\t%p\n' 2>/dev/null \
        | sort -rn \
        | head -n 1 \
        | cut -f2-
}

# Buang seluruh database drill yang tertinggal dari eksekusi sebelumnya.
#
# Berbeda dengan operasi atas, yang di sini adalah administrasi database dan
# TIDAK bisa dilakukan sebagai user aplikasi biasa. Karena itu diperlukan peran
# superuser eksplisit yang dapat di-override lewat PG_SUPERUSER, sebab nama
# peran itu sendiri bisa berbeda antar image. Nilai kosong memakai
# POSTGRES_USER dari container, yang pada image postgres memang berperan
# superuser.
drop_stale_drills() {
    local container="$1"
    local superuser="$2"

    local -a stale=()
    local maintenance_db
    maintenance_db="$(container_maintenance_db "${container}")"

    # `mapfile` memecah hasil per baris. Hasil TIDAK boleh di-`tr -d
    # '[:space:]'` seperti pada nilai skalar: perintah itu ikut menghapus
    # newline, sehingga semua nama database tersambung menjadi satu nama
    # panjang yang tidak pernah ada.
    mapfile -t stale < <(
        docker exec "${container}" psql -U "${superuser}" -d "${maintenance_db}" -tAc \
            "SELECT datname FROM pg_database WHERE datname LIKE '${DRILL_DB_PREFIX}%'"
    )

    for stale_db in "${stale[@]}"; do
        [[ -z "${stale_db}" ]] && continue
        backup_log INFO "Membuang sisa drill sebelumnya: ${stale_db}."
        if ! docker exec "${container}" dropdb -U "${superuser}" --if-exists --force "${stale_db}"; then
            backup_log WARN "Gagal membuang '${stale_db}'. Buang manual dengan:"
            backup_log WARN "  docker exec ${container} dropdb -U ${superuser} --force ${stale_db}"
        fi
    done
}

# -----------------------------------------------------------------------------
# Alur utama
# -----------------------------------------------------------------------------

main() {
    require_command docker

    BACKUP_LOG_FILE="${BACKUP_LOG_FILE:-${REPO_ROOT}/dashboard-karangasem/storage/logs/backup.log}"
    mkdir -p "$(dirname "${BACKUP_LOG_FILE}")"

    if [[ -z "${DUMP_FILE}" ]]; then
        DUMP_FILE="$(latest_daily_dump)"
    fi

    if [[ -z "${DUMP_FILE}" ]]; then
        backup_die "Tidak ada dump harian di '${BACKUP_DIR}/daily'. Jalankan run-backup.sh lebih dulu."
    fi

    if [[ ! -f "${DUMP_FILE}" ]]; then
        backup_die "Dump '${DUMP_FILE}' tidak ada."
    fi

    require_non_empty_dump "${DUMP_FILE}"

    local container
    container="$(detect_app_db_container)" \
        || backup_die "Container database tidak diketahui. Pass --container=<nama> secara eksplisit."
    DRILL_CONTAINER="${container}"

    require_container "${container}"

    local drill_db="${DRILL_DB_PREFIX}$(date '+%Y%m%d%H%M%S')_$$"
    DRILL_DB="${drill_db}"

    backup_log INFO "=== Restore drill dimulai ==="
    backup_log INFO "Sumber: ${DUMP_FILE} ($(du -h "${DUMP_FILE}" | cut -f1)), container: ${container}."
    backup_log INFO "Database sementara: ${DRILL_DB}."

    DRILL_SUPERUSER="${PG_SUPERUSER:-$(container_user "${container}")}"
    drop_stale_drills "${container}" "${DRILL_SUPERUSER}"

    # Dipasang SEBELUM database dibuat: kalau `createdb` berhasil tapi skrip
    # mati sebelum blok berikut, trap-lah yang tetap membersihkan.
    cleanup() {
        local status=$?

        if [[ "${KEEP_DATABASE}" -eq 1 ]]; then
            backup_log INFO "Database '${DRILL_DB}' sengaja disimpan (--keep)."
            return "${status}"
        fi

        backup_log INFO "Membuang database sementara '${DRILL_DB}'."
        if ! docker exec "${DRILL_CONTAINER}" dropdb -U "${DRILL_SUPERUSER}" --if-exists --force "${DRILL_DB}"; then
            backup_log WARN "Gagal membuang '${DRILL_DB}'. Buang manual dengan:"
            backup_log WARN "  docker exec ${DRILL_CONTAINER} dropdb -U ${DRILL_SUPERUSER} --force ${DRILL_DB}"
        fi

        return "${status}"
    }
    trap cleanup EXIT

    docker exec "${container}" createdb -U "$(container_user "${container}")" "${DRILL_DB}" \
        || backup_die "Gagal membuat database sementara '${DRILL_DB}'."

    # Dump dikirim lewat stdin: berkasnya ada di host, bukan di dalam
    # container, sehingga tidak perlu menyalin file bolak-balik.
    #
    # `--exit-on-error` dipilih karena pg_restore secara bawaan melaporkan
    # error lalu melanjutkan. Tanpa flag itu, dump yang separuh rusak tetap
    # dilaporkan "berhasil" dan kerusakan baru terlihat jauh kemudian.
    if ! docker exec -i "${container}" sh -c \
        "PGPASSWORD=\"\${POSTGRES_PASSWORD}\" pg_restore -U \"\${POSTGRES_USER:-app}\" --exit-on-error --dbname='${DRILL_DB}'" \
        <"${DUMP_FILE}"; then
        backup_die "pg_restore gagal mengembalikan '${DUMP_FILE}' ke '${DRILL_DB}'."
    fi

    # Verifikasi setelah restore. Restore yang "berhasil" tanpa satu pun
    # objek sama saja dengan data yang hilang.
    local table_count user_count
    table_count="$(container_psql "${container}" "${DRILL_DB}" \
        "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'" | tr -d '[:space:]')"

    if [[ -z "${table_count}" ]] || [[ "${table_count}" -lt 1 ]]; then
        backup_die "Restore selesai tetapi '${DRILL_DB}' tidak memuat satu pun tabel. Dump kemungkinan bukan dump yang benar."
    fi

    # Jumlah akun yang ikut dipulihkan. Dump lama bisa jadi tidak punya
    # tabel `users`, jadi keberadaannya dicek lebih dulu agar "tabel tidak
    # ada" tidak tertukar dengan "restore gagal".
    local has_users
    has_users="$(container_psql "${container}" "${DRILL_DB}" \
        "SELECT to_regclass('public.users') IS NOT NULL" | tr -d '[:space:]')"

    if [[ "${has_users}" == "t" ]]; then
        user_count="$(container_psql "${container}" "${DRILL_DB}" "SELECT count(*) FROM users" | tr -d '[:space:]')"
    else
        user_count="tabel users tidak ada pada dump ini"
    fi

    backup_log INFO "Restore drill BERHASIL: ${table_count} tabel pada schema public, ${user_count}."
    backup_log INFO "=== Restore drill selesai ==="
}

main "$@"