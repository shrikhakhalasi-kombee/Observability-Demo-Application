<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->decimal('total_price', 10, 2);
            $table->timestamps();

            $table->index('user_id', 'idx_orders_user_id');
            $table->index('status', 'idx_orders_status');
            $table->index('created_at', 'idx_orders_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
