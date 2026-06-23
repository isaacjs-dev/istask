<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\DiaryEntry;
use App\Models\Label;
use App\Models\Note;
use App\Models\Notebook;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Models\Workspace as WorkspaceModel;
use App\Services\Diary\DiaryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Monta os payloads que o front (protótipo) espera, no mesmo formato do antigo
 * `data.js` (estado inicial + listas de tarefas/mensagens).
 */
class TaskRepository
{
    /** Ids de áreas que o usuário possui ou participa (compartilhadas). */
    private function accessibleWorkspaceIds(User $user)
    {
        return $user->ownedWorkspaces()->pluck('id')
            ->merge($user->sharedWorkspaces()->pluck('workspaces.id'))
            ->unique()->values();
    }

    /** Ids de projetos acessíveis: próprios, em áreas acessíveis, ou compartilhados diretamente. */
    private function accessibleProjectIds(User $user)
    {
        $wsIds = $this->accessibleWorkspaceIds($user);

        return Project::where(function ($q) use ($user, $wsIds) {
            $q->where('user_id', $user->id)
                ->orWhereIn('workspace_id', $wsIds)
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id));
        })->pluck('id');
    }

    /** Ids de cadernos acessíveis: em áreas acessíveis ou compartilhados diretamente. */
    private function accessibleNotebookIds(User $user)
    {
        $wsIds = $this->accessibleWorkspaceIds($user);

        return Notebook::where(function ($q) use ($user, $wsIds) {
            $q->whereIn('workspace_id', $wsIds)
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id));
        })->pluck('id');
    }

    /**
     * Há mudanças em conteúdo ACESSÍVEL desde $since? Alimenta o polling de
     * sincronização (Fase 3): cobre criação/edição/exclusão (soft) de tarefas,
     * projetos, cadernos, notas e áreas acessíveis, etiquetas próprias e novos
     * compartilhamentos/permissões para o usuário. Respeita estritamente o escopo.
     */
    public function hasChangesSince(User $user, Carbon $since): bool
    {
        $wsIds = $this->accessibleWorkspaceIds($user);
        $projIds = $this->accessibleProjectIds($user);
        $nbIds = $this->accessibleNotebookIds($user);
        $touched = fn ($q) => $q->where('updated_at', '>', $since)->orWhere('deleted_at', '>', $since);

        if (Task::withTrashed()->whereIn('project_id', $projIds)->where($touched)->exists()) {
            return true;
        }
        if (Project::withTrashed()->whereIn('id', $projIds)->where($touched)->exists()) {
            return true;
        }
        if (Notebook::withTrashed()->whereIn('id', $nbIds)->where($touched)->exists()) {
            return true;
        }
        if (WorkspaceModel::withTrashed()->whereIn('id', $wsIds)->where($touched)->exists()) {
            return true;
        }
        $noteChanged = Note::withTrashed()
            ->where(function ($q) use ($user, $nbIds) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('collaborators', fn ($c) => $c->where('users.id', $user->id))
                    ->orWhereIn('notebook_id', $nbIds);
            })
            ->where($touched)->exists();
        if ($noteChanged) {
            return true;
        }
        if (Label::where('user_id', $user->id)->where('updated_at', '>', $since)->exists()) {
            return true;
        }
        foreach (['workspace_members', 'project_members', 'notebook_members', 'note_collaborators'] as $pivot) {
            if (DB::table($pivot)->where('user_id', $user->id)->where('updated_at', '>', $since)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,array> */
    public function tasksFor(User $user): array
    {
        return Task::query()
            ->whereIn('project_id', $this->accessibleProjectIds($user))
            ->with(['steps', 'comments', 'history', 'project.members', 'project.workspace.members', 'attachments', 'labels', 'links', 'taskRelations.relatedTask'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map->toApiArray()
            ->all();
    }

    /** Mensagens (no formato do front) de uma conversa. @return array<int,array> */
    public function messagesForConversation(Conversation $conversation): array
    {
        return $conversation->messages()->get()->map->toApiArray()->all();
    }

    /** Lista de conversas do usuário (ativas primeiro, recentes no topo). @return array<int,array> */
    public function conversationsFor(User $user): array
    {
        return $user->conversations()
            ->withCount('messages')
            ->orderByRaw('archived_at is null desc')
            ->orderByDesc('updated_at')
            ->get()
            ->map->toApiArray()
            ->all();
    }

    /** Conversa ativa: a mais recente não arquivada (cria uma se não houver). */
    public function activeConversation(User $user): Conversation
    {
        return $user->conversations()->active()->latest('updated_at')->first()
            ?? $user->conversations()->create(['title' => 'Assistente']);
    }

    /** Estado inicial completo para o carregamento da página. */
    public function bootstrap(): array
    {
        $user = Workspace::user();
        $active = $this->activeConversation($user);

        // Divisão por dia das atividades em aberto (item 5) — nunca pode quebrar o carregamento.
        try {
            app(DiaryService::class)->reconcile($user);
        } catch (Throwable $e) {
            report($e);
        }

        // Expurgo automático da lixeira de notas (retenção de 7 dias).
        Note::onlyTrashed()
            ->where('user_id', $user->id)
            ->where('deleted_at', '<=', now()->subDays(7))
            ->get()
            ->each->forceDelete();

        return [
            'tasks'              => $this->tasksFor($user),
            'messages'           => $this->messagesForConversation($active),
            'conversations'      => $this->conversationsFor($user),
            'activeConversationId' => (string) $active->id,
            'prefs'              => $user->prefs(),
            'workspaces'         => $this->workspacesPayload($user),
            'activeWorkspaceId'  => $this->activeWorkspaceId($user),
            'projects'           => $this->projectsPayload($user),
            'notebooks'          => $this->notebooksPayload($user),
            // Notas próprias + compartilhadas com o usuário (arquivadas incluídas; lixeira fica fora — telas filtram no front).
            'notes'              => $this->notesPayload($user),
            'labels'             => $user->labels()->orderBy('name')->get()->map->toApiArray()->all(),
            'diaryEntries'       => $user->diaryEntries()->with(['task', 'project', 'attachments', 'histories'])->latest('started_at')->limit(120)->get()->map->toApiArray()->all(),
            'me'                 => $this->meFor($user),
            'notifications'      => $this->notificationsPayload($user),
            'serverTime'         => now()->toIso8601String(),
            'csrf'               => csrf_token(),
        ];
    }

    /**
     * Registro de atividades das tarefas acessíveis (TaskHistory) + as entradas do
     * Diário do próprio usuário, mescladas e ordenadas por data. Suporta filtro por
     * período (from/to) e por área (workspaceId) — este último alimenta a visão de
     * time (apenas TaskHistory; o Diário é pessoal e não entra na visão de time).
     *
     * @param array{from?:mixed,to?:mixed,workspaceId?:int,limit?:int} $opts
     * @return array<int,array>
     */
    public function activitiesPayload(User $user, array $opts = []): array
    {
        $projectIds = $this->accessibleProjectIds($user);
        $limit = $opts['limit'] ?? 500;

        $q = TaskHistory::query()
            ->whereHas('task', fn ($t) => $t->whereIn('project_id', $projectIds))
            ->with(['task.project.workspace', 'user'])
            ->orderByDesc('created_at')->orderByDesc('id');

        if (! empty($opts['from'])) {
            $q->where('created_at', '>=', $opts['from']);
        }
        if (! empty($opts['to'])) {
            $q->where('created_at', '<=', $opts['to']);
        }
        if (! empty($opts['workspaceId'])) {
            $wid = (int) $opts['workspaceId'];
            $q->whereHas('task.project', fn ($p) => $p->where('workspace_id', $wid));
        }

        $items = $q->limit($limit)->get()->map(fn (TaskHistory $h) => [
            'id'          => (string) $h->id,
            'action'      => $h->action,
            'by'          => $h->actor,
            'byId'        => $h->user_id ? (string) $h->user_id : null,
            'at'          => optional($h->created_at)->toIso8601String(),
            'taskId'      => (string) $h->task_id,
            'taskTitle'   => optional($h->task)->title,
            'project'     => optional(optional($h->task)->project)->name,
            'workspaceId' => optional(optional($h->task)->project)->workspace_id ? (string) $h->task->project->workspace_id : null,
            'workspace'   => optional(optional(optional($h->task)->project)->workspace)->name,
            'kind'        => 'task',
        ])->all();

        // Entradas do Diário do usuário (somente na visão pessoal; o Diário é pessoal).
        if (empty($opts['workspaceId'])) {
            $dq = DiaryEntry::where('user_id', $user->id)
                ->whereNotNull('started_at')
                ->with(['task', 'project.workspace'])
                ->orderByDesc('started_at');
            if (! empty($opts['from'])) {
                $dq->where('started_at', '>=', $opts['from']);
            }
            if (! empty($opts['to'])) {
                $dq->where('started_at', '<=', $opts['to']);
            }
            $diary = $dq->limit($limit)->get()->map(function (DiaryEntry $e) use ($user) {
                $dur = \App\Support\ActivityNarrator::duration($e->computedDurationMinutes());

                return [
                    'id'          => 'diary-' . $e->id,
                    'action'      => 'registrou no diário' . ($dur ? ' — <b>' . $dur . '</b>' : ''),
                    'by'          => $user->name,
                    'byId'        => (string) $user->id,
                    'at'          => optional($e->started_at)->toIso8601String(),
                    'taskId'      => $e->task_id ? (string) $e->task_id : null,
                    'taskTitle'   => $e->title ?: ($e->description ?: optional($e->task)->title),
                    'project'     => optional($e->project)->name,
                    'workspaceId' => optional($e->project)->workspace_id ? (string) $e->project->workspace_id : null,
                    'workspace'   => optional(optional($e->project)->workspace)->name,
                    'kind'        => 'diary',
                ];
            })->all();

            $items = array_merge($items, $diary);
        }

        // ordena por data desc (ISO8601 com offset consistente ordena lexicograficamente) e limita
        usort($items, fn ($a, $b) => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        return array_slice($items, 0, $limit);
    }

    /** Avisos in-app não lidos (exceto lembretes de nota, que têm fluxo de toast próprio). @return array<int,array> */
    public function notificationsPayload(User $user): array
    {
        return $user->unreadNotifications()->latest()->limit(50)->get()
            ->reject(fn ($n) => ($n->data['kind'] ?? null) === 'note_reminder')
            ->map(fn ($n) => [
                'id'        => $n->id,
                'data'      => $n->data,
                'createdAt' => optional($n->created_at)->toIso8601String(),
            ])->values()->all();
    }

    /** Áreas de Trabalho do usuário (próprias + compartilhadas). @return array<int,array> */
    public function workspacesPayload(User $user): array
    {
        return WorkspaceModel::whereIn('id', $this->accessibleWorkspaceIds($user))
            ->with(['owner', 'members'])
            ->orderBy('position')->orderBy('id')->get()->map->toApiArray()->all();
    }

    /** Cadernos acessíveis (áreas próprias/compartilhadas + cadernos compartilhados). @return array<int,array> */
    public function notebooksPayload(User $user): array
    {
        return Notebook::whereIn('id', $this->accessibleNotebookIds($user))
            ->withCount('notes')
            ->with(['members', 'workspace.owner', 'workspace.members'])
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (Notebook $nb) => array_merge($nb->toApiArray(), ['count' => $nb->notes_count]))
            ->all();
    }

    /** Projetos acessíveis (próprios + em áreas/projetos compartilhados), com membros/permissão. @return array<int,array> */
    public function projectsPayload(User $user): array
    {
        $wsIds = $this->accessibleWorkspaceIds($user);

        return Project::whereIn('id', $this->accessibleProjectIds($user))
            ->with(['members', 'workspace.members', 'user'])
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (Project $p) => [
                'id'          => $p->id,
                'slug'        => $p->slug,
                'name'        => $p->name,
                'icon'        => $p->icon,
                'description' => $p->description,
                'startDate'   => optional($p->start_date)->format('Y-m-d'),
                'dueDate'     => optional($p->due_date)->format('Y-m-d'),
                'completedAt' => optional($p->completed_at)->format('Y-m-d'),
                'status'      => $p->status ?: 'nao_iniciado',
                'priority'    => $p->priority ?: 'media',
                'workspaceId' => $p->workspace_id ? (string) $p->workspace_id : null,
                'isOwner'     => $p->user_id === $user->id,
                'ownerName'   => optional($p->user)->name,
                'ownerAvatarUrl' => optional($p->user)->avatarUrl,
                // Projeto compartilhado individualmente cuja área eu não acesso → vai para a
                // área virtual "Projetos compartilhados" no front (sempre visível quando houver).
                'sharedSolo'  => $p->user_id !== $user->id && (! $p->workspace_id || ! $wsIds->contains($p->workspace_id)),
                'permission'  => Access::projectPermission($user, $p),
                'members'     => $p->members->map(fn (User $u) => [
                    'id'         => (string) $u->id,
                    'name'       => $u->name,
                    'email'      => $u->email,
                    'avatarUrl'  => $u->avatarUrl,
                    'initials'   => Initials::of($u->name),
                    'permission' => $u->pivot->permission,
                ])->all(),
            ])->all();
    }

    /** Notas próprias + compartilhadas (colaborador) + em cadernos acessíveis. @return array<int,array> */
    public function notesPayload(User $user): array
    {
        return Note::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('collaborators', fn ($c) => $c->where('users.id', $user->id))
                    ->orWhereIn('notebook_id', $this->accessibleNotebookIds($user));
            })
            ->with(['labels', 'items', 'attachments', 'collaborators', 'user', 'notebook.members', 'notebook.workspace.owner', 'notebook.workspace.members'])
            ->latest('updated_at')->get()->map->toApiArray()->all();
    }

    /** Área ativa (da preferência, se acessível; senão a primeira própria, senão a primeira compartilhada). */
    public function activeWorkspaceId(User $user): ?string
    {
        $pref = $user->prefs()['activeWorkspaceId'] ?? null;
        if ($pref && $this->accessibleWorkspaceIds($user)->contains((int) $pref)) {
            return (string) $pref;
        }

        $first = $user->ownedWorkspaces()->orderBy('position')->first()
            ?? $user->sharedWorkspaces()->first();

        return $first ? (string) $first->id : null;
    }

    /** Dados do usuário expostos ao front (perfil). */
    public function meFor(User $user): array
    {
        return [
            'name'      => $user->name,
            'email'     => $user->email,
            'bio'       => $user->bio,
            'initials'  => Initials::of($user->name),
            'avatarUrl' => $user->avatarUrl,
        ];
    }
}
