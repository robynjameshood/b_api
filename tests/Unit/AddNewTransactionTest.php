<?php

namespace Tests\Unit;

use App\BarbaraWebServices;
use Tests\TestCase;

class AddNewTransactionTest extends TestCase
{
    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function testExample()
    {
        $barbaraWebServices = new BarbaraWebServices();

        $cardUpdate = $barbaraWebServices->addNewTransaction(
            1,
            1,
            'HG11JD',
            'TEST-123456',
            '15',
            'TEST',
            'TEST',
            'TEST',
            'TEST',
            'J TEST',
            '1234',
            '2020-01-01',
            'VISA'
            );

        var_dump($cardUpdate);

        $this->assertTrue($cardUpdate);
    }

}
