<?php

namespace App\Models\Concerns;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Catat otomatis dibuat/diubah/dihapus ke audit log.
 * Model boleh menambah kolom yang diabaikan lewat: protected array $auditAbaikan = ['kolom'];
 * dan nama tampilan lewat method labelAudit().
 */
trait Diaudit
{
    /** Kolom yang tidak pernah dicatat */
    private static array $auditAbaikanUmum = ['updated_at', 'created_at', 'version', 'synced_at', 'origin', 'remember_token'];

    /** Kolom rahasia: dicatat berubah tanpa nilainya */
    private static array $auditRahasia = ['password', 'pin', 'token', 'token_hash', 'secret'];

    /** Perubahan oleh sistem (seeder, perintah konsol, antrean) tanpa pengguna login tidak dicatat */
    private static function perluAudit(): bool
    {
        return auth()->check() || ! app()->runningInConsole();
    }

    public static function bootDiaudit(): void
    {
        static::created(function (Model $model) {
            if (! self::perluAudit()) {
                return;
            }

            Audit::catat('dibuat', $model->labelAuditLengkap().' dibuat', $model, ['baru' => $model->auditNilai($model->getAttributes())]);
        });

        static::updated(function (Model $model) {
            if (! self::perluAudit()) {
                return;
            }

            $berubah = array_diff_key($model->getChanges(), array_flip($model->auditKolomAbaikan()));

            if ($berubah === []) {
                return;
            }

            $lama = array_intersect_key($model->getOriginal(), $berubah);

            Audit::catat('diubah', $model->labelAuditLengkap().' diubah ('.implode(', ', array_keys($berubah)).')', $model, [
                'lama' => $model->auditNilai($lama),
                'baru' => $model->auditNilai($berubah),
            ]);
        });

        static::deleted(function (Model $model) {
            if (! self::perluAudit()) {
                return;
            }

            Audit::catat('dihapus', $model->labelAuditLengkap().' dihapus', $model, ['lama' => $model->auditNilai($model->getAttributes())]);
        });
    }

    public function auditKolomAbaikan(): array
    {
        return array_merge(self::$auditAbaikanUmum, property_exists($this, 'auditAbaikan') ? $this->auditAbaikan : []);
    }

    private function auditNilai(array $nilai): array
    {
        $nilai = array_diff_key($nilai, array_flip($this->auditKolomAbaikan()));

        foreach ($nilai as $k => $v) {
            if (in_array($k, self::$auditRahasia, true)) {
                $nilai[$k] = $v === null ? null : '••••';
            } elseif (is_string($v) && mb_strlen($v) > 300) {
                $nilai[$k] = mb_substr($v, 0, 300).'…';
            }
        }

        return $nilai;
    }

    private function labelAuditLengkap(): string
    {
        $jenis = ucfirst(mb_strtolower(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($this)))));
        $nama = method_exists($this, 'labelAudit')
            ? $this->labelAudit()
            : ($this->getAttribute('nama') ?? $this->getAttribute('name') ?? $this->getAttribute('kunci') ?? $this->getKey());

        return trim($jenis.' '.$nama);
    }
}
