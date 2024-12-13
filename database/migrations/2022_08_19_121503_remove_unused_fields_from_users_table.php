<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RemoveUnusedFieldsFromUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['name','surname','postcode','vehicle_reg','policy_ref']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default("")->after('id');
            $table->string("surname")->default("")->after("name");
            $table->string('postcode')->default("")->after('surname');
            $table->string('vehicle_reg')->default("")->after('policy_ids');
            $table->longText('policy_ref')->after('token_expiry');
        });
    }
}
