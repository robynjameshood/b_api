<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPaymentRequest;
use App\Quote;
use App\Services\PaymentHubService;
use App\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function paymentHubSetup(PaymentHubService $paymentHubService, Request $request)
    {
        // Create a validator
        $requestData = $request->all();
        $validator = Validator::make(
            $requestData,
            [
                'data.quote_id' => 'required|integer|exists:quotes,id',
                'data.description' => 'required|string',
                'data.order_content' => 'required|string',
                'data.redirect_url' => 'required_without_all:data.redirect_callback|url|nullable',
                'data.redirect_callback' => 'required_without_all:data.redirect_url|boolean|nullable',
                'shipping_address.shipping_address_line_1' => 'required|string',
                'shipping_address.shipping_address_line_2' => 'string|nullable',
                'shipping_address.shipping_address_line_3' => 'string|nullable',
                'shipping_address.shipping_postal_code' => 'required|string',
                'shipping_address.shipping_city' => 'required|string',
                'shipping_address.shipping_state' => 'string|nullable',
                'shipping_address.shipping_country_code' => 'required|string',
                'billing_address.billing_address_line_1' => 'required|string',
                'billing_address.billing_address_line_2' => 'string|nullable',
                'billing_address.billing_address_line_3' => 'string|nullable',
                'billing_address.billing_postal_code' => 'required|string',
                'billing_address.billing_city' => 'required|string',
                'billing_address.billing_state' => 'string|nullable',
                'billing_address.billing_country_code' => 'required|string',
                'references.customer_id' => 'required|integer',
                'references.policy_id' => 'required|integer',
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

        $requestData['data']['merchant_code'] = 'NCIECOM';
        $requestData['data']['order_code'] = (config('app.env') == 'UAT' ? 'UAT' : '') .
            'BDCP_' . $requestData['references']['customer_id'] . '_' .
            $requestData['references']['policy_id'] . '_' . rand(10000, 99999).time();

        $requestData['data']['order_notification_url'] = config('app.url') . config('app.payment_order_notification_url');
        $requestData['data']['order_notification_key'] = 'Bearer ' . config('app.payment_order_notification_key');

        $quoteJson = json_decode($quote->details);

        $requestData['data']['amount_taken'] = $quoteJson->result->total;

        $newTransaction = new Transaction();
        $newTransaction->quote_id = $quote->id;
        $newTransaction->amount = $quoteJson->result->total;
        $newTransaction->vendor_tx_code = $requestData['data']['order_code'];
        $newTransaction->security_key = 'NONE';
        $newTransaction->save();

        $response = $paymentHubService->newOrder($requestData);

        return Response::json($response,200);

    }

    public function processPayment(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'order_code' => 'required',
                'status' => 'required'
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $transaction = Transaction::query()->where('vendor_tx_code', $request->order_code)->first();
        ProcessPaymentRequest::dispatch($transaction);
    }
}
