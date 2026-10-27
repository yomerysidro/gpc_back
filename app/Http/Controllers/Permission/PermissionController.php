<?php

namespace App\Http\Controllers\Permission;

use App\Http\Controllers\Controller;
use App\Http\Data\Module\ModuleData;
use App\Http\Data\Permission\PermissionData;
use App\Http\Requests\Permission\PermissionRequest;
use App\Http\Requests\Permission\PermissionStatusRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    use ApiResponse;

    /**
     * Create a permission in the module {id}.
     */
    public function store(PermissionRequest $request, int $id): JsonResponse
    {
        $response = ModuleData::moduleExists($id);

        if (! $response['success']) {
            return $this->error($response['message'], 500);
        }

        if (! $response['data']) {
            return $this->error('Módulo no encontrado.', 404);
        }

        return $this->respond(PermissionData::createPermission($id, $request->validated()), 201);
    }

    /**
     * Update permission.
     */
    public function update(PermissionRequest $request, int $id): JsonResponse
    {
        return $this->respond(PermissionData::updatePermission($id, $request->validated()));
    }

    /**
     * Activate or deactivate permission.
     */
    public function updateStatus(PermissionStatusRequest $request, int $id): JsonResponse
    {
        return $this->respond(PermissionData::updatePermissionStatus($id, $request->boolean('estado')));
    }

    /**
     * data null means the permission does not exist.
     */
    private function respond(array $response, int $code = 200): JsonResponse
    {
        if (! $response['success']) {
            return $this->error($response['message'], 500);
        }

        if ($response['data'] === null) {
            return $this->error($response['message'], 404);
        }

        return $this->ok($response['data'], $response['message'], $code);
    }
}
