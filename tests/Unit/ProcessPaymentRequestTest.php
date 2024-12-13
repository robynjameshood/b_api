<?php

namespace Tests\Unit;

use App\BarbaraWebServices;
use App\Jobs\ProcessPaymentRequest;
use App\Services\ElectraApiService;
use App\Transaction;
use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProcessPaymentRequestTest extends TestCase
{
    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function testExample()
    {
        // $transaction = Transaction::find(76);
        $transaction = Transaction::query()
            ->where('vendor_tx_code', 'BDCP_2020424_1_110931612968997')
            ->first();

        $processPaymentRequest = new ProcessPaymentRequest($transaction, ['VPSTxId' => 'dfgdsfgds','VendorTxCode' => 'BDCP_2020424_1_110931612968997','TxAuthNo' => '4563463','Last4Digits' => '1234','ExpiryDate' => '02/21','CardType' => 'VISA']);
        $barbaraWebservices = new BarbaraWebServices();
        $electraWebServices = new ElectraApiService();
        $processPaymentRequest->handle($electraWebServices);
        $this->assertTrue(true);
    }
}
