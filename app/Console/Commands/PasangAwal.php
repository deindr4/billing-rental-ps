<?php

namespace App\Console\Commands;

use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Models\RilisApk;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tv\RilisApkService;
use App\Services\Tv\StatusTvService;
use App\Support\HakAkses;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data awal saat aplikasi dipasang dengan installer (tahap 14): rental, cabang, owner, super admin, APK TV.
 * Aman dijalankan ulang (pemasangan ulang / update): data yang sudah ada tidak digandakan.
 */
class PasangAwal extends Command
{
    protected $signature = 'pasang:awal
        {--rental= : Nama rental}
        {--cabang=Cabang Utama : Nama cabang}
        {--zona=Asia/Makassar : Zona waktu cabang}
        {--owner-nama=Owner : Nama owner}
        {--owner-email= : Email login owner}
        {--owner-password= : Password owner (min. 8)}
        {--pin= : PIN owner 4-6 angka (persetujuan di kasir & TV)}
        {--superadmin-email=superadmin@billing.lokal : Email super admin}
        {--apk= : File APK TV Agent untuk didaftarkan sebagai rilis}
        {--url-lokal= : Alamat server lokal untuk TV (mis. http://192.168.1.10)}
        {--daftar-owner : Tampilkan email login owner (pemasangan dari backup)}';

    protected $description = 'Data awal pemasangan: rental, cabang, owner, super admin, rilis APK TV';

    public function handle(): int
    {
        HakAkses::siapkanPermission();

        if ($this->option('rental')) {
            if (! $this->dataRental()) {
                return self::FAILURE;
            }
        } elseif ($url = StatusTvService::urlServer($this->option('url-lokal'))) {
            // Dipulihkan dari backup di PC baru: alamat server lokal TV ikut IP PC ini (hanya bila satu cabang)
            $cabang = Cabang::withoutGlobalScopes()->get();

            if ($cabang->count() === 1) {
                Pengaturan::simpan('server.url_lokal', $url, $cabang->first()->id);
                $this->line("Alamat server lokal TV: {$url}");
            }
        }

        if ($this->option('daftar-owner')) {
            // Langsung ke tabel peran (relasi roles() Spatie tersaring tim/tenant aktif, di konsol tidak ada)
            $owner = DB::table('users')
                ->join('model_has_roles', fn ($j) => $j->on('model_has_roles.model_id', '=', 'users.id')->where('model_has_roles.model_type', (new User)->getMorphClass()))
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', 'Owner')->where('users.is_active', true)
                ->distinct()->get(['users.email', 'users.username']);

            foreach ($owner as $u) {
                $this->line("OWNER: {$u->email} ({$u->username})");
            }
        }

        if ($apk = $this->option('apk')) {
            $this->daftarkanApk($apk);
        }

        return self::SUCCESS;
    }

    private function dataRental(): bool
    {
        $nama = trim((string) $this->option('rental'));
        $email = strtolower(trim((string) $this->option('owner-email')));
        $password = (string) $this->option('owner-password');
        $pin = (string) $this->option('pin');

        if (mb_strlen($nama) < 3 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $this->error('Nama rental (min. 3 huruf), email owner yang valid & password (min. 8) wajib diisi.');

            return false;
        }

        if ($pin !== '' && ! preg_match('/^\d{4,6}$/', $pin)) {
            $this->error('PIN harus 4-6 angka.');

            return false;
        }

        DB::transaction(function () use ($nama, $email, $password, $pin) {
            $tenant = Tenant::query()->first() ?? Tenant::create([
                'kode' => $this->kodeDari($nama, 6),
                'nama' => $nama,
                'status' => 'aktif',
            ]);

            $cabang = Cabang::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first() ?? Cabang::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'kode' => $tenant->kode.'1',
                'nama' => trim((string) $this->option('cabang')) ?: 'Cabang Utama',
                'zona_waktu' => (string) $this->option('zona'),
            ]);

            HakAkses::siapkanTenant($tenant);
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
            app(Tenancy::class)->set($tenant->id, $cabang->id);

            // Alamat server lokal untuk TV Agent (Admin → Operasional → Server lokal & cloud)
            if ($url = StatusTvService::urlServer($this->option('url-lokal'))) {
                Pengaturan::simpan('server.url_lokal', $url, $cabang->id);
            }

            $owner = User::withoutGlobalScopes()->where('email', $email)->first();

            if ($owner && ! $owner->isSuperAdmin()) {
                // Pasang ulang di atas data lama (mis. percobaan pertama gagal di tengah): password & PIN dari wizard
                // terakhir yang berlaku — dulu tetap password lama, owner tidak bisa login.
                $owner->forceFill([
                    'password' => Hash::make($password),
                    'pin' => $pin !== '' ? Hash::make($pin) : $owner->pin,
                    'is_active' => true,
                ])->save();
            }

            if (! $owner) {
                $owner = new User;
                $owner->forceFill([
                    'tenant_id' => $tenant->id,
                    'name' => trim((string) $this->option('owner-nama')) ?: 'Owner',
                    'username' => Str::before($email, '@'),
                    'email' => $email,
                    'password' => Hash::make($password),
                    'pin' => $pin !== '' ? Hash::make($pin) : null,
                    'is_active' => true,
                ])->save();
            }

            $owner->cabang()->syncWithoutDetaching([$cabang->id]);
            $owner->syncRoles(['Owner']);

            // Super admin platform (kelola rilis APK, backup di cloud, dll.) — password sama dengan owner, bisa diganti:
            // php artisan superadmin <email>
            $su = strtolower((string) $this->option('superadmin-email'));

            if (! User::withoutGlobalScopes()->where('email', $su)->exists()) {
                (new User)->forceFill([
                    'tenant_id' => null,
                    'name' => 'Super Admin',
                    'username' => 'superadmin',
                    'email' => $su,
                    'password' => Hash::make($password),
                    'is_super_admin' => true,
                    'is_active' => true,
                ])->save();
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->info("Rental {$tenant->nama} · {$cabang->nama} · owner {$email} siap.");
        });

        return true;
    }

    /** Daftarkan APK TV bawaan installer sebagai rilis (bila versinya lebih baru dari rilis yang ada) */
    private function daftarkanApk(string $file): void
    {
        if (! is_file($file)) {
            $this->warn("APK tidak ditemukan: {$file}");

            return;
        }

        $info = $this->versiApk($file);

        if (! $info) {
            $this->warn('Versi APK tidak terbaca (berkas versi.txt tidak ada); lewati.');

            return;
        }

        [$nama, $kode] = $info;

        if (RilisApk::query()->where('versi_kode', '>=', $kode)->exists()) {
            $this->line("Rilis APK {$nama} sudah ada / lebih baru; lewati.");

            return;
        }

        $path = RilisApkService::FOLDER."/tv-agent-{$nama}.apk";
        Storage::disk(RilisApkService::DISK)->put($path, file_get_contents($file));

        RilisApk::create([
            'versi_nama' => $nama,
            'versi_kode' => $kode,
            'file' => $path,
            'catatan' => 'Dipasang bersama installer.',
            'wajib' => false,
            'aktif' => true,
        ] + app(RilisApkService::class)->metaFile($path));

        $this->info("Rilis APK {$nama} (kode {$kode}) didaftarkan.");
    }

    /** Versi dari berkas versi.txt di samping APK: "0.5.1 9" */
    private function versiApk(string $file): ?array
    {
        $versi = dirname($file).DIRECTORY_SEPARATOR.'versi.txt';

        if (! is_file($versi) || ! preg_match('/^(\d+\.\d+\.\d+)\s+(\d+)/', trim((string) file_get_contents($versi)), $m)) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    private function kodeDari(string $nama, int $panjang): string
    {
        $huruf = strtoupper(preg_replace('/[^A-Za-z]/', '', Str::ascii(implode('', array_map(fn ($k) => $k[0] ?? '', preg_split('/\s+/', $nama))))));

        if (strlen($huruf) < 2) {
            $huruf = strtoupper(preg_replace('/[^A-Za-z]/', '', Str::ascii($nama)));
        }

        return substr($huruf ?: 'RNT', 0, $panjang);
    }
}
