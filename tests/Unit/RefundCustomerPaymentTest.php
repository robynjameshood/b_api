<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\BarbaraWebServices;


class RefundCustomerPaymentTest extends TestCase
{
    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function testExample()
    {
        $barbaraWebServices = new BarbaraWebServices();

        $paymentRefund = $barbaraWebServices->refundPaymentForTransaction(1, 1, 466208, 5);

        var_dump($paymentRefund);

        $this->assertTrue($paymentRefund);
    }

}
