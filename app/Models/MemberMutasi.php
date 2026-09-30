<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Models\Concerns\TidakBisaDihapus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Buku besar member (saldo, poin, stamp, belanja). Tidak pernah dihapus; salah catat -> koreksi. */
class MemberMutasi extends Model
{
    use BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    public const AKUN_SALDO = 'saldo';

    public const AKUN_POIN = 'poin';

    public const AKUN_STAMP = 'stamp';

    public const AKUN_BELANJA = 'belanja';

    public const LABEL_JENIS = [
        'topup' => 'Top up',
        'bonus' => 'Bonus top up',
        'bayar' => 'Bayar',
        'refund' => 'Pengembalian',
        'dapat' => 'Didapat',
        'tukar' => 'Ditukar',
        'batal' => 'Pembatalan',
        'koreksi' => 'Koreksi',
    ];

    protected $table = 'member_mutasi';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'member_id', 'akun', 'jenis', 'jumlah', 'saldo_akhir',
        'transaksi_id', 'pembayaran_id', 'user_id', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'saldo_akhir' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }
}
