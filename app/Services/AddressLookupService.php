<?php
/**
 * Created by PhpStorm.
 * User: Chris.Williams
 * Date: 20/06/2019
 * Time: 13:49
 */

namespace App\Services;

use Facades\App\ElectraWebServices;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Exception;
use Illuminate\Support\Facades\Log;
use stdClass;

class AddressLookupService
{
    private $http;

    public function __construct()
    {
        $this->http = new Client(['base_uri' => config('app.postcode_lookup_url')]);
    }

    public function addressLookup($number, $postcode)
    {
        $address = new stdClass();
        $address->line1 = '';
        $address->line2 = '';
        $address->line3 = '';
        $address->postcode = $postcode;

        try {
            $response = $this->http->get(
                'Find/v2.10/json.ws?Key='.config('app.postcode_lookup_key').'&SearchTerm='.urlencode($number.' '.$postcode).'&LastId=&SearchFor=Everything&Country=GBR&LanguagePreference=EN&MaxSuggestions=&MaxResults='
            );

            $json = json_decode($response->getBody()->getContents());

            if (empty($json) || count($json) == 0) {
                return false;
            }


            $response = $this->http->get(
                'Retrieve/v2.10/json.ws?Key='.config('app.postcode_lookup_key').'&id='.$json[0]->Id
            );

            $json = json_decode($response->getBody()->getContents());

            if (empty($json) || count($json) == 0) {
                return false;
            }

            $fullAddress = $json[0];
            $postcode = str_replace(' ', '', $fullAddress->PostalCode);
            $postcode = substr($postcode, 0, -3).' '.substr($postcode, -3);

            $address->line1 = $fullAddress->Line1;
            $address->line3 = $fullAddress->City;
            if ($fullAddress->Line2) {
                $address->line2 = $fullAddress->Line2;
            } elseif ($fullAddress->District) {
                $address->line2 = $fullAddress->District;
            } else {
                $address->line2 = ' ';
            }
            $address->postcode = $postcode;

        } catch (RequestException $error) {
            Log::error('Failed address lookup', ['error' => $error, 'number' => $number, 'postcode' => $postcode]);
            return false;
        } catch (Exception $error) {
            Log::error('Failed to process address', ['error' => $error, 'number' => $number, 'postcode' => $postcode]);
            return false;
        }

        return $address;
    }
}
