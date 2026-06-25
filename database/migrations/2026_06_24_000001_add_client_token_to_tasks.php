<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chave de idempotência da criação de tarefas. O front gera um token único por
 * rascunho de "Nova tarefa"; reenvios do MESMO rascunho (duplo clique, retry de
 * rede) reaproveitam a tarefa já criada em vez de duplicá-la. O índice é único e
 * por token (não global): cada solicitação/usuário é independente — nunca bloqueia
 * a criação simultânea de outros usuários.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('client_token')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['client_token']);
            $table->dropColumn('client_token');
        });
    }
};
