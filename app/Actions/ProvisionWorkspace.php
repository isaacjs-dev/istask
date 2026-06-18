<?php

namespace App\Actions;

use App\Models\AiMessage;
use App\Models\Conversation;
use App\Models\Notebook;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

/**
 * Provisiona o workspace inicial de um usuário: os 5 projetos padrão e a
 * mensagem de boas-vindas do assistente. Usado tanto no cadastro de novos
 * usuários quanto no seeder.
 *
 * @return array<string,\App\Models\Project> mapa slug => Project
 */
class ProvisionWorkspace
{
    public const PROJECTS = [
        ['slug' => 'geral',       'name' => 'Geral',        'icon' => 'Folder'],
        ['slug' => 'sistemas',    'name' => 'Sistemas',     'icon' => 'Settings'],
        ['slug' => 'processos',   'name' => 'Processos',    'icon' => 'Refresh'],
        ['slug' => 'integracoes', 'name' => 'Integrações',  'icon' => 'Merge'],
        ['slug' => 'comunicacao', 'name' => 'Comunicação',  'icon' => 'Comment'],
    ];

    public const WELCOME = 'Olá! Sou seu assistente de tarefas. Escreva comandos em linguagem natural — posso <b>criar</b>, <b>concluir</b>, <b>priorizar</b>, <b>juntar</b> duplicadas e <b>reorganizar</b> tudo automaticamente.';

    public function for(User $user): array
    {
        $workspace = Workspace::create([
            'owner_id' => $user->id,
            'name'     => 'Pessoal',
            'position' => 0,
        ]);

        $projects = [];
        foreach (self::PROJECTS as $i => $p) {
            $projects[$p['slug']] = Project::create([
                'user_id'      => $user->id,
                'workspace_id' => $workspace->id,
                'slug'         => $p['slug'],
                'name'         => $p['name'],
                'icon'         => $p['icon'],
                'position'     => $i,
            ]);
        }

        Notebook::create([
            'workspace_id' => $workspace->id,
            'name'         => 'Geral',
            'position'     => 0,
        ]);

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title'   => 'Assistente',
        ]);

        AiMessage::create([
            'user_id'         => $user->id,
            'project_id'      => $projects['geral']->id,
            'conversation_id' => $conversation->id,
            'role'            => 'ai',
            'message'         => self::WELCOME,
            'created_at'      => now(),
        ]);

        return $projects;
    }
}
