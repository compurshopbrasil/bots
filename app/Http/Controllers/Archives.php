<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use App\Helpers\API;

class Archives extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        if (($params = API::validate([
            'file' => ['required', 'file'],
        ], $request->all(), response: $response)) instanceof JsonResponse) {
            return $params;
        }

        $storage = Storage::disk('in');

        $file = $request->file('file');

        if (!$file->isValid()) {
            $response->addMessage('error', 'File is not valid.');
            return API::response($response);
        }

        $response->setData([
            'name' => $file->getClientOriginalName(),
            'path' => $storage->putFileAs('', $file, $file->getClientOriginalName()),
        ]);

        return API::response($response->data());
    }
}
