<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Http\Controllers;

use App\Libraries\M1pposu\SourceAuthenticator;
use App\Libraries\User\CountryChange;
use App\Libraries\User\DatadogLoginAttempt;
use App\Libraries\User\ForceReactivation;
use App\Models\Country;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Transformers\CurrentUserTransformer;
use Auth;
use romanzipp\Turnstile\Validator as TurnstileValidator;
use Throwable;

class SessionsController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest', ['only' => [
            'create',
            'store',
        ]]);

        parent::__construct();
    }

    public function create()
    {
        return view('sessions.create');
    }

    public function store()
    {
        $request = request();

        $params = get_params(
            $request->all(),
            null,
            ['username:string', 'password', 'cf-turnstile-response'],
            ['null_missing' => true],
        );
        $username = presence(trim($params['username'] ?? ''));
        $password = $params['password'];

        if ($username === null) {
            DatadogLoginAttempt::log('missing_username');

            abort(422);
        }

        if ($password === null) {
            DatadogLoginAttempt::log('missing_password');

            abort(422);
        }

        if (captcha_login_triggered()) {
            $token = $params['cf-turnstile-response'];
            $validCaptcha = false;

            if ($token !== null) {
                $validCaptcha = (new TurnstileValidator())->validate($token)->isValid();
            }

            if (!$validCaptcha) {
                if ($token === null) {
                    DatadogLoginAttempt::log('missing_captcha');
                } else {
                    DatadogLoginAttempt::log('invalid_captcha');
                }

                return $this->triggerCaptcha(osu_trans('users.login.invalid_captcha'), 422);
            }
        }

        $ip = $request->getClientIp();

        $user = User::findForLogin($username);

        $emailLoginDisabled = $user === null
            && strpos($username, '@') !== false
            && !$GLOBALS['cfg']['osu']['user']['allow_email_login'];

        if ($emailLoginDisabled) {
            $authError = osu_trans('users.login.email_login_disabled');
        } else {
            $authError = User::attemptLogin($user, $password, $ip);
        }

        if ($authError !== null && !$emailLoginDisabled) {
            try {
                $sourceAuthUser = app(SourceAuthenticator::class)->attempt($username, $password);
            } catch (Throwable $e) {
                log_error($e);

                return error_popup('Source login is temporarily unavailable. Please try again or contact support.', 503);
            }

            if ($sourceAuthUser !== null) {
                $user = $sourceAuthUser;
                $authError = null;
                LoginAttempt::logLoggedIn($ip, $user);
            }
        }

        if ($authError === null) {
            $forceReactivation = new ForceReactivation($user, $request);

            if ($forceReactivation->isRequired()) {
                DatadogLoginAttempt::log('password_reset');
                $forceReactivation->run();

                \Session::flash('password_reset_start', [
                    'reason' => $forceReactivation->getReason(),
                    'username' => $username,
                ]);

                return ujs_redirect(route('password-reset'));
            }

            // try fixing user country if it's currently set to unknown
            if ($user->country_acronym === Country::UNKNOWN) {
                try {
                    $requestCountry = request_country();
                    if ($requestCountry !== null) {
                        CountryChange::handle($user, $requestCountry, 'automated unknown country fixup on login');
                    }
                } catch (\Throwable $e) {
                    // report failures but continue anyway
                    log_error($e);
                }
            }

            DatadogLoginAttempt::log(null);
            $this->login($user);

            return [
                'csrf_token' => csrf_token(),
                'header' => view('layout._header_user')->render(),
                'header_popup' => view('layout._popup_user')->render(),
                'user' => json_item($user, new CurrentUserTransformer()),
            ];
        }

        if (captcha_login_triggered()) {
            return $this->triggerCaptcha($authError);
        }

        return error_popup($authError, 403);
    }

    public function destroy()
    {
        if (Auth::check()) {
            logout();
        }

        return [];
    }

    private function triggerCaptcha($message, $returnCode = 403)
    {
        return response([
            'error' => $message,
            'captcha_triggered' => true,
        ], $returnCode);
    }
}
