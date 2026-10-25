<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Services\PasskeyService;
use App\Services\PasskeySettings;
use Illuminate\Http\Request;

class PasskeyController extends Controller
{
    private $service;
    public function __construct(PasskeyService $service) { $this->service = $service; }
    public function config() { return response(['data' => $this->service->capabilities()]); }
    public function relatedOrigins(Request $request)
    {
        if (!PasskeySettings::enabled() || !$request->isSecure() ||
            strtolower($request->getHost()) !== PasskeySettings::rpId(config('v2board.passkey_rp_id'))) abort(404);
        return response()->json(PasskeySettings::relatedOriginsDocument())
            ->header('Cache-Control', 'public, max-age=300')
            ->header('X-Content-Type-Options', 'nosniff');
    }
    public function loginOptions(Request $r) { return response(['data' => $this->service->loginOptions($r)]); }
    public function loginVerify(Request $r) { return response(['data' => $this->service->loginVerify($r)]); }
    public function listing(Request $r) { return response(['data' => $this->service->listing($r)]); }
    public function passwordReauth(Request $r) { return response(['data' => $this->service->passwordReauth($r)]); }
    public function reauthOptions(Request $r) { return response(['data' => $this->service->reauthOptions($r)]); }
    public function reauthVerify(Request $r) { return response(['data' => $this->service->reauthVerify($r)]); }
    public function registerOptions(Request $r) { return response(['data' => $this->service->registerOptions($r)]); }
    public function registerVerify(Request $r) { return response(['data' => $this->service->registerVerify($r)]); }
    public function rename(Request $r) { return response(['data' => $this->service->rename($r)]); }
    public function delete(Request $r) { return response(['data' => $this->service->delete($r)]); }
    public function adminRevoke(Request $r) { return response(['data' => $this->service->adminRevoke($r)]); }
}
