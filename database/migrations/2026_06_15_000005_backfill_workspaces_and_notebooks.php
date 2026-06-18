<?php

use App\Models\Note;
use App\Models\Notebook;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;

/**
 * Migra os dados existentes para a nova hierarquia, sem perder nada: cada usuário
 * que já tem projetos/notas ganha uma Área "Pessoal" e um caderno "Geral", e os
 * projetos/notas atuais são vinculados a eles. Idempotente (só preenche o que
 * estiver nulo / o que ainda não existir).
 */
return new class extends Migration
{
    public function up(): void
    {
        User::query()->orderBy('id')->each(function (User $user) {
            $hasProjects = Project::withTrashed()->where('user_id', $user->id)->exists();
            $hasNotes = Note::withTrashed()->where('user_id', $user->id)->exists();
            if (! $hasProjects && ! $hasNotes) {
                return; // usuário sem conteúdo legado — nada a migrar
            }

            $workspace = Workspace::where('owner_id', $user->id)->orderBy('position')->first()
                ?? Workspace::create(['owner_id' => $user->id, 'name' => 'Pessoal', 'position' => 0]);

            Project::withTrashed()->where('user_id', $user->id)->whereNull('workspace_id')
                ->update(['workspace_id' => $workspace->id]);

            $notebook = Notebook::where('workspace_id', $workspace->id)->orderBy('position')->first()
                ?? Notebook::create(['workspace_id' => $workspace->id, 'name' => 'Geral', 'position' => 0]);

            Note::withTrashed()->where('user_id', $user->id)->whereNull('notebook_id')
                ->update(['notebook_id' => $notebook->id]);
        });
    }

    public function down(): void
    {
        // Reversível pelo desfazimento das colunas/tabelas nas migrations anteriores.
    }
};
