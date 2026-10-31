<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\ImageCaptchaService;
use Illuminate\Http\Request;

class CaptchaController extends Controller
{
    public function create(Request $request, ImageCaptchaService $service)
    {
        return response(['data' => $service->create($request)])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
