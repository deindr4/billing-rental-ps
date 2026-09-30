<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Filament\Pages\Backup as BackupPage;
use App\Filament\Resources\AuditLog\Pages\ListAuditLog;
use App\Models\AuditLog;
use App\Models\Cabang;
use App\Models\PaketHarga;
use App\Models\Unit;
use App\Models\User;
use App\Services\BackupService;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\LaporanService;
use App\Services\PinService;
use App\Support\Audit;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;
use ZipArchive;

class AuditBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->owner->forceFill(['pin' => Hash::make('1234')])->save();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        $this->actingAs($this->owner);
    }

    public function test_perubahan_data_master_tercatat_dengan_nilai_lama_baru(): void
    {
        $paket = PaketHarga::where('nama', 'Per Jam PS4')->firstOrFail();
        $paket->update(['harga' => 9000]);

        $log = AuditLog::where('aksi', 'diubah')->where('subjek_id', $paket->id)->latest('created_at')->firstOrFail();

        $this->assertSame(8000, $log->data['lama']['harga']);
        $this->assertSame(9000, $log->data['baru']['harga']);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertFalse($log->anomali);

        // PIN tidak pernah tercatat nilainya
        $pinLog = AuditLog::where('subjek_id', $this->owner->id)->where('aksi', 'diubah')->latest('created_at')->first();
        $this->assertSame('••••', $pinLog?->data['baru']['pin'] ?? '••••');
    }

    public function test_status_unit_tidak_membanjiri_log(): void
    {
        $unit = Unit::where('kode', 'TV2')->firstOrFail();
        $sebelum = AuditLog::count();

        $unit->update(['status' => Unit::STATUS_SERVIS]);
        $unit->update(['status' => Unit::STATUS_KOSONG]);

        $this->assertSame($sebelum, AuditLog::count());
    }

    public function test_anomali_batal_pin_salah_dan_laporan(): void
    {
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        $billing = app(BillingService::class);
        $sesi = $billing->mulai(Unit::where('kode', 'TV2')->first(), $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60]);
        $billing->batalkan($sesi->transaksi, $this->owner, 'Salah pilih unit');

        try {
            app(PinService::class)->setujui('9999', 'transaksi.batal', $this->cabang->tenant_id);
        } catch (BillingException) {
        }

        $this->assertSame(1, AuditLog::where('aksi', 'batal_transaksi')->where('anomali', true)->count());
        $this->assertSame(1, AuditLog::where('aksi', 'pin_gagal')->count());

        $a = app(LaporanService::class)->anomali(now()->startOfDay(), now()->endOfDay());
        $this->assertSame(2, $a['per_aksi']->sum('jumlah'));
        $this->assertStringContainsString('Salah pilih unit', $a['terbaru']->firstWhere('aksi', 'batal_transaksi')->keterangan);
    }

    public function test_audit_log_tidak_bisa_diubah(): void
    {
        $log = Audit::catat('login', 'Login tes');

        $this->expectException(QueryException::class);
        DB::table('audit_log')->where('id', $log->id)->update(['keterangan' => 'diedit']);
    }

    public function test_login_tercatat(): void
    {
        auth()->logout();

        $this->post('/admin/login'); // tidak penting hasilnya; event login diuji langsung
        event(new Login('web', $this->owner, false));
        event(new Failed('web', null, ['username' => 'penyusup', 'password' => 'x']));

        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('aksi', 'login')->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('aksi', 'login_gagal')->where('keterangan', 'like', '%penyusup%')->count());
    }

    public function test_halaman_log_aktivitas(): void
    {
        PaketHarga::where('nama', 'Per Jam PS4')->firstOrFail()->update(['harga' => 9500]);

        Livewire::test(ListAuditLog::class)->assertOk()->assertSee('Paket harga Per Jam PS4 diubah');
    }

    public function test_backup_bisa_dibuat_dan_dipulihkan_ke_database_lain(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tenants/x/logo.webp', 'gambar');

        $service = app(BackupService::class);
        $nama = $service->buat('tes');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($service->path($nama)));
            $sql = $zip->getFromName('database.sql');
            $this->assertSame('gambar', $zip->getFromName('uploads/tenants/x/logo.webp'));
            $zip->close();

            $this->assertStringContainsString('CREATE TABLE `transaksi`', $sql);
            $this->assertStringContainsString('CREATE TRIGGER `transaksi_larangan_hapus`', $sql);
            $this->assertTrue($service->daftar()->contains('nama', $nama));

            // Jalankan dump ke database kosong terpisah
            $cfg = config('database.connections.mysql');
            $pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']}", $cfg['username'], $cfg['password']);
            $pdo->exec('DROP DATABASE IF EXISTS billing_ps_backup_test');
            $pdo->exec('CREATE DATABASE billing_ps_backup_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE billing_ps_backup_test');

            foreach (explode("\n-- ;;\n", $sql) as $s) {
                $s = trim($s);
                if ($s !== '' && ! str_starts_with($s, '--')) {
                    $pdo->exec($s);
                }
            }

            $this->assertSame(
                DB::table('units')->count(),
                (int) $pdo->query('SELECT COUNT(*) FROM units')->fetchColumn()
            );
            $this->assertSame(
                DB::table('users')->where('email', 'owner@billing.test')->value('pin'),
                $pdo->query("SELECT pin FROM users WHERE email = 'owner@billing.test'")->fetchColumn()
            );

            $pdo->exec('DROP DATABASE billing_ps_backup_test');
        } finally {
            $service->hapus($nama);
        }
    }

    public function test_halaman_backup_owner_di_lokal_dan_super_admin(): void
    {
        // Server lokal (satu rental): owner boleh backup & restore
        $this->get('/admin/backup')->assertOk();

        // Server cloud (banyak tenant): hanya super admin
        config(['app.mode' => 'cloud']);
        $this->get('/admin/backup')->assertForbidden();
        config(['app.mode' => 'local']);

        // Pemulihan wajib ketik PULIHKAN + password benar
        Livewire::test(BackupPage::class)
            ->call('mulaiPulihkan', 'backup-tidak-ada.zip')
            ->set('konfirmasi', 'pulihkan')->set('password', 'salah')
            ->call('pulihkan')->assertHasErrors(['konfirmasi'])
            ->set('konfirmasi', 'PULIHKAN')
            ->call('pulihkan')->assertHasErrors(['password']);

        // File unggahan yang bukan backup ditolak
        $palsu = UploadedFile::fake()->create('palsu.zip', 10, 'application/zip');
        Livewire::test(BackupPage::class)->set('fileBackup', $palsu)->call('unggah')->assertHasErrors(['fileBackup']);

        $admin = User::where('email', 'admin@billing.test')->firstOrFail();
        $this->actingAs($admin);

        Livewire::test(BackupPage::class)->assertOk()->set('retensiHari', 7)->call('simpanPengaturan')->assertHasNoErrors();
        $this->assertSame(7, app(BackupService::class)->retensiHari());
        app(BackupService::class)->simpanPengaturan(14, true);
    }
}
