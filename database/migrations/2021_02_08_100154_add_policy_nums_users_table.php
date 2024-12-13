<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPolicyNumsUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json("secondary_customer_ids")->default("[]")->after('customer_id');
            $table->json("policy_ids")->default("[]")->after("secondary_customer_ids");
            $table->string("surname")->default("")->after("name");
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
            $table->dropColumn("policy_ids");
            $table->dropColumn("secondary_customer_ids");
            $table->dropColumn("surname");
        });
    }
}
