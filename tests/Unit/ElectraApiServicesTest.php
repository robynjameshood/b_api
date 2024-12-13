<?php

namespace Tests\Unit;

use App\Services\ElectraApiService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ElectraApiServicesTest extends TestCase
{
    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function testExample()
    {
        $electraApiService = new ElectraApiService();

        $electraApiService->updateAddress(40, '12','13','14','hg11jd');
    }
}
