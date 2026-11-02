<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Services\AuthService;
use App\Services\PasswordLoginLimiter;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(AuthRegister $request)
    {
        return response()->json(['data' => app(\App\Services\RegistrationService::class)->register($request)]);
    }

    public function login(AuthLogin $request)
    {
        $email = \App\Services\EmailAddress::key($request->input('email'));
        $password = $request->input('password');
        $limiter = new PasswordLoginLimiter();
        $limiter->throttle($request);
        $user = \App\Services\EmailIdentity::find($email);
        if (!$limiter->verify($user, $email, $password)) {
            abort(500, __('Incorrect email or password'));
        }

        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }

        $authService = new AuthService($user);
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function token2Login(Request $request)
    {
        if ($request->input('token')) {
            $redirect = '/#/login?verify=' . $request->input('token') . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
            if (config('v2board.app_url')) {
                $location = config('v2board.app_url') . $redirect;
            } else {
                $location = url($redirect);
            }
            return redirect()->to($location)->send();
        }

        if ($request->input('verify')) {
            return response([
                'data' => AuthService::redeemQuickLoginCode($request, $request->input('verify'))
            ]);
        }
    }

    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $code = AuthService::issueQuickLoginCode($authorization);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }

    public function forget(AuthForget $request)
    {
        app(\App\Services\EmailVerificationService::class)->recover(
            $request->input('email'), $request->input('email_code'), $request->input('password')
        );
        return response(['data' => true]);
    }
}
