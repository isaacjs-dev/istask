<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 da revisão geral: metadados de acompanhamento em Áreas de Trabalho e
 * Projetos (descrição, datas, status, prioridade). Tudo aditivo e nullable/with
 * default — não altera os registros existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->date('start_date')->nullable()->after('description');
            $table->date('end_date')->nullable()->after('start_date');
            $table->string('status', 20)->default('ativo')->after('end_date'); // ativo|pausado|concluido|arquivado
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('description');
            $table->date('due_date')->nullable()->after('start_date');
            $table->date('completed_at')->nullable()->after('due_date');
            $table->string('status', 20)->default('nao_iniciado')->after('completed_at'); // nao_iniciado|em_andamento|concluido|pausado
            $table->string('priority', 12)->default('media')->after('status'); // urgente|alta|media|baixa
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['description', 'start_date', 'end_date', 'status']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'due_date', 'completed_at', 'status', 'priority']);
        });
    }
};
