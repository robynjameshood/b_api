<?php

namespace App\Http\Controllers\Auth;

use App\Services\ElectraApiService;
use App\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Facades\App\ElectraWebServices;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
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
        $this->middleware('guest');
    }

    public function register(Request $request, ElectraApiService $electraApiService)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password' => ['required', 'string', 'regex:/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9])\S{8,}$/', 'confirmed'],
                'surname' => 'required',
                'policyNumber' => 'required'
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        try {
            $policiesSearchResult = $electraApiService->policySearch(collect($request->all())->except(['password','password_confirmation'])->toArray());
            $policies = collect($policiesSearchResult->data)->filter(function($policy) use ($request) {
                return strtolower($policy->policy_holder_email) == strtolower($request->email);
            });

            // no policies found
            if ($policies->isEmpty()) {
                return response()->json([
                    'message' => 'BDW005',
                    'result' => false
                ]);
            }

            // multiple policies found
            if ($policies->count() > 1) {
                return response()->json([
                  'message' => 'BDW010',
                    'result' => false
                ]);
            }

            $policy = $policies->first();
            $userAlreadyRegisteredWithCustomerNumber = User::query()
                ->where('customer_id', $policy->customer_id)
                ->orWhereJsonContains('secondary_customer_ids', (string)$policy->customer_id )
                ->first();

            if ($userAlreadyRegisteredWithCustomerNumber) {
                return response()->json([
                    'message' => 'BDW009',
                    'result' => false
                ]);
            }

            $userData = [
                'password' => Hash::make($request->password),
                'email' => $request->email,
                'customer_id' => $policy->customer_id,
                'secondary_customer_ids' => [],
                'policy_ids' => []
            ];

            $user = new User($userData);
            $user->save();
            $this->guard()->login($user);
            try {
                ElectraWebServices::addCustomerEvent($user->customer_id, 'BDCP - User has Registered Account', '', '');
            }
            catch (\Exception $exception) {
                Log::error('Failed to add user registered event', ['message' => $exception->getMessage()]);
            }

            return $this->registered($request, $user)
                ?: redirect($this->redirectPath());
        }
        catch (\Exception $exception) {
            Log::error('Policy search failed when customer tried to register policy',
                [
                    'message' => $exception->getMessage()
                ]
            );
            return response()->json([
                'message' => 'We have encountered an error please try again later if the error persists please contact us',
                'result' => false
            ],500);
        }
    }

    protected function registered(Request $request, $user)
    {
        $user->generateToken();
        return response()->json(['data' => $user->toArray()], 201);
    }
}
