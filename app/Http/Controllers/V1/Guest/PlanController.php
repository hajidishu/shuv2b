<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\PublicPlanService;

class PlanController extends Controller
{
    public function fetch(PublicPlanService $plans)
    {
        // Disabling or changing the allowlist must not leave a cached catalog.
        return response($plans->catalog())->header('Cache-Control', 'no-store, private');
    }
}
