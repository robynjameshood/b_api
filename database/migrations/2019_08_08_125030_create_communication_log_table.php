<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCommunicationLogTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('communication_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->enum('type', ['sms', 'email']);
            $table->string('email_address');
            $table->dateTime('sent_at');
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
        Schema::dropIfExists('communication_log');
    }
}
