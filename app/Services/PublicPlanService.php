<?php

namespace App\Services;

use App\Models\Plan;

class PublicPlanService
{
    public const FIELDS = ['id', 'name', 'content', 'month_price', 'quarter_price', 'half_year_price',
        'year_price', 'two_year_price', 'three_year_price', 'onetime_price'];

    public function catalog(): array
    {
        $enabled = (int)config('v2board.public_plan_enabled', 0) === 1;
        $result = ['data' => [], 'meta' => ['enabled' => $enabled,
            'currency' => config('v2board.currency', 'CNY'), 'currency_symbol' => config('v2board.currency_symbol', '¥')]];
        $configured = config('v2board.public_plan_ids', []);
        if (!$enabled || !is_array($configured) || !$configured || count($configured) > 100) return $result;
        $ids = [];
        foreach ($configured as $id) {
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id < 1) return $result;
            $ids[] = (int)$id;
        }
        // Select only advertised fields. Never serialize an entire plan model
        // or run user-count queries from this unauthenticated endpoint.
        $plans = Plan::where('show', 1)->whereIn('id', array_unique($ids))
            ->orderBy('sort')->orderBy('id')->get(self::FIELDS);
        foreach ($plans as $plan) $result['data'][] = $plan->only(self::FIELDS);
        return $result;
    }
}
