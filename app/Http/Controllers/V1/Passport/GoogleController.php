<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Services\GoogleIdentityService;
use App\Services\GoogleSettings;
use Illuminate\Http\Request;

class GoogleController extends Controller
{
    private $service;
    public function __construct(GoogleIdentityService $service) { $this->service = $service; }
    private function result(array $data) { return response(['data' => $data])->header('Cache-Control', 'no-store, private'); }
    public function config() { return $this->result(GoogleSettings::publicSettings()); }
    public function options(Request $r) { return $this->result($this->service->options($r)); }
    public function verify(Request $r) { return $this->result($this->service->login($r)); }
    public function register(Request $r) { return $this->result($this->service->register($r)); }
    public function listing(Request $r) { return $this->result($this->service->listing($r)); }
    public function bindOptions(Request $r) { return $this->result($this->service->options($r, true)); }
    public function bind(Request $r) { return $this->result($this->service->bind($r)); }
    public function unbind(Request $r) { return $this->result($this->service->unbind($r)); }
}
