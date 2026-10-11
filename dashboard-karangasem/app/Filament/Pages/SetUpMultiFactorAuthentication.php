<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * Halaman penyiapan MFA untuk panel admin.
 *
 * Filament menyediakan halaman yang sama lewat kelasnya sendiri, tetapi
 * tampilannya berupa satu tautan teks "Aktifkan" dengan heading bahasa Inggris
 * dan tanpa penjelasan apa pun harus dilakukan. Untuk operator yang baru
 * memakai panel, halaman itu tampak seperti buntu: tidak ada tombol yang
 * jelas dan tidak ada petunjuk.
 *
 * Halaman ini mempertahankan seluruh mekanisme MFA milik Filament — hanya
 * menambahkan penjelasan dan membuat aksi penyiapan tampil sebagai tombol
 * utama. Keamanan tidak dilonggarkan: MFA tetap wajib, dan admin yang belum
 * memasangnya tetap dialihkan ke halaman ini.
 */
class SetUpMultiFactorAuthentication extends SimplePage
{
    protected static string $routeSlug = 'multi-factor-authentication/set-up';

    protected static ?string $title = 'Pasang Autentikator (2FA)';

    /**
     * Admin yang sudah memasang MFA tidak boleh melihat halaman ini lagi;
     * Panel utama adalah tujuan setelah pemasangan selesai.
     */
    public function mount(): void
    {
        if (! Filament::hasMultiFactorAuthentication() || $this->isEnabled()) {
            redirect()->intended(Filament::getUrl());
        }
    }

    public function getHeading(): ?string
    {
        return 'Pasang aplikasi authenticator';
    }

    public function getSubheading(): ?string
    {
        return 'Panel admin mewajibkan verifikasi dua langkah. Ikuti tiga langkah di bawah ini — setelah selesai, tombol "Lanjut ke Panel" akan aktif.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Langkah pemasangan')
                ->description('Butuh sekitar satu menit. Google Authenticator, Microsoft Authenticator, atau Authy sama-sama bisa dipakai.')
                ->columns(1)
                ->schema([
                    $this->step(
                        number: 1,
                        title: 'Pasang aplikasi authenticator di ponsel',
                        body: 'Belum punya? Unduh Google Authenticator dari App Store (iOS) atau Play Store (Android).',
                    ),
                    $this->step(
                        number: 2,
                        title: 'Tekan tombol "Mulai penyiapan" di bawah',
                        body: 'Sebuah jendela terbuka berisi kode QR dan kode teks. Pindai kodenya dengan aplikasi authenticator Anda.',
                    ),
                    $this->step(
                        number: 3,
                        title: 'Masukkan kode 6 digit dari aplikasi Anda',
                        body: 'Simpan kode pemulihan yang ditampilkan. Satu-satunya cara masuk kembali bila ponsel hilang.',
                    ),
                ]),

            Section::make('Status')
                ->compact()
                ->schema([
                    Text::make(fn (): string => $this->isEnabled()
                        ? 'Authenticator aktif. Setiap login ke panel memerlukan kode dari aplikasi Anda.'
                        : 'Belum aktif — panel akan menolak login sampai authenticator dipasang.')
                        ->badge()
                        ->color(fn (): string => $this->isEnabled() ? 'success' : 'warning'),
                ]),

            Actions::make($this->getSetupActions()),
        ]);
    }

    /**
     * @return array<Action>
     */
    public function getSetupActions(): array
    {
        $appAuthentication = AppAuthentication::make();
        $user = Filament::auth()->user();

        return [
            // Aksi penyiapan asli Filament dipakai apa adanya — ini satu-satunya
            // cara resmi memasang secret, memverifikasi kode, dan menerbitkan
            // kode pemulihan. Yang diubah hanya presentasinya menjadi tombol utama.
            SetUpAppAuthenticationAction::make($appAuthentication)
                ->label('Mulai penyiapan')
                ->button()
                ->size('lg')
                ->color('primary'),

            Action::make('continue')
                ->label('Lanjut ke Panel')
                ->button()
                ->size('lg')
                ->color('gray')
                ->visible($this->isEnabled(...))
                ->action(fn () => redirect()->intended(Filament::getUrl())),
        ];
    }

    public function isEnabled(): bool
    {
        return AppAuthentication::make()->isEnabled(Filament::auth()->user());
    }

    /**
     * Satu baris langkah bernomor.
     */
    protected function step(int $number, string $title, string $body): Component
    {
        return Section::make()
            ->compact()
            ->schema([
                Text::make($title)
                    ->weight('bold')
                    ->size('lg'),
                Text::make($body)
                    ->color('gray')
                    ->size('sm'),
            ])
            ->extraAttributes(['aria-label' => "Langkah {$number}"]);
    }
}
