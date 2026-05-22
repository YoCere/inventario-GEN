<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate más estricto que 'admin'. Restringe acceso a configuraciones técnicas
 * (tokens API, modelos IA, webhook secrets, etc.) donde un mal toque rompe
 * integraciones. Solo rol Developer pasa.
 */
class EnsureDeveloper
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isDeveloper()) {
            abort(403, 'Acceso restringido. Solo el desarrollador puede modificar esta configuración técnica.');
        }

        return $next($request);
    }
}
