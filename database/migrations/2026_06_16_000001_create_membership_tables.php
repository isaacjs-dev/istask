<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compartilhamento (Fase 2): membros de Áreas de Trabalho, Projetos e Cadernos,
 * com permissão edit|view. Espelha o padrão de `note_collaborators`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['workspace' => 'workspaces', 'project' => 'projects', 'notebook' => 'notebooks'] as $key => $table) {
            Schema::create($key . '_members', function (Blueprint $t) use ($key, $table) {
                $t->id();
                $t->foreignId($key . '_id')->constrained($table)->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('permission', 16)->default('edit');
                $t->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique([$key . '_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notebook_members');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('workspace_members');
    }
};
