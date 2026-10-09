<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Services\PasswordPolicy;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->rule(
                        PasswordPolicy::rule(),
                        // Aturan hanya berlaku bila password diisi: Registry
                        // edit boleh dikosongkan agar password lama tetap.
                        fn (?string $state): bool => filled($state),
                    )
                    ->validationMessages(PasswordPolicy::MESSAGES)
                    // Tidak ada Hash::make() manual: model User memakai cast
                    // 'password' => 'hashed' yang menghash sekali dan idempoten
                    // (Hash::isHashed() menjaga agar tidak ter-hash ganda).
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create'),
                DateTimePicker::make('expires_at')->nullable(),
                Toggle::make('is_active')->default(true),
                TextInput::make('vip_notes')->maxLength(255)->nullable(),
                Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ]);
    }
}
