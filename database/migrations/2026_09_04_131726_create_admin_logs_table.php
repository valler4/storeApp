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
    Schema::create('admin_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
        $table->string('admin_name_snapshot')->nullable();
        $table->string('action');
        $table->string('target_type')->nullable();
        $table->integer('target_id')->nullable();
        $table->text('details')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
    }
};
