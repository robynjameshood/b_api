<?php

namespace Tests\Unit;

use App\BarbaraWebServices;
use Tests\TestCase;

class GetCustomerPaymentCardsTest extends TestCase
{
    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function testExample()
    {
        $barbaraWebServices = new BarbaraWebServices();

        $customerCards = $barbaraWebServices->getCustomerPaymentCards(1073648);

        var_dump($customerCards);

        $this->assertIsArray($customerCards);
    }
}
