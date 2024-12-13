<?php


namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Exception;
use Illuminate\Support\Facades\Log;

class GetQuoteApiService
{
    private $http;

    public function __construct()
    {
        $this->http = new Client(['base_uri' => config('app.quote_hero_api_url')]);
    }

    public function mtaQuote($request)
    {
        try {
            $response = $this->http->post(
                'quote/mta',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.quote_hero_api_key'),
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
            Log::error('Failed to get mta quote', ['response' => $response]);
            throw new Exception('Failed to get mta quote', $response->getReasonPhrase(), $response->getStatusCode());
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }

    public function purchaseQuote($quoteId)
    {
        try {
            $response = $this->http->put(
                'quote/' . $quoteId . '/purchased',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.quote_hero_api_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ]
                ]
            );
        } catch (RequestException $error) {
            throw $error;
        }

        if ($response->getStatusCode() != 200) {
            Log::error('Failed to mark quote as purchased in Quote Hero', ['response' => $response]);
            throw new Exception('Failed to mark quote as purchased in Quote Hero', $response->getReasonPhrase(), $response->getStatusCode());
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }

    public function getQuote($quoteId) {
        try {
            $response = $this->http->get(
                'quote/' . $quoteId,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.quote_hero_api_key'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ]
                ]
            );
        } catch (RequestException $error) {
            throw $error;
        }

        if ($response->getStatusCode() != 200) {
            Log::error('Failed to retrieve quote', ['response' => $response]);
            throw new Exception('Failed to retrieve quote', $response->getReasonPhrase(), $response->getStatusCode());
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }

    public function cancellationQuote($request)
    {
        try {
            $response = $this->http->post(
                'quote/cancel',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . config('app.quote_hero_api_key'),
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
            Log::error('Failed to get cancellation quote', ['response' => $response]);
            throw new Exception('Failed to get cancellation quote', $response->getReasonPhrase(), $response->getStatusCode());
        }

        $result = json_decode($response->getBody()->getContents());

        return $result;
    }
}
