<?php

namespace App\Http\Controllers\Auth;

use App\BarbaraWebServices;
use App\Http\Controllers\JsonController;
use App\User;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Facades\App\ElectraWebServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ForgotPasswordController extends JsonController
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    public function passwordReset(Request $request, BarbaraWebServices $barbaraWebServices)
    {
        $expiresInString = config('app.password_reset_expires_in');
        $expiresIn = CarbonInterval::createFromDateString($expiresInString);
        if (!$request->has('email')) {
            $this->addError('Please enter your email address');
        } else {
            $user = User::query()->where('email', $request->email)->first();
            if (!empty($user)) {
                $token = md5(sprintf('%d-%d', $user->id, time()));
                $user->login_token = $token;
                $user->token_expiry = Carbon::now()->add($expiresIn);
                $user->save();

                $policyId = 0;
                //TODO Update the policy id from the database when we have it

                // do we have an nested array from users.policy_ids ?
                if(count($user->policy_ids) !== count($user->policy_ids, COUNT_RECURSIVE)) {
                    if(array_key_exists($user->customer_id, $user->policy_ids)) {
                        $policyId = $user->policy_ids[$user->customer_id][0];
                    }
                }
                elseif(count($user->policy_ids)) {
                    // just an array
                    $policyId = $user->policy_ids[0];
                }

                $packId = (int)config('app.password_reset_pack_id') ?? 0;
                if($packId === 0) {
                    Log::error('Failed to get pack ID for password reset email', ['ENV variable' => 'password_reset_pack_id']);
                } else {
                    $barbaraResponse = $barbaraWebServices->sendTokenLoginLinkEmail($packId, $policyId, $user->login_token, $user->email, $user->id, $expiresInString);

                    if ($barbaraResponse['status']) {
                        Log::error('Failed to sent password reset email to Barbara', ['result' => $barbaraResponse]);
                    }

                    $this->setResult(true);
                }
            }
        }
        return $this->getResponse();
    }

}
