<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicCertificateLookup
{
    public const CODE_PATTERN = '(?:[a-f0-9]{30}|(?=[A-Za-z0-9]*[A-Za-z])(?=[A-Za-z0-9]*[0-9])[A-Za-z0-9]{2,30})';

    public function __construct(private CertificateHumanResources $resources, private CertificateDocumentVersions $versions) {}

    public static function supportsCode(string $code): bool
    {
        return preg_match('~\A'.self::CODE_PATTERN.'\z~', $code) === 1;
    }

    /** @return list<array<string, mixed>> */
    public function search(string $dni): array
    {
        $people = DB::table('gestion.persona_documento as pd')
            ->join('gestion.tipo_documento as td', 'td.id_tipo_documento', '=', 'pd.id_tipo_documento')
            ->join('gestion.persona as p', 'p.id_persona', '=', 'pd.id_persona')
            ->where('pd.numero_documento', $dni)->where('td.estado', true)
            ->whereRaw('UPPER(TRIM(td.nombre)) = ?', ['DNI'])
            ->whereRaw('LOWER(TRIM(pd.estado)) = ?', ['activo'])
            ->distinct()->limit(2)->pluck('p.id_persona');
        if ($people->count() !== 1) {
            return [];
        }
        $items = [];
        foreach ($this->resources->publicConfirmedDocuments((int) $people->first()) as $document) {
            if (! self::supportsCode($document->codigo_verificacion)) {
                continue;
            }

            try {
                $context = $this->versions->current($document, readOnly: true)['context'] ?? [];
                if ($context !== [] && ((int) ($context['id_persona'] ?? 0) !== (int) $document->id_persona
                    || (int) ($context['id_tipo_certificado'] ?? 0) !== (int) $document->id_tipo_certificado)) {
                    continue;
                }
                $pdfUrl = config('certification.public_lookup.publish_all_eligible_pdfs') === true
                    ? $this->pdfUrl($document->codigo_verificacion) : null;
                $items[] = [
                    'referencia_publica' => hash_hmac('sha256', $document->codigo_verificacion, (string) config('app.key')),
                    'codigo_verificacion' => $document->codigo_verificacion,
                    'origen' => $context['origen'] ?? ($document->id_participacion !== null
                        ? ($document->id_sesion === null ? 'actividad' : 'sesion')
                        : ($document->id_reconocimiento !== null ? 'reconocimiento' : 'practica')),
                    'tipo_certificado' => $document->tipo_certificado,
                    'actividad' => $context['actividad'] ?? $document->actividad,
                    'sesion' => $context['sesion'] ?? $document->sesion,
                    'fecha_sesion' => $context['fecha_sesion'] ?? $document->fecha_sesion,
                    'estado_disponibilidad' => 'Listo para entrega',
                    'disponible_rrhh' => true,
                    'pdf_publicado' => $pdfUrl !== null,
                    'url_documento' => $pdfUrl,
                    'url_descarga' => $pdfUrl === null ? null : $pdfUrl.'&descargar=1',
                ];
            } catch (HttpException) {
                continue;
            }
        }

        return $items;
    }

    public function allowsPdf(string $code): bool
    {
        $reference = config('certification.public_lookup.pdf_policy_reference');

        return is_string($reference) && trim($reference) !== ''
            && in_array($code, config('certification.public_lookup.pdf_codes', []), true);
    }

    public function confirmedPublicArtifact(object $document): ?object
    {
        if (config('certification.public_lookup.publish_all_eligible_pdfs') !== true
            || $document->estado !== 'Vigente' || $document->estado_certificado !== CertificateManagement::PENDING) {
            return null;
        }

        foreach ($this->resources->publicConfirmedDocuments((int) $document->id_persona, $document->codigo_verificacion) as $confirmed) {
            if ((int) $confirmed->id_certificado_documento === (int) $document->id_certificado_documento) {
                return $confirmed;
            }
        }

        return null;
    }

    public function isPublishable(object $document): bool
    {
        return $document->estado === 'Vigente'
            && $document->estado_certificado === 'Vigente'
            && in_array($document->estado_flujo ?? null, [null, 'Vigente', CertificateManagement::ISSUED], true)
            && filled($document->fecha_emision)
            && ! str_starts_with((string) $document->ruta_archivo, 'certificados/preparados/')
            && ! str_starts_with((string) $document->ruta_archivo, 'certificados/desarrollo/')
            && ! str_starts_with((string) $document->ruta_archivo, 'certificados/demostracion/')
            && (config('certification.public_lookup.publish_all_eligible_pdfs') === true
                || $this->allowsPdf($document->codigo_verificacion));
    }

    public function pdfUrl(string $code): string
    {
        return route('certificates.verify', ['code' => $code], false).'?archivo=1';
    }
}
