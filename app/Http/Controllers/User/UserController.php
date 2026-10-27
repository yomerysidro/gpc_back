<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Data\User\UserData;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use ApiResponse;

    /**
     * List users
     */
    public function list()
    {
        $response = UserData::getUsers();

        if (! $response['success']) {
            return $this->error(
                $response['message'],
                500
            );
        }

        return $this->ok(
            $response['data'],
            $response['message']
        );
    }

    /**
     * User detail.
     */
    public function detail(int $idUser)
    {
        $response = UserData::getUserById($idUser);

        if (! $response['success']) {
            return $this->error(
                $response['message'],
                404
            );
        }

        return $this->ok(
            $response['data'],
            $response['message']
        );
    }

    /**
     * Create user.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        if ($validator->fails()) {
            return $this->information(
                'Invalid validation data.',
                422,
                $validator->errors()
            );
        }

        $response = UserData::createUser(
            $validator->validated()
        );

        if (! $response['success']) {
            return $this->error(
                $response['message'],
                400
            );
        }

        return $this->ok(
            $response['data'],
            $response['message'],
            201
        );
    }

    /**
     * Update user.
     */
    public function update(Request $request, int $idUser)
    {
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($idUser),
            ],

            'password' => [
                'nullable',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        if ($validator->fails()) {
            return $this->information(
                'Invalid validation data.',
                422,
                $validator->errors()
            );
        }

        $response = UserData::updateUser(
            $idUser,
            $validator->validated()
        );

        if (! $response['success']) {
            return $this->error(
                $response['message'],
                404
            );
        }

        return $this->ok(
            $response['data'],
            $response['message']
        );
    }

    /**
     * Delete user.
     */
    public function destroy(int $idUser)
    {
        $response = UserData::deleteUser($idUser);

        if (! $response['success']) {
            return $this->error(
                $response['message'],
                404
            );
        }

        return $this->ok(
            $response['data'],
            $response['message']
        );
    }
}
