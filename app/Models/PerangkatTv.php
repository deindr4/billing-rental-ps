<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TV yang menjalankan aplikasi TV Agent, dipasangkan ke satu unit.
 */
class PerangkatTv extends Model
{
    use BelongsToCabang, BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    /** Data heartbeat & status remote berubah terus: tidak diaudit */
    protected array $auditAbaikan = [
        'terakhir_online', 'ip', 'layar', 'diagnostik', 'diagnostik_pada', 'volume', 'senyap', 'layar_hidup',
        'versi_app', 'versi_android', 'bypass_sampai',
    ];

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_DICABUT = 'dicabut';

    /** TV dianggap online jika melapor dalam rentang ini (detik) */
    public const BATAS_ONLINE_DETIK = 60;

    protected $table = 'perangkat_tv';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'unit_id', 'android_id', 'merek', 'model', 'versi_android', 'versi_app',
        'status', 'token_hash', 'rahasia_offline', 'bypass_sampai', 'terakhir_online', 'ip', 'layar',
        'diagnostik', 'diagnostik_pada', 'volume', 'senyap', 'layar_hidup', 'input_hdmi', 'input_hdmi_label', 'hdmi_nama',
        'dipasangkan_pada', 'dipasangkan_oleh',
    ];

    protected $hidden = ['token_hash', 'rahasia_offline'];

    protected function casts(): array
    {
        return [
            'rahasia_offline' => 'encrypted',
            'diagnostik' => 'array',
            'diagnostik_pada' => 'datetime',
            'hdmi_nama' => 'array',
            'volume' => 'integer',
            'senyap' => 'boolean',
            'layar_hidup' => 'boolean',
            'bypass_sampai' => 'datetime',
            'terakhir_online' => 'datetime',
            'dipasangkan_pada' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function log(): HasMany
    {
        return $this->hasMany(LogTv::class, 'perangkat_id');
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AKTIF);
    }

    public function isOnline(): bool
    {
        return $this->terakhir_online !== null
            && $this->terakhir_online->gt(now()->subSeconds(self::BATAS_ONLINE_DETIK));
    }

    public function sedangBypass(): bool
    {
        return $this->bypass_sampai !== null && $this->bypass_sampai->isFuture();
    }

    public function namaTampil(): string
    {
        return trim(($this->merek ?? '').' '.($this->model ?? '')) ?: 'TV '.substr($this->android_id, -6);
    }

    /**
     * Input TV yang dilaporkan APK di diagnostik ("HDMI 2 [HDMI] = com.xxx/HW3").
     *
     * @return array<string,string> id => label
     */
    public function daftarInput(): array
    {
        $hasil = [];

        foreach ((array) ($this->diagnostik['input'] ?? []) as $baris) {
            if (preg_match('/^(.*?)(?: \[HDMI\])? = (.+)$/', (string) $baris, $m)) {
                $hasil[trim($m[2])] = trim($m[1]);
            }
        }

        return $hasil;
    }

    /** Label input untuk kasir: "HDMI 3 · PS5" (nama konsol diatur admin), atau label TV saja */
    public function labelHdmi(string $inputId): string
    {
        $label = $this->daftarInput()[$inputId] ?? $inputId;
        $nama = trim((string) (($this->hdmi_nama ?? [])[$inputId] ?? ''));

        return $nama !== '' ? "{$label} · {$nama}" : $label;
    }

    /** @return array<string, string> input id => "HDMI 1 · PS3" */
    public function pilihanHdmi(): array
    {
        $hasil = [];

        foreach (array_keys($this->daftarInput()) as $id) {
            $hasil[$id] = $this->labelHdmi($id);
        }

        return $hasil;
    }

    /** Nama channel Reverb khusus perangkat ini (tanpa awalan "private-") */
    public function channel(): string
    {
        return 'tv.'.$this->id;
    }
}
