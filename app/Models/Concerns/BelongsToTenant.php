<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::saving(function (Model $model): void {
            $tenantId = app(TenantContext::class)->id();

            if (! $model->exists) {
                $assignedTenantId = $model->getAttribute('tenant_id');

                if (
                    $assignedTenantId !== null
                    && (int) $assignedTenantId !== $tenantId
                ) {
                    throw new LogicException(
                        'Cannot create a record for another tenant.'
                    );
                }

                $model->setAttribute('tenant_id', $tenantId);

                return;
            }

            if (
                (int) $model->getRawOriginal('tenant_id') !== $tenantId
                || $model->isDirty('tenant_id')
            ) {
                throw new LogicException(
                    'Cannot change tenant ownership or save another tenant record.'
                );
            }
        });

        static::deleting(function (Model $model): void {
            $tenantId = app(TenantContext::class)->id();

            if (
                (int) $model->getRawOriginal('tenant_id') !== $tenantId
                || $model->isDirty('tenant_id')
            ) {
                throw new LogicException(
                    'Cannot delete another tenant record.'
                );
            }
        });
    }
}
