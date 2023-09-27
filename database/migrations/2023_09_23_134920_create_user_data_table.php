<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserdataTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_data', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->unsignedBigInteger('user_id')
                ->index()
                ->nullable();
            $table->unsignedBigInteger('gender_id')
                ->index()
                ->nullable();
            $table->unsignedBigInteger('country_id')
                ->index()
                ->nullable();
            $table->unsignedBigInteger('dni_id')
                ->index()
                ->nullable();
            $table->unsignedBigInteger('plan_internet_id')
                ->index()
                ->nullable();
            $table->unsignedBigInteger('status_internet_id')
                ->index()
                ->nullable();
            $table->string('names');
            $table->string('lastname');
            $table->string('address');
            $table->string('dni');
            $table->string('email');
            $table->string('phone');
            $table->string('birthday');
            $table->boolean('active')
                ->default(true);
            $table->boolean('status')
                ->default(false);
            
            $table->timestamps();
            
            $table->foreign('user_id')
                ->references('id')
                ->on('users');
            $table->foreign('gender_id')
                ->references('id')
                ->on('genders');
            $table->foreign('country_id')
                ->references('id')
                ->on('countries');
            $table->foreign('dni_id')
                ->references('id')
                ->on('dnis');
            $table->foreign('plan_internet_id')
                ->references('id')
                ->on('internet_plan');
            $table->foreign('status_internet_id')
                ->references('id')
                ->on('internet_status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_data', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            
            $table->dropForeign(['gender_id']);
            $table->dropColumn('gender_id');

            $table->dropForeign(['country_id']);
            $table->dropColumn('country_id');

            $table->dropForeign(['dni_id']);
            $table->dropColumn('dni_id');
        });
        Schema::dropIfExists('user_data');
    }
};
