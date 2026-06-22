<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Links externos e relacionamentos entre tarefas (Frente B5 do PRD). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('label', 160)->nullable();
            $table->timestamps();
        });

        Schema::create('task_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('type', 20)->default('relacionada'); // relacionada|bloqueia|depende
            $table->timestamps();
            $table->unique(['task_id', 'related_task_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_relations');
        Schema::dropIfExists('task_links');
    }
};
