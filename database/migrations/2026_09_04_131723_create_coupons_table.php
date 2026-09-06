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
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('discount_type',['percentage', 'fixed']);
            $table->decimal('discount_value', 10, 2);
            $table->foreignId('category_id')->nullable()->constrained();
            $table->integer('usage_limit_per_user')->nullable();
            $table->integer('total_usage_limit')->nullable();
            $table->enum('status', ['active', 'inactive']);
            $table->date('expiry_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
