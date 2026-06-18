<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unifica "categoria" e "projetos": para tarefas ainda no projeto "geral",
     * realinha project_id com base na categoria atual (mesmo mapeamento usado
     * pelo seeder), depois remove a coluna category.
     */
    public function up(): void
    {
        $toSlug = function (?string $category): string {
            $c = mb_strtolower((string) $category);
            if (str_contains($c, 'comunica')) return 'comunicacao';
            if (str_contains($c, 'integra')) return 'integracoes';
            if (str_contains($c, 'desenvolv') || str_contains($c, 'sistema')) return 'sistemas';
            if (str_contains($c, 'processo') || str_contains($c, 'projeto')) return 'processos';
            return 'geral';
        };

        $projects = DB::table('projects')->get(['id', 'user_id', 'slug']);
        $bySlug = $projects->groupBy('user_id')->map(fn ($g) => $g->keyBy('slug'));
        $byId = $projects->keyBy('id');

        foreach (DB::table('tasks')->get(['id', 'project_id', 'category']) as $task) {
            $current = $byId[$task->project_id] ?? null;
            if (! $current || $current->slug !== 'geral') continue; // já alinhado

            $targetSlug = $toSlug($task->category);
            if ($targetSlug === 'geral') continue; // sem mudança

            $target = $bySlug[$current->user_id][$targetSlug] ?? null;
            if ($target) {
                DB::table('tasks')->where('id', $task->id)->update(['project_id' => $target->id]);
            }
        }

        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('category'));
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $t) => $t->string('category')->default('Geral')->after('status'));
    }
};
