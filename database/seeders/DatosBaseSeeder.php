<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Datos base: roles, áreas del organigrama, catálogos y usuarios de prueba.
 *
 * Idempotente: cada fila se busca por su clave natural antes de insertarse,
 * así que correr db:seed varias veces no duplica nada.
 */
class DatosBaseSeeder extends Seeder
{
    /**
     * Unidades del Organigrama oficial (Anexo ROF, Modelo 4) de la
     * Municipalidad Distrital de Morales.
     */
    private const AREAS = [
        'Alcaldía',
        'Gerencia Municipal',
        'Oficina General de Atención al Ciudadano y Gestión Documentaria',
        'Oficina General de Asesoría Jurídica',
        'Oficina General de Administración',
        'Oficina General de Planeamiento y Presupuesto',
        'Gerencia de Administración Tributaria',
        'Gerencia de Desarrollo Territorial y Económico',
        'Gerencia de Servicios Municipales y Gestión Ambiental',
        'Gerencia de Desarrollo Social',
    ];

    private const USUARIOS = [
        [
            'nombres' => 'Antón',
            'apellido_paterno' => 'Talían',
            'dni' => '12345678',
            'correo' => 'anton.informatica@moralesmuni.pe',
            'rol' => 'Administrador',
            'area' => 'Oficina General de Administración',
            'cargo' => 'Jefe de Informática',
        ],
        [
            'nombres' => 'Maria',
            'apellido_paterno' => 'Perez',
            'dni' => '87654321',
            'correo' => 'maria.rrhh@moralesmuni.pe',
            'rol' => 'Digitador',
            'area' => 'Oficina General de Administración',
            'cargo' => 'Especialista de RR.HH.',
        ],
        [
            'nombres' => 'Juan',
            'apellido_paterno' => 'Alcalde',
            'dni' => '11112222',
            'correo' => 'alcalde@moralesmuni.pe',
            'rol' => 'Gerencial',
            'area' => 'Alcaldía',
            'cargo' => 'Alcalde',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (['Administrador', 'Digitador', 'Gerencial'] as $rol) {
                $this->firstOrInsert('acceso.rol', 'id_rol', ['nombre' => $rol], ['estado' => true]);
            }

            foreach (self::AREAS as $area) {
                $this->firstOrInsert('gestion.area', 'id_area', ['nombre' => $area]);
            }

            foreach (['DNI', 'Carnet de Extranjería', 'Pasaporte'] as $tipo) {
                $this->firstOrInsert('gestion.tipo_documento', 'id_tipo_documento', ['nombre' => $tipo], ['estado' => true]);
            }

            $this->firstOrInsert('certificado.tipo_actividad', 'id_tipo_actividad', ['nombre' => 'Capacitación'], ['estado' => true]);

            foreach (['Municipal', 'Externo'] as $tipo) {
                $this->firstOrInsert('certificado.tipo_participacion', 'id_tipo_participacion', ['nombre' => $tipo], ['estado' => true]);
            }

            foreach (['Capacitación', 'Reconocimiento', 'Prácticas'] as $tipo) {
                $this->firstOrInsert('certificado.tipo_certificado', 'id_tipo_certificado', ['nombre_tipo' => $tipo], ['estado' => true]);
            }

            foreach (self::USUARIOS as $usuario) {
                $this->crearUsuario($usuario);
            }
        });
    }

    /**
     * persona → persona_documento (DNI) → persona_trabajador → usuario → usuario_rol.
     * La persona se identifica por su DNI y el usuario por su correo.
     */
    private function crearUsuario(array $datos): void
    {
        $hoy = now()->toDateString();
        $idDni = DB::table('gestion.tipo_documento')->where('nombre', 'DNI')->value('id_tipo_documento');
        $idArea = DB::table('gestion.area')->where('nombre', $datos['area'])->value('id_area');
        $idRol = DB::table('acceso.rol')->where('nombre', $datos['rol'])->value('id_rol');

        $persona = [
            'nombres' => $datos['nombres'],
            'apellido_paterno' => $datos['apellido_paterno'],
        ];

        $idPersona = DB::table('gestion.persona_documento')
            ->where('id_tipo_documento', $idDni)
            ->where('numero_documento', $datos['dni'])
            ->value('id_persona');

        if ($idPersona) {
            DB::table('gestion.persona')->where('id_persona', $idPersona)->update($persona);
        } else {
            $idPersona = DB::table('gestion.persona')->insertGetId(
                $persona + ['fecha_registro' => $hoy],
                'id_persona'
            );

            DB::table('gestion.persona_documento')->insert([
                'id_persona' => $idPersona,
                'id_tipo_documento' => $idDni,
                'numero_documento' => $datos['dni'],
                'estado' => 'Activo',
            ]);
        }

        $this->firstOrInsert(
            'gestion.persona_trabajador',
            'id_persona_trabajador',
            ['id_persona' => $idPersona],
            ['id_area' => $idArea, 'cargo' => $datos['cargo'], 'estado' => true],
            ['fecha_ingreso' => $hoy]
        );

        // La contraseña solo se fija al crear el usuario, para no pisarla en cada seed.
        $idUsuario = $this->firstOrInsert(
            'acceso.usuario',
            'id_usuario',
            ['correo' => $datos['correo']],
            ['id_persona' => $idPersona],
            ['password_hash' => Hash::make('Test1234')]
        );

        $this->firstOrInsert('acceso.usuario_rol', 'id_usuario_rol', ['id_usuario' => $idUsuario], ['id_rol' => $idRol]);
    }

    /**
     * Busca la fila por $claves: si existe actualiza $valores, si no la inserta
     * junto con $soloAlInsertar. Devuelve el id de la fila.
     */
    private function firstOrInsert(string $tabla, string $pk, array $claves, array $valores = [], array $soloAlInsertar = []): int
    {
        $id = DB::table($tabla)->where($claves)->value($pk);

        if ($id) {
            if ($valores) {
                DB::table($tabla)->where($pk, $id)->update($valores);
            }

            return $id;
        }

        return DB::table($tabla)->insertGetId($claves + $valores + $soloAlInsertar, $pk);
    }
}
