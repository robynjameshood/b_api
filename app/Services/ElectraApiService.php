<?php
/**
 * Created by PhpStorm.
 * User: Chris.Williams
 * Date: 20/06/2019
 * Time: 13:49
 */

namespace App\Services;

use App\Http\Controllers\DataController;
use Facades\App\ElectraWebServices;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Exception;
use Illuminate\Support\Facades\Log;
use stdClass;
use Carbon\Carbon;

class ElectraApiService
{
    private $http;

    public function __construct()
    {
        $this->http = new Client(['base_uri' => config('app.electra_api_url')]);
    }

    /**
     * Generate the auth for a request
     *
     * @param mixed[] ...$params Parameters for "Auth Field" section of the digest
     *
     * @return array
     */
    protected function generateAuth(...$params)
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

    public function policySearch($request)
    {
        try {
            $response = $this->http->get(
                'policySearch',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.electra_api_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($request)
                ]
            );
        } catch (RequestException $error) {
            throw $error;
        }

        if ($response->getStatusCode() != 200) {
            throw new Exception('Failed to get policy details', $response->getReasonPhrase(), $response->getStatusCode());
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }

    public function getRiskSummary($contractId)
    {
        try {
            $response = $this->http->get(
                'get-risk-summary',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.electra_api_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode([
                        'contract_id' => $contractId
                    ])
                ]
            );
        } catch (RequestException $error) {
            throw $error;
        }

        if ($response->getStatusCode() != 200) {
            throw new Exception('Failed to get risk summary', $response->getReasonPhrase(), $response->getStatusCode());
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }

    public function getRisk($contractId, $risk_id)
    {
        try {
            $response = $this->http->get(
                'get-risk',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.electra_api_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode([
                        'contract_id' => $contractId,
                        'risk_id' => $risk_id
                    ])
                ]
            );
        } catch (RequestException $error) {
            throw $error;
        }

        if ($response->getStatusCode() != 200) {
            throw new Exception('Failed to get risk', $response->getReasonPhrase(), $response->getStatusCode());
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }

    public function syncRisksForContract($contractId){
        try {
            $response = $this->http->get(
                "sync-risks-for-contract/$contractId",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.electra_api_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode([
                        'contract_id' => $contractId,
                    ])
                ]
            );
        } catch (RequestException $error) {
            return json_decode(json_encode(["data" => [
                'result' => false,
                'messages' => ['Unable to sync risks for contract']
            ]]));
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }

    public function postMTA($mtaDetails)
    {
        try {
            $response = $this->http->post(
                'post-mta',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.electra_api_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($mtaDetails)
                ]
            );
        } catch (RequestException $error) {
            throw $error;
        }

        if ($response->getStatusCode() != 200) {
            throw new Exception('Failed to post mta', $response->getReasonPhrase(), $response->getStatusCode());
        }

        $result = json_decode($response->getBody()->getContents());
//        if($result->code != 0) {
//            throw new Exception('Failed to post mta');
//        }
        return $result;
    }

    public function postCancellation($cancellationDetails)
    {
        try {
            $response = $this->http->post(
                'post-cancellation',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.electra_api_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($cancellationDetails)
                ]
            );
        } catch (RequestException $error) {
            throw $error;
        }

        if ($response->getStatusCode() != 200) {
            throw new Exception('Failed to post cancellation', $response->getReasonPhrase(), $response->getStatusCode());
        }

        $result = json_decode($response->getBody()->getContents());
        return $result;
    }

    public function updateAddress($customerId, $line1, $line2, $line3, $postcode)
    {
        //Validation passed so save the information

        $soap = new \SoapClient(env('SSP_CUSTOMER_WSDL'), ['trace' => 1]);

        //Get the customers current details
        $dataController = new DataController();
        $currentDetails = $dataController->getCustomerCurrentDetails($soap, $customerId);

        //Set up the parameters for the soap
        $params = new StdClass();
        $params->request = new StdClass();
        $params->request->Auth = $this->generateAuth(
            $customerId
        );

        //Set the customer details to their current values
        $params->request->Customer = $currentDetails;

        $params->request->Customer->Address->Line1 = $line1;
        $params->request->Customer->Address->Line2 = $line2;
        $params->request->Customer->Address->Line3 = $line3;
        $params->request->Customer->Address->Line4 = '';
        $params->request->Customer->Address->Postcode = $postcode;

        //Try send the soap
        try {
            $response = $soap->Update($params);
            if(config('app.env') !== 'production') {
                Log::info('Update address response', ['response' => json_encode($response)]);
            }

            if ($response !== false
                && !empty($response->UpdateResult)
                && !empty($response->UpdateResult->Updated)
            ) {

                ElectraWebServices::addCustomerEvent($customerId, 'BDCP - User Changed Policy Address', '', '');
            } else {
                Log::error('Failed to update customer address',
                    ['customer_id' => $customerId]);
            }
        } catch (\Exception $e) {
            ElectraWebServices::addCustomerEvent($customerId, 'BDCP - User tried, but failed to update address', '', '');
            Log::error($e->getMessage() . ' - Failed to update customer address',
                ['trace' => $e->getTraceAsString()]
            );
        }
    }
}
