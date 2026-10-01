<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Turnamen extends Model
{
    use BelongsToCabang, BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    public const STATUS = [
        'draft' => 'Draft',
        'pendaftaran' => 'Pendaftaran dibuka',
        'berjalan' => 'Berjalan',
        'selesai' => 'Selesai',
        'batal' => 'Dibatalkan',
    ];

    protected $table = 'turnamen';

    /** Format turnamen => [nama, keterangan singkat] */
    public const FORMAT = [
        'gugur' => ['Sistem gugur', 'Kalah sekali langsung tersingkir.'],
        'gugur_ganda' => ['Gugur ganda', 'Tersingkir setelah kalah dua kali (bagan atas, bagan bawah, grand final).'],
        'liga' => ['Liga', 'Semua peserta saling bertemu; juara dari klasemen (menang 3, seri 1, kalah 0).'],
        'grup_gugur' => ['Fase grup + gugur', 'Grup saling bertemu, juara & runner-up grup lolos ke babak gugur silang antar grup.'],
    ];

    protected $fillable = [
        'tenant_id', 'cabang_id', 'slug', 'nama', 'game', 'format', 'jumlah_grup', 'lolos_per_grup', 'putaran',
        'mulai_pada', 'biaya_daftar', 'bonus_produk_id', 'bonus_qty', 'kuota',
        'hadiah', 'total_hadiah', 'aturan', 'daftar_online', 'status',
    ];

    protected function casts(): array
    {
        return [
            'mulai_pada' => 'datetime',
            'biaya_daftar' => 'integer',
            'bonus_qty' => 'integer',
            'jumlah_grup' => 'integer',
            'lolos_per_grup' => 'integer',
            'putaran' => 'integer',
            'kuota' => 'integer',
            'total_hadiah' => 'integer',
            'daftar_online' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function namaFormat(): string
    {
        return self::FORMAT[$this->format][0] ?? 'Sistem gugur';
    }

    /** Produk F&B gratis yang didapat setiap pendaftar (bundling) */
    public function bonusProduk(): BelongsTo
    {
        return $this->belongsTo(Produk::class, 'bonus_produk_id')->withoutGlobalScopes();
    }

    /** "Teh Botol Kotak" atau "2x Teh Botol Kotak"; null = tanpa bundling */
    public function labelBonus(): ?string
    {
        if (! $this->bonus_produk_id || $this->bonus_qty < 1 || ! $this->bonusProduk) {
            return null;
        }

        return ($this->bonus_qty > 1 ? $this->bonus_qty.'x ' : '').$this->bonusProduk->nama;
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function peserta(): HasMany
    {
        return $this->hasMany(TurnamenPeserta::class);
    }

    public function pertandingan(): HasMany
    {
        return $this->hasMany(TurnamenPertandingan::class)->orderBy('babak')->orderBy('nomor');
    }

    public function pesertaAktif(): HasMany
    {
        return $this->peserta()->where('status', '!=', 'batal');
    }

    public function penuh(): bool
    {
        return $this->pesertaAktif()->count() >= $this->kuota;
    }

    public function bisaDaftarOnline(): bool
    {
        return $this->daftar_online && $this->status === 'pendaftaran' && $this->mulai_pada->isFuture() && ! $this->penuh();
    }

    /** Nama babak dari jumlah babak total: Final, Semifinal, Perempat final, Babak 16 besar ... */
    public static function namaBabak(int $babak, int $totalBabak): string
    {
        return match ($totalBabak - $babak) {
            0 => 'Final',
            1 => 'Semifinal',
            2 => 'Perempat final',
            default => 'Babak '.(2 ** ($totalBabak - $babak + 1)).' besar',
        };
    }
}
