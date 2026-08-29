<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Всё, что приходит под /api, считается запросом к API — независимо от того,
 * что прислал клиент в заголовке Accept.
 *
 * Без этого Laravel при ошибке отдаёт HTML-страницу, и `curl http://localhost/api/...`
 * возвращает разметку вместо JSON. Разбирать такую страницу глазами в консоли
 * долго, а скриптом — невозможно.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
