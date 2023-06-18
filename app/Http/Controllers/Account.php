<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Helpers\API;

class Account extends Controller
{
    public function createAccount(Request $request): JsonResponse
    {
        if (($params = API::validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8'],
        ], $request->all(), response: $response)) instanceof JsonResponse) {
            return $params;
        }

        $response->data = (object) [
            'name' => $params['name'],
            'email' => $params['email'],
            'password' => $params['password'],
        ];

        return API::response($response);
    }

    public function login(Request $request): JsonResponse
    {
        if (($params = API::validate([
            'email' => ['nullable', 'string', 'email'],
            'password' => ['nullable', 'string', 'min:8'],
        ], $request->all(), r: $response)) instanceof JsonResponse) {
            return $params;
        }
    }
}
