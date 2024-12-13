<?php

namespace Database\Seeders;

use App\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DocumentType::updateOrCreate(['name' => 'Certificate/Schedule Form'],['display_name'=>'Certificate/Schedule Form']);
        DocumentType::updateOrCreate(['name' => 'Proposal/Statement Of Fact Form'],['display_name' => 'Proposal/Statement Of Fact Form']);
        DocumentType::updateOrCreate(['name' => 'Policy IPID'],['display_name' => 'Policy IPID']);
        DocumentType::updateOrCreate(['name' => 'NCI Motor TOBA'],['display_name' => 'Terms of Business']);
        DocumentType::updateOrCreate(['name' => 'Policy Booklet'],['display_name' => 'Policy Booklet']);
    }
}
