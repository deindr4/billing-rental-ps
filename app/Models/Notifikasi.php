<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu kejadian di lonceng notifikasi. Dibuat lewat App\Services\Notifikasi\Lonceng::kirim(). */
class Notifikasi extends Model
{
    use BelongsToTenant, HasSyncMeta, HasUuids;

    protected $table = 'notifikasi';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'jenis', 'kelompok', 'tingkat', 'judul', 'isi', 'url', 'kunci',
        'subjek_type', 'subjek_id', 'user_id',
    ];

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function pelaku(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
