<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Marketing;

class MarketingsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        Marketing::create([
            'customer_id' => '1',
            'sms_marketing' => '0',
            'email_marketing' => '0',
            'phone_marketing' => '0',
            'post_marketing' => '0',
        ]);
    }
}
