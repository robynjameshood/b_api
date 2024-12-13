<?php

namespace App\Jobs;

use App\Quote;
use App\Services\ElectraApiService;
use App\Services\GetQuoteApiService;
use App\Services\PaymentHubService;
use App\Transaction;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use App\User;
use Rollbar\Rollbar;

class ProcessPaymentRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    /**
     * @var Transaction
     */
    private $transaction;

    /**
     * Create a new job instance.
     *
     * @param Transaction $transaction
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    /**
     * Execute the job.
     *
     * @param ElectraApiService $electraApiService
     * @return void
     * @throws Exception
     */
    public function handle(ElectraApiService $electraApiService, GetQuoteApiService $getQuoteApiService)
    {

        $mtaData = $cancellationData = [];
        $quoteDetails = $this->transaction->quote;

        try {
            $quoteJson = json_decode($this->transaction->quote->details);
            $policy_ids = [$quoteJson->request->contractId];
            if($this->transaction->quote->type=="MTA"){
                if ($quoteJson->request->addressChanged) {
                    $policy_ids = [];
                    $customer = $this->transaction->quote->user;
                    // quote doesn't have a user id created before new code deployed BDCP-254
                    if (!$customer) {
                        $customer = User::where('customer_id', $this->transaction->quote->customer_id)->first();
                    }
                    $customer_ids = array_merge($customer->secondary_customer_ids, [$customer->customer_id]);
                    $customer_ids = array_map('intval', $customer_ids);

                    foreach($customer->policy_ids as $customer_policy_ids){
                        foreach($customer_policy_ids as $policy_id){
                            $policy_ids[] = $policy_id;
                        }
                    }

                    $line2 = isset($quoteJson->request->address->Line2) ? $quoteJson->request->address->Line2 : $quoteJson->request->address->Line3;
                    $line3 = isset($quoteJson->request->address->Line3) && isset($quoteJson->request->address->Line2) ? $quoteJson->request->address->Line3 : "";

                    foreach($customer_ids as $customer_id) {
                        // Address MTA so just update the address
                        $electraApiService->updateAddress(
                            $customer_id,
                            $quoteJson->request->address->Line1,
                            $line2,
                            $line3,
                            $quoteJson->request->address->Postcode
                        );
                    }
                    $mtaData['mtaReason'] = 'Change of Address';
                } else {
                    $mtaData['mtaReason'] = 'Change of Vehicle';
                }
                //Get the new risk quote details
                $currentRiskQuoteId = null;
                foreach ($quoteJson->result->quote_inputs as $quote_input) {
                    if ($quote_input->name == 'currentRiskQuoteId') {
                        $currentRiskQuoteId = $quote_input->pivot->value;
                    }
                }

                if (is_null($currentRiskQuoteId)) {
                    Log::error('Failed to get current risk quote id when processing MTA', [
                        'current_risk_quote_id' => $currentRiskQuoteId
                    ]);

                    $quoteDetails->update(['completed' => -1]);
                    throw new Exception('Failed to get current risk quote id when processing MTA');
                }

                $currentRiskQuote = $getQuoteApiService->getQuote($currentRiskQuoteId);
                $currentRiskData = $currentRiskQuote->data ?? null;

                if (is_null($currentRiskData) || (!isset($currentRiskData->gross) || !isset($currentRiskData->net_to_insurer))) {
                    Log::error('Failed to retrieve current risk quote for MTA processing', [
                        'current_risk_quote_id' => $currentRiskQuoteId,
                        'current_risk_quote' => $currentRiskQuote,
                        'current_risk_data' => $currentRiskData
                    ]);

                    $quoteDetails->update(['completed' => -1]);
                    throw new Exception('Failed to retrieve current risk quote for MTA processing');
                }

            }
            // Normal MTA so update the risk

            foreach($policy_ids as $policy_id) {

                if($this->transaction->quote->type=="MTA"){
                    $mtaData['sessionID'] = Uuid::uuid4()->toString();

                    $mtaData['reference'] = $policy_id;
                    $mtaData['referenceType'] = "ContractID";
                    $mtaData['transactionType'] = "Midterm Adjustment";
                    $mtaData['paymentDate'] = date("c", time());
                    $mtaData['effectiveDate'] = $quoteJson->request->risk->effectiveDate;
                    $mtaData['paymentType'] = "WP";

                    $mtaData['addons'] = null;
                    $mtaData['expiryDate'] = $quoteJson->request->expiryDate;

                    $mtaData['totPremTax'] = $quoteJson->result->gross;
                    $mtaData['totTax'] = $quoteJson->result->tax;
                    $mtaData['taxPct'] = $quoteJson->result->tax_rate;
                    $mtaData['totPremDue'] = $quoteJson->result->gross;
                    $mtaData['netRate'] = number_format(bcadd($quoteJson->result->net_to_insurer, $quoteJson->result->tax, 10), 2);
                    $mtaData['commission'] = $quoteJson->result->commission;
                    $mtaData['mtaFap'] = $currentRiskData->gross;
                    $mtaData['mtaNetFap'] = number_format(bcadd($currentRiskData->net_to_insurer, $currentRiskData->tax, 10), 2);
                    $mtaData['agentPrem'] = "0.00";

                    $mtaData['distSystem'] = "retail";
                    $mtaData['insurer'] = $quoteJson->insurer;

                    $cover['type'] = $quoteJson->request->risk->coverType;
                    $cover['range'] = $quoteJson->request->risk->coverRange;
                    $cover['home'] = $quoteJson->request->risk->homeStart;
                    $cover['excess'] = $quoteJson->request->risk->excess;

                    $riskDetails['vehicles'] = $quoteJson->request->risk->vehicles;
                    $riskDetails['persons'] = $quoteJson->request->risk->drivers;

                    $mtaData['payment'] = $this->transaction->amount;
                    $mtaData['charges'] = [];

                    foreach($quoteJson->result->fee_campaigns as $campaign){
                        $mtaData['charges'][] = [
                            'amount' => $campaign->quote_campaign_amount,
                            'description' => $campaign->description
                        ];
                    }

                    if($quoteJson->request->contractId != $policy_id){
                        $mtaData['payment'] = 0.00;
                        $mtaData['charges'] = null;

                        $polDetails = (new ElectraApiService)->policySearch(['id' => $policy_id]);
                        $polDetails = $polDetails->data[0];

                        if($polDetails->state == 0) {
                            continue;
                        }

                        $mtaData['expiryDate'] = $polDetails->policy_expiry_date;

                        $cover['type'] = $polDetails->cover->type;
                        $cover['range'] = $polDetails->cover->range;
                        $cover['home'] = $polDetails->cover->home;
                        $cover['excess'] = $polDetails->cover->excess;

                        $riskDetails['vehicles'] = $polDetails->detailed_vehicles;
                        $riskDetails['persons'] = $polDetails->detailed_members;

                    }
                    $riskDetails['cover'] = $cover;
                    $mtaData['riskDetails'] = $riskDetails;

                    $electraApiService->postMTA($mtaData);
                }else{ // Cancellation
                    $cancellationData['sessionID'] = Uuid::uuid4()->toString();

                    $cancellationData['reference'] = $policy_id;
                    $cancellationData['referenceType'] = "ContractID";
                    $cancellationData['transactionType'] = "Cancellation";
                    $cancellationData['paymentDate'] = date("c", time());
                    $cancellationData['effectiveDate'] = $quoteJson->request->effectiveDate;
                    $cancellationData['paymentType'] = "WP";

                    $cancellationData['expiryDate'] = $quoteJson->request->policy->Period->ExpiryDate;

                    $cancellationData['totPremTax'] = $quoteJson->result->gross;
                    $cancellationData['totTax'] = $quoteJson->result->tax;
                    $cancellationData['taxPct'] = $quoteJson->result->tax_rate;
                    $cancellationData['totPremDue'] = $quoteJson->result->gross;
                    $cancellationData['netRate'] = number_format(bcadd($quoteJson->result->net_to_insurer, $quoteJson->result->tax, 10), 2);
                    $cancellationData['commission'] = $quoteJson->result->commission;
                    $cancellationData['agentPrem'] = "0.00";

                    $cancellationData['distSystem'] = "retail";
                    $cancellationData['insurer'] = $quoteJson->insurer;

                    $cancellationData['payment'] = $this->transaction->amount;
                    $cancellation['source']=2; //added hard coded
                    $cancellation['reason']=(string)$quoteJson->request->cancellationReason;
                    $cancellation['reason_text']=$quoteJson->request->cancellationReasonText;
                    $cancellation['claims_in_period']=false; //added hard coded
                    $cancellation['return_premium']=12.00; //added hard coded
                    $cancellation['insurer_charge']=0.00; //added hard coded

                    $cancellationData['charges'] = [];

                    foreach($quoteJson->result->fee_campaigns as $campaign){
                        $cancellationData['charges'][] = [
                            'amount' => $campaign->quote_campaign_amount,
                            'description' => $campaign->description
                        ];
                    }

                    if($quoteJson->request->contractId != $policy_id){
                        $cancellationData['payment'] = 0.00;
                        $cancellationData['charges'] = null;

                        $polDetails = (new ElectraApiService)->policySearch(['id' => $policy_id]);
                        $polDetails = $polDetails->data[0];

                        if($polDetails->state == 0) {
                            continue;
                        }
                    }
                    $cancellationData['cancellation'] = $cancellation;
                    $electraApiService->postCancellation($cancellationData);
                }

            }

            //Purchase the quote in Quote Hero
            $getQuoteApiService->purchaseQuote($this->transaction->quote->external_quote_id);

        } catch (Exception $e) {
            Log::error('Failed to process '.$this->transaction->quote->type, ['data' => !empty($mtaData) ? $mtaData : $cancellationData, 'transaction' => $this->transaction->toArray(), 'exception' => $e->getMessage()]);

            $quoteDetails->update(['completed' => -1]);
            throw new Exception('Failed to process ' .$this->transaction->quote->type);
        }

        $quoteDetails->update(['completed' => 1, 'completed_at' => now()]);

    }
}
