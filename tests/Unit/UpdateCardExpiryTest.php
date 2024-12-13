<?php

namespace Tests\Unit;

use App\BarbaraWebServices;
use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UpdateCardExpiryTest extends TestCase
{
    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function testExample()
    {
        $barbaraWebServices = new BarbaraWebServices();

        $cardUpdate = $barbaraWebServices->updateCustomerCardExpiry(1, 1, 466208, '2021', '08');

        var_dump($cardUpdate);

        $this->assertTrue($cardUpdate);
    }
}
