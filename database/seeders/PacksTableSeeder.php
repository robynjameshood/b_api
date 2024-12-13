<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Pack;

class PacksTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Pack::updateOrCreate([
            'pack_id' => '34',
            'title' => 'RMC Renewal Pack',
            'show_to_user' => '1'

        ]);
        Pack::updateOrCreate([
            'pack_id' => '37',
            'title' => 'RMC New Business PACK',
            'show_to_user' => '1'

        ]);
        Pack::updateOrCreate([
            'pack_id' => '44',
            'title' => 'RMC New Business PACK (Posted)',
            'show_to_user' => '1'

        ]);
    }
}
