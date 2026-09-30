<?php

namespace App\Support;

/**
 * Menyimpan tenant & cabang yang sedang aktif selama satu request / job.
 */
final class Tenancy
{
    private ?string $tenantId = null;

    private ?string $cabangId = null;

    public function set(?string $tenantId, ?string $cabangId = null): void
    {
        $this->tenantId = $tenantId;
        $this->cabangId = $cabangId;
    }

    public function setCabang(?string $cabangId): void
    {
        $this->cabangId = $cabangId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function cabangId(): ?string
    {
        return $this->cabangId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function hasCabang(): bool
    {
        return $this->cabangId !== null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->cabangId = null;
    }
}
