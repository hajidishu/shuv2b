<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\InviteCode;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use App\Services\CaptchaService;
use Illuminate\Support\Facades\RateLimiter;

class CommController extends Controller
{
    private function isEmailVerify()
    {
        return response([
            'data' => (int)config('v2board.email_verify', 0) ? 1 : 0
        ]);
    }

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        $ip = $request->ip();
        if (RateLimiter::tooManyAttempts($ip, 3)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit($ip, 60);

        app(CaptchaService::class)->verify($request, 'email');
        $email = \App\Services\EmailAddress::normalize($request->input('email'));
        $mode = $request->input('isforget', 0);
        if (!in_array($mode, [null, 0, 1, '0', '1'], true)) abort(422, '验证码用途无效');
        if ((int)config('v2board.email_whitelist_enable', 0) && !Helper::emailSuffixVerify($email,
            config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) abort(500, __('Email suffix is not in the Whitelist'));
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = substr($email, 0, strrpos($email, '@'));
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) abort(500, __('Gmail alias is not supported'));
        }
        $codes = app(\App\Services\EmailVerificationService::class);
        if ($mode === 1 || $mode === '1') $codes->sendRecovery($email);
        else $codes->sendRegistration($email); // An omitted isforget can never issue a recovery challenge.
        return response([
            'data' => true
        ]);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return response([
            'data' => true
        ]);
    }

    private function getEmailSuffix()
    {
        $suffix = config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        if (!is_array($suffix)) {
            return preg_split('/,/', $suffix);
        }
        return $suffix;
    }
}
