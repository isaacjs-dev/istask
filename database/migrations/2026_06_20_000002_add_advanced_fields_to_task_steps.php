<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Checklist avançado: responsável e prioridade por item (due_date já existe). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_steps', function (Blueprint $table) {
            $table->string('assignee', 120)->nullable()->after('status');
            $table->string('priority', 20)->nullable()->after('assignee');
        });
    }

    public function down(): void
    {
        Schema::table('task_steps', function (Blueprint $table) {
            $table->dropColumn(['assignee', 'priority']);
        });
    }
};
