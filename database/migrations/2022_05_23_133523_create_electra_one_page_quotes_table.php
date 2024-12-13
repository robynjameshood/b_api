<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateElectraOnePageQuotesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('electra_one_page_quotes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('quote_hero_id');
            $table->string('policy_number');
            $table->dateTime('effective_date');
            $table->float('commission', 8, 2);
            $table->float('aprp', 8, 2);
            $table->float('annual_difference', 8, 2);
            $table->float('admin_charge_discount', 8, 2);

            $table->timestampsTz();

            $table->index('created_at');
            $table->index('updated_at');
            $table->index('policy_number', 'effective_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('electra_one_page_quotes');
    }
}
