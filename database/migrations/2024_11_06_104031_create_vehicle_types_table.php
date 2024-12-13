<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('value')->nullable();
        });

        DB::table('vehicle_types')->insert(
            [
                [
                    'name' => 'Car',
                    'value' => 1
                ],
                [
                    'name' => 'Van',
                    'value' => 2
                ],
                [
                    'name' => 'LCV',
                    'value' => 2
                ],
                [
                    'name' => 'HCV',
                    'value' => 2
                ],
                [
                    'name' => 'Motorcycle',
                    'value' => 3
                ],
                [
                    'name' => 'Bike',
                    'value' => 3
                ]
            ]
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vehicle_types');
    }
};
