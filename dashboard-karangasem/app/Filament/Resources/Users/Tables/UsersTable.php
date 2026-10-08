<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->roles->pluck('name')->implode(', ')),
                TextColumn::make('expires_at')->dateTime()->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn ($record): string => match (true) {
                        $record->is_active !== true => 'Nonaktif',
                        $record->expires_at !== null && $record->expires_at->isPast() => 'Kedaluwarsa',
                        default => 'Aktif',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Aktif' => 'success',
                        'Kedaluwarsa' => 'warning',
                        default => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->options(fn () => \Spatie\Permission\Models\Role::pluck('name', 'name')->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $role) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $role))
                    )),
                SelectFilter::make('status')
                    ->options(['aktif' => 'Aktif', 'kedaluwarsa' => 'Kedaluwarsa', 'nonaktif' => 'Nonaktif'])
                    ->query(function (Builder $query, array $data) {
                        return match ($data['value'] ?? null) {
                            'aktif' => $query->where('is_active', true)->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                            'kedaluwarsa' => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()),
                            'nonaktif' => $query->where('is_active', false),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('perpanjang')
                    ->label('Perpanjang 30 hari')
                    ->action(fn ($record) => $record->update([
                        'expires_at' => ($record->expires_at && $record->expires_at->isFuture() ? $record->expires_at : now())->addDays(30),
                    ])),
                Action::make('toggleActive')
                    ->label(fn ($record) => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
                    ->action(fn ($record) => $record->update(['is_active' => ! $record->is_active])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
