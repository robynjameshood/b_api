<?php
/**
 * Created by PhpStorm.
 * User: Chris.Williams
 * Date: 18/01/2019
 * Time: 16:02
 */

namespace App;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class BarbaraWebServices
{
    private $_guzzleClient = null;
    public function __construct()
    {
        $this->_guzzleClient = new Client(
            [
                'base_uri' => env('BARBARA_API_URL'),
                'headers' => [
                    'Authorization' => 'Bearer ' . env('BARBARA_COMMUNICATIONS_API_KEY'),
                    'Accept' => 'application/json, application/pdf',
                ]
            ]
        );
    }

    /**
     * @param $customerId Id of the customer
     * @param $contractId Electra contract_id
     * @return array Response of request either error information or list of all communications sent to customer
     */
    public function getAllCommunicationsForCustomer($customerId, $contractId )
    {
        $packsToShow = Pack::where('show_to_user', true)->pluck('pack_id')->implode(',');

        $response = $this->_guzzleClient->get(
            '/api/inc/routes/communications/previous/getAllCommunicationsForUser.php',
            [
                'query' =>
                    [
                        'customer' => $customerId,
                        'pack_ids' => $packsToShow
                    ]
            ]
        );

        $communications = json_decode($response->getBody()->getContents(),true);
        if (is_array($communications) && count($communications) > 0) {
            if(isset($communications['communications'])){
                foreach($communications['communications'] as $key => $communication) {
                    $pack = Pack::findPackId($communication['pack_id']);
                    $communications['communications'][$key]['title'] = $pack['title'];
                    $communications['communications'][$key]['contract'] = $contractId;
                }
            }
        } else {
            $communications = [];
        }

        return $communications;
    }

    /**
     * @param $customerId Id of the customer
     * @param $ordinal Ordinal of the policy
     * @param $contractId Electra contract_id
     * @return array Response of request either error information or list of communications for the customers policy
     */
    public function getAllCommunicationsForCustomerPolicy($customerId,$ordinal,$contractId)
    {
        $packsToShow = Pack::where('show_to_user', true)->pluck('pack_id')->implode(',');
        $response = $this->_guzzleClient->get(
            '/api/inc/routes/communications/previous/getAllCommunicationsForUserPolicy.php',
            [
                'query' =>
                    [
                        'customer' => $customerId,
                        'policy' => $ordinal,
                        'pack_ids' => $packsToShow
                    ]
            ]
        );

        $communications = json_decode($response->getBody()->getContents(),true);
        if (is_array($communications) && count($communications) > 0) {
            foreach($communications['communications'] as $key => $communication) {
                $pack = Pack::findPackId($communication['pack_id']);
                $communications['communications'][$key]['title'] = $pack['title'];
                $communications['communications'][$key]['contract'] = $contractId;
            }
        } else {
            $communications = [];
        }
        return $communications;
    }

    /**
     * @param $customerId Id of the customer
     * @param $ordinal Ordinal of the policy
     * @param $communicationId Id of the communication be retrieved
     * @param $communicationType Type of commuincation to be retrieved
     * @return mixed json if any errors returned, file stream of returned pdf
     */
    public function getCustomerCommunication($customerId,$ordinal,$communicationId,$communicationType)
    {
        $response = $this->_guzzleClient->get(
            '/api/inc/routes/communications/previous/getUserCommunication.php',
            [
                'query' => [
                    'customer' => $customerId,
                    'policy' => $ordinal,
                    'communication_id' => $communicationId,
                    'type' => $communicationType
                ]
            ]
        );

        return $response->getHeader('Content-Type')[0] === 'application/json' ? json_decode($response->getBody()->getContents(),true) : $response->getBody()->getContents();
    }

    public function addNewTransaction($customerId, $policyId, $amountTaken, $vpsTxId, $vendorTxCode, $securityKey,
                                      $txAuthNo, $cardNumber, $cardExpiry, $cardType)
    {
        $formattedCardExpiry = '20'.substr($cardExpiry, -2).'-'.substr($cardExpiry, 0, 2).'-01';

        $response = $this->_guzzleClient->post(
            '/api/inc/routes/main/add-new-transaction.php',
            [
                'form_params' =>
                    [
                        'customerId' => $customerId,
                        'policyId' => $policyId,
                        'amountTaken' => $amountTaken,
                        'vpsTxId' => $vpsTxId,
                        'vendorTxCode' => $vendorTxCode,
                        'securityKey' => $securityKey,
                        'txAuthNo' => $txAuthNo,
                        'cardNumber' => $cardNumber,
                        'cardExpiry' => $formattedCardExpiry,
                        'cardType' => $cardType
                    ]
            ]
        );


        if ($response->getHeader('Content-Type')[0] === 'application/json') {
            $data = json_decode($response->getBody()->getContents(),true);
        } else {
            $data = $response->getBody()->getContents();
        }

        if (isset($data['status']) && $data['status'] == 0) {
            $returnData = true;
        } else {
            $returnData = $data['error'];
        }

        return $returnData;
    }


    public function getCustomerPaymentCards($customerId, $activeCards = false)
    {
        $response = $this->_guzzleClient->get(
            '/api/inc/routes/main/customer-cards.php',
            [
                'query' =>
                    [
                        'customerId' => $customerId,
                        'activeCards' => $activeCards
                    ]
            ]
        );


        if ($response->getHeader('Content-Type')[0] === 'application/json') {
            $data = json_decode($response->getBody()->getContents(),true);
        } else {
            $data = $response->getBody()->getContents();
        }

        $returnData = [];

        if (isset($data['status']) && $data['status'] == 0) {
            $returnData = $data['card_details'];
        }

        return $returnData;
    }

    public function refundPaymentForTransaction($customerId, $policyId, $transactionId, $amount)
    {
        $response = $this->_guzzleClient->get(
            '/api/inc/routes/main/customer-payment-refund.php',
            [
                'query' =>
                    [
                        'customerId' => $customerId,
                        'policyId' => $policyId,
                        'transactionId' => $transactionId,
                        'amount' => $amount
                    ]
            ]
        );


        if ($response->getHeader('Content-Type')[0] === 'application/json') {
            $data = json_decode($response->getBody()->getContents(),true);
        } else {
            $data = $response->getBody()->getContents();
        }

        $returnData = false;

        if (isset($data['status']) && $data['status'] == 0) {
            $returnData = true;
        }

        return $returnData;
    }

    public function updateCustomerCardExpiry($customerId, $policyId, $transactionId, $updatedCardExpiryYear, $updatedCardExpiryMonth)
    {
        $response = $this->_guzzleClient->get(
            '/api/inc/routes/main/update-card-expiry.php',
            [
                'query' =>
                    [
                        'customerId' => $customerId,
                        'policyId' => $policyId,
                        'transactionId' => $transactionId,
                        'updatedCardExpiryYear' => $updatedCardExpiryYear,
                        'updatedCardExpiryMonth' => $updatedCardExpiryMonth
                    ]
            ]
        );

        if ($response->getHeader('Content-Type')[0] === 'application/json') {
            $data = json_decode($response->getBody()->getContents(),true);
        } else {
            $data = $response->getBody()->getContents();
        }

        $returnData = false;

        if (isset($data['status']) && $data['status'] == 0) {
            $returnData = true;
        }

        return $returnData;
    }

    public function sendTokenLoginLinkEmail(int $packId, int $policyId, string $loginLink, string $email, int $customerId, string $expiresIn) {

        $response = $this->_guzzleClient->post(
            '/api/inc/routes/communications/generate/remote-adhoc.php',
            [
                'form_params' => [
                    'pack' => $packId,
                    'guid' =>  Uuid::uuid4()->toString(),
                    'placeholders' => json_encode([ "*|CUSTOMER_EMAIL|*" => $email ,
                        "*|CUSTOMER_CONTACT_EMAIL|*" => 1,
                        "*|LOGIN_LINK|*" => $loginLink,
                        "*|ENV_URL|*" =>  config('app.ui_url'),
                        "*|CUSTOMER_RECIPIENT|*" => "Rescue My Car Customer",
                        "*|SEND|*" => 1,
                        "*|CUSTOMER_NAME|*" => "Rescue My Car Customer",
                        "*|EXPIRES_IN|*" => $expiresIn
                    ]),
                    'riskId' => $policyId,
                    'customer' => $customerId
                ]
            ]
        );

        return $response->getHeader('Content-Type')[0] === 'application/json' ? json_decode($response->getBody()->getContents(),true) : $response->getBody()->getContents();
    }

    public function sendElectraOnePageReport(string $date, string $table) {

        $packId = (int)config('app.electra_report_pack_id') ?? 0;
        $email = config('app.electra_report_email');

        $response = $this->_guzzleClient->post(
            '/api/inc/routes/communications/generate/remote-adhoc.php',
            [
                'form_params' => [
                    'pack' => $packId,
                    'guid' =>  Uuid::uuid4()->toString(),
                    'placeholders' => json_encode([
                        "*|REPORT_DATE|*" => $date,
                        "*|REPORT_DATA|*" => $table,
                        "*|SEND|*" => 1,
                        "*|CUSTOMER_EMAIL|*" => $email,
                        "*|CUSTOMER_CONTACT_EMAIL|*" => 1,
                    ]),
                    'riskId' => -1,
                    'customer' => -1,
                ]
            ]
        );

        return $response->getHeader('Content-Type')[0] === 'application/json' ? json_decode($response->getBody()->getContents(),true) : $response->getBody()->getContents();
    }

}
