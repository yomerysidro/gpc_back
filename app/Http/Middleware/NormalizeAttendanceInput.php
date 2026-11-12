<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeAttendanceInput
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
            $input = $request->isJson() ? $request->json() : $request->request;
            $values = $input->all();
            foreach (['ID_ACTIVIDAD', 'ID_PARTICIPACION', 'ID_ACTIVIDAD_SESION', 'HORA_ENTRADA', 'HORA_SALIDA', 'ESTADO'] as $field) {
                $alias = strtolower($field);
                if (! array_key_exists($alias, $values)) {
                    continue;
                }
                if (array_key_exists($field, $values) && $values[$field] !== $values[$alias]) {
                    return response()->json([
                        'success' => false, 'message' => 'Datos de validación incorrectos.',
                        'data' => [$field => ['Los nombres alternativos del campo contienen valores distintos.']],
                    ], 422);
                }
                $input->set($field, $values[$alias]);
                $input->remove($alias);
            }
        }

        return $next($request);
    }
}
