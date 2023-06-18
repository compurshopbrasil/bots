<?php

namespace App\Helpers;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Helpers\RAPI;

class API
{
    /**
     * @var array
     */
    public static RAPI $init;

    /**
     * @return RAPI
     */
    public static function RAPI(): RAPI
    {
        return self::$init ??= RAPI::create();
    }

    /**
     * @param object $data
     * @param int $code
     * 
     * @return JsonResponse
     */
    public static function response(object $data, int $code = Response::HTTP_OK): JsonResponse
    {
        return response()->json($data, $code);
    }

    /**
     * @param object $data
     * @param int $code
     * 
     * @return JsonResponse
     */
    public static function validate(array $rules, array $fields, array $default = [], ?object &$response = null): mixed
    {
        $response = $response ??= self::RAPI();

        $validator = Validator::make($fields, $rules);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $response->addMessage('error', $error);
            }

            return self::response($response, Response::HTTP_BAD_REQUEST);
        }

        $params = $validator->validated();

        foreach ($default as $key => $value) {
            if (!isset($params[$key])) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
