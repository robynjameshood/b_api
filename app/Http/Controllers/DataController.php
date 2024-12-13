<?php

namespace App\Http\Controllers;

use App\ElectraOnePageQuote;
use App\Jobs\ProcessPaymentRequest;
use App\Quote;
use App\Services\AddressLookupService;
use App\Services\ElectraApiService;
use App\Services\GetQuoteApiService;
use App\Services\PaymentHubService;
use App\StringIdConverter;
use App\Transaction;
use Carbon\Carbon;
use Facades\App\ElectraWebServices;
use Facades\App\BarbaraWebServices;
use App\User;
use App\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;
use App\Pack;
use App\Toba;
use GuzzleHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Rollbar\Rollbar;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class DataController extends JsonController
{
    /**
     * Return a policy document based on the hashed location in Electra
     *
     * @param $policyId
     * @param $hashedFileUrl
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function document($policyId, $hashedFileUrl, $filename)
    {
        $user = Auth::user();
        if ($policyId == 0) {
            //return default terms of business if no file in electra
            if ($filename === 'Terms of Business') {
                try {
                    //Storage get will now return null instead of an exception if the file is not found
                    $data = Storage::disk('s3')->get('pccp/default_documents/TOBA.pdf');
                    if (is_null($data)) {
                        throw new FileNotFoundException('s3/pccp/default_documents/TOBA.pdf');
                    }
                    ElectraWebServices::addEvent($user->customer_id, $policyId, 'Customer downloaded their ' . $filename);
                    return response()->streamDownload(
                        function () use ($data) {
                            echo $data;
                        },
                        'terms-of-business.pdf',
                        ['Content-Type' => 'application/pdf']
                    );
                } catch (\Exception $error) {
                    Log::error(
                        'User failed to access document' . $error->getMessage(),
                        ['trace' => $error->getTraceAsString()]
                    );
                    ElectraWebServices::addEvent($user->customer_id, $policyId, 'Customer failed to downloaded their ' . $filename);
                    abort(484);
                }
            }
            Log::error('Unable to find policy document - ' . $filename . ' - no URL');
            ElectraWebServices::addEvent($user->customer_id, $policyId, 'Customer failed to downloaded their ' . $filename);
            abort(480);
        }

        $docClient = new \SoapClient(env('SSP_DOCUMENT_WSDL'), ['trace' => 1]);

        $params = new StdClass();
        $params->request = new StdClass();
        $params->request->Auth = $this->generateAuth(
            $policyId
        );
        $params->request->PolicyId = $policyId;
        $documents = $docClient->GetDocumentList($params);

        if (is_array($documents->GetDocumentListResult->Documents->DocumentItem)) {
            $documents = $documents->GetDocumentListResult->Documents->DocumentItem;
        } else {
            $documents = [$documents->GetDocumentListResult->Documents->DocumentItem];
        }
        $documentUrl = $this->decryptUrl($hashedFileUrl);

        $errorCode = 480;
        $documentError = 0;
        foreach ($documents as $document) {
            if ($document->Link == $documentUrl) {
                $documentError = 1;
                switch ($document->Description) {
                    case 'Certificate/Schedule Form':
                        $errorCode = 481;
                        break;
                    case 'Proposal/Statement Of Fact Form':
                        $errorCode = 482;
                        break;
                    case 'Policy IPID':
                        $errorCode = 483;
                        break;
                    case 'Terms of Business':
                        $errorCode = 484;
                        break;
                    default:
                        break;
                }
                if ($user->customer_id !== $document->CustomerId) {
                    Log::error('User not authorised to access document ' . $documentUrl);
                    abort($errorCode);
                }

                $guzzleClient = new GuzzleHttp\Client();
                try {
                    ElectraWebServices::addEvent($user->customer_id, $policyId, 'Customer downloaded their ' . $filename);
                    $res = $guzzleClient->get($document->Link);
                    $date = Carbon::parse($document->Created)->format('Y-m-d_h-i-s');
                    $filename = Str::slug($document->Description . '_' . $date) . $document->Extension;
                    $headers = $res->getHeaders();
                    $headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
                    return response()->streamDownload(function () use ($res) {
                        echo $res->getBody();
                    }, $filename, $headers);
                } catch (GuzzleHttp\Exception\RequestException $e) {
                    Log::error(
                        'User failed to access document' . $e->getMessage(),
                        ['trace' => $e->getTraceAsString()]
                    );
                    ElectraWebServices::addEvent($user->customer_id, $policyId, 'Customer failed to downloaded their ' . $filename);
                }
            }
        }
        if ($documentError === 0) {
            Log::error('Unable to find policy document ' . $documentUrl);
            ElectraWebServices::addEvent($user->customer_id, $policyId, 'Customer failed to downloaded their ' . $filename);
        }
        abort($errorCode);
    }

    public function tobaDocument(Toba $toba, $policyId){
        try {
            $user = Auth::user();
            ElectraWebServices::addEvent($user->customer_id, $policyId, 'Customer downloaded their terms-of-business.pdf','','');
            $data = $toba->url_location;
            return response()->streamDownload(
                function () use ($data) {
                    readfile($data);
                },
                'terms-of-business.pdf',
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Exception $error) {
            Log::error(
                'User failed to access document',
                [
                    'message' => $error->getMessage(),
                    'trace' => $error->getTraceAsString()
                ]
            );
            ElectraWebServices::addEvent($user->customer_id, $policyId, 'Customer failed to downloaded their terms-of-business.pdf','','');
            abort(484);
        }
    }


    /**
     * Add an event to the policy file
     *
     * @param User $user The user to add the event to
     * @param integer $policy The Id of the policy to add the event to
     * @param string $lineOne The first line of text for the event
     * @param string $lineTwo The second line of text for the event
     * @param string $lineThree The third line of text for the event
     *
     * @return bool
     */
    public function addEvent(User $user, $policy, $lineOne, $lineTwo, $lineThree)
    {
        $client = new \SoapClient(env('SSP_CUSTOMER_WSDL'), ['trace' => 1]);

        $params = new StdClass();
        $params->request = new StdClass();
        $params->request->Auth = $this->generateAuth(
            $user->customer_id, $policy
        );
        $params->request->Event = [
            'CustomerId' => $user->customer_id,
            'PolicyId' => $policy,
            'Description' => [$lineOne, $lineTwo, $lineThree]
        ];

        try {
            $response = $client->AddEvent($params);
            if ($response !== false
                && !empty($response->AddEventResult)
                && !empty($response->AddEventResult->EventAdded)
            ) {
                return true;
            } else {
                Log::error('Failed to add event: ' . $lineOne . ' for ' . $lineThree);
                return false;
            }
        } catch (\Exception $e) {
            Log::error(
                $e->getMessage() . ' - Failed to add event: ' . $lineOne . ' for ' . $lineThree,
                ['trace' => $e->getTraceAsString()]
            );
            return false;
        }
    }

    public function communication($policyId, $ordinal, $packId, $communicationId, $communicationType)
    {
        $user = Auth::user();
        $pack = Pack::findPackId($packId);
        if (!$pack) {
            Log::error(
                'User failed to access communication',
                [
                    'customer_id' => $user->customer_id,
                    'policy_id' => $policyId,
                    'ordinal' => $ordinal,
                    'pack_id' => $packId,
                    'communication_id' => $communicationId,
                    'communication_type' => $communicationType,
                    'message' => 'Unable to find pack'
                ]
            );
            abort(404);
        }
        try {
            $customerPolicies = array_filter($user->policy_ids, function($policyIds) use ($policyId) {
                return array_search($policyId, $policyIds) !== false;
            });
            if (count($customerPolicies) == 0) {
                Log::error(
                    'Failed to find customer id to access communication',
                    [
                        'customer_id' => $user->customer_id,
                        'policy_id' => $policyId,
                        'ordinal' => $ordinal,
                        'pack_id' => $packId,
                        'communication_id' => $communicationId,
                        'communication_type' => $communicationType
                    ]
                );
                abort(404);
            }

            $customerId = array_keys($customerPolicies)[0];
            $data = BarbaraWebServices::getCustomerCommunication($customerId, $ordinal, $communicationId, $communicationType);
            if (is_array($data) && $data['status'] === 1 ) {
                Log::error(
                    'User failed to access communication',
                    [
                        'customer_id' => $customerId,
                        'policy_id' => $policyId,
                        'ordinal' => $ordinal,
                        'pack_id' => $packId,
                        'communication_id' => $communicationId,
                        'communication_type' => $communicationType,
                        'message' => $data['error']
                    ]
                );
                abort(404);
            }

            ElectraWebServices::addEvent($customerId, $policyId, 'User has downloaded communication', $pack->title, '');

            return response()->streamDownload(function () use ($data) {
                echo $data;
            }, Str::slug($pack->title) . '.pdf', ['Content-Type' => 'application/pdf']);

        } catch (Exception $e) {
            Log::error(
                'User failed to access communication',
                [
                    'customer_id' => $customerId,
                    'policy_id' => $policyId,
                    'ordinal' => $ordinal,
                    'pack_id' => $packId,
                    'communication_id' => $communicationId,
                    'communication_type' => $communicationType,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );
            abort(404);
        }
    }


    public function get(Request $request)
    {
        $cipher = "aes-256-cbc";
        $key = config("app.encryption_key");
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivlen);

        $result = [
            'customer' => [],
            'policies' => [],
            'account' => []
        ];

        // Setup soap clients for the different endpoints
        $user = Auth::user();

        $customerSoap = new \SoapClient(env('SSP_CUSTOMER_WSDL'), ['trace' => 1]);

        try {
            // Request the customer details
            $params = new StdClass();
            $params->request = new StdClass();
            $params->request->Auth = $this->generateAuth(
                $user->customer_id
            );
            $params->request->Id = $user->customer_id;
            $response = $customerSoap->Load($params);
            $customer = $response->LoadResult->Customer;

            $params->request->CustomerId = $user->customer_id;
            $policy = $customerSoap->GetPolicyList($params);

            $policies = [];
            if (is_array($policy->GetPolicyListResult->Policies->PolicyItem)) {
                $policies = $policy->GetPolicyListResult->Policies->PolicyItem;
            } else {
                $policies[] = $policy->GetPolicyListResult->Policies->PolicyItem;
            }

            // Request a list of policies
            $i = 0;

            $policy_ids = [];

            $customers_to_unlink = [];

            foreach($user->secondary_customer_ids as $secondary_customer_id) {
                $params->request->Auth = $this->generateAuth(
                    $secondary_customer_id
                );
                $params->request->Id = $secondary_customer_id;
                //$response = $customerSoap->Load($params);
                //$customer = $response->LoadResult->Customer;

                $params->request->CustomerId = $secondary_customer_id;
                $policy = $customerSoap->GetPolicyList($params);
                $secondary_policies = [];
                if (is_array($policy->GetPolicyListResult->Policies->PolicyItem)) {
                    $secondary_policies = $policy->GetPolicyListResult->Policies->PolicyItem;
                } else {
                    $secondary_policies[] = $policy->GetPolicyListResult->Policies->PolicyItem;
                }
                $policies = array_merge($policies, $secondary_policies);
            }

            // Get the cache of processing policies
            $processingPolicies = collect($user->processing_policies);
            foreach ($policies as $pol) {
                if ($pol->SubType->Code == 'MBV' || $pol->SubType->Code == 'MBI') {
                    if (array_key_exists($pol->CustomerId, $policy_ids)) {
                        $policy_ids[$pol->CustomerId][] = $pol->Id;
                    } else {
                        $policy_ids[$pol->CustomerId] = [$pol->Id];
                    }
                }
                $i++;
                $communications = [];
                try {
                    $communications = BarbaraWebServices::getAllCommunicationsForCustomerPolicy($pol->CustomerId, $pol->Ordinal, $pol->Id);
                    if ($communications['status'] === 1) {
                        // data returned
                        Log::error(
                            'Failed to access communication list',
                            [
                                'message' => $communications['error'],
                                'customer_id' => $pol->CustomerId,
                                'policy_id' => $pol->Id,
                                'ordinal' => $pol->Ordinal,
                            ]
                        );
                        $communications['communications'] = [];
                    }
                    $communications = $communications['communications'];
                    if (!empty($communications)) {
                        array_walk($communications, function (&$communication) {
                            $communication['source'] = 'barbara';
                        });
                    }
                } catch (\Exception $e) {
                    // failed to get a response
                    Log::error(
                        'Failed to retrieve communication list',
                        [
                            'customer_id' => $pol->CustomerId,
                            'policy_id' => $pol->Id,
                            'ordinal' => $pol->Ordinal,
                            'exception_message' => $e->getMessage(),
                            'trace' => $e
                        ]
                    );
                }
                $coverDate = $pol->Period->CoverDate;
                $document = Toba::where('from_date', '<=', $coverDate)->orderByDesc('from_date')->first();

                array_push($communications,[
                    'communication_id' => $document['id'],
                    'url' => $document['url_location'],
                    'source' => 'bdcp',
                    'title' => 'Terms of Business',
                    'date' => $document['from_date'],
                    'type' => 'PDF',
                    'sent_at' => Carbon::createFromTimeString($coverDate)->format('d/m/Y H:i'),
                ]);

                $communications = array_reverse(array_values(Arr::sort($communications, function ($communication) {
                    return Carbon::createFromFormat('d/m/Y H:i', $communication['sent_at']);
                })));

                //MTA status

                $canMTA = false;
                $minimumMTADate = null;
                $maximumMTADate = null;

                $contractCanMTA = (new ElectraApiService)->getRiskSummary($pol->Id);
                if (isset($contractCanMTA->data->mta->result)) {
                    $canMTA = $contractCanMTA->data->mta->result;
                    $minimumMTADate = isset($contractCanMTA->data->mta->minimum_date->date) ? $contractCanMTA->data->mta->minimum_date->date : null;
                    $maximumMTADate = isset($contractCanMTA->data->mta->maximum_date->date) ? $contractCanMTA->data->mta->maximum_date->date : null;
                    if (!is_null($maximumMTADate) && Carbon::parse($maximumMTADate) < Carbon::now()) {
                        $canMTA = false;
                    }
                }

                $polDetails = (new ElectraApiService)->policySearch(['id' => $pol->Id]);
                if ($polDetails->count > 0) {
                    $polDetails = $polDetails->data[0];
                    $polDetails = [
                        'cover' => $polDetails->cover,
                        'detailed_members' => $polDetails->detailed_members,
                        'members' => $polDetails->members,
                        'vehicles' => $polDetails->vehicles,
                        'detailed_vehicles' => $polDetails->detailed_vehicles,
                        'can_mta' => $canMTA,
                        'minimum_mta_date' => $minimumMTADate,
                        'maximum_mta_date' => $maximumMTADate
                    ];
                } else {
                    $polDetails = null;
                }

                $expired = Carbon::parse($pol->Period->ExpiryDate) < Carbon::now();

                if ($pol->SubType->Code == 'MBV' || $pol->SubType->Code == 'MBI') {

                    $pol_customer = ElectraWebServices::getCustomer($pol->CustomerId);
                    $polCustomerPostcode = strtolower(str_replace(" ","",$pol_customer["Address"]["Postcode"]));
                    $customerPostcode = strtolower(str_replace(" ","",$customer->Address->Postcode));

                    if(!(strtolower($pol_customer["Email"]) == strtolower($customer->Email) &&
                        strtolower($pol_customer['Name']['Surname']) == strtolower($customer->Name->Surname) &&
                            $polCustomerPostcode == $customerPostcode)) {
                        if(!in_array($pol->CustomerId, $customers_to_unlink)) {
                            $customers_to_unlink[] = $pol->CustomerId;
                        }
                    }

                    // data to be encoded
                    $details = [
                        'policyNumber' => $pol->InsurerPolicyReference,
                        'surname' => $customer->Name->Surname,
                        'postcode' => $pol_customer["Address"]["Postcode"]
                    ];

                    // encode into json string

                    $encodedDetails = json_encode($details);

                    // open_ssl encode

                    $encodedDetails = openssl_encrypt($encodedDetails, $cipher, $key, OPENSSL_RAW_DATA, $iv);

                    $encodedDetails = base64_encode($encodedDetails) . "::" . base64_encode($iv);

                    $encodedDetails = rawurlencode($encodedDetails);

                    $policyNumber = $pol->InsurerPolicyReference;

                    $processingPolicyIndex = $processingPolicies
                        ->search(function($value) use ($policyNumber) {
                            if (
                                (isset($value['prevPoliciyNum']) && $value['prevPoliciyNum'] == $policyNumber) ||
                                (isset($value['polNum']) && $value['polNum'] == $policyNumber)) {
                                return true;
                            }
                            return false;
                    });

                    $statusCode = $pol->Status->Code;
                    $statusDescription = $pol->Status->Description;
                    $coverDate = date(DATE_ISO8601, strtotime($pol->Period->CoverDate));
                    $expiryDate = date(DATE_ISO8601, strtotime($pol->Period->ExpiryDate));
                    $renewalDate = date(DATE_ISO8601, strtotime($pol->Period->RenewalDate));
                    // Details for policy that is processing
                    if ($processingPolicyIndex !== false) {
                        $processingPolicy = $processingPolicies->get($processingPolicyIndex);
                        // Policy is new
                        // The processing cache tell us that the state it was to be put in is new
                        // Remove from cache
                        if ($statusCode == 1 && $processingPolicy['renewal'] == 0 && $processingPolicy['purchased'] == 1) {
                            $processingPolicies->forget($processingPolicyIndex);
                        }
                        // Policy is Lapsed rebroked
                        // The processing cache tell us that the state it was to be put in is lapsed rebroked
                        // Remove from cache
                        elseif ($statusCode == 9 &&
                            ( ($processingPolicy['prevCoverRange'] == 3 && $processingPolicy['coverRange'] != 3) ||
                            ($processingPolicy['prevCoverRange'] != 3 && $processingPolicy['coverRange'] == 3) ) ) {
                            $processingPolicies->forget($processingPolicyIndex);
                        }
                        // Policy is renewed
                        // The processing cache tell us that the state it was to be put in is renewed
                        // Remove from cache
                        elseif ($statusCode == 2 && $processingPolicy['renewal'] == 1 && $processingPolicy['purchased'] == 1) {
                            $processingPolicies->forget($processingPolicyIndex);
                        }
                        // Policy is cancelled
                        // The processing cache tell us that the state it was to be put in is cancelled
                        // Remove from cache
                        elseif ($statusCode == 5 & $processingPolicy['renewal'] == 1 && $processingPolicy['purchased'] == 0) {
                            $processingPolicies->forget($processingPolicyIndex);
                        }
                        else {
                            $statusCode = '-2';
                            $statusDescription = "Processing";
                            $coverDate = date(DATE_ISO8601, strtotime( '+1 year', strtotime($coverDate)));
                            $expiryDate = date(DATE_ISO8601, strtotime( '+1 year', strtotime($expiryDate)));
                            $renewalDate= date(DATE_ISO8601, strtotime( '+1 year', strtotime($renewalDate)));
                        }
                    }

                    $result['policies'][$i] = [
                        'CustomerId' => $pol->CustomerId,
                        'CurrentBalance' => $pol->CurrentBalance,
                        'policyId' => $pol->Id,
                        'ordinal' => $pol->Ordinal,
                        'Insurer' => [
                            'Code' => $pol->Insurer->Code,
                            'Description' => $pol->Insurer->Description,
                            'ShortDescription' => $pol->Insurer->ShortDescription,
                            'InsurerPolicyReference' => $policyNumber,
                        ],
                        'Period' => [
                            'CoverDate' => $coverDate,
                            'ExpiryDate' => $expiryDate,
                            'InceptionDate' => date(
                                DATE_ISO8601,
                                strtotime($pol->Period->InceptionDate)),
                            'PeriodInMonths' => $pol->Period->PeriodInMonths,
                            'RenewalDate' => $renewalDate,
                            'Expired' => $expired
                        ],
                        'Status' => [
                            'Code' => $statusCode,
                            'Description' => $statusDescription
                        ],
                        'SubType' => [
                            'Code' => $pol->SubType->Code,
                            'Description' => $pol->SubType->Description
                        ],
                        'communications' => $communications,
                        'Details' => $polDetails,
                        'encodedDetails' => $encodedDetails
                    ];
                }
                else {
                    if(!$expired && !in_array($pol->CustomerId, $customers_to_unlink)) {
                        $customers_to_unlink[] = $pol->CustomerId;
                    }
                }
            }

            $saveUser = Auth::guard('api')->user();
            $saveUser->processing_policies = $processingPolicies;
            $saveUser->policy_ids = $policy_ids;


            if(!empty($customers_to_unlink) && (isset($request->runPolSync) ? $request->runPolSync : 'false') == "true") {
                foreach($result["policies"] as $key => $pol){
                    if(in_array($pol["CustomerId"], $customers_to_unlink)){
                        unset($result['policies'][$key]);
                    }
                }

                $result['policies'] = array_values($result['policies']);
                array_unshift($result['policies'],"");
                unset($result['policies'][0]);

                $new_policy_ids = array_diff_key($saveUser->policy_ids, array_flip($customers_to_unlink));
                $saveUser->policy_ids = $new_policy_ids;

                $new_secondary_customer_ids = array_diff($saveUser->secondary_customer_ids, $customers_to_unlink);

                if(!empty($new_secondary_customer_ids)) {

                    if (in_array($saveUser->customer_id, $customers_to_unlink)) {

                        $saveUser->customer_id = array_pop($new_secondary_customer_ids);

                        $params = new StdClass();
                        $params->request = new StdClass();
                        $params->request->Auth = $this->generateAuth(
                            $saveUser->customer_id
                        );
                        $params->request->Id = $saveUser->customer_id;
                        $response = $customerSoap->Load($params);
                        $customer = $response->LoadResult->Customer;
                    }
                }

                $saveUser->secondary_customer_ids = $new_secondary_customer_ids;

            }

            $has_valid_policies = !empty($result['policies']);

            $saveUser->save();

            $multipleMtaDelayTime = config('app.multiple_mta_delay_time');
            $mtaDelayDatetime = Carbon::now('UTC')->sub($multipleMtaDelayTime);

            $mtaCompletedWithinWindow = Quote::query()
                ->whereIn('customer_id', array_merge($user->secondary_customer_ids, [$customer->Id]))
                ->where('completed', '=', 1)
                ->where('completed_at', '>', $mtaDelayDatetime)
                ->first();

            $result['account'] = [
                'userEmail' => $user->email,
                'userId' => $user->id,
                'validAccount' => $has_valid_policies,
                'accountCanMta' => !$mtaCompletedWithinWindow,
                'multipleMtaDelayTime' => $multipleMtaDelayTime
            ];

            $result['customer'] = [
                'customer_id' => $customer->Id,
                'title' => $customer->Name->Title->Description,
                'forename' => $customer->Name->Forename,
                'surname' => $customer->Name->Surname,
                'email' => $customer->Email,
                'home_telephone' => isset($customer->HomeTelephone) ? $customer->HomeTelephone : null,
                'mobile_telephone' => isset($customer->MobileTelephone) ? $customer->MobileTelephone : null,
                'work_telephone' => isset($customer->WorkTelephone) ? $customer->WorkTelephone : null,
                'marketing' => [
                    'can_email' => isset($customer->ContactPreferences->Email) ? $customer->ContactPreferences->Email : false,
                    'can_post' => isset($customer->ContactPreferences->Post) ? $customer->ContactPreferences->Post : false,
                    'can_sms' => isset($customer->ContactPreferences->Sms) ? $customer->ContactPreferences->Sms : false,
                    'can_phone' => isset($customer->ContactPreferences->Telephone) ? $customer->ContactPreferences->Telephone : false,
                ],
                'address' => $customer->Address
            ];

        } catch (\Exception $e) {
            $this->addError($e->getMessage());
            Log::error(
                $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );
        }

        $this->setResult($result);

        return $this->getResponse();
    }

    /**
     * @param $url
     * @return string
     */
    public function encryptUrl($url)
    {
        return base64_encode(openssl_encrypt($url, env('URL_CRYPT_CIPHER'), env('URL_CRYPT_KEY'), 0, env('URL_CRYPT_IV')));
    }

    /**
     * @param $url
     * @return string
     */
    public function decryptUrl($url)
    {
        return openssl_decrypt(base64_decode($url), env('URL_CRYPT_CIPHER'), env('URL_CRYPT_KEY'), 0, env('URL_CRYPT_IV'));
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

        } catch (\Exception $e) {
            Log::error(
                $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            return false;
        }
    }

    public function updateAccountInfo(Request $request)
    {
        $user = Auth::guard('api')->user();

        $validator = Validator::make(
            $request->all(),
            [
                'currentPassword' => [
                    function ($attribute, $value, $fail) use ($user) {
                        if (!$user->logged_in_by_token && empty($value)) {
                            $fail("{$attribute} is required");
                        }
                    },
                    function ($attribute, $value, $fail) use ($user) {
                        if (!$user->logged_in_by_token && !Hash::check($value, $user->password)) {
                            $fail('Invalid Password');
                        }
                    },
                ],
                'password' => [
                    'required',
                    'string',
                    'regex:/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9])\S{8,}$/',
                    'confirmed'
                ],
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        try {
            $user->password = Hash::make($request->password);
            $user->logged_in_by_token = false;
            $user->save();
            $this->setResult('true');
            ElectraWebServices::addCustomerEvent($user->customer_id, 'BDCP - User Changed Account Login Password', '', '');

        } catch (\Exception $e) {
            Log::error(
                $e->getMessage(),
                ['trace' => $e->getTraceAsString(), 'user' => is_null($user) ? null : $user->id]
            );
        }

        return $this->getResponse();
    }

    public function updateContactAndMarketingDetails(Request $request)
    {
        $user = Auth::user();
        // Create a validator
        $validator = Validator::make(
            $request->all(),
            [
                'home_telephone' => array(
                    'required_without_all:mobile_telephone,work_telephone',
                    'string',
                    'nullable',
                    'regex:/^[0-9 ]*$/'
                ),
                'mobile_telephone' => array(
                    'required_without_all:home_telephone,work_telephone',
                    'string',
                    'nullable',
                    'regex:/^[0-9 ]*$/'
                ),

                'email' => array(
                    'required',
                    'email',
                    Rule::unique('users')->ignore($user->customer_id, 'customer_id'),
                ),
            ],
            [
                'email.unique' => 'This email is already registered.',
                'home_telephone.regex' => 'Telephone can only contain numbers and spaces.',
                'mobile_telephone.regex' => 'Telephone can only contain numbers and spaces.'
            ]
        );


        $passValidation = false;
        $updateDatabaseEmail = true;
        //Turning off the force email update feature, will need removing if active in the future
        $request->force_email_update = false;
        // Check if the input passes validation
        if ($validator->fails()) {

        } else {
            $passValidation = true;
        }

        if ($passValidation) {
            //Validation passed so save the information

            $customerId = $user->customer_id;
            $soap = new \SoapClient(env('SSP_CUSTOMER_WSDL'), ['trace' => 1]);

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

            $params->request->Customer->Email = $request->email;

            $params->request->Customer->HomeTelephone = $request->home_telephone;
            $params->request->Customer->MobileTelephone = $request->mobile_telephone;

            //Try send the soap
            try {
                $response = $soap->Update($params);
                if ($response !== false
                    && !empty($response->UpdateResult)
                    && !empty($response->UpdateResult->Updated)
                ) {
                    //Success! Now update the databases email
                    if ($updateDatabaseEmail) {
                        $user = User::where('customer_id', $customerId)->first();
                        $user->email = $request->email;
                        $user->save();
                    }

                    $this->addSuccess();
                    ElectraWebServices::addCustomerEvent($user->customer_id, 'BDCP - User Changed Policy Contact Details', '', '');
                    $this->setResult(true);
                } else {
                    $this->addError('Failed to update customer details');
                    Log::error('Failed to update customer details',
                        ['customer_id' => $customerId]);
                }
            } catch (\Exception $e) {
                $this->addError($e->getMessage());
                ElectraWebServices::addCustomerEvent($user->customer_id, 'BDCP - User tried, but failed to update contact details', '', '');
                Log::error($e->getMessage() . ' - Failed to update customer details',
                    ['trace' => $e->getTraceAsString()]
                );
            }
        }

        return $this->getResponse();
    }

    public function updateAddress(Request $request, ElectraApiService $electraApiService)
    {
        $user = Auth::user();
        // Create a validator
        $validator = Validator::make(
            $request->all(),
            [
                'address_line_1' => 'required|string',
                'address_line_2' => 'required|string',
                'address_line_3' => 'string|nullable',
                'address_postcode' => 'required|string'
            ]
        );


        $passValidation = false;
        //Turning off the force email update feature, will need removing if active in the future
        $request->force_email_update = false;
        // Check if the input passes validation
        if ($validator->fails()) {

        } else {
            $passValidation = true;
        }

        if ($passValidation) {
            $electraApiService->updateAddress(
                $user->customer_id,
                $request->get('address_line_1'),
                $request->get('address_line_2'),
                $request->get('address_line_3'),
                $request->get('address_postcode')
            );
        }

        return $this->getResponse();
    }

    public function addNewTransaction(Request $request)
    {
        // Create a validator
        $validator = Validator::make(
            $request->all(),
            [
                'policyId' => 'required',
                'postCode' => 'required',
                'policyNumber' => 'required',
                'amountTaken' => 'required',
                'vpsTxId' => 'required',
                'vendorTxCode' => 'required',
                'securityKey' => 'required',
                'txAuthNo' => 'required',
                'cardholderName' => 'required',
                'cardNumber' => 'required',
                'cardExpiry' => 'required',
                'cardType' => 'required'
            ]
        );

        // Check if the input passes validation
        if ($validator->fails()) {
            $errors = $validator->errors();
            foreach ($errors->getMessages() as $key => $message) {
                $this->addValidationError($key, $message[0]);
            }
        } else {
            $user = Auth::user();
            $this->setResult(
                BarbaraWebServices::updateCustomerCardExpiry(
                    $user->customer_id,
                    $request->policyId,
                    $request->postCode,
                    $request->policyNumber,
                    $request->amountTaken,
                    $request->vpsTxId,
                    $request->vendorTxCode,
                    $request->securityKey,
                    $request->txAuthNo,
                    $request->cardholderName,
                    $request->cardNumber,
                    $request->cardExpiry,
                    $request->cardType
                )
            );
        }

        return $this->getResponse();
    }


    public function getCustomerPaymentCards()
    {
        $user = Auth::user();
        return BarbaraWebServices::getCustomerPaymentCards($user->customer_id);
    }

    public function refundCustomerPayment(Request $request)
    {
        // Create a validator
        $validator = Validator::make(
            $request->all(),
            [
                'policyId' => 'required',
                'transactionId' => 'required',
                'amount' => 'required'
            ]
        );

        // Check if the input passes validation
        if ($validator->fails()) {
            $errors = $validator->errors();
            foreach ($errors->getMessages() as $key => $message) {
                $this->addValidationError($key, $message[0]);
            }
        } else {
            $user = Auth::user();
            $this->setResult(BarbaraWebServices::refundPaymentForTransaction($user->customer_id, $request->policyId, $request->transactionId, $request->amount));
        }

        return $this->getResponse();
    }

    public function updateCustomerCardExpiry(Request $request)
    {
        // Create a validator
        $validator = Validator::make(
            $request->all(),
            [
                'policyId' => 'required',
                'transactionId' => 'required',
                'updatedCardExpiryYear' => 'required',
                'updatedCardExpiryMonth' => 'required'
            ]
        );

        // Check if the input passes validation
        if ($validator->fails()) {
            $errors = $validator->errors();
            foreach ($errors->getMessages() as $key => $message) {
                $this->addValidationError($key, $message[0]);
            }
        } else {
            $user = Auth::user();
            $this->setResult(BarbaraWebServices::updateCustomerCardExpiry($user->customer_id, $request->policyId, $request->transactionId, $request->updatedCardExpiryYear, $request->updatedCardExpiryMonth));
        }

        return $this->getResponse();
    }


    public function getMTAQuote(Request $request, ElectraApiService $electraApiService, GetQuoteApiService $getQuoteApiService, StringIdConverter $stringIdConverter)
    {
        // Create a validator
        $validator = Validator::make(
            $request->all(),
            [
                'contractId' => 'required|integer',
                'policyId' => 'required|integer',
                'coverDate' => 'required|date',
                'expiryDate' => 'required|date|after_or_equal:today',
                'inceptionDate' => 'required|date',
                'risk' => 'required',
                'risk.coverType' => 'required|in:VEHICLE,PERSONAL',
                'risk.coverRange' => 'required|in:LOCAL,NATIONAL,EUROPEAN,RMC COMPREHENSIVE',
                'risk.homeStart' => 'required|integer|in:0,1|nullable',
                'risk.excess' => 'required',
                'risk.effectiveDate' => 'required|date|after_or_equal:today',
                'risk.vehicles' => 'required|array',
                'risk.vehicles.*.id' => 'required|distinct|string',
                'risk.vehicles.*.type' => 'required|in:Car,Bike,Van',
                'risk.vehicles.*.use' => 'integer|in:1,2,3,4',
                'risk.vehicles.*.reg' => 'required|string|regex:"^[a-zA-Z0-9 ]*$"',
                'risk.vehicles.*.make' => 'required|string',
                'risk.vehicles.*.model' => 'required|string',
                'risk.vehicles.*.manufacture_year' => 'required_without_all:risk.vehicles.*.regDate, risk.vehicles.*.age|integer|digits:4',
                'risk.vehicles.*.manufacture_month' => 'integer|between:1,12',
                'risk.vehicles.*.age' => 'required_without_all:risk.vehicles.*.regDate,risk.vehicles.*.manufacture_year|integer',
                'risk.vehicles.*.regDate' => 'required_without_all:risk.vehicles.*.age,risk.vehicles.*.manufacture_year|date|before:today',
                'risk.vehicles.*.weight' => 'integer',
                'risk.vehicles.*.length' => 'integer',
                'risk.vehicles.*.width' => 'integer',
                'risk.vehicles.*.height' => 'integer',
                'risk.drivers' => 'required_if:risk.coverType,2|array',
                'risk.drivers.*.id' => 'required|distinct|string',
                'risk.drivers.*.dateOfBirth' => 'date'
            ]
        );

        $details = $request->all();

        array_walk($details['risk']['vehicles'], function (&$vehicle) {
            $vehicle['make_model'] = $vehicle['make'] . ', ' . $vehicle['model'];
        });

        // Check if the input passes validation
        if ($validator->fails()) {
            $errors = $validator->errors();
            foreach ($errors->getMessages() as $key => $message) {
                $this->addValidationError($key, $message[0]);
            }

            return $this->getResponse();
        }
        else {
            $allPolicyRisks = $electraApiService->getRiskSummary($request['contractId']);
            $latestHistory = $allPolicyRisks->data->risk_history[0];

            foreach($allPolicyRisks->data->risk_history as $key => $historyItem){
                if($historyItem->effective_date > $latestHistory->effective_date){
                    $latestHistory = $historyItem;
                }
            }

            $coverDate = new \DateTime($allPolicyRisks->data->cover_date, new \DateTimeZone('Europe/London'));
            $bqbThemeId = Carbon::parse($allPolicyRisks->data->cover_date)->greaterThanOrEqualTo('2017-03-17') ? 1 : 6;
            $inceptionDate = new \DateTime($allPolicyRisks->data->inception_date->date, new \DateTimeZone('Europe/London'));
            $renewalDate = new \DateTime($allPolicyRisks->data->renewal_date->date, new \DateTimeZone('Europe/London'));
            $interval = $coverDate->diff($renewalDate);
            $tenure = ((int)floor($renewalDate->setTime(0,0)->diff($inceptionDate->setTime(0,0))->days / 365) - 1) . ' years';

            $coverLength = ($interval->days + 1) .  " days";
            if(($interval->days) + 1 >= 365){
                $coverLength = "1 Year";
            }

            $replacePersonalCoverVehicleFeatureFlag = filter_var(config('app.replace_personal_cover_vehicle_feature_flag'), FILTER_VALIDATE_BOOLEAN);
            $orderedCoverPeriodRisks = [];
            $vehicleAddedDates = [];

            if ($allPolicyRisks) {
                $insurer = $allPolicyRisks->data->insurer;
                $currentRiskVehicles = [];
                $riskDetails = $electraApiService->getRisk($request['contractId'], $latestHistory->id);
                $claimsCount = $riskDetails->data->claims_count ?? 0;

                $previousRiskDetails = [];
                $previousRiskDetails['postcode'] = $riskDetails->data->postcode;
                $previousRiskDetails['coverType'] = $stringIdConverter->stringToId('cover_type', $riskDetails->data->cover->type);
                $previousRiskDetails['coverRange'] = $stringIdConverter->stringToId('cover_range', $riskDetails->data->cover->range);
                $previousRiskDetails['homeStart'] = $riskDetails->data->cover->home;
                $previousRiskDetails['excess'] = intval($riskDetails->data->cover->excess);
                $previousRiskDetails['startDate'] = $coverDate->format('Y-m-d H:i:s');
                $previousRiskDetails['renewalCount'] = $riskDetails->data->renewal_count;
                $previousRiskDetails['premium'] = $riskDetails->data->premium;
                $previousRiskDetails['purchased_at'] = $riskDetails->data->purchased_at->date ?? NULL;
                $previousRiskDetails['cover_date'] = $coverDate->format('Y-m-d');
                $previousRiskDetails['coverLength'] = $coverLength;
                $previousRiskDetails['bqbThemeId'] = $bqbThemeId;
                $previousRiskDetails['tenure'] = $tenure;
                $previousRiskDetails['claims'] = $claimsCount;

                foreach ($allPolicyRisks->data->risk_history as $riskHistoryItem) {
                    try {
                        $riskHistoryDetails = $electraApiService->getRisk($request['contractId'], $riskHistoryItem->id)->data;
                    }
                    catch (\Exception $e) {
                        Log::error('Unable to fetch risk', ['con_id' => $request['contractId'], 'risk_id' => $riskHistoryItem->id]);
                        $response['code'] = 1;
                        $response['messages'] = 'The given data was invalid';
                        $response['error'] = 'Unable to find valid risk';
                        return $response;
                    }

                    // risk not part of current policy term skip
                    if ($riskDetails->data->renewal_count != $riskHistoryDetails->renewal_count) continue;

                    $orderedCoverPeriodRisks[$riskHistoryDetails->effective_date->date] = $riskHistoryDetails;

                    // already processed this risk history skip
                    if (isset($vehicleAddedDates[$riskHistoryItem->id])) continue;

                    foreach ($riskHistoryDetails->vehicles as $riskVehicles) {
                        $vehicleAddedDates[$riskHistoryItem->id][$riskVehicles->reg] = $riskHistoryDetails->effective_date->date;
                    }
                }

                //Sort the array based on the date keys in descending order
                krsort($orderedCoverPeriodRisks);

                $latestFirstPersonalCoverRiskHistory = null;
                foreach ($orderedCoverPeriodRisks as $orderedCoverPeriodRisk) {
                    if (!is_null($latestFirstPersonalCoverRiskHistory)) {
                        if (strtoupper($orderedCoverPeriodRisk->cover->type) == 'PERSONAL') {
                            //$latestFirstPersonalCoverRiskHistory has been set yet and the risk is personal and earlier so replace it
                            $latestFirstPersonalCoverRiskHistory = $orderedCoverPeriodRisk;
                        } else {
                            //Risk is not personal so we have reached the latest first personal cover risk
                            break;
                        }
                    } else {
                        if (strtoupper($orderedCoverPeriodRisk->cover->type) == 'PERSONAL') {
                            //$latestFirstPersonalCoverRiskHistory hasn't been set yet and the risk is personal so set it
                            $latestFirstPersonalCoverRiskHistory = $orderedCoverPeriodRisk;
                        }
                    }
                }

                if ($replacePersonalCoverVehicleFeatureFlag && $riskDetails->data->cover->type == 'PERSONAL' && !is_null($latestFirstPersonalCoverRiskHistory)) {
                    //Previous risk is personal cover so set the vehicle
                    $vehicleDetails = $latestFirstPersonalCoverRiskHistory->vehicles[0];
                    $vehicleAddedDate = $this->getVehicleAddedDate($vehicleAddedDates, $vehicleDetails->reg);
                    $previousRiskDetails['vehicles'][] = [
                        'id' => '1',
                        'type' => $stringIdConverter->stringToId('vehicle_type', $vehicleDetails->type),
                        'reg' => $vehicleDetails->reg,
                        'make_model' => $vehicleDetails->make_model,
                        'make' => $vehicleDetails->make,
                        'model' => $vehicleDetails->model,
                        'age' => intval(date('Y', strtotime($vehicleAddedDate))) - intval($vehicleDetails->year),
                    ];
                } else {
                    foreach ($riskDetails->data->vehicles as $key => $riskVehicles) {
                        $currentRiskVehicles[] = $riskVehicles->reg;
                        //Keep a track of when the vehicles were added to the policy so their age can be calculated correctly
                        if (array_key_exists($riskVehicles->reg, $vehicleAddedDates)) {
                            $vehicleAddedDate = $vehicleAddedDates[$riskVehicles->reg];
                        } else {
                            $vehicleAddedDates[$riskVehicles->reg] = $riskDetails->data->effective_date->date;
                            $vehicleAddedDate = $riskDetails->data->effective_date->date;
                        }

                        $previousRiskVehicles['id'] = (string)($key + 1);
                        $previousRiskVehicles['type'] = $stringIdConverter->stringToId('vehicle_type', $riskVehicles->type);
                        $previousRiskVehicles['reg'] = $riskVehicles->reg;
                        $previousRiskVehicles['make_model'] = $riskVehicles->make_model;
                        $previousRiskVehicles['make'] = $riskVehicles->make;
                        $previousRiskVehicles['model'] = $riskVehicles->model;
                        $previousRiskVehicles['age'] = intval(date('Y', strtotime($vehicleAddedDate))) - intval($riskVehicles->year);

                        $previousRiskDetails['vehicles'][] = $previousRiskVehicles;
                    }
                }

                //Clean out any vehicles that have been removed
                for ($count = 0; $count < count($vehicleAddedDates); $count++) {
                    $reg = array_keys($vehicleAddedDates)[$count];
                    if (!in_array($reg, $currentRiskVehicles)) {
                        //Remove any regs which are no longer on the policy
                        unset($vehicleAddedDates[$reg]);
                    }
                }

                if (is_array($riskDetails->data->personal_members) && count($riskDetails->data->personal_members) > 0) {
                    foreach ($riskDetails->data->personal_members as $key => $riskMember) {
                        $previousRiskMember['id'] = (string)($key + 1);
                        $previousRiskMember['title'] = $riskMember->title;
                        $previousRiskMember['forename'] = $riskMember->forename;
                        $previousRiskMember['surname'] = $riskMember->surname;
                        $previousRiskDetails['drivers'][] = $previousRiskMember;
                    }
                }

                $renewalCount = $riskDetails->data->renewal_count;

                $quoteObject['previousRisk']['quote_inputs'] = $previousRiskDetails;
                $quoteObject['previousRisk']['type'] = !is_null($riskDetails->data->quote_hero_type) ? $riskDetails->data->quote_hero_type : ($renewalCount == 0 ? "NewBusiness" : "Renewal");

                $newRisk = $request->all()['risk'];
                if ($replacePersonalCoverVehicleFeatureFlag && $newRisk['coverType'] == $stringIdConverter->stringToId('cover_type', 'PERSONAL') && $riskDetails->data->cover->type == 'PERSONAL' && !is_null($latestFirstPersonalCoverRiskHistory)) {
                    //New risk is personal cover so set the vehicle
                    $vehicleDetails = $latestFirstPersonalCoverRiskHistory->vehicles[0];
                    $vehicleAddedDate = $this->getVehicleAddedDate($vehicleAddedDates, $vehicleDetails->reg);
                    $newRisk['vehicles'] = [
                        [
                            'id' => '1',
                            'type' => $stringIdConverter->stringToId('vehicle_type', $vehicleDetails->type),
                            'reg' => $vehicleDetails->reg,
                            'make_model' => $vehicleDetails->make_model,
                            'make' => $vehicleDetails->make,
                            'model' => $vehicleDetails->model,
                            'age' => intval(date('Y', strtotime($vehicleAddedDate))) - intval($vehicleDetails->year),
                        ]
                    ];
                }
                else {
                    //New risk is not personal cover so continue as normal
                    if (is_array($newRisk['vehicles']) && count($newRisk['vehicles']) > 0) {
                        foreach ($newRisk['vehicles'] as $key => $newRiskVehicle) {
                            if (in_array($newRiskVehicle['reg'], array_keys($vehicleAddedDates))) {
                                //Vehicle was previously on the policy so use the date
                                $newRisk['vehicles'][$key]['age'] = intval(date('Y', strtotime($vehicleAddedDates[$newRiskVehicle['reg']]))) - intval($newRiskVehicle['manufacture_year']);
                            } else {
                                //Vehicle not on the previous so use effective date
                                $newRisk['vehicles'][$key]['age'] = intval(date('Y', strtotime($newRisk['effectiveDate']))) - intval($newRiskVehicle['manufacture_year']);
                            }
                            $newRisk['vehicles'][$key]['type'] = $stringIdConverter->stringToId('vehicle_type', $newRiskVehicle['type']);
                        }
                    }
                }

                if(count($newRisk['drivers']) == 0){
                    unset($newRisk['drivers']);
                }

                $newRisk['coverType'] = $stringIdConverter->stringToId('cover_type', $newRisk['coverType']);
                $newRisk['coverRange'] = $stringIdConverter->stringToId('cover_range', $newRisk['coverRange']);
                $newRisk['excess'] = intval($newRisk['excess']);
                $newRisk['coverLength'] = $coverLength;
                $newRisk['startDate'] = date('Y-m-d H:i', strtotime($newRisk['effectiveDate']));
                $newRisk['bqbThemeId'] = $bqbThemeId;
                $newRisk['tenure'] = $tenure;
                $newRisk['claims'] = $claimsCount;

                $quoteObject['currentRisk']['source'] = $riskDetails->data->source->text;
                $quoteObject['currentRisk']['quoteType'] = "Breakdown";
                $quoteObject['currentRisk']['type'] = !is_null($riskDetails->data->quote_hero_type) ? $riskDetails->data->quote_hero_type : ($renewalCount == 0 ? "NewBusiness" : "Renewal");;
                $quoteObject['previousRisk']['source'] = $riskDetails->data->source->text;
                $quoteObject['previousRisk']['quoteType'] = "Breakdown";
                $quoteObject['previousRisk']['quote_hero_quote_id'] = $riskDetails->data->quote_hero_quote_id;
                $quoteObject['quoteId'] = (string)Str::uuid();
                $quoteObject['calculationSource'] = 'electra';
                $quoteObject['renewalCount'] = $renewalCount;
                $quoteObject['coverDate'] = $details['coverDate'];
                $quoteObject['expiryDate'] = $details['expiryDate'];
                $quoteObject['inceptionDate'] = $details['inceptionDate'];
                $quoteObject['currentRisk']['quote_inputs'] = $newRisk;

                try {
                    $mtaQuote = $getQuoteApiService->mtaQuote($quoteObject);
                } catch (\Exception $e) {
                    Log::info('Failed to get price from Quote Hero', ['error' => $e->getMessage()]);
                }

                if (!is_null($mtaQuote->data->total)) {
                    $data = $mtaQuote->data;
                    $id = $mtaQuote->data->id;
                    $response['code'] = 0;
                    $data->charges = [];
                    foreach($data->fee_campaigns as $campaign){
                        $data->charges[] = [
                            'code' => $campaign->code,
                            'description' => $campaign->description,
                            'value' => $campaign->quote_campaign_amount
                        ];
                    }
                } else {
                    $data = null;
                    $id = -1;
                    $response['code'] = 1;
                    $response['messages'] = $mtaQuote->messages ?? 'Failed to get a price';
                    $response['error'] = $mtaQuote->error ?? 'Unknown error';
                }

                $user = Auth::guard('api')->user();

                $transactionId = null;
                if (!is_null($data) && $data->total < 0) {
                    //Refund so check we can find a transaction to refund against
                    $paymentHubService = new PaymentHubService();
                    $contractCustomerId = $user->getCustomerIdForContractId($request['contractId']);
                    try {
                        if (!is_null($contractCustomerId)) {
                            //We have the required details to continue
                            $transactionSearch = $paymentHubService->transactionSearch([
                                'data' => [
                                    'customer_id' => strval($contractCustomerId),
                                    'contract_id'=> strval($request['contractId'])
                                ],
                                'show_attempted' => false
                            ]);

                            $transactionText = [
                                'breakdown new business',
                                'payment',
                                'repeat payment',
                                'renewal',
                                'breakdown mta',
                                'additional payment'
                            ];

                            if (isset($transactionSearch->data) && is_array($transactionSearch->data) && count($transactionSearch->data) > 0) {
                                //We have transactions for the policy so search through and set it
                                $orderedTransactions = array_reverse($transactionSearch->data);
                                foreach ($orderedTransactions as $transactionData) {
                                    if (in_array(strtolower($transactionData->description), $transactionText) && floatval($transactionData->amount_taken) != floatval($transactionData->amount_refunded)) {
                                        //This is the transaction we want and it has money available to be refunded so check if we have enough to refund
                                        if (bcsub(floatval($transactionData->amount_taken), floatval($transactionData->amount_refunded), 10) > abs($data->total)) {
                                            $transactionId = $transactionData->id;
                                            $data->RPCardNumber = substr($transactionData->token->card_number, -4);
                                            break;
                                        }
                                    }
                                }
                            }

                            if (is_null($transactionId)) {
                                //We've not been able to find a transaction to return against and the MTA has a return premium
                                $response['code'] = 1;
                                $response['messages'] = 'Unable to process MTA online';
                                $response['error'] = 'Refund processing error';
                            }
                        }
                    } catch (\Exception $e) {
                        // failed to get a response
                        Log::error(
                            'Failed to retrieve policy transaction',
                            [
                                'customer_id' => $contractCustomerId,
                                'policy_id' => $request['contractId'],
                                'exception_message' => $e->getMessage(),
                                'trace' => $e
                            ]
                        );
                    }
                }

                //Create the quote record in the BDCP database
                $newQuote = new Quote();
                $newQuote->user_id = $user->id;
                $newQuote->customer_id = $user->customer_id;
                $newQuote->policy_id = $request['contractId'];
                $newQuote->type = 'MTA';
                $newQuote->details = json_encode(['request' => $details, 'result' => $data, 'insurer' => $insurer]);
                $newQuote->external_quote_id = $id;
                $newQuote->return_premium_transaction_id = $transactionId;
                if (!is_null($transactionId)) {
                    $newQuote->refunded = 0;
                }
                $newQuote->completed = 0;

                $newQuote->save();

                if ($response['code'] == 0) {
                    //Only format the data if a successful response is being returned
                    if ($data) {
                        $data->quote_id = $newQuote->id;
                        $data->totPremDue = $data->total;
                    }

                    $allowed = ['id', 'quote_id', 'totPremDue', 'gross', 'charges', 'RPCardNumber'];

                    $response['data'] = array_filter((array) $data, function($key) use($allowed){
                        return in_array($key,$allowed);
                    }, ARRAY_FILTER_USE_KEY);
                }

                return $response;

            }
            else {
                //Log an error
                Log::error('Failed');
            }

        }
    }

    /**
     * @param Request $request
     * @param ElectraApiService $electraApiService
     * @param GetQuoteApiService $getQuoteApiService
     * @param StringIdConverter $stringIdConverter
     * @throws ValidationException
     */
    public function getMTAQuoteElectraAPI(
        Request $request,
        ElectraApiService $electraApiService,
        GetQuoteApiService $getQuoteApiService,
        StringIdConverter $stringIdConverter
    )
    {
        // Create a validator
        $validator = Validator::make(
            $request->all(),
            [
                'contractId' => 'required|integer',
                'risk' => 'required',
                'risk.coverType' => 'required|integer|in:1,2',
                'risk.coverRange' => 'required|integer|in:1,2,3,4',
                'risk.homeStart' => 'required|integer|in:0,1|nullable',
                'risk.excess' => 'required',
                'risk.effectiveDate' => 'required|date|after_or_equal:today',
                'risk.vehicles' => 'required|array',
                'risk.vehicles.*.id' => 'required|distinct|string',
                'risk.vehicles.*.type' => 'required|in:1,2,3',
                'risk.vehicles.*.use' => 'integer|in:1,2,3,4',
                'risk.vehicles.*.reg' => 'required|string|regex:"^[a-zA-Z0-9 ]*$"',
                'risk.vehicles.*.make' => 'required|string',
                'risk.vehicles.*.model' => 'required|string',
                'risk.vehicles.*.manufacture_year' => 'required_without_all:risk.vehicles.*.regDate, risk.vehicles.*.age|integer|digits:4',
                'risk.vehicles.*.manufacture_month' => 'integer|between:1,12',
                'risk.vehicles.*.age' => 'required_without_all:risk.vehicles.*.regDate,risk.vehicles.*.manufacture_year|integer',
                'risk.vehicles.*.regDate' => 'required_without_all:risk.vehicles.*.age,risk.vehicles.*.manufacture_year|date|before:today',
                'risk.vehicles.*.weight' => 'integer',
                'risk.vehicles.*.length' => 'integer',
                'risk.vehicles.*.width' => 'integer',
                'risk.vehicles.*.height' => 'integer',
                'risk.drivers' => 'required_if:risk.coverType,2|array',
                'risk.drivers.*.id' => 'required|distinct|string',
                'risk.drivers.*.dateOfBirth' => 'date'
            ]
        );

        // Check if the input passes validation
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        try {
            $allPolicyRisks = $electraApiService->getRiskSummary($request['contractId'])->data;
        } catch (\Exception $e) {
            $data = [
                'message' => 'The given data was invalid.',
                'errors' => ['con_id' => 'Unable to fetch risk summary']

            ];
            Log::error('Failed to find risk summary', ['con_id' => $request['contractId']]);
            return new JsonResponse($data, 422);
        }

        if (!$allPolicyRisks) {
            $data = [
                'message' => 'The given data was invalid.',
                'errors' => ['con_id' => 'No risks returned']

            ];
            Log::error('No risks returned', ['con_id' => $request['contractId']]);
            return new JsonResponse($data, 422);
        }


        // get the most recent active risk history
        $latestHistory = $allPolicyRisks->risk_history[0];
        foreach ($allPolicyRisks->risk_history as $historyItem) {
            if ($historyItem->effective_date > $latestHistory->effective_date) {
                $latestHistory = $historyItem;
            }
        }

        try {
            $latestActiveRisk = $electraApiService->getRisk($request['contractId'], $latestHistory->id)->data;
        }
        catch (\Exception $exception) {
            $data = [
                'message' => 'The given data was invalid.',
                'errors' => ['con_id' => 'Can\'t get current risk']

            ];
            Log::error('Can\'t get current risk', ['con_id' => $request['contractId'], 'rsh_id' => $latestHistory->id]);
            return new JsonResponse($data, 422);
        }

        $replacePersonalCoverVehicleFeatureFlag = filter_var(config('app.replace_personal_cover_vehicle_feature_flag'), FILTER_VALIDATE_BOOLEAN);
        $orderedCoverPeriodRisks = [];
        $vehicleAddedDates = [];
        // need to know when the vehicles of the latestActive risk was added to policy so the age can be calculated
        // correctly when having to recreate the previous risk to quote against if we don't have the original
        // quote hero id we only care about dates for the most recent policy/renewal
        foreach ($allPolicyRisks->risk_history as $riskHistoryItem) {
            try {
                $riskDetails = $electraApiService->getRisk($request['contractId'], $riskHistoryItem->id)->data;
            }
            catch (\Exception $e) {
                $data = [
                    'message' => 'The given data was invalid.',
                    'errors' => ['rsh_id' => 'Unable to find valid risk']

                ];
                Log::error('Unable to fetch risk', ['con_id' => $request['contractId'], 'risk_id' => $riskHistoryItem->id]);
                return new JsonResponse($data, 422);
            }

            // risk not part of current policy term skip
            if ($latestActiveRisk->renewal_count != $riskDetails->renewal_count) continue;

            $orderedCoverPeriodRisks[$riskDetails->effective_date->date] = $riskDetails;

            // already processed this risk history skip
            if (isset($vehicleAddedDates[$riskHistoryItem->id])) continue;

            foreach ($riskDetails->vehicles as $riskVehicles) {
                $vehicleAddedDates[$riskHistoryItem->id][$riskVehicles->reg] = $riskDetails->effective_date->date;
            }
        }

        //Sort the array based on the date keys in descending order
        krsort($orderedCoverPeriodRisks);

        $latestFirstPersonalCoverRiskHistory = null;
        foreach ($orderedCoverPeriodRisks as $orderedCoverPeriodRisk) {
            if (!is_null($latestFirstPersonalCoverRiskHistory)) {
                if (strtoupper($orderedCoverPeriodRisk->cover->type) == 'PERSONAL') {
                    //$latestFirstPersonalCoverRiskHistory has been set yet and the risk is personal and earlier so replace it
                    $latestFirstPersonalCoverRiskHistory = $orderedCoverPeriodRisk;
                } else {
                    //Risk is not personal so we have reached the latest first personal cover risk
                    break;
                }
            } else {
                if (strtoupper($orderedCoverPeriodRisk->cover->type) == 'PERSONAL') {
                    //$latestFirstPersonalCoverRiskHistory hasn't been set yet and the risk is personal so set it
                    $latestFirstPersonalCoverRiskHistory = $orderedCoverPeriodRisk;
                }
            }
        }


        $coverDate = new \DateTime($allPolicyRisks->cover_date, new \DateTimeZone('Europe/London'));
        $inceptionDate = (new \DateTime($allPolicyRisks->inception_date->date, new \DateTimeZone('Europe/London')));
        $renewalDate = new \DateTime($allPolicyRisks->renewal_date->date, new \DateTimeZone('Europe/London'));
        $interval = $coverDate->diff($renewalDate);
        $tenure = ((int)floor($renewalDate->setTime(0,0)->diff($inceptionDate->setTime(0,0))->days / 365) - 1) . ' years';

        $coverLength = ($interval->days + 1) . " days";
        if (($interval->days) + 1 >= 365) {
            $coverLength = "1 Year";
        }
        $bqbThemeId = Carbon::parse($latestActiveRisk->cover_date->date)->greaterThanOrEqualTo('2017-03-17') ? 1 : 6;

        $newRisk = $request->all()['risk'];
        //Use the value passed if not get from the risk details if not default to zero
        $claimsCount = $newRisk['claims'] ?? ($riskDetails->data->claims_count ?? 0);

        // if previous risk has a quote hero id send that else create previous risk object
        $previousRiskDetails = [];
        if ($latestActiveRisk->quote_hero_quote_id && !($replacePersonalCoverVehicleFeatureFlag && $latestActiveRisk->cover->type == 'PERSONAL')) {
            $previousRiskDetails['quote_hero_quote_id'] = $latestActiveRisk->quote_hero_quote_id;
            $previousRiskDetails['quote_inputs']['cover_date'] = $coverDate->format('Y-m-d');
        }
        else {
            $previousRiskDetails['quote_inputs']['postcode'] = $latestActiveRisk->postcode;
            $previousRiskDetails['quote_inputs']['coverType'] = $stringIdConverter->stringToId('cover_type', $latestActiveRisk->cover->type);
            $previousRiskDetails['quote_inputs']['coverRange'] = $stringIdConverter->stringToId('cover_range', $latestActiveRisk->cover->range);
            $previousRiskDetails['quote_inputs']['homeStart'] = $latestActiveRisk->cover->home;
            $previousRiskDetails['quote_inputs']['excess'] = intval($latestActiveRisk->cover->excess);
            $previousRiskDetails['quote_inputs']['startDate'] = $coverDate->format('Y-m-d H:i:s');
            $previousRiskDetails['quote_inputs']['renewalCount'] = $latestActiveRisk->renewal_count;
            $previousRiskDetails['quote_inputs']['cover_date'] = $coverDate->format('Y-m-d');
            $previousRiskDetails['quote_inputs']['coverLength'] = $coverLength;
            $previousRiskDetails['quote_inputs']['bqbThemeId'] = $bqbThemeId;
            $previousRiskDetails['quote_inputs']['tenure'] = $tenure;
            $previousRiskDetails['quote_inputs']['claims'] = $claimsCount;
            $previousRiskDetails['source'] = $latestActiveRisk->source->text;
            $previousRiskDetails['quoteType'] = "Breakdown";
            $previousRiskDetails['type'] = !is_null($latestActiveRisk->quote_hero_type) ? $latestActiveRisk->quote_hero_type : ($latestActiveRisk->renewal_count == 0 ? "NewBusiness" : "Renewal");

            if (is_array($latestActiveRisk->personal_members) && count($latestActiveRisk->personal_members) > 0) {
                foreach ($latestActiveRisk->personal_members as $key => $riskMember) {
                    $previousRiskDetails['quote_inputs']['drivers'][] = [
                        'id' => (string)($key + 1),
                        'title' => $riskMember->title,
                        'forename' => $riskMember->forename,
                        'surname' => $riskMember->surname,
                    ];
                }
            }

            if ($replacePersonalCoverVehicleFeatureFlag && $latestActiveRisk->cover->type == 'PERSONAL' && !is_null($latestFirstPersonalCoverRiskHistory)) {
                //Previous risk is personal cover so set the vehicle
                $vehicleDetails = $latestFirstPersonalCoverRiskHistory->vehicles[0];
                $vehicleAddedDate = $this->getVehicleAddedDate($vehicleAddedDates, $vehicleDetails->reg);
                $previousRiskDetails['quote_inputs']['vehicles'][] = [
                    'id' => '1',
                    'type' => $stringIdConverter->stringToId('vehicle_type', $vehicleDetails->type),
                    'reg' => $vehicleDetails->reg,
                    'make_model' => $vehicleDetails->make_model,
                    'make' => $vehicleDetails->make,
                    'model' => $vehicleDetails->model,
                    'age' => intval(date('Y', strtotime($vehicleAddedDate))) - intval($vehicleDetails->year),
                ];
            } else {
                //Previous risk is not personal cover so continue as normal
                foreach ($latestActiveRisk->vehicles as $key => $riskVehicles) {
                    $vehicleAddedDate = $this->getVehicleAddedDate($vehicleAddedDates, $riskVehicles->reg);
                    $previousRiskDetails['quote_inputs']['vehicles'][] = [
                        'id' => (string)($key + 1),
                        'type' => $stringIdConverter->stringToId('vehicle_type', $riskVehicles->type),
                        'reg' => $riskVehicles->reg,
                        'make_model' => $riskVehicles->make_model,
                        'make' => $riskVehicles->make,
                        'model' => $riskVehicles->model,
                        'age' => intval(date('Y', strtotime($vehicleAddedDate))) - intval($riskVehicles->year),
                    ];
                }
            }
        }
        $quoteObject['previousRisk'] = $previousRiskDetails;

        //Need to reverse the get the latest risk vehicles
        $vehicleAddedDates = array_reverse($vehicleAddedDates);
        $vehicleAddedDatesLatest = reset($vehicleAddedDates);

        // need to calculate the age based on the date the vehicle was last added to the current policy term
        if ($replacePersonalCoverVehicleFeatureFlag && $newRisk['coverType'] == $stringIdConverter->stringToId('cover_type', 'PERSONAL') && $latestActiveRisk->cover->type == 'PERSONAL' && !is_null($latestFirstPersonalCoverRiskHistory)) {
            //New risk is personal cover so set the vehicle
            $vehicleDetails = $latestFirstPersonalCoverRiskHistory->vehicles[0];
            $vehicleAddedDate = $this->getVehicleAddedDate($vehicleAddedDates, $vehicleDetails->reg);
            $newRisk['vehicles'] = [
                [
                    'id' => '1',
                    'type' => $stringIdConverter->stringToId('vehicle_type', $vehicleDetails->type),
                    'reg' => $vehicleDetails->reg,
                    'make_model' => $vehicleDetails->make_model,
                    'make' => $vehicleDetails->make,
                    'model' => $vehicleDetails->model,
                    'age' => intval(date('Y', strtotime($vehicleAddedDate))) - intval($vehicleDetails->year),
                ]
            ];
        } else {
            //New risk is not personal cover so continue as normal
            if (is_array($newRisk['vehicles']) && count($newRisk['vehicles']) > 0) {
                foreach ($newRisk['vehicles'] as $key => $newRiskVehicle) {
                    if (in_array($newRiskVehicle['reg'], array_keys($vehicleAddedDatesLatest))) {
                        //Vehicle was previously on the policy so use the date
                        //We need to go through the risks to find when the vehicle was first added
                    foreach($vehicleAddedDates as $vehicleAddedDateForRisk){
                        if(!in_array($newRiskVehicle['reg'], array_keys($vehicleAddedDateForRisk))){
                            break;
                        }
                        $addedDate = $vehicleAddedDateForRisk[$newRiskVehicle['reg']];
                    }
                    $newRisk['vehicles'][$key]['age'] = intval(date('Y', strtotime($addedDate))) - intval($newRiskVehicle['manufacture_year']);
                    } else {
                        //Vehicle not on the previous so use effective date
                        $newRisk['vehicles'][$key]['age'] = intval(date('Y', strtotime($newRisk['effectiveDate']))) - intval($newRiskVehicle['manufacture_year']);
                    }
                }
            }
        }

        if(count($newRisk['drivers']) == 0) {
            unset($newRisk['drivers']);
        }

        $newRisk['coverLength'] = $coverLength;
        $newRisk['bqbThemeId'] = $bqbThemeId;
        $newRisk['tenure'] = $tenure;
        $newRisk['claims'] = $claimsCount;

        $newRisk['startDate'] = date('Y-m-d H:i', strtotime($newRisk['effectiveDate']));

        $quoteObject['currentRisk']['source'] = $latestActiveRisk->source->text;
        $quoteObject['currentRisk']['quoteType'] = "Breakdown";

        $quoteObject['currentRisk']['quote_inputs'] = $newRisk;

        $quoteObject['currentRisk']['type'] = !is_null($latestActiveRisk->quote_hero_type) ? $latestActiveRisk->quote_hero_type : ($latestActiveRisk->renewal_count == 0 ? "NewBusiness" : "Renewal");

        try {
            $mtaQuote = $getQuoteApiService->mtaQuote($quoteObject);
        }
        catch (\Exception $e) {
            $data = [
                'message' => 'Failed to get price from Quote Hero',
                'errors' => [$e->getMessage()]
            ];
            Log::info('Failed to get price from Quote Hero', ['error' => $e->getMessage()]);
            return new JsonResponse($data, 422);
        }

        try {
            $curRiskQuoteHeroId = Arr::first($mtaQuote->data->quote_inputs,function($value) {
                return $value->name === 'currentRiskQuoteId';
            })->pivot->value;
            $currentRiskQuote = $getQuoteApiService->getQuote($curRiskQuoteHeroId);
        }
        catch (\Exception $exception) {
            $data = [
                'message' => 'Unable to find new risk quote',
                'errors' => [$exception->getMessage()]
            ];
            return new JsonResponse($data, 422);
        }

        $mtaQuote->data->current_risk_quote = $currentRiskQuote->data;

        return new JsonResponse($mtaQuote);
    }

    public function getMTAQuoteElectra(){
        return view('electraOnePage');

    }

    public function getVehicleAddedDate($policyList, $vehicleReg) {
        $addedDate = null;
        foreach(array_reverse($policyList, true) as $currentElement){
            if(!array_key_exists($vehicleReg, $currentElement) && $addedDate != null) {
                break;
            }
            if(array_key_exists($vehicleReg, $currentElement)){
                $addedDate = $currentElement[$vehicleReg];
            }
        }
        return $addedDate;
    }

    /**
     * @param Request $request
     * @param \App\ElectraWebServices $electraWebServices
     * @return JsonResponse
     */
    public function getLookupList(Request $request, \App\ElectraWebServices $electraWebServices)
    {
        $listName = Str::studly($request->listName);
        $LineOfBusiness=$this->getLineOfBusinessName($request->selectedVehicleType);
        try {
            $this->setResult($electraWebServices->getLookupList($listName,$LineOfBusiness));
            $this->addSuccess();
        } catch (\Exception $exception) {
            $this->addError($exception->getMessage());
        }
        return $this->getResponse();
    }

    /**
     * @param Request $request
     * @param \App\ElectraWebServices $electraWebServices
     * @return JsonResponse
     */
    public function getRegVehicleLookups(Request $request){
        try {
            $apiKey = config('app.vehicle_lookup_api_key');
            $result = Http::get(config('app.vehicle_lookup_api_url').'?v=2&api_nullitems=1&key_vrm='.$request->search.'&auth_apikey='.$apiKey);
            $data = json_decode($result, true);
            $vehicleDetail = $data['Response']["DataItems"];
            $vehicleType ='';
            $getVehicleType = VehicleType::where('name',$vehicleDetail["VehicleRegistration"]["VehicleClass"])->value('value');
            if(isset($getVehicleType) && $getVehicleType==1){
                $vehicleType = 'Car';
            }else if(isset($getVehicleType) && $getVehicleType==2){
                $vehicleType = 'Van';
            }else if(isset($getVehicleType) && $getVehicleType==3){
                $vehicleType = 'Bike';
            }
            $response = [
                'type' => $vehicleType,
                'bodyStyle' => isset($vehicleDetail["SmmtDetails"]["BodyStyle"]) ?
                    trim(ucwords(strtolower($vehicleDetail["SmmtDetails"]["BodyStyle"]))) :
                    '',
                'color' => isset($vehicleDetail["VehicleRegistration"]["Colour"]) ?
                    trim(ucwords(strtolower($vehicleDetail["VehicleRegistration"]["Colour"]))) :
                    '',
                'dateOfManufacture' => isset($vehicleDetail["VehicleRegistration"]["YearOfManufacture"]) ?
                    $vehicleDetail["VehicleRegistration"]["YearOfManufacture"] . "/01/01" :
                    null,
                'yearOfManufacture' => isset($vehicleDetail["VehicleRegistration"]["YearOfManufacture"]) ?
                    $vehicleDetail["VehicleRegistration"]["YearOfManufacture"] :
                    null,
                'dateOfRegistration' => isset($vehicleDetail["VehicleRegistration"]["DateFirstRegistered"]) ?
                    date('Y-m-d', strtotime($vehicleDetail["VehicleRegistration"]["DateFirstRegistered"])) :
                    null,
                'make' => isset($vehicleDetail["ClassificationDetails"]["Dvla"]["Make"]) ?
                    trim(ucwords(strtolower($vehicleDetail["ClassificationDetails"]["Dvla"]["Make"]))) :
                    null,
                'model' => isset($vehicleDetail["ClassificationDetails"]["Dvla"]["Model"]) ?
                    $vehicleDetail["ClassificationDetails"]["Dvla"]["Model"] :
                    null,
                ];

            $this->setResult($response);
            $this->addSuccess();
        } catch (\Exception $exception) {
            Log::error('Failed to get vehicle models by make', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]);
            $this->addError($exception->getMessage());
        }
        return $this->getResponse();
    }

    /**
     * @param Request $request
     * @param \App\ElectraWebServices $electraWebServices
     * @return JsonResponse
     */
    public function getVehicleModelsByMake(Request $request, \App\ElectraWebServices $electraWebServices)
    {
        try {
            $LineOfBusiness=$this->getLineOfBusinessName($request->selectedVehicleType);
            $bodyTypes = Collection::make($electraWebServices->getLookupList('VehicleBodyType',$LineOfBusiness));
            $transmissionTypes = Collection::make($electraWebServices->getLookupList('VehicleTransmissionType',$LineOfBusiness));
            $fuelTypes = Collection::make($electraWebServices->getLookupList('VehicleFuelType',$LineOfBusiness));
            $result = $electraWebServices->getVehicleModelsByMake($request->makeCode, $request->get('search'),$LineOfBusiness);
            foreach ($result as $key => $value) {
                $bodyTypeCode = $bodyTypes->first(function ($item) use ($key, $value) {
                    return (isset($value['body_type']) && isset($value['body_type']['code'])) && $item['code'] === $value['body_type']['code'] || (isset($item['alternate_code']) && $item['alternate_code'] === $value['body_type']['code']);
                });
                $result[$key]['body_type']['code'] = $bodyTypeCode['code'] ?? null;

                $transmissionCode = $transmissionTypes->first(function ($item) use ($key, $value) {
                    return (isset($value['transmission']) && isset($value['transmission']['code'])) && $item['code'] === $value['transmission']['code'] || (isset($item['alternate_code']) && $item['alternate_code'] === $value['transmission']['code']);
                });
                $result[$key]['transmission']['code'] = $transmissionCode['code'] ?? null;

                $fuelCode = $fuelTypes->first(function ($item) use ($key, $value) {
                    return (isset($value['fuel']) && isset($value['fuel']['code'])) && $item['code'] === $value['fuel']['code'] || (isset($item['alternate_code']) && $item['alternate_code'] === $value['fuel']['code']);
                });
                $result[$key]['fuel']['code'] = $fuelCode['code'] ?? null;

                $result[$key]['description'] .=
                    ' ' . round($result[$key]['engine_capacity'] / 1000, 1) . 'L' .
                    ' ' . $result[$key]['manufactured_from'] . ' -' .
                    ' ' . ($result[$key]['manufactured_to'] !== 0 ? $result[$key]['manufactured_to'] : 'Present');
            }

            $this->setResult($result);
            $this->addSuccess();
        } catch (\Exception $exception) {
            Log::error('Failed to get vehicle models by make', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]);
            $this->addError($exception->getMessage());
        }
        return $this->getResponse();
    }

    public function postZeroCostMTA(Request $request, BarbaraWebServices $barbaraWebServices, ElectraApiService $electraApiService)
    {
        $user = Auth::User();
        $quote = Quote::where('external_quote_id', $request->get('external_quote_id'))->where('customer_id',$user->customer_id)->first();

        if (!$quote) {
            $result['code'] = 1;
            $this->setResult($result);
            $this->addError('Can\'t find MTA details');
            return $this->getResponse();
        }
        $quoteJson = json_decode($quote->details);

        if($quoteJson->result->totPremDue > 0 || $quoteJson->result->adminFee > 0) {
            $result['code'] = 1;
            $this->setResult($result);
            $this->addError('Quote requires a payment');
            return $this->getResponse();
        }

        $mtaData = [];

        try {
            if ($quoteJson->request->addressChanged) {
                //Address MTA so just update the address
                $dataController = new DataController();
                $dataController->updateAddress($quoteJson->request->address);

            } else {
                //Normal MTA so update the risk

                $mtaData['sessionID'] = Uuid::uuid4()->toString();

                $mtaData['reference'] = $quoteJson->request->contractId;
                $mtaData['referenceType'] = "ContractID";
                $mtaData['transactionType'] = "Midterm Adjustment";
                $mtaData['payment'] = 0;
                $mtaData['paymentDate'] = date("c", time());
                $mtaData['effectiveDate'] = $quoteJson->request->risk->effectiveDate;
                $mtaData['paymentType'] = "WP";
                $mtaData['charges'] = [$quoteJson->result->adminFee];
                $mtaData['addons'] = null;
                $mtaData['expiryDate'] = $quoteJson->request->risk->endDate;

                $mtaData['totPremTax'] = $quoteJson->result->totPremTax;
                $mtaData['totTax'] = $quoteJson->result->totTax;
                $mtaData['taxPct'] = $quoteJson->result->taxPct;
                $mtaData['totPremDue'] = $quoteJson->result->totPremDue;
                $mtaData['netRate'] = $quoteJson->result->netRate;
                $mtaData['commission'] = $quoteJson->result->commission;
                $mtaData['mtaFap'] = $quoteJson->result->mtaFap;
                $mtaData['mtaNetFap'] = $quoteJson->result->mtaNetFap;
                $mtaData['agentPrem'] = $quoteJson->result->agentPrem;

                $mtaData['distSystem'] = "retail";
                $mtaData['insurer'] = $quoteJson->insurer;

                $cover['type'] = $quoteJson->request->risk->coverType;
                $cover['range'] = $quoteJson->request->risk->coverRange;
                $cover['home'] = $quoteJson->request->risk->homeStart;
                $cover['excess'] = $quoteJson->request->risk->excess;

                $riskDetails['vehicles'] = $quoteJson->request->risk->vehicles;
                $riskDetails['persons'] = $quoteJson->request->risk->drivers;
                $riskDetails['cover'] = $cover;

                $mtaData['riskDetails'] = $riskDetails;

                $mtaResult = $electraApiService->postMTA($mtaData);
                if( $mtaResult['code'] != 0 ) {
                    $result = [];
                    $result['code'] = 1;
                    $this->setResult($result);
                    $this->addError('Unable to process MTA');
                    Log::info('Failed to import policy to electra see error in the electra api', ['data' => $mtaData]);
                    return $this->getResponse();
                }
            }

            $quote->update(['completed' => 1, 'completed_at' => now()]);
            $result = [];
            $result['code'] = 0;
            $this->setResult($result);
            $this->addSuccess();
            return $this->getResponse();
        } catch (\Exception $e) {
            $result = [];
            $result['code'] = 1;
            $this->setResult($result);
            $this->addError('Unable to process MTA');
            Log::info('Failed to import policy to electra', ['exception' => $e, 'data' => $mtaData]);
            return $this->getResponse();
        }
    }

    public function postMTA(Request $request)
    {
        $requestData = $request->get('data');

        $transactionDetails = Transaction::query()
            ->where('vendor_tx_code', '=', $requestData['order_code'])
            ->first();

        $result = [];

        if ($transactionDetails) {
            //Success
            ProcessPaymentRequest::dispatch($transactionDetails);
        } else {
            $result['data']['result'] = false;
        }

        return response()->json($result, 200);
    }

    public function postCancellation(Request $request)
    {
        $requestData = $request->get('data');

        $transactionDetails = Transaction::query()
            ->where('vendor_tx_code', '=', $requestData['order_code'])
            ->first();

        $result = [];

        if ($transactionDetails) {
            //Success
            ProcessPaymentRequest::dispatch($transactionDetails);
        } else {
            $result['data']['result'] = false;
        }

        return response()->json($result, 200);
    }

    public function addressLookup(Request $request, AddressLookupService $addressLookupService)
    {
        $result = [];
        $result['code'] = 0;
        $result['data']['address'] = $addressLookupService->addressLookup($request->get('number'), $request->get('postcode'));

        return response()->json($result, 200);
    }

    public function resubmitMTA($transactionId)
    {
        $transactionDetails = Transaction::find($transactionId);

        $result = [
            'data' => [
                'result' => true
            ]
        ];

        if ($transactionDetails) {
            //Success
            ProcessPaymentRequest::dispatch($transactionDetails);
        } else {
            $result['data']['result'] = false;
        }

        return response()->json($result, 200);
    }

    public function linkPolicies(Request $request, ElectraApiService $electraApiService){
        $validator = Validator::make($request->all(), [
            'data.policyNum' => 'required'
        ]);

        if($validator->fails()){
            throw new ValidationException($validator);
        }

        $identifiedId = null;
        $user = Auth::user();

        try {
            $polNumber = preg_replace('/\s./', '', strtoupper($request->data["policyNum"]));

            $mainCustomerDetails = ['customerId' => $user->customer_id];

            // the main customer is logged into bdcp - so get their data from electra
            $mainCustomer = $electraApiService->policySearch($mainCustomerDetails)->data;

            // we check that the request policy number has the same surname, postcode and email as the main customer
            $customerToBeLinked = [
                'surname' => $mainCustomer[0]->policy_holder_surname,
                'postcode' => $mainCustomer[0]->address->postcode,
                'policyNumber' => $polNumber
            ];

            // attempt to find a policy that matches based on the above in electra
            $foundPolicy = $electraApiService->policySearch($customerToBeLinked)?->data[0] ?? null;

            // policy search returned null or the returned policy holder email doesn't match the logged-in user
            // would be nice if this could be done with the search in EAv2 but requires an update to EAv2 to search by email
            if (empty($foundPolicy) || strtolower($foundPolicy->policy_holder_email) != strtolower($user->email)) {
                throw new \Exception("No additional policy found");
            }

            $identifiedId = $foundPolicy->customer_id;

            if ($identifiedId == $user->customer_id || in_array($identifiedId, $user->secondary_customer_ids)) {
                $this->addError("Customer already linked");
                return $this->getResponse();
            }

            // search for all policies belonging to the customer of the searched for policy
            $policies = $electraApiService->policySearch(['customerId' => (string) $foundPolicy->customer_id])?->data;
            if (empty($policies)) {
                $this->addError("No policies found to link");
                return $this->getResponse();
            }

            $policyIds = [];
            $policyRefs = [];
            foreach ($policies as $pol) {
                // We only want breakdown policies
                if (!($pol->policy_type_code == 'MBV' || $pol->policy_type_code == 'MBI')) {
                    continue;
                }
                $policyIds[] = $pol->policy_api_id;
                $policyRefs[] = $pol->policy_ref;
            }

            $saveUser = Auth::guard('api')->user();

            $secondaryCustomerIds = $saveUser->secondary_customer_ids;
            if ($request->data["makeMain"]) {
                array_push($secondaryCustomerIds, $saveUser->customer_id);
                $saveUser->customer_id = $identifiedId;
            } else {
                array_push($secondaryCustomerIds, $identifiedId);
            }

            $saveUser->secondary_customer_ids = $secondaryCustomerIds;

            $savedPolicyIds = $saveUser->policy_ids;
            $savedPolicyIds[$identifiedId] = $policyIds;
            $saveUser->policy_ids = $savedPolicyIds;

            $saveUser->save();
        }
        catch(\Exception $e) {
            Log::error("Failed to link policies", [
                "customer_id" => $user->customer_id,
                "secondary_customer_id" => $identifiedId,
                "searched_policyNum" => $polNumber,
                "exception" => $e->getMessage()
            ]);

            $this->addError("Failed to link policies");
            return $this->getResponse();
        }

        $this->addSuccess();

        $this->setResult(["policies" => $policyRefs]);

        return $this->getResponse();

    }

    function postReturnPremiumMTA(Request $request, PaymentHubService $paymentHubService) {

        $requestData = $request->all();
        $validator = Validator::make(
            $requestData,
            [
                'data.quote_id' => 'required|integer|exists:quotes,id'
            ]
        );
        // Check if the input passes validation

        if ($validator->fails()) {
            $errors = $validator->errors();
            $response['code'] = 1;
            $response['data'] = null;
            foreach ($errors->getMessages() as $key => $message) {
                $response['messages'][] = $message[0];
            }
            return Response::json($response,400);
        }

        $user = Auth::user();

        $quote = Quote::query()
            ->where('user_id', '=', $user->id)
            ->where('id', '=', $requestData['data']['quote_id'])
            ->first();

        if (!$quote) {
            //Failed to find the quote for the user_id
            $response['code'] = 1;
            $response['data'] = null;
            $response['messages'] = ['Unable to find quote'];

            return Response::json($response,400);
        }

        if ($quote->completed == 1) {
            //Quote found but has already been completed
            $response['code'] = 1;
            $response['data'] = null;
            $response['messages'] = ['Quote already completed'];

            return Response::json($response,400);
        }

        $quoteJson = json_decode($quote->details);

        $newTransaction = new Transaction();
        $newTransaction->quote_id = $quote->id;
        $newTransaction->amount = $quoteJson->result->total;
        $newTransaction->vendor_tx_code = 'REFUND';
        $newTransaction->security_key = 'NONE';
        $newTransaction->save();

        try {
            if ($newTransaction->amount < 0 && !is_null($quote->return_premium_transaction_id) && $quote->refunded == 0) {
                //We have a return premium and a return premium transaction
                $paymentHubService->refundTransaction($quote->return_premium_transaction_id, [
                    'data' => [
                        'refund_amount' => abs($newTransaction->amount)
                    ]
                ]);
                $newTransaction->quote->update(['refunded' => 1]);
            }
        } catch (\Exception $exception) {
            Log::error('Failed to refund for '.$requestData['data']['type'], [
                'bdcp_transaction_id' => $newTransaction->id,
                'bdcp_quote_id' => $quote->id,
                'exception_details' => $exception,
            ]);
            return Response::json(['message' => 'Failed to refund'],400);
        }

        ProcessPaymentRequest::dispatch($newTransaction);

        return Response::json($newTransaction,200);
    }
    // Set LineOfBusiness name based on selected vehicle type
    function getLineOfBusinessName($selectedVehicleType){
        if($selectedVehicleType=="Bike"){
            $LineOfBusiness="Motorcycle";
        }else if ($selectedVehicleType=="Van"){
            $LineOfBusiness="CommercialVehicle";
        }else{
            $LineOfBusiness="PrivateMotorCar";
        }
        return $LineOfBusiness;
    }

    public function policyCancellation(
        Request $request,
        GetQuoteApiService $getQuoteApiService,
        ElectraApiService $electraApiService,
        StringIdConverter $stringIdConverter){

        $validator = Validator::make($request->all(), [
            'effectiveDate' => 'required',
            'cancellationReason' => 'required',
            'policyNum' => 'required'
        ]);

        if($validator->fails()){
            throw new ValidationException($validator);
        }

        $user = Auth::user();


        try {
            $allPolicyRisks = $electraApiService->getRiskSummary($request['policyNum'])->data;
            $insurer = $allPolicyRisks->insurer;
        } catch (\Exception $e) {
            $data = [
                'message' => 'The given data was invalid.',
                'errors' => ['con_id' => 'Unable to fetch risk summary']

            ];
            Log::error('Failed to find risk summary', ['con_id' => $request['policyNum']]);
            return new JsonResponse($data, 422);
        }

        if (!$allPolicyRisks) {
            $data = [
                'message' => 'The given data was invalid.',
                'errors' => ['con_id' => 'No risks returned']

            ];
            Log::error('No risks returned', ['con_id' => $request['policyNum']]);
            return new JsonResponse($data, 422);
        }


        // get the most recent active risk history
        $latestHistory = $allPolicyRisks->risk_history[0];
        foreach ($allPolicyRisks->risk_history as $historyItem) {
            if ($historyItem->effective_date > $latestHistory->effective_date) {
                $latestHistory = $historyItem;
            }
        }
        try {
            $latestActiveRisk = $electraApiService->getRisk($request['policyNum'], $latestHistory->id)->data;
        }
        catch (\Exception $exception) {
            $data = [
                'message' => 'The given data was invalid.',
                'errors' => ['con_id' => 'Can\'t get current risk']

            ];
            Log::error('Can\'t get current risk', ['con_id' => $request['policyNum'], 'rsh_id' => $latestHistory->id]);
            return new JsonResponse($data, 422);
        }

        foreach ($allPolicyRisks->risk_history as $riskHistoryItem) {
            try {
                $riskDetails = $electraApiService->getRisk($request['policyNum'], $riskHistoryItem->id)->data;
            }
            catch (\Exception $e) {
                $data = [
                    'message' => 'The given data was invalid.',
                    'errors' => ['rsh_id' => 'Unable to find valid risk']

                ];
                Log::error('Unable to fetch risk', ['con_id' => $request['policyNum'], 'risk_id' => $riskHistoryItem->id]);
                return new JsonResponse($data, 422);
            }

            // risk not part of current policy term skip
            if ($latestActiveRisk->renewal_count != $riskDetails->renewal_count) continue;

            $orderedCoverPeriodRisks[$riskDetails->effective_date->date] = $riskDetails;

            // already processed this risk history skip
            if (isset($vehicleAddedDates[$riskHistoryItem->id])) continue;

            foreach ($riskDetails->vehicles as $riskVehicles) {
                $vehicleAddedDates[$riskHistoryItem->id][$riskVehicles->reg] = $riskDetails->effective_date->date;
            }
        }

        //Sort the array based on the date keys in descending order
        krsort($orderedCoverPeriodRisks);

        $latestFirstPersonalCoverRiskHistory = null;
        foreach ($orderedCoverPeriodRisks as $orderedCoverPeriodRisk) {
            if (!is_null($latestFirstPersonalCoverRiskHistory)) {
                if (strtoupper($orderedCoverPeriodRisk->cover->type) == 'PERSONAL') {
                    //$latestFirstPersonalCoverRiskHistory has been set yet and the risk is personal and earlier so replace it
                    $latestFirstPersonalCoverRiskHistory = $orderedCoverPeriodRisk;
                } else {
                    //Risk is not personal so we have reached the latest first personal cover risk
                    break;
                }
            } else {
                if (strtoupper($orderedCoverPeriodRisk->cover->type) == 'PERSONAL') {
                    //$latestFirstPersonalCoverRiskHistory hasn't been set yet and the risk is personal so set it
                    $latestFirstPersonalCoverRiskHistory = $orderedCoverPeriodRisk;
                }
            }
        }

        $replacePersonalCoverVehicleFeatureFlag = filter_var(config('app.replace_personal_cover_vehicle_feature_flag'), FILTER_VALIDATE_BOOLEAN);
        $coverDate = new \DateTime($allPolicyRisks->cover_date, new \DateTimeZone('Europe/London'));
        $inceptionDate = (new \DateTime($allPolicyRisks->inception_date->date, new \DateTimeZone('Europe/London')));
        $renewalDate = new \DateTime($allPolicyRisks->renewal_date->date, new \DateTimeZone('Europe/London'));
        $bqbThemeId = Carbon::parse($latestActiveRisk->cover_date->date)->greaterThanOrEqualTo('2017-03-17') ? 1 : 6;
        $tenure = ((int)floor($renewalDate->setTime(0,0)->diff($inceptionDate->setTime(0,0))->days / 365) - 1) . ' years';
        $interval = $coverDate->diff($renewalDate);

        $claimsCount = $newRisk['claims'] ?? ($riskDetails->data->claims_count ?? 0);

        $coverLength = ($interval->days + 1) . " days";
        if (($interval->days) + 1 >= 365) {
            $coverLength = "1 Year";
        }
        // if previous risk has a quote hero id send that else create previous risk object
        $previousRiskDetails = [];
        // if ($latestActiveRisk->quote_hero_quote_id && !($replacePersonalCoverVehicleFeatureFlag && $latestActiveRisk->cover->type == 'PERSONAL')) {
        //     $previousRiskDetails['quote_hero_quote_id'] = $latestActiveRisk->quote_hero_quote_id;
        //     $previousRiskDetails['quote_inputs']['cover_date'] = $coverDate->format('Y-m-d');
        // }
        // else {
            $previousRiskDetails['quote_inputs']['postcode'] = $latestActiveRisk->postcode;
            $previousRiskDetails['quote_inputs']['coverType'] = $stringIdConverter->stringToId('cover_type', $latestActiveRisk->cover->type);
            $previousRiskDetails['quote_inputs']['coverRange'] = $stringIdConverter->stringToId('cover_range', $latestActiveRisk->cover->range);
            $previousRiskDetails['quote_inputs']['homeStart'] = $latestActiveRisk->cover->home;
            $previousRiskDetails['quote_inputs']['excess'] = intval($latestActiveRisk->cover->excess);
            $previousRiskDetails['quote_inputs']['startDate'] = $coverDate->format('Y-m-d H:i:s');
            $previousRiskDetails['quote_inputs']['renewalCount'] = $latestActiveRisk->renewal_count;
            $previousRiskDetails['quote_inputs']['cover_date'] = $coverDate->format('Y-m-d');
            $previousRiskDetails['quote_inputs']['coverLength'] = $coverLength;
            $previousRiskDetails['quote_inputs']['bqbThemeId'] = $bqbThemeId;
            $previousRiskDetails['quote_inputs']['tenure'] = $tenure;
            $previousRiskDetails['quote_inputs']['claims'] = $claimsCount;
            $previousRiskDetails['source'] = $latestActiveRisk->source->text;
            $previousRiskDetails['quoteType'] = "Breakdown";
            $previousRiskDetails['type'] = !is_null($latestActiveRisk->quote_hero_type) ? $latestActiveRisk->quote_hero_type : ($latestActiveRisk->renewal_count == 0 ? "NewBusiness" : "Renewal");

            if (is_array($latestActiveRisk->personal_members) && count($latestActiveRisk->personal_members) > 0) {
                foreach ($latestActiveRisk->personal_members as $key => $riskMember) {
                    $previousRiskDetails['quote_inputs']['drivers'][] = [
                        'id' => (string)($key + 1),
                        'title' => $riskMember->title,
                        'forename' => $riskMember->forename,
                        'surname' => $riskMember->surname,
                    ];
                }
            }

            if ($replacePersonalCoverVehicleFeatureFlag && $latestActiveRisk->cover->type == 'PERSONAL' && !is_null($latestFirstPersonalCoverRiskHistory)) {
                //Previous risk is personal cover so set the vehicle
                $vehicleDetails = $latestFirstPersonalCoverRiskHistory->vehicles[0];
                $vehicleAddedDate = $this->getVehicleAddedDate($vehicleAddedDates, $vehicleDetails->reg);
                $previousRiskDetails['quote_inputs']['vehicles'][] = [
                    'id' => '1',
                    'type' => $stringIdConverter->stringToId('vehicle_type', $vehicleDetails->type),
                    'reg' => $vehicleDetails->reg,
                    'make_model' => $vehicleDetails->make_model,
                    'make' => $vehicleDetails->make,
                    'model' => $vehicleDetails->model,
                    'colour' => $vehicleDetails->colour,
                    'age' => intval(date('Y', strtotime($vehicleAddedDate))) - intval($vehicleDetails->year),
                    'year' => date('Y', strtotime($vehicleAddedDate)),
                    'manufacture_year' => date('Y', strtotime($vehicleAddedDate)),
                ];
            } else {
                //Previous risk is not personal cover so continue as normal
                foreach ($latestActiveRisk->vehicles as $key => $riskVehicles) {
                    $vehicleAddedDate = $this->getVehicleAddedDate($vehicleAddedDates, $riskVehicles->reg);
                    $previousRiskDetails['quote_inputs']['vehicles'][] = [
                        'id' => (string)($key + 1),
                        'type' => $stringIdConverter->stringToId('vehicle_type', $riskVehicles->type),
                        'reg' => $riskVehicles->reg,
                        'make_model' => $riskVehicles->make_model,
                        'make' => $riskVehicles->make,
                        'model' => $riskVehicles->model,
                        'colour' => $riskVehicles->colour,
                        'age' => intval(date('Y', strtotime($vehicleAddedDate))) - intval($riskVehicles->year),
                        'year' => date('Y', strtotime($vehicleAddedDate)),
                        'manufacture_year' => date('Y', strtotime($vehicleAddedDate)),
                    ];
                }
            }
        // }


        $currentRiskDetails = $previousRiskDetails;
        $currentRiskDetails['quote_inputs']['effectiveDate'] = $request['effectiveDate'];

        $previousRiskDetails['quote_inputs']['effectiveDate'] = $previousRiskDetails['quote_inputs']['startDate'];

        $quoteObject = [
            "previousRisk" => $previousRiskDetails,
            "currentRisk" => $currentRiskDetails
        ];

        try {
            $cancellationQuote = $getQuoteApiService->cancellationQuote($quoteObject);
        } catch (\Exception $e) {
            Log::info('Failed to get price from Quote Hero', ['error' => $e->getMessage()]);
        }

        if (!is_null($cancellationQuote->data->total)) {
            $data = $cancellationQuote->data;
            $id = $cancellationQuote->data->id;
            $response['code'] = 0;
            $data->charges = [];
            foreach($data->fee_campaigns as $campaign){
                $data->charges[] = [
                    'code' => $campaign->code,
                    'description' => $campaign->description,
                    'value' => $campaign->quote_campaign_amount
                ];
            }
        } else {
            $data = null;
            $id = -1;
            $response['code'] = 1;
            $response['messages'] = $cancellationQuote->messages ?? 'Failed to get a price';
            $response['error'] = $cancellationQuote->error ?? 'Unknown error';
        }

        $user = Auth::guard('api')->user();

        //Create the quote record in the BDCP database
        $newQuote = new Quote();
        $newQuote->user_id = $user->id;
        $newQuote->customer_id = $user->customer_id;
        $newQuote->policy_id = $request['policyNum'];
        $newQuote->type = 'CANCELLATION';
        $newQuote->details = json_encode(['request' => $request->all(), 'result' => $data, 'insurer' => $insurer]);
        $newQuote->external_quote_id = $id;
        $newQuote->completed = 0;

        $newQuote->save();

        if ($response['code'] == 0) {
            //Only format the data if a successful response is being returned
            if ($data) {
                $data->quote_id = $newQuote->id;
                $data->totPremDue = $data->total;
            }

            $allowed = ['id', 'quote_id', 'totPremDue', 'gross', 'charges', 'RPCardNumber'];

            $response['data'] = array_filter((array) $data, function($key) use($allowed){
                return in_array($key,$allowed);
            }, ARRAY_FILTER_USE_KEY);
        }

        return $response;
    }

    function savePolicyCache(Request $request) {
        $validator = Validator::make(
            $request->all(),
            [
                'data.policy' => 'required|array'
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $user = Auth::user();

        $processingPolicies = $user->processing_policies ?? [];
        $processingPolicies[] = $request->input('data.policy');
        $user->update([
            'processing_policies' => $processingPolicies
        ]);

        Auth::setUser($user);

        return Response::json([
            'result' => true,
        ]);
    }
}
