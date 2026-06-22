<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paridade tarefa×nota (Frente B2 da revisão geral):
 * datas avançadas, recorrência, lembrete, arquivamento e etiquetas em tarefas.
 * Tudo aditivo — não altera dados existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('due_date');
            $table->unsignedInteger('estimated_minutes')->nullable()->after('start_date');
            $table->string('recurrence', 20)->default('none')->after('estimated_minutes');
            $table->dateTime('remind_at')->nullable()->after('recurrence');
            $table->dateTime('remind_fired_at')->nullable()->after('remind_at');
            $table->timestamp('archived_at')->nullable()->after('completed_at');
        });

        Schema::create('label_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['label_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_task');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'start_date', 'estimated_minutes', 'recurrence',
                'remind_at', 'remind_fired_at', 'archived_at',
            ]);
        });
    }
};
