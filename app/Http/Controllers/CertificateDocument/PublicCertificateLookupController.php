<?php

namespace App\Http\Controllers\CertificateDocument;

use App\Http\Controllers\Controller;
use App\Services\PublicCertificateLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PublicCertificateLookupController extends Controller
{
    public function __invoke(Request $request, PublicCertificateLookup $lookup): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow'];
        $invalid = $request->query() !== [] || array_diff(array_keys($request->all()), ['dni']) !== [];
        $validator = Validator::make($request->all(), ['dni' => ['required', 'string', 'regex:/^[0-9]{8}$/D']]);
        if ($invalid || $validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Ingrese un DNI válido de ocho dígitos.',
                'data' => ['certificados' => []]], 422, $headers);
        }
        try {
            return response()->json(['success' => true, 'message' => 'Consulta de disponibilidad documental.',
                'data' => ['certificados' => $lookup->search($validator->validated()['dni'])]], 200, $headers);
        } catch (Throwable $exception) {
            Log::warning('No se pudo completar la consulta pública de certificados.', ['exception_type' => $exception::class]);

            return response()->json(['success' => false, 'message' => 'No se pudo completar la consulta. Intente más tarde.',
                'data' => ['certificados' => []]], 503, $headers);
        }
    }
}
