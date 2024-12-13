<?php


namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Exception;
use Illuminate\Support\Facades\Log;

class PaymentHubService
{
    private $http;

    public function __construct()
    {
        $this->http = new Client(['base_uri' => config('app.payment_hub_url')]);
    }

    public function newOrder($request)
    {
        try {
            $response = $this->http->post(
                'newOrder',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.payment_hub_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($request)
                ]
            );
        } catch (RequestException $error) {
            throw $error;
        }

        if ($response->getStatusCode() != 200 && $response->getStatusCode() != 201) {
            Log::error('Failed to get Payment Hub URL', ['response' => $response->getBody()->getContents(), 'status' => $response->getStatusCode()]);
            return ['response' => $response];
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }

    public function transactionSearch($request)
    {
        try {
            $response = $this->http->post(
                'transactions/search',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.payment_hub_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($request)
                ]
            );
        } catch (RequestException $error) {
            throw $error;
        }

        if ($response->getStatusCode() != 200 && $response->getStatusCode() != 201) {
            Log::error('Failed Payment Hub transaction search', ['request' => $request, 'response' => $response->getBody()->getContents(), 'status' => $response->getStatusCode()]);
            return ['response' => $response];
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }

    public function refundTransaction($transactionId, $request)
    {
        try {
            $response = $this->http->post(
                'transactions/' . $transactionId . '/refund',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.payment_hub_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($request)
                ]
            );
        } catch (RequestException $error) {
            throw $error;
        }

        if ($response->getStatusCode() != 200 && $response->getStatusCode() != 201) {
            Log::error('Failed Payment Hub refund', ['transactionId' => $transactionId, 'request' => $request, 'response' => $response->getBody()->getContents(), 'status' => $response->getStatusCode()]);
            throw new \Exception('Failed to refund');
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }
}
