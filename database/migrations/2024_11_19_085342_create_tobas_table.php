<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tobas', function (Blueprint $table) {
            $table->id();
            $table->dateTimeTz('from_date');
            $table->string('url_location');
            $table->timestamps();
        });

        DB::table('tobas')->insert(
            [
                [
                    'from_date' => Carbon::parse('1970-01-01 00:00:00'),
                    'url_location' => 'https://static.rescuemycar.com/documents/Rescuemycar.com-UK-TOBA.pdf'
                ],
                [
                    'from_date' => Carbon::parse('2024-08-01 00:00:00'),
                    'url_location' => 'https://static.rescuemycar.com/documents/Rescuemycar.com+UK+TOBA+2024-08-01.pdf'
                ]
            ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tobas');
    }
};
