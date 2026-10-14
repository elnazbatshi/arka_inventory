<?php

namespace App\Tenancy;

use LogicException;

final class TenantContext
{
    private ?int $tenantId = null;

    public function set(int $tenantId): void
    {
        if ($tenantId < 1) {
            throw new LogicException('Invalid tenant ID.');
        }
        $this->tenantId = $tenantId;
    }

    public function id(): int
    {
        if ($this->tenantId === null) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        return $this->tenantId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }
}
