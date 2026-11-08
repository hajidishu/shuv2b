<?php
namespace App\Services;

use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;

class UserGroupService
{
    // 0 follows the plan; 1 keeps an administrator-selected permission group.
    public static function forUpdate(User $user, array $params): array
    {
        $planId=array_key_exists('plan_id',$params) ? $params['plan_id'] : $user->plan_id;
        $plan=$planId ? Plan::find($planId) : null;
        if (array_key_exists('plan_id',$params) && $params['plan_id']!==null && !$plan) abort(500,'订阅计划不存在');
        $mode=(int)($params['group_mode'] ?? $user->group_mode);
        if ($mode===1) {
            $groupId=array_key_exists('group_id',$params) ? $params['group_id'] : $user->group_id;
            if (array_key_exists('group_id',$params) || (int)$user->group_mode!==1)
                abort_unless($groupId && ServerGroup::whereKey($groupId)->exists(),422,'请选择有效的自定义权限组');
            $params['group_id']=$groupId;
        } else {
            $params['group_id']=$plan ? $plan->group_id : null;
        }
        $params['group_mode']=$mode===1 ? 1 : 0;
        return $params;
    }

    public static function applyPlan(User $user, Plan $plan, int $orderType): void
    {
        if ((int)$user->group_mode===1) {
            if (!in_array($orderType,[1,3],true)) return;
            // Queue workers may outlive an administrator's setting change.
            $file=config_path('v2board.php');
            $settings=is_file($file) ? require $file : [];
            if (!($settings['reset_custom_group_on_purchase'] ?? config('v2board.reset_custom_group_on_purchase',0))) return;
        }
        $user->group_mode=0;
        $user->group_id=$plan->group_id;
    }
}
