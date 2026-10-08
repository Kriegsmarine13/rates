<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3)->comment('currency code');
            $table->decimal('rate', 10, 4)->comment('flat rate against RUR');
            $table->date('date')->comment('date of the rate');
            $table->integer('nominal', false, true)->comment('nominal rate');

            $table->index(['currency', 'date'], 'rates_currency_date_index');
            $table->unique(['currency', 'date'], 'rates_currency_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rates');
    }
};
