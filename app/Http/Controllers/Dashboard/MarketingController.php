<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\JsonController;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use App\Marketing;
Use App\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use stdClass;
use Facades\App\ElectraWebServices;


class MarketingController extends JsonController
{
    /*
    |--------------------------------------------------------------------------
    | Marketing Controller
    |--------------------------------------------------------------------------
    | this ascertains the users Contact Preferences
    |
    */
/*    private $_result = false;
    private $_messages = array();

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }



    protected function getResponse()
    {
        return response()->json(
            [
                'result' => $this->_result,
                'messages' => $this->_messages
            ]
        );
    }*/


    // retrieve preferences
//    public function preferences(Request $request)
//    {
//        if (!$request->has('customer_id')) {
//            $this->_result = false;
//        }else{
//            $marketing = Marketing::where('customer_id', $request->customer_id)->first();
//            $this->_result = true;
//            $this->_messages = $marketing;
//
//        }
//        return $this->getResponse();
//    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \SoapFault
     */
    public function marketing(Request $request)
    {
        $user = Auth::user();

        $validator = \Illuminate\Support\Facades\Validator::make(['validAccount' => empty($user->policy_ids)], [
            'validAccount' => [
                'required',
                'boolean',
                function ($attribute, $value, $fail) {
                    if ($value === true) {
                        $fail('Can not edit preferences. no valid policies for customer');
                    }
                },
            ],
        ] );

        if($validator->fails()){
            throw new ValidationException($validator);
        }

        $customerId = $user->customer_id;

        // Save to Electra
        $soap = new \SoapClient(env('SSP_CUSTOMER_WSDL'), ['trace' => 1]);

        $customers_to_update = array_merge($user->secondary_customer_ids, [$customerId]);

        foreach($customers_to_update as $customerId) {

            //Get the customers current details
            $currentDetails = $this->getCustomerCurrentDetails($soap, $customerId);

            //Set up the parameters for the soap
            $params = new StdClass();
            $params->request = new StdClass();
            $params->request->Auth = $this->generateAuth(
                $customerId
            );

            //Set the customer details to their current values
            $params->request->Customer = $currentDetails;

            //Update the customers marketing and contact details
            if ($request->can_email || $request->can_post || $request->can_sms || $request->can_phone) {
                $params->request->Customer->CanMarket = true;
            } else {
                $params->request->Customer->CanMarket = false;
            }
            $params->request->Customer->ContactPreferences->Email = $request->can_email;
            $params->request->Customer->ContactPreferences->Post = $request->can_post;
            $params->request->Customer->ContactPreferences->Sms = $request->can_sms;
            $params->request->Customer->ContactPreferences->Telephone = $request->can_phone;


            //Try send the soap
            try {
                $response = $soap->Update($params);
                if ($response !== false
                    && !empty($response->UpdateResult)
                    && !empty($response->UpdateResult->Updated)
                ) {
                    ElectraWebServices::addCustomerEvent($customerId, 'BDCP - Marketing Preferences Updated', '', '');
                    $this->addSuccess();
                    $this->setResult(true);
                } else {
                    $this->addError('Failed to update customer details');
                    Log::error('Failed to update customer details',
                        ['customer_id' => $customerId]);
                }
            } catch (\Exception $e) {
                $this->addError($e->getMessage());
                Log::error(
                    $e->getMessage() . ' - Failed to update customer details',
                    ['trace' => $e->getTraceAsString()]
                );
            }
        }

        return $this->getResponse();

    }

    public function getCustomerCurrentDetails($customerSoap, $customerId)
    {
        try {
            // Request the customer details
            $params = new StdClass();
            $params->request = new StdClass();
            $params->request->Auth = $this->generateAuth(
                $customerId
            );
            $params->request->Id = $customerId;
            $response = $customerSoap->Load($params);

            return $response->LoadResult->Customer;

        }  catch(\Exception $e) {
            Log::error(
                $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            return false;
        }
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
                    Auth::login($user);

                    $token = md5(sprintf('%d-%d', $user->id, time()));
                    $user->api_token = $token;
                    $user->save();


                    $data = new DataController();
                    $request->merge(["runPolSync" => "true"]);
                    $data = array_merge(
                        ['token' => $token],
                        $this->mergeResponse($data->get($request))
                    );
                    $this->setResult($data);
                }
            }
        }
        return $this->getResponse();
    }

}

