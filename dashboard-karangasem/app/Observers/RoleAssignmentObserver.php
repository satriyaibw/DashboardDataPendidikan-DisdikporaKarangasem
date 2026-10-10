<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AdminActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Role;

/**
 * Mencatat perubahan peran sebagai jejak audit tersendiri.
 *
 * Peran tidak bisa dicatat oleh {@see UserObserver::created()}: Spatie
 * menunda `assignRole()` sampai event `saved`, sehingga saat `created`
 * dipanggil pivot peran belum ada. Membaca relasi apa pun pada saat itu
 * selalu menghasilkan kosong — termasuk lewat query builder, karena baris
 * pivotnya memang belum ditulis.
 *
 * Event `RoleAttachedEvent`/`RoleDetachedEvent` dipancarkan tepat setelah
 * pivot terpasang, jadi di sinilah peran benar-benar bisa dibaca.
 *
 * Catatan: event Spatie hanya dipancarkan bila `permission.events_enabled`
 * bernilai true. Proyek ini mengaktifkannya karena audit trail di panel
 * Filament tidak lengkap tanpa informasi peran.
 */
class RoleAssignmentObserver
{
    public function roleAttached(RoleAttachedEvent $event): void
    {
        $this->record($event->model, $event->rolesOrIds, 'user.role_attached');
    }

    public function roleDetached(RoleDetachedEvent $event): void
    {
        $this->record($event->model, $event->rolesOrIds, 'user.role_detached');
    }

    /**
     * Tulis satu entri audit untuk perubahan peran.
     *
     * Perubahan peran tetap dilewati bila tidak ada aktor: seeder dan
     * scheduler berjalan tanpa sesi admin, dan entri dengan `actor_id` null
     * hanya menjadi noise.
     */
    protected function record(Model $model, mixed $roles, string $action): void
    {
        if (Auth::id() === null || ! $model instanceof User) {
            return;
        }

        // Nama peran dibaca sekali, di titik event diterima. Tabel peran
        // berdiri sendiri dan tidak bergantung pada akun, jadi nilainya sudah
        // benar di sini — termasuk untuk model yang belum tersimpan, yang
        // pivot-nya belum ada sama sekali. Karena itu nama peran yang
        // dicatat selalu persis peran yang dipancarkan event, bukan seluruh
        // peran yang meloncat pada akun.
        $roleNames = static::roleNames($roles);

        if (! $model->exists) {
            $this->recordAfterSave($model, $roleNames, $action);

            return;
        }

        AdminActivityLogger::log($action, $model, [
            'roles' => $roleNames,
        ]);
    }

    /**
     * Catat peran model yang belum tersimpan.
     *
     * Spatie memancarkan event dari dalam `assignRole()`, jadi untuk model
     * baru event tiba sebelum `save()`: akun belum punya primary key untuk
     * ditunjuk, sehingga baris audit belum bisa ditulis. Penulisan
     * ditunda sampai `saved`.
     *
     * Penanda `&$recorded` itu wajib. Listener `saved` didaftarkan ke
     * dispatcher milik model, bukan ke instance-nya, dan tidak pernah
     * dilepas — sehingga ia tetap menyala untuk setiap `save()` berikutnya
     * selama proses berjalan. Tanpa penanda, memuat ulang satu akun akan
     * menulis baris audit `user.role_attached` tambahan yang isinya
     * identik, membanjiri log dengan salinan dari satu peristiwa yang sama.
     * Pola ini sama dengan yang dipakai `HasRoles::assignRole()` Spatie
     * untuk listener miliknya sendiri.
     */
    protected function recordAfterSave(User $model, string $roleNames, string $action): void
    {
        $recorded = false;

        $model->saved(function (User $saved) use ($model, $roleNames, $action, &$recorded): void {
            if ($recorded || $saved->isNot($model)) {
                return;
            }

            $recorded = true;

            AdminActivityLogger::log($action, $saved, [
                'roles' => $roleNames,
            ]);
        });
    }

    /**
     * Nama peran dari id yang diberikan event.
     *
     * Event membawa id, bukan nama. Id dibaca ulang dari tabel peran, bukan
     * lewat relasi `$user->roles`: pada event detach pivot-nya sudah dilepas,
     * sehingga relasi tidak lagi memuat peran yang baru dilepas.
     */
    protected static function roleNames(mixed $roleIds): string
    {
        $ids = static::normalize($roleIds);

        if ($ids === []) {
            return '';
        }

        return Role::query()
            ->whereKey($ids)
            ->pluck('name')
            ->sort()
            ->implode(', ');
    }

    /**
     * @return array<int, int|string>
     */
    protected static function normalize(mixed $roles): array
    {
        if ($roles instanceof Collection) {
            $roles = $roles->all();
        }

        if (is_object($roles)) {
            $roles = [$roles];
        }

        return array_values(array_filter(
            array_map(fn ($role) => is_object($role) ? ($role->id ?? null) : $role, (array) $roles),
            fn ($id) => $id !== null,
        ));
    }
}
