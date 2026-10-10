#!/usr/bin/env bash
# =============================================================================
# Fase 6 — helper bersama untuk skrip backup
#
# Berisi seluruh fungsi yang dipakai run-backup.sh dan restore-drill.sh.
# Di-source, BUKAN dieksekusi: `set -euo pipefail` sengaja TIDAK di sini
# karena akan berefek pada shell pemanggil yang hanya butuh satu fungsi.
#
# Aturan yang berlaku di seluruh skrip Fase 6:
#   - Password TIDAK PERNAH muncul sebagai argumen baris perintah (akan
#     terlihat di `ps` milik semua user di mesin itu).
#   - Kegagalan tidak boleh senyap: setiap langkah mengembalikan exit code.
#   - Tidak ada nilai produksi yang di-hardcode di sini.
# =============================================================================

# Direktori tempat log skrip backup ditulis. Di-override lewat variabel
# lingkungan bila lokasi log tidak bisa ditulis oleh user yang menjalankan.
BACKUP_LOG_FILE="${BACKUP_LOG_FILE:-}"

# -----------------------------------------------------------------------------
# Logging
# -----------------------------------------------------------------------------

# Tulis satu baris bertimestamp ke log dan ke stderr.
#
# `date` eksternal dipakai, bukan `printf '%(%F)T'` dari bash, karena skrip
# ini juga sering dijalankan lewat `sh` pada distro yang tidak menyediakan
# ekstensi bash.
backup_log() {
    local level="$1"
    shift

    local line
    line="$(date '+%Y-%m-%d %H:%M:%S%z') [${level}] $*"

    printf '%s\n' "${line}" >&2

    if [[ -n "${BACKUP_LOG_FILE}" ]]; then
        printf '%s\n' "${line}" >>"${BACKUP_LOG_FILE}" 2>/dev/null || true
    fi
}

# -----------------------------------------------------------------------------
# Fail-safe
# -----------------------------------------------------------------------------

# Hentikan skrip dengan pesan yang jelas. Dipakai hanya untuk kondisi yang
# membuat penghentian jadi pilihan satu-satunya; kegagalan yang masih bisa
# dicoba ditangani sendiri tidak boleh lewat sini.
backup_die() {
    backup_log ERROR "$*"
    exit 1
}

# Pastikan perintah yang dibutuhkan benar-benar ada sebelum skrip berjalan
# sedikit pun. Menutup "pg_dump tidak terpasang" di akhir eksekusi jauh lebih
# menyulitkan daripada menolak di awal.
require_command() {
    local command_name="$1"

    if ! command -v "${command_name}" >/dev/null 2>&1; then
        backup_die "Perintah '${command_name}' tidak ditemukan di PATH. Backup dibatalkan."
    fi
}

# Pastikan container yang dibutuhkan benar-benar hidup sebelum mulai
# mengambil dump. Menghentikan container Metabase lewat `pg_dump` akan
# menghasilkan pesan error yang jauh lebih membingungkan.
require_container() {
    local container="$1"

    if ! docker inspect --type container "${container}" >/dev/null 2>&1; then
        backup_die "Container '${container}' tidak ada. Cek 'docker ps' lalu set variabelnya."
    fi

    if [[ "$(docker inspect --format '{{.State.Running}}' "${container}")" != "true" ]]; then
        backup_die "Container '${container}' sedang tidak berjalan. Backup dibatalkan."
    fi
}

# -----------------------------------------------------------------------------
# Verifikasi
# -----------------------------------------------------------------------------

# Pastikan file dump bukan nol byte.
#
# Dump berukuran 0 byte adalah tanda paling umum "backup berjalan tetapi tidak
# berisi apa-apa" — dump sepi yang tetap terlihat hijau di dashboard uptime.
require_non_empty_dump() {
    local dump_file="$1"

    if [[ ! -f "${dump_file}" ]]; then
        backup_die "File dump '${dump_file}' tidak terbentuk. pg_dump kemungkinan gagal tanpa berhenti."
    fi

    if [[ ! -s "${dump_file}" ]]; then
        backup_die "File dump '${dump_file}' berukuran 0 byte. Hasil backup tidak dapat dipercaya."
    fi

    # Format custom PostgreSQL selalu diawali magic bytes "PGDMP". Dump dengan
    # magis lain berarti yang tersimpan bukan dump custom — misalnya hasil
    # terpotong atau file kosong yang lolos cek ukuran.
    if [[ "$(head -c 5 "${dump_file}")" != "PGDMP" ]]; then
        backup_die "File dump '${dump_file}' tidak diawali magic bytes PGDMP. Format tidak sesuai."
    fi
}

# Pastikan dump benar-benar bisa dibaca `pg_restore` dan memuat entri.
#
# `pg_restore --list` tanpa argumen membaca dari stdin, sehingga file hasil
# dump dapat diperiksa dari luar container tanpa menyalinnya ke dalam.
verify_dump_readable() {
    local container="$1"
    local dump_file="$2"

    local listing
    if ! listing="$(docker exec -i "${container}" pg_restore --list <"${dump_file}" 2>&1)"; then
        backup_die "pg_restore tidak dapat membaca '${dump_file}': ${listing}"
    fi

    if [[ -z "${listing}" ]]; then
        backup_die "pg_restore membaca '${dump_file}' tanpa error tetapi isinya kosong."
    fi

    backup_log INFO "Verifikasi OK: $(printf '%s' "${listing}" | wc -l | tr -d ' ') entri pada $(basename "${dump_file}")."
}

# -----------------------------------------------------------------------------
# Rotasi retensi
# -----------------------------------------------------------------------------

# Buang file yang lebih tua dari sejumlah hari, lalu laporkan apa yang dihapus.
#
# `find -mtime +N` berarti "lebih tua dari N+1 hari 24 jam penuh", jadi angka
# yang dikurangi satu agar "14 hari retensi" berarti tepat 14 file harian.
prune_older_than_days() {
    local directory="$1"
    local keep_days="$2"
    local label="$3"

    if [[ ! -d "${directory}" ]]; then
        return 0
    fi

    local removed
    removed="$(find "${directory}" -maxdepth 1 -type f -name "${label}" -mtime +$((keep_days - 1)) -print -delete 2>/dev/null || true)"

    if [[ -n "${removed}" ]]; then
        while IFS= read -r file; do
            backup_log INFO "Rotasi ${label}: menghapus $(basename "${file}") (melebihi ${keep_days} hari)."
        done <<<"${removed}"
    fi
}

# Sisakan sejumlah file terbaru dan hapus sisanya.
#
# Urutan diambil dari stempel waktu inode lewat `find -printf`, bukan dari
# `ls -t`: `ls -t` memecah jadi beberapa invocation saat daftar melewati
# batas argumen, dan setiap invocation diurutkan sendiri — sehingga "file
# terbaru" yang tersisa bisa bukan yang benar-benar terbaru.
keep_newest_files() {
    local directory="$1"
    local keep_count="$2"
    local label="$3"

    if [[ ! -d "${directory}" ]] || [[ "${keep_count}" -lt 1 ]]; then
        return 0
    fi

    local -a stale=()
    mapfile -t stale < <(
        find "${directory}" -maxdepth 1 -type f -name "${label}" -printf '%T@\t%p\n' \
            | sort -rn \
            | tail -n "+$((keep_count + 1))" \
            | cut -f2-
    )

    for file in "${stale[@]}"; do
        [[ -z "${file}" ]] && continue
        backup_log INFO "Rotasi ${label}: menghapus $(basename "${file}") (kelebih ${keep_count} file)."
        rm -f "${file}"
    done
}