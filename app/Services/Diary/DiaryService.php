<?php

namespace App\Services\Diary;

use App\Models\DiaryEntry;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Automação do Diário de Atividades: cria/fecha períodos a partir das
 * movimentações de tarefas (item 3/4/6), divide atividades por dia (item 5) e
 * sugere o tipo da atividade (item 9). Idempotente (item 11): movimentos sem
 * mudança real de status são ignorados e não há dois períodos abertos por
 * tarefa+usuário; as continuações diárias usam `movement_key` único.
 */
class DiaryService
{
    private const STATUS = [
        'pendente' => 'Pendente', 'andamento' => 'Em andamento',
        'aguardando' => 'Aguardando terceiros', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado',
    ];

    /**
     * Reage a uma mudança de status de uma tarefa.
     */
    public function onStatusChange(Task $task, ?string $from, string $to, User $actor, ?Carbon $at = null): void
    {
        $from = $from ?: 'pendente';
        if ($from === $to) {
            return; // sem movimentação real (cobre refresh / repetição da requisição)
        }
        $at = $at ? $at->copy() : now();
        $label = $actor->name;
        $reopen = $from === 'concluido';
        $open = $this->openEntry($task);

        // Entrar em "Em andamento": abre um período de trabalho (item 4).
        if ($to === 'andamento') {
            if ($open) {
                return; // já existe período aberto — não duplica (item 11)
            }
            $entry = $this->createMovementEntry($task, $from, $to, $actor, $at, [
                'started_at' => $at,
                'ended_at'   => null,
            ]);
            $entry->logHistory(
                $reopen ? 'reopened' : 'movement',
                $reopen ? 'Tarefa reaberta — novo período iniciado' : 'Período iniciado · ' . $this->movementText($from, $to),
                ['from' => $from, 'to' => $to],
                $label,
                $actor->id
            );

            return;
        }

        // Sair de "Em andamento" (ou concluir): fecha o período aberto — é ele que registra o trabalho.
        if ($open) {
            $open->ended_at = $at;
            $open->status_to = $to;
            if (! $open->moved_by) {
                $open->moved_by = $label;
            }
            $open->save();
            $open->logHistory(
                $to === 'concluido' ? 'concluded' : 'status_change',
                ($to === 'concluido' ? 'Tarefa concluída · ' : 'Período encerrado · ') . $this->movementText($from, $to),
                ['from' => $from, 'to' => $to],
                $label,
                $actor->id
            );

            return;
        }

        // Sem período aberto: registra a movimentação como evento instantâneo (item 3).
        $entry = $this->createMovementEntry($task, $from, $to, $actor, $at, [
            'started_at' => $at,
            'ended_at'   => $at,
        ]);
        $entry->logHistory(
            $to === 'concluido' ? 'concluded' : 'movement',
            ($to === 'concluido' ? 'Tarefa concluída · ' : 'Movimentação · ') . $this->movementText($from, $to),
            ['from' => $from, 'to' => $to],
            $label,
            $actor->id
        );
    }

    /**
     * Divisão por dia (item 5): fecha períodos abertos de dias anteriores no fim
     * do expediente e abre continuações até hoje enquanto a tarefa seguir em
     * andamento. Idempotente — seguro chamar a cada carregamento de página.
     */
    public function reconcile(User $user, ?Carbon $now = null): void
    {
        $now = $now ? $now->copy() : now();
        $today = $now->copy()->startOfDay();
        $prefs = $user->prefs();
        [$ws, $wsM] = $this->parseTime($prefs['workdayStart'] ?? '09:00');
        [$we, $weM] = $this->parseTime($prefs['workdayEnd'] ?? '18:00');

        $open = $user->diaryEntries()->whereNull('ended_at')->whereNotNull('started_at')->get();
        foreach ($open as $entry) {
            $startDay = $entry->started_at->copy()->startOfDay();
            if (! $startDay->lt($today)) {
                continue; // começou hoje — nada a dividir
            }

            // 1) fecha a entrada no fim do expediente do dia em que começou
            $eod = $entry->started_at->copy()->setTime($we, $weM, 0);
            if ($eod->lessThanOrEqualTo($entry->started_at)) {
                $eod = $entry->started_at->copy(); // expediente já passado — duração 0
            }
            $entry->ended_at = $eod;
            if (! $entry->status_to) {
                $entry->status_to = $entry->task?->status ?? 'andamento';
            }
            $entry->save();
            $entry->logHistory('auto_closed_eod', 'Fechado automaticamente no fim do expediente', ['endedAt' => $eod->toDateTimeString()], 'Sistema');

            // 2) continuação diária enquanto a tarefa seguir em andamento
            $task = $entry->task;
            if ($task && $task->status === 'andamento') {
                $this->openSplits($entry, $task, $user, $startDay, $today, $ws, $wsM, $we, $weM);
            }
        }
    }

    /**
     * Sugere a letra do tipo de atividade a partir do título/seção/descrição.
     */
    public function suggestType(Task $task): string
    {
        $hay = $this->norm($task->title . ' ' . ($task->section ?? '') . ' ' . ($task->description ?? ''));
        $map = [
            'R' => ['reuniao', 'meeting', 'call', 'alinhamento', 'daily'],
            'C' => ['corrig', 'bug', 'erro', 'fix', 'hotfix', 'ajuste'],
            'T' => ['teste', 'testar', 'qa', 'homolog'],
            'A' => ['analis', 'levantamento', 'requisito', 'investig', 'diagnost'],
            'P' => ['planej', 'roadmap', 'backlog', 'organiz', 'cronograma'],
            'S' => ['suporte', 'support', 'atendiment', 'chamado'],
            'V' => ['valid', 'revis', 'review', 'aprov'],
            'E' => ['estud', 'pesquis', 'leitura', 'aprend', 'documenta'],
            'D' => ['desenvolv', 'implement', 'program', 'tela', 'criar', 'build', 'integr', 'deploy'],
        ];
        foreach ($map as $letter => $words) {
            foreach ($words as $w) {
                if (str_contains($hay, $this->norm($w))) {
                    return $letter;
                }
            }
        }

        return 'O';
    }

    // ============================================================ HELPERS
    private function openEntry(Task $task): ?DiaryEntry
    {
        return DiaryEntry::where('task_id', $task->id)->whereNull('ended_at')->latest('started_at')->first();
    }

    private function createMovementEntry(Task $task, ?string $from, string $to, User $actor, Carbon $at, array $extra): DiaryEntry
    {
        return DiaryEntry::create(array_merge([
            'user_id'       => $actor->id,
            'task_id'       => $task->id,
            'project_id'    => $task->project_id,
            'title'         => $task->title,
            'activity_type' => $this->suggestType($task),
            'status_from'   => $from,
            'status_to'     => $to,
            'source'        => 'auto',
            'moved_by'      => $actor->name,
            'description'   => $task->title,
        ], $extra));
    }

    private function openSplits(DiaryEntry $origin, Task $task, User $user, Carbon $startDay, Carbon $today, int $ws, int $wsM, int $we, int $weM): void
    {
        $day = $startDay->copy()->addDay();
        while ($day->lessThanOrEqualTo($today)) {
            $key = 'split:' . $task->id . ':' . $day->format('Y-m-d');
            $isToday = $day->equalTo($today);

            if (! DiaryEntry::where('movement_key', $key)->exists()) {
                $entry = DiaryEntry::create([
                    'user_id'       => $user->id,
                    'task_id'       => $task->id,
                    'project_id'    => $task->project_id,
                    'title'         => $task->title,
                    'activity_type' => $this->suggestType($task),
                    'status_from'   => 'andamento',
                    'status_to'     => $isToday ? null : 'andamento',
                    'source'        => 'auto_split',
                    'movement_key'  => $key,
                    'moved_by'      => $origin->moved_by,
                    'started_at'    => $day->copy()->setTime($ws, $wsM, 0),
                    'ended_at'      => $isToday ? null : $day->copy()->setTime($we, $weM, 0),
                    'description'   => $task->title,
                ]);
                $entry->logHistory('auto_split', 'Continuação automática do dia anterior', ['day' => $day->format('Y-m-d')], 'Sistema');
            }
            $day->addDay();
        }
    }

    private function movementText(?string $from, string $to): string
    {
        return (self::STATUS[$from] ?? $from) . ' → ' . (self::STATUS[$to] ?? $to);
    }

    /** @return array{0:int,1:int} */
    private function parseTime(string $hhmm): array
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return [9, 0];
        }

        return [min((int) $m[1], 23), min((int) $m[2], 59)];
    }

    private function norm(?string $s): string
    {
        return mb_strtolower(Str::ascii((string) $s));
    }
}
