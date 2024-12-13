<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id')->unique();
            $table->string('name');
            $table->string('postcode');
            $table->string('customer_id');
            $table->string('vehicle_reg');
            $table->string('email', 150)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('api_token', 60)->unique()->nullable();
            $table->string('login_token', 60)->unique()->nullable();
            $table->string('token_expiry', 60)->unique()->nullable();
            $table->string('policy_ref');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
