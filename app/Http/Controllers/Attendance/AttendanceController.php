<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Data\Attendance\AttendanceData;
use App\Models\ActivitySession\ActivitySession;
use App\Models\Participation\Participation;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class AttendanceController extends Controller
{
    use ApiResponse;

    /**
     * List attendances.
     */
    public function list(): JsonResponse
    {
        $response = AttendanceData::getAttendances();

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    /**
     * Attendance detail.
     */
    public function detail(int $idAttendance): JsonResponse
    {
        $response = AttendanceData::getAttendanceById($idAttendance);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    /**
     * Create attendance.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'ID_PARTICIPACION' => ['bail', 'required', 'integer', Rule::exists(Participation::class, 'id_participacion')],
                'ID_ACTIVIDAD_SESION' => ['bail', 'required', 'integer', Rule::exists(ActivitySession::class, 'id_actividad_sesion')],
                'HORA_ENTRADA' => ['nullable', 'date_format:H:i'],
                'HORA_SALIDA' => ['nullable', 'date_format:H:i'],
                'ESTADO' => ['required', 'string', 'max:30'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = AttendanceData::createAttendance($validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message'], 201);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al registrar la asistencia.', 500);
        }
    }

    /**
     * Update attendance, including partial requests.
     */
    public function update(Request $request, int $idAttendance): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'ID_PARTICIPACION' => ['bail', 'sometimes', 'required', 'integer', Rule::exists(Participation::class, 'id_participacion')],
                'ID_ACTIVIDAD_SESION' => ['bail', 'sometimes', 'required', 'integer', Rule::exists(ActivitySession::class, 'id_actividad_sesion')],
                'HORA_ENTRADA' => ['sometimes', 'nullable', 'date_format:H:i'],
                'HORA_SALIDA' => ['sometimes', 'nullable', 'date_format:H:i'],
                'ESTADO' => ['sometimes', 'required', 'string', 'max:30'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = AttendanceData::updateAttendance($idAttendance, $validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message']);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al actualizar la asistencia.', 500);
        }
    }

    /**
     * Delete attendance.
     */
    public function destroy(int $idAttendance): JsonResponse
    {
        $response = AttendanceData::deleteAttendance($idAttendance);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
