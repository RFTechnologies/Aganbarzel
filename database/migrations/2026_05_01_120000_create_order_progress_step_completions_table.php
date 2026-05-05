<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_progress_step_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_order_id');
            $table->string('step_key', 191);
            $table->timestampTz('completed_at');
            $table->timestamps();

            $table->unique(['user_id', 'shopify_order_id', 'step_key'], 'opsc_order_step_unique');
            $table->index(['user_id', 'shopify_order_id'], 'opsc_order_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_progress_step_completions');
    }
};
