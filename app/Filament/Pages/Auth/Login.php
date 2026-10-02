<?php

namespace App\Filament\Pages\Auth;

use App\Support\BatasLogin;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as LoginFilament;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/** Login panel admin dengan batas yang sama seperti login kasir: IP publik 3x gagal -> diblokir 15 menit */
class Login extends LoginFilament
{
    public function authenticate(): ?LoginResponse
    {
        if ($detik = BatasLogin::sisaBlokir(request())) {
            Notification::make()->title(BatasLogin::pesan($detik))->danger()->send();

            return null;
        }

        try {
            $hasil = parent::authenticate();
        } catch (ValidationException $e) {
            BatasLogin::gagal(request());

            throw $e;
        }

        if (Filament::auth()->check()) {
            BatasLogin::berhasil(request());
        }

        return $hasil;
    }
}
