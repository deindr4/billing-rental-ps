<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Models\Concerns\TidakBisaDihapus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Pengeluaran extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    public const KATEGORI = [
        'stok' => 'Belanja stok (persediaan)',
        'fnb' => 'F&B / Dapur',
        'operasional' => 'Operasional',
        'sparepart' => 'Sparepart & Perbaikan',
        'utilitas' => 'Listrik, Air & Internet',
        'gaji' => 'Gaji & Bonus',
        'lainnya' => 'Lainnya',
    ];

    /** Kategori yang bisa dipilih manual (belanja stok otomatis dari menu Stok Masuk) */
    public const KATEGORI_MANUAL = [
        'fnb' => 'F&B / Dapur',
        'operasional' => 'Operasional',
        'sparepart' => 'Sparepart & Perbaikan',
        'utilitas' => 'Listrik, Air & Internet',
        'gaji' => 'Gaji & Bonus',
        'lainnya' => 'Lainnya',
    ];

    /** Kategori yang bukan beban (tidak mengurangi laba, karena dihitung lewat HPP) */
    public const BUKAN_BEBAN = ['stok'];

    public const SUMBER_DANA = [
        'kas_laci' => 'Kas laci',
        'rekening' => 'Transfer / rekening',
    ];

    protected $table = 'pengeluaran';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'shift_id', 'user_id', 'disetujui_oleh', 'nomor', 'kategori', 'jumlah',
        'keterangan', 'sumber_dana', 'foto_nota', 'status', 'dibatalkan_pada', 'dibatalkan_oleh', 'alasan_batal',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'dibatalkan_pada' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function penyetuju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function isDibatalkan(): bool
    {
        return $this->status === 'dibatalkan';
    }

    public function fotoUrl(): ?string
    {
        return $this->foto_nota && Storage::disk('public')->exists($this->foto_nota)
            ? Storage::disk('public')->url($this->foto_nota)
            : null;
    }
}
