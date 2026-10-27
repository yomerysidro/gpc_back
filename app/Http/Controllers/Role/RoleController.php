<?php

namespace App\Http\Controllers\Role;

use App\Http\Controllers\Controller;
use App\Http\Data\Role\RoleData;
use App\Http\Requests\Role\RoleModulesRequest;
use App\Http\Requests\Role\RolePermissionsRequest;
use App\Http\Requests\Role\RoleRequest;
use App\Http\Requests\Role\RoleStatusRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    use ApiResponse;

    /**
     * List roles with their user count.
     */
    public function list(): JsonResponse
    {
        return $this->respond(RoleData::getRoles());
    }

    /**
     * Create role.
     */
    public function store(RoleRequest $request): JsonResponse
    {
        return $this->respond(RoleData::createRole($request->validated()), 201);
    }

    /**
     * Update role.
     */
    public function update(RoleRequest $request, int $id): JsonResponse
    {
        return $this->respond(RoleData::updateRole($id, $request->validated()));
    }

    /**
     * Activate or deactivate role.
     */
    public function updateStatus(RoleStatusRequest $request, int $id): JsonResponse
    {
        return $this->respond(RoleData::updateRoleStatus($id, $request->boolean('estado')));
    }

    /**
     * Module ids assigned to the role.
     */
    public function modules(int $id): JsonResponse
    {
        return $this->respond(RoleData::getRoleModules($id));
    }

    /**
     * Replace the role's modules.
     */
    public function syncModules(RoleModulesRequest $request, int $id): JsonResponse
    {
        return $this->respond(RoleData::syncRoleModules($id, $request->validated('modulos')));
    }

    /**
     * Permissions assigned to the role.
     */
    public function permissions(int $id): JsonResponse
    {
        return $this->respond(RoleData::getRolePermissions($id));
    }

    /**
     * Replace the role's permissions.
     */
    public function syncPermissions(RolePermissionsRequest $request, int $id): JsonResponse
    {
        return $this->respond(RoleData::syncRolePermissions($id, $request->validated('permisos')));
    }

    /**
     * data null means the role does not exist; a failure without
     * 'code' is a database error.
     */
    private function respond(array $response, int $code = 200): JsonResponse
    {
        if (! $response['success']) {
            return $this->information($response['message'], $response['code'] ?? 500, $response['data'] ?? []);
        }

        if ($response['data'] === null) {
            return $this->error($response['message'], 404);
        }

        return $this->ok($response['data'], $response['message'], $code);
    }
}
