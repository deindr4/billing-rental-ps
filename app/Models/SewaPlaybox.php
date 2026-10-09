<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use App\Support\Koordinat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu sewa Playbox bawa pulang: bayar di muka, jaminan & deposit, checklist keluar/kembali, denda, kerusakan */
class SewaPlaybox extends Model
{
    use BelongsToCabang, BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    public const JAMINAN = ['identitas' => 'KTP / identitas asli', 'deposit' => 'Uang deposit', 'barang' => 'Barang lain'];

    public const KONDISI = ['baik' => 'Baik', 'rusak' => 'Rusak', 'hilang' => 'Hilang'];

    protected $table = 'sewa_playbox';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'nomor', 'playbox_id', 'penyewa_id', 'user_id', 'shift_id', 'transaksi_id',
        'transaksi_kembali_id', 'status', 'satuan', 'jumlah', 'harga_satuan', 'mulai_pada', 'jatuh_tempo', 'kembali_pada',
        'alamat', 'lat', 'lng', 'jaminan', 'deposit', 'deposit_dipotong', 'checklist_keluar', 'checklist_kembali',
        'foto_keluar', 'foto_kembali', 'tanda_tangan', 'perpanjangan', 'denda', 'biaya_kerusakan', 'diingatkan_pada', 'catatan',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'harga_satuan' => 'integer',
            'mulai_pada' => 'datetime',
            'jatuh_tempo' => 'datetime',
            'kembali_pada' => 'datetime',
            'diingatkan_pada' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'jaminan' => 'array',
            'deposit' => 'integer',
            'deposit_dipotong' => 'integer',
            'checklist_keluar' => 'array',
            'checklist_kembali' => 'array',
            'foto_keluar' => 'array',
            'foto_kembali' => 'array',
            'perpanjangan' => 'array',
            'denda' => 'integer',
            'biaya_kerusakan' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function playbox(): BelongsTo
    {
        return $this->belongsTo(Playbox::class);
    }

    public function penyewa(): BelongsTo
    {
        return $this->belongsTo(Penyewa::class);
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    public function transaksiKembali(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class, 'transaksi_kembali_id');
    }

    public function scopeBerjalan(Builder $query): Builder
    {
        return $query->where('status', 'berjalan');
    }

    public function telat(): bool
    {
        return $this->status === 'berjalan' && $this->jatuh_tempo->isPast();
    }

    public function urlMaps(): ?string
    {
        return Koordinat::urlMaps($this->lat, $this->lng);
    }

    /** "3 Hari" */
    public function labelDurasi(): string
    {
        return $this->jumlah.' '.(Playbox::SATUAN[$this->satuan] ?? $this->satuan);
    }
}
