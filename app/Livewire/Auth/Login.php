<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Support\BatasLogin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Masuk')]
class Login extends Component
{
    public string $login = '';

    public string $password = '';

    public bool $remember = false;

    public function masuk()
    {
        $this->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ], [
            'login.required' => __('Email atau username wajib diisi.'),
            'password.required' => __('Password wajib diisi.'),
        ]);

        // IP publik: 3x gagal -> diblokir 15 menit (localhost & LAN dikecualikan, lihat BatasLogin)
        if ($detik = BatasLogin::sisaBlokir(request())) {
            $this->addError('login', BatasLogin::pesan($detik));

            return;
        }

        // Semua jaringan (termasuk Wi-Fi rental): 5 percobaan per menit per akun + IP
        $key = 'login:'.Str::lower($this->login).'|'.BatasLogin::ipAsli(request());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $detik = RateLimiter::availableIn($key);
            $this->addError('login', __('Terlalu banyak percobaan. Coba lagi dalam :n detik.', ['n' => $detik]));

            return;
        }

        $field = filter_var($this->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $berhasil = Auth::attempt([
            $field => $this->login,
            'password' => $this->password,
            'is_active' => true,
        ], $this->remember);

        if (! $berhasil) {
            RateLimiter::hit($key, 60);
            BatasLogin::gagal(request());

            $detik = BatasLogin::sisaBlokir(request());
            $this->addError('login', $detik ? BatasLogin::pesan($detik) : __('Email/username atau password salah.'));

            return;
        }

        RateLimiter::clear($key);
        BatasLogin::berhasil(request());
        session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        return $this->redirectIntended(route('rental'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
