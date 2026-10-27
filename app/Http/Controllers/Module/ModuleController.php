<?php

namespace App\Http\Controllers\Module;

use App\Http\Controllers\Controller;
use App\Http\Data\Module\ModuleData;
use App\Http\Requests\Module\ModuleRequest;
use App\Http\Requests\Module\ModuleStatusRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ModuleController extends Controller
{
    use ApiResponse;

    /**
     * List modules with their permissions.
     */
    public function list(): JsonResponse
    {
        return $this->respond(ModuleData::getModules());
    }

    /**
     * Create module.
     */
    public function store(ModuleRequest $request): JsonResponse
    {
        return $this->respond(ModuleData::createModule($request->validated()), 201);
    }

    /**
     * Update module.
     */
    public function update(ModuleRequest $request, int $id): JsonResponse
    {
        return $this->respond(ModuleData::updateModule($id, $request->validated()));
    }

    /**
     * Activate or deactivate module.
     */
    public function updateStatus(ModuleStatusRequest $request, int $id): JsonResponse
    {
        return $this->respond(ModuleData::updateModuleStatus($id, $request->boolean('estado')));
    }

    /**
     * data null means the module does not exist.
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
