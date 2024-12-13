<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\User;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = \Faker\Factory::create();
        $password = Hash::make('demormc');

        User::create([
            'name' => 'Administrator',
            'email' => 'admin@test.com',
            'vehicle_reg' => 'RMC 1AA',
            'customer_id' => '1',
            'postcode' => 'M1 1AA',
            'policy_ref'=> 'TEST-000013',
            'password' => $password,
        ]);
    }
}
