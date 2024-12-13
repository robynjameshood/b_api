<?php
/**
 * Created by PhpStorm.
 * User: Chris.Williams
 * Date: 29/11/2018
 * Time: 15:06
 */

namespace App;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SoapFault;
use stdClass;
use SoapClient;
use Icewind\SMB\Server;
use Icewind\SMB\NativeServer;
use League\Flysystem\Filesystem;
use RobGridley\Flysystem\Smb\SmbAdapter;
use Ramsey\Uuid\Uuid;
use PDO;

class ElectraWebServices
{
    private $_electra = null;
    public function __construct()
    {
       /* try {
            $this->_electra = new PDO(
                sprintf(
                    'informix:host=%s;service=%s;database=%s;server=%s;protocol=onsoctcp;',
                    env('ELECTRA_HOST'),
                    env('ELECTRA_SERVICE'),
                    env('ELECTRA_DATABASE'),
                    env('ELECTRA_SERVER')
                ),
                env('ELECTRA_USERNAME'),
                env('ELECTRA_PASSWORD')
            );
            $this->_electra->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(\PDOException $e) {
            throw new \Exception('Failed to connect to electra', $e->getCode(), $e);
        }*/
    }

    /**
     * Generate the auth for a request
     *
     * @param mixed[] ...$params Parameters for "Auth Field" section of the digest
     *
     * @return array
     */
    static protected function generateAuth(...$params)
    {
        $now = Carbon::now('Europe/London');
        $timeStamp = $now->toAtomString();
        if(empty($params)) {
            $baseKey = sha1(
                sprintf(
                    '%s|%s',
                    env('SSP_SCID'),
                    $now->format('d/m/Y H:i:s')
                )
            );
        }
        else {
            $params = implode('|', $params);
            $baseKey = sha1(
                sprintf(
                    '%s|%s|%s',
                    env('SSP_SCID'),
                    $now->format('d/m/Y H:i:s'),
                    $params
                )
            );
        }

        $digest = sha1(
            sprintf(
                '%s|%s',
                $baseKey,
                env('SSP_PASSWORD')
            )
        );

        return [
            'BrokerId' => env('SSP_SCID'),
            'Digest' => $digest,
            'Timestamp' => $timeStamp
        ];
    }


    /**
     * @param array $array
     * @param array $output
     */
    static public function snakeCaseArrayKeysRecursive(array $array, array &$output) {
        foreach ($array as $key => $value) {
            if(is_array($value)) {
                $output1 = [];
                self::snakeCaseArrayKeysRecursive($value, $output1);
                $output[Str::snake($key)] = $output1;
            }
            else {
                $output[Str::snake($key)] = $value;
            }
        }
    }

    /**
     * @param array $array
     * @param array $output
     */
    static public function studlyCaseArrayKeysRecursive(array $array, array &$output) {
        foreach ($array as $key => $value) {
            if(is_array($value)) {
                $output1 = [];
                self::studlyCaseArrayKeysRecursive($value, $output1);
                $output[Str::studly($key)] = $output1;
            }
            else {
                $output[Str::studly($key)] = $value;
            }
        }
    }

    /**
     * Add an event to the policy file
     *
     * @param integer $customerId   The customer to add the event to
     * @param integer $policy The Id of the policy to add the event to
     * @param string  $lineOne   The first line of text for the event
     * @param string  $lineTwo   The second line of text for the event
     * @param string  $lineThree   The third line of text for the event
     *
     * @throws \Exception on failure
     */
    static public function addEvent($customerId, $policy, $lineOne, $lineTwo, $lineThree)
    {
        $lineOne = config('app.debug') ? substr('TEST ' . $lineOne,0,70) : $lineOne;
        $client = new SoapClient(env('SSP_CUSTOMER_WSDL'), ['trace' => 1]);

        $params = new StdClass();
        $params->request = new StdClass();
        $params->request->Auth = ElectraWebServices::generateAuth(
            $customerId, $policy
        );
        $params->request->Event = [
            'CustomerId' => $customerId,
            'PolicyId' => $policy, // this is contract_id
            'Description' => [$lineOne, $lineTwo, $lineThree]
        ];
        $response = $client->AddEvent($params);
        if ($response == false
            && empty($response->AddEventResult)
            && empty($response->AddEventResult->EventAdded)
        )
        {
            throw new \Exception('Failed to add event: '.$lineOne.' for '.$lineThree);
        }
    }

    /**
     * Add an event to the policy file
     *
     * @param integer $customerId   The customer to add the event to
     * @param string  $lineOne   The first line of text for the event
     * @param string  $lineTwo   The second line of text for the event
     * @param string  $lineThree   The third line of text for the event
     *
     * @throws \Exception on failure
     */
    static public function addCustomerEvent($customerId, $lineOne, $lineTwo = '', $lineThree = '')
    {
        $lineOne = config('app.debug') ? substr('TEST ' . $lineOne,0,70) : $lineOne;
        $client = new SoapClient(env('SSP_CUSTOMER_WSDL'), ['trace' => 1]);

        $params = new StdClass();
        $params->request = new StdClass();
        $params->request->Auth = ElectraWebServices::generateAuth(
            $customerId, 0
        );
        $params->request->Event = [
            'CustomerId' => $customerId,
            'Description' => [$lineOne, $lineTwo, $lineThree]
        ];

        $response = $client->AddEvent($params);

        if ($response == false
            && empty($response->AddEventResult)
            && empty($response->AddEventResult->EventAdded)
        )
        {
            throw new \Exception('Failed to add customer event: '.$lineOne.' for '.$lineThree);
        }
    }

    /**
     * @param $listName
     * @return array
     * @throws SoapFault
     * @throws \Exception
     */
    public function getLookupList($listName,$LineOfBusiness) {
        $lookupClient = new \SoapClient(config('app.ssp_lookup_wsdl'), ['trace' => 1]);
        $params = new StdClass();
        $params->request = new StdClass();
        $params->request->Auth = $this->generateAuth();
        $params->request->EffectiveDate = Carbon::now()->toAtomString();
        $params->request->LineOfBusiness = $LineOfBusiness;
        $params->request->List = $listName;
        try {
            $codes = $lookupClient->GetList($params)->GetListResult->Codes;
            if( $codes == new stdClass() ) {
                return [];
            }
            $result = json_decode(json_encode($codes->Code),true);
            $data = [];
            ElectraWebServices::snakeCaseArrayKeysRecursive($result,$data);
            return $data;
        }
        catch (\Exception $e) {
            Log::error('Unable to get ' .  $listName . ' lookup list',['exception'=>$e]);
            throw new \Exception('Unable to get ' .  $listName . ' lookup list');
        }
    }

    /**
     * @param $makeCode
     * @param $search
     * @return array
     * @throws SoapFault
     * @throws \Exception
     */
    public function getVehicleModelsByMake($makeCode, $search,$LineOfBusiness) {
        $lookupClient = new \SoapClient(config('app.ssp_lookup_wsdl'), ['trace' => 1]);
        $params = new StdClass();
        $params->request = new StdClass();
        $params->request->Auth = $this->generateAuth();
        $params->request->EffectiveDate = Carbon::now()->toAtomString();
        $params->request->ItemsPerPage = 1000000;
        $params->request->LineOfBusiness = $LineOfBusiness;
        $params->request->Manufacturer = new stdClass();
        $params->request->Manufacturer->Code = $makeCode;
        $params->request->ModelSearchText = $search;
        try {
            $models = $lookupClient->GetVehicleList($params)->GetVehicleListResult->Vehicles;
            if( $models == new stdClass() ) {
                return [];
            }
            $result = json_decode(json_encode($models->VehicleCode),true);
            $data = [];
            ElectraWebServices::snakeCaseArrayKeysRecursive($result,$data);
            return $data;
        }
        catch (\Exception $e) {
            Log::error('Unable to get vehicle model list',['exception'=>$e]);
            throw new \Exception('Failed to get model list');
        }
    }

    public function identify($email, $policyRef, $surname) {
        $client = new \SoapClient(env('SSP_CUSTOMER_WSDL'), ['trace' => 1]);

        $params = new StdClass();
        $params->request = new StdClass();
        $params->request->Auth = $this->generateAuth(
            $surname, null
        );
        $params->request->RegistrationDetails = [
            'Email' => $email,
            'InsurerPolicyReference' => $policyRef,
            'Surname' => $surname
        ];

        // Send the request and process the new user
        try {
            $response = $client->Identify($params);
        } catch (\Exception $e){
            throw $e;
        }

        return json_decode(json_encode($response), true);
    }

    public function getPolicyLists($customer_id) {
        $customerSoap = new \SoapClient(env('SSP_CUSTOMER_WSDL'), ['trace' => 1]);
        $params = new StdClass();
        $params->request = new StdClass();
        $params->request->Auth = $this->generateAuth(
            $customer_id
        );
        $params->request->Id = $customer_id;

        $params->request->CustomerId = $customer_id;
        $policy = $customerSoap->GetPolicyList($params);
        $policies = [];
        if (is_array($policy->GetPolicyListResult->Policies->PolicyItem)) {
            $policies = $policy->GetPolicyListResult->Policies->PolicyItem;
        } else {
            $policies[] = $policy->GetPolicyListResult->Policies->PolicyItem;
        }

        return $policies;
    }

    public function getCustomer($customer_id) {
        $customerSoap = new \SoapClient(env('SSP_CUSTOMER_WSDL'), ['trace' => 1]);

        try {
            // Request the customer details
            $params = new StdClass();
            $params->request = new StdClass();
            $params->request->Auth = $this->generateAuth(
                $customer_id
            );
            $params->request->Id = $customer_id;
            $response = $customerSoap->Load($params);
            $customer = $response->LoadResult->Customer;
            return json_decode(json_encode($customer), true);

        } catch(\Exception $e) {
            throw $e;
        }
    }

}
