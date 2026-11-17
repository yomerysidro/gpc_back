<?php

namespace App\Services;

use App\Models\User\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CertificateSigningDemo
{
    private const WARNING = 'Simulación de firma — No válida oficialmente';

    public function __construct(private CertificateSigningStageB $stageB) {}

    /** @return list<array<string, string>> */
    public function signers(): array
    {
        return [
            ['id_demo' => 'DEMO-1', 'nombre' => 'Responsable DEMO 1', 'cargo' => 'Cargo de prueba DEMO 1'],
            ['id_demo' => 'DEMO-2', 'nombre' => 'Responsable DEMO 2', 'cargo' => 'Cargo de prueba DEMO 2'],
            ['id_demo' => 'DEMO-3', 'nombre' => 'Responsable DEMO 3', 'cargo' => 'Cargo de prueba DEMO 3'],
        ];
    }

    /** @return array<string, mixed> */
    public function progress(int $id, User $actor): array
    {
        return $this->withState($id, $actor);
    }

    /**
     * @param  list<array{id_demo: string, orden_firma: int}>  $signers
     * @return array<string, mixed>
     */
    public function assign(int $id, User $actor, string $mode, array $signers, string $key): array
    {
        $catalog = collect($this->signers())->keyBy('id_demo');
        $ordered = collect($signers)->sortBy('orden_firma')->values();
        abort_unless($ordered->pluck('orden_firma')->all() === range(1, $ordered->count()), 422,
            'El orden debe ser consecutivo desde uno.');
        $assignment = $ordered->map(fn (array $signer): array => [
            ...$catalog[$signer['id_demo']], 'orden_firma' => $signer['orden_firma'],
            'simulada' => false, 'fecha_simulacion' => null,
        ])->all();
        $fingerprint = hash('sha256', json_encode(['asignar', $mode, $assignment], JSON_THROW_ON_ERROR));

        return $this->withState($id, $actor, function (array &$state) use ($mode, $assignment, $key, $fingerprint): bool {
            if ($this->repeated($state, $key, $fingerprint)) {
                return true;
            }
            abort_if(collect($state['firmantes'])->contains('simulada', true), 409,
                'La demostración ya comenzó; se conserva su asignación.');
            $state['modalidad'] = $mode;
            $state['firmantes'] = $assignment;
            $state['operaciones'][$key] = $fingerprint;

            return false;
        });
    }

    /** @return array<string, mixed> */
    public function simulate(int $id, User $actor, string $signerId, string $key): array
    {
        $fingerprint = hash('sha256', 'simular:'.$signerId);

        return $this->withState($id, $actor, function (array &$state) use ($signerId, $key, $fingerprint): bool {
            if ($this->repeated($state, $key, $fingerprint)) {
                return true;
            }
            $position = collect($state['firmantes'])->search(fn (array $signer): bool => $signer['id_demo'] === $signerId);
            abort_if($position === false, 422, 'El responsable DEMO debe estar asignado.');
            abort_if($state['firmantes'][$position]['simulada'], 409, 'Este responsable ya completó su simulación.');
            if ($state['modalidad'] === 'SECUENCIAL') {
                $next = collect($state['firmantes'])->firstWhere('simulada', false);
                abort_unless($next['id_demo'] === $signerId, 409, 'La simulación debe respetar el orden secuencial.');
            }
            $state['firmantes'][$position]['simulada'] = true;
            $state['firmantes'][$position]['fecha_simulacion'] = now()->toIso8601String();
            $state['operaciones'][$key] = $fingerprint;

            return false;
        });
    }

    /** @param array<string, mixed> $state */
    private function repeated(array $state, string $key, string $fingerprint): bool
    {
        if (! isset($state['operaciones'][$key])) {
            return false;
        }
        abort_unless(hash_equals($state['operaciones'][$key], $fingerprint), 409,
            'La clave de operación ya fue utilizada con otros datos.');

        return true;
    }

    /**
     * @param  callable(array<string, mixed>&): bool|null  $operation
     * @return array<string, mixed>
     */
    private function withState(int $id, User $actor, ?callable $operation = null): array
    {
        abort_unless(app()->environment('local') && config('certification.stage_b.demo_enabled') === true,
            404, 'Demostración local deshabilitada.');
        $document = $this->stageB->pendingForUpdate($id, $actor);
        $disk = Storage::disk('local');
        $directory = 'certificados/simulaciones/'.(int) $actor->getAuthIdentifier();
        abort_unless($disk->makeDirectory($directory), 500, 'No se pudo preparar el almacenamiento de simulación.');
        $path = $disk->path($directory.'/'.$id.'.json.enc');
        $lock = @fopen($path.'.lock', 'c+b');
        abort_unless(is_resource($lock), 500, 'No se pudo reservar la demostración local.');
        $temporary = null;
        try {
            abort_unless(flock($lock, LOCK_EX | LOCK_NB), 503, 'La demostración está ocupada.');
            $state = [
                'actor' => (int) $actor->getAuthIdentifier(), 'id_certificado_documento' => $id,
                'hash_pdf' => $document->hash_certificado, 'modalidad' => null, 'firmantes' => [], 'operaciones' => [],
            ];
            if (is_file($path)) {
                try {
                    $state = json_decode(Crypt::decryptString(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    abort(409, 'La demostración almacenada no es válida.');
                }
            }
            abort_unless($state['actor'] === (int) $actor->getAuthIdentifier()
                && $state['id_certificado_documento'] === $id
                && hash_equals($state['hash_pdf'], $document->hash_certificado), 409,
                'El documento o su integridad cambiaron; la simulación anterior ya no corresponde.');
            $repeated = false;
            if ($operation !== null) {
                $repeated = $operation($state);
                if (! $repeated) {
                    $encrypted = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
                    $temporary = tempnam(dirname($path), '.simulacion-');
                    abort_unless(is_string($temporary)
                        && file_put_contents($temporary, $encrypted) === strlen($encrypted)
                        && rename($temporary, $path), 500, 'No se pudo guardar la demostración local.');
                }
            }
            $completed = $state['firmantes'] !== [] && collect($state['firmantes'])->every('simulada');

            return [
                'modo' => 'DEMO', 'advertencia' => self::WARNING, 'id_certificado_documento' => $id,
                'id_certificado' => (int) $document->id_certificado, 'estado_real' => $document->estado_certificado,
                'hash_pdf_original' => $document->hash_certificado, 'modalidad' => $state['modalidad'],
                'persistencia' => 'archivo_privado_local', 'asignacion_real_persistida' => false,
                'firmantes_disponibles' => $this->signers(), 'firmantes_demo' => $state['firmantes'],
                'simulacion_completa' => $completed, 'reintento' => $repeated,
                'firma_verificada' => false, 'emitido' => false, 'entregado' => false,
                'fecha_emision' => $document->fecha_emision, 'notificado' => (bool) $document->notificado,
            ];
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
