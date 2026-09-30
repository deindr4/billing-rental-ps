<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** (Di cloud) server lokal rental yang boleh sinkron. Token hanya ditampilkan sekali saat dibuat. */
class ServerSinkron extends Model
{
    use HasUuids;

    protected $table = 'server_sinkron';

    protected $fillable = ['tenant_id', 'nama', 'token_hash', 'is_active', 'terakhir_kontak', 'terakhir_ip', 'catatan'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'terakhir_kontak' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Buat server baru. Return [server, token polos] */
    /** Format token: sk_ + 48 huruf/angka acak */
    public const POLA_TOKEN = '/^sk_[A-Za-z0-9]{48}$/';

    public static function tokenAcak(): string
    {
        return 'sk_'.Str::random(48);
    }

    /**
     * Daftarkan server lokal. $token diisi jika token dibuat di server lokal (tombol "Buat token acak")
     * lalu ditempel di cloud; kosong = cloud membuatkan token baru.
     */
    public static function buat(string $nama, ?string $tenantId = null, ?string $token = null): array
    {
        $token = $token ?: static::tokenAcak();

        $server = static::create([
            'nama' => $nama,
            'tenant_id' => $tenantId,
            'token_hash' => hash('sha256', $token),
            'is_active' => true,
        ]);

        return [$server, $token];
    }

    public static function dariToken(?string $token): ?self
    {
        return $token ? static::where('token_hash', hash('sha256', $token))->where('is_active', true)->first() : null;
    }
}
