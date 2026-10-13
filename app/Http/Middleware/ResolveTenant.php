<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();

        try {
            $tenantId = filter_var(
                $request->header('X-Tenant-ID'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($tenantId === false) {
                return response()->json([
                    'message' => 'A valid X-Tenant-ID header is required.',
                ], 400);
            }

            if (! Tenant::query()->whereKey($tenantId)->exists()) {
                return response()->json([
                    'message' => 'Tenant not found.',
                ], 404);
            }

            $this->context->set($tenantId);

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
