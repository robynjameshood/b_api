<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\DataController;
use App\Http\Controllers\JsonController;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Facades\App\ElectraWebServices;
Use App\AuditLog;
use Mockery\Exception;

class LoginController extends JsonController
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    public function login(Request $request)
    {
        $this->validateLogin($request);

        if ($this->attemptLogin($request)) {
            $user = $this->guard()->user();
            $user->generateToken();

            ElectraWebServices::addCustomerEvent($user->customer_id, 'BDCP - User has Logged In', '', '');

            return response()->json([
                'data' => $user->toArray(),
            ]);
        }

        return $this->sendFailedLoginResponse($request);
    }

    public function logout(Request $request)
    {
        $user = Auth::guard('api')->user();

        if ($user) {
            $user->api_token = null;
            $user->save();
        }
        ElectraWebServices::addCustomerEvent($user->customer_id, 'BDCP - User has Logged Out', '', '');
        return response()->json(['data' => 'User logged out.'], 200);
    }

    public function token(Request $request)
    {
        if (!$request->has('token')) {
            $this->addError('No token specified');
        } else {
            $user = User::where('login_token', $request->token)->first();
            if (empty($user)) {
                $this->addError('The login token provided is invalid');
            } else {
                if (strtotime($user->token_expiry) <= strtotime('now')) {
                    $this->addError('The provided login token has expired');
                } else {

                    try {
                        Auth::login($user);
                        $token = md5(sprintf('%d-%d', $user->id, time()));
                        $user->api_token = $token;
                        $user->logged_in_by_token = true;
                        $user->save();
                        $this->setResult(['token' => $token, 'logged_in_by_token' => true]);

                        ElectraWebServices::addCustomerEvent($user->customer_id, 'BDCP - User has Logged In Via Token', '', '');
                    } catch (Exception $e) {
                        $this->addError("Failed to login");
                        Log::error(
                            $e->getMessage(),
                            ['trace' => $e->getTraceAsString(), 'user' => is_null($user) ? null : $user->id]
                        );
                    }
                }
            }
        }
        return $this->getResponse();
    }

}

