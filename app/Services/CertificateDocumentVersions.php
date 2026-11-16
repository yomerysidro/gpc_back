<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CertificateDocumentVersions
{
    /** @return array<string, mixed>|null */
    public function current(object $document, bool $readOnly = false): ?array
    {
        $read = function () use ($document, $readOnly): ?array {
            $disk = Storage::disk('local');
            $pointer = $this->directory($document).'/current';
            if (! $disk->exists($pointer)) {
                return null;
            }
            $bytes = $disk->get($pointer);
            $record = $this->decode($bytes);
            $this->assertIdentity($record, $document);
            $archive = $this->directory($document).'/'.$record['revision'].'.manifest';
            abort_unless($disk->exists($archive) && $this->decode($disk->get($archive)) === $record,
                409, 'La revisión vigente no coincide con su aprobación inmutable.');
            abort_if($readOnly && $disk->get($pointer) !== $bytes, 409, 'La revisión cambió durante la consulta.');

            return $record;
        };

        return $readOnly ? $read() : $this->locked((int) $document->id_certificado_documento, $read);
    }

    public function resolve(object $document, bool $readOnly = false): object
    {
        $record = $this->current($document, $readOnly);
        if ($record === null) {
            return $document;
        }
        $result = clone $document;
        $result->hash_certificado_original = $record['original_hash'];
        $result->ruta_archivo_original = $record['original_path'];
        $result->ruta_archivo = $record['path'];
        $result->hash_certificado = $record['hash'];
        $result->revision_pdf = $record['revision'];
        $result->plan_pdf_aprobado = $record['plan'];
        $result->persistencia_revision = 'archivo_privado_versionado';

        return $result;
    }

    /** @param array<string, mixed> $record */
    public function approve(object $document, array $record, callable $validate): void
    {
        $this->locked((int) $document->id_certificado_documento, function () use ($document, $record, $validate): void {
            $disk = Storage::disk('local');
            $pointer = $this->directory($document).'/current';
            $current = $disk->exists($pointer) ? $this->decode($disk->get($pointer)) : null;
            if ($current !== null) {
                $this->assertIdentity($current, $document);
            }
            $expected = $current['hash'] ?? ($document->hash_certificado_original ?? $document->hash_certificado);
            abort_unless(hash_equals($record['previous_hash'], $expected)
                && ($record['previous_revision'] ?? null) === ($current['revision'] ?? null),
                409, 'Otra revisión fue aprobada; revise la versión vigente.');
            $this->assertIdentity($record, $document);
            $validate();
            $encrypted = Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR));
            $archive = $this->directory($document).'/'.$record['revision'].'.manifest';
            $this->writeExclusive($archive, $encrypted);
            $temporary = $pointer.'.'.$record['revision'];
            try {
                $this->writeExclusive($temporary, $encrypted);
                abort_unless(rename($disk->path($temporary), $disk->path($pointer)), 500, 'No se pudo activar la revisión aprobada.');
            } catch (Throwable $exception) {
                $disk->delete($temporary);
                throw $exception;
            }
        });
    }

    /** @return array<string, mixed> */
    public function approvedContent(object $document): array
    {
        return RequestReadMemo::remember(self::class.'.approved.'.hash('sha256', serialize([
            $document->id_certificado_documento, $document->hash_certificado,
        ])), fn (): array => $this->readApprovedContent($document));
    }

    /** @return array<string, mixed> */
    private function readApprovedContent(object $document): array
    {
        $current = $this->current($document, readOnly: true);
        if ($current !== null) {
            return ['message' => $current['message'], 'context' => $current['context']];
        }
        $disk = Storage::disk('local');
        $originalHash = $document->hash_certificado_original ?? $document->hash_certificado;
        $indexed = 'certificados/preparados/'.$document->codigo_verificacion.'.manifest';
        $paths = $disk->exists($indexed) ? [$indexed] : $disk->files('certificados/revisiones');
        foreach ($paths as $path) {
            if (! str_ends_with($path, '.manifest')) {
                continue;
            }
            try {
                $source = $this->decode($disk->get($path));
            } catch (HttpException) {
                continue;
            }
            if (($source['code'] ?? null) === $document->codigo_verificacion && ($source['hash'] ?? null) === $originalHash
                && is_string($source['message'] ?? null) && is_array($source['context'] ?? null)) {
                return ['message' => $source['message'], 'context' => $source['context']];
            }
        }
        abort(409, 'No se localizó el texto aprobado vinculado al hash original. No se sustituye por texto del cliente.');
    }

    public function writeExclusive(string $path, string $bytes): void
    {
        $disk = Storage::disk('local');
        abort_unless($disk->makeDirectory(dirname($path)), 500, 'No se pudo preparar el almacenamiento privado.');
        $stream = @fopen($disk->path($path), 'x+b');
        abort_unless(is_resource($stream), 409, 'No se sobrescriben archivos existentes.');
        try {
            $written = 0;
            while ($written < strlen($bytes)) {
                $count = fwrite($stream, substr($bytes, $written));
                abort_unless(is_int($count) && $count > 0, 500, 'No se pudo guardar el archivo completo.');
                $written += $count;
            }
            abort_unless(fflush($stream), 500, 'No se pudo completar la escritura.');
        } catch (Throwable $exception) {
            fclose($stream);
            $disk->delete($path);
            throw $exception;
        }
        fclose($stream);
    }

    private function directory(object $document): string
    {
        return 'certificados/versiones/'.(int) $document->id_certificado_documento;
    }

    /** @param array<string, mixed> $record */
    private function assertIdentity(array $record, object $document): void
    {
        abort_unless(($record['document'] ?? null) === (int) $document->id_certificado_documento
            && ($record['certificate'] ?? null) === (int) $document->id_certificado
            && ($record['code'] ?? null) === $document->codigo_verificacion
            && ($record['original_hash'] ?? null) === ($document->hash_certificado_original ?? $document->hash_certificado)
            && ($record['original_path'] ?? null) === ($document->ruta_archivo_original ?? $document->ruta_archivo)
            && is_string($record['revision'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $record['revision']) === 1,
            409, 'La revisión no corresponde a la identidad documental original.');
    }

    /** @return array<string, mixed> */
    private function decode(string $bytes): array
    {
        try {
            $result = json_decode(Crypt::decryptString($bytes), true, 512, JSON_THROW_ON_ERROR);
            abort_unless(is_array($result), 409, 'Aprobación no válida.');

            return $result;
        } catch (Throwable) {
            abort(409, 'No se pudo verificar la aprobación documental.');
        }
    }

    private function locked(int $id, callable $operation): mixed
    {
        $disk = Storage::disk('local');
        $directory = 'certificados/versiones/'.$id;
        abort_unless($disk->makeDirectory($directory), 500, 'No se pudo preparar el versionado.');
        $lock = @fopen($disk->path($directory.'/lock'), 'c+b');
        abort_unless(is_resource($lock), 500, 'No se pudo abrir el bloqueo documental.');
        try {
            abort_unless(flock($lock, LOCK_EX | LOCK_NB), 409, 'El documento tiene otra revisión en curso.');

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
