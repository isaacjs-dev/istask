<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->string('status')->default('pendente');      // pendente|andamento|aguardando|concluido|cancelado
            $table->string('priority')->default('media');        // urgente|alta|media|baixa
            $table->string('category')->default('Geral');
            $table->string('section')->nullable();               // override da seção na visão em lista
            $table->date('due_date')->nullable();
            $table->string('responsible')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
