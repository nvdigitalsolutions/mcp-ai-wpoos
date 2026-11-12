<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class SiteController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name' => (string) config('app.name', 'NV oOS'),
        ]);
    }
}
