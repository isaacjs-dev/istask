<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_entry_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diary_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor')->default('Sistema');
            $table->string('action');                  // created | movement | status_change | time_adjusted | ...
            $table->text('description')->nullable();
            $table->json('meta')->nullable();          // antes/depois — append-only, nunca sobrescreve
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_entry_histories');
    }
};
