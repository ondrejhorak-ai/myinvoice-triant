<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Support;

use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TriRequest
{
    public static function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    public static function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }

    public static function userRole(Request $request): string
    {
        $user = (array) $request->getAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, []);

        return (string) ($user['role'] ?? '');
    }
}
