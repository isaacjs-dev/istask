<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * Motor de comandos em linguagem natural (PT-BR) — porte fiel do
 * `modelo_Tarefas/app/ai-engine.js`. Interpreta o texto do usuário e devolve
 * um conjunto de "ações" (create/update/delete) + uma resposta para o chat.
 *
 * Opera sobre o array de tarefas já serializado (Task::toApiArray), de modo
 * que a lógica de casamento por título é idêntica à do protótipo. As ações são
 * aplicadas ao banco por App\Services\Ai\AiActionApplier.
 */
class AiEngine
{
    /** Âncora temporal — mesma "hoje" do protótipo, para datas relativas consistentes. */
    private const TODAY = '2026-06-08';

    private const PRIO_WORDS = [
        'urgente' => ['urgent', 'urgente', 'imediat', 'agora', 'critico', 'critica'],
        'alta'    => ['alta', 'alto', 'importante', 'prioridade alta'],
        'media'   => ['media', 'medio', 'normal', 'media prioridade'],
        'baixa'   => ['baixa', 'baixo', 'depois', 'sem pressa'],
    ];

    private const STATUS_WORDS = [
        'concluido'  => ['conclu', 'finaliz', 'termin', 'pronto', 'feito', 'done', 'complet'],
        'andamento'  => ['andamento', 'fazendo', 'iniciei', 'comecei', 'trabalhando', 'progresso'],
        'aguardando' => ['aguardando', 'esperando', 'terceiro', 'depende'],
        'pendente'   => ['pendente', 'reabrir', 'reabre', 'abrir de novo'],
        'cancelado'  => ['cancel'],
    ];

    private const PRIO_LABELS = [
        'urgente' => 'Urgente', 'alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa',
    ];
    private const PRIO_RANK = ['urgente' => 0, 'alta' => 1, 'media' => 2, 'baixa' => 3];
    private const STATUS_LABELS = [
        'pendente' => 'Pendente', 'andamento' => 'Em andamento',
        'aguardando' => 'Aguardando terceiros', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado',
    ];
    private const PROJECT_NAMES = [
        'geral' => 'Geral', 'sistemas' => 'Sistemas', 'processos' => 'Processos',
        'integracoes' => 'Integrações', 'comunicacao' => 'Comunicação',
    ];

    private function norm(?string $s): string
    {
        return mb_strtolower(Str::ascii((string) $s));
    }

    /** Resposta principal: ['reply' => string, 'replyCard' => ?array, 'actions' => array, ...] */
    public function process(string $text, array $tasks, array $context = []): array
    {
        $t = $this->norm($text);

        // ---- Novos intents/módulos (precedência sobre o dispatch clássico) ----
        $early = $this->tryUndoRedo($t)
            ?? $this->tryDiary($text, $t, $context)
            ?? $this->tryNotes($text, $t)
            ?? $this->tryProject($text, $t)
            ?? $this->tryEditField($text, $t, $tasks)
            ?? $this->tryOpenOrSearch($text, $t, $tasks);
        if ($early !== null) {
            return $early;
        }

        $verbCreate     = (bool) preg_match('/\b(adicion|cria|crie|nova tarefa|novo card|add|inclui|registr|preciso|lembra)/', $t);
        $verbComplete   = (bool) preg_match('/\b(conclu|finaliz|termin|marca\w* como (feito|pronto|conclu)|done|fiz|terminei|completa)/', $t);
        $verbPrioritize = (bool) preg_match('/\b(prioriz|priorid|urgent|deixa\w* urgente|marca\w* como urgente|torna\w* urgente)/', $t);
        $verbMerge      = (bool) preg_match('/\b(junta|junte|merge|unifica|combina|mescla|duplicad)/', $t);
        $verbDelete     = (bool) preg_match('/\b(exclui|deleta|remove|apaga)/', $t);
        $verbMove       = (bool) preg_match('/\b(move\w*|mover|passa\w* para|muda\w* (o )?status|coloca\w* em)\b/', $t);
        $verbDue        = (bool) preg_match('/\b(prazo|vence|entrega|data|adia|reagenda|muda\w* (a )?data)\b/', $t);
        $verbList       = (bool) preg_match('/\b(quais|liste|mostra|quantas|o que (tenho|falta)|resume|resumo)\b/', $t);

        // ---- MERGE ----
        if ($verbMerge) {
            return $this->doMerge($text, $tasks);
        }

        // ---- LIST / SUMMARY ----
        if ($verbList && ! $verbCreate) {
            return $this->doList($text, $tasks);
        }

        // ---- CREATE ----
        if ($verbCreate && ! $verbComplete) {
            $title = $this->extractTitle($text);
            if (mb_strlen($title) < 2) {
                return $this->reply('Não consegui identificar o título da tarefa. Tente: <b>“Adiciona tarefa Revisar contrato amanhã, prioridade alta”</b>.');
            }
            $priority = $this->detectPriority($text) ?? 'media';
            $projectSlug = $this->detectProjectSlug($text);
            $due = $this->detectDue($text);
            $task = [
                'title' => $title, 'description' => '', 'status' => 'pendente',
                'priority' => $priority, 'project' => $projectSlug, 'projectName' => self::PROJECT_NAMES[$projectSlug] ?? 'Geral',
                'due' => $due, 'responsible' => 'Você', 'checklist' => [],
            ];

            return [
                'reply' => 'Pronto! Criei a tarefa e já organizei na lista certa.',
                'replyCard' => $task,
                'actions' => [['type' => 'create', 'task' => $task]],
            ];
        }

        // ---- COMPLETE ----
        if ($verbComplete) {
            $target = $this->findTargetFromText($text, $tasks, ['conclu', 'finaliz', 'termin', 'marca', 'como', 'feito', 'pronto', 'fiz', 'terminei', 'a', 'o', 'tarefa', 'de', 'completa', 'completar']);
            if (! $target) {
                return $this->notFound('concluir');
            }

            return [
                'reply' => "Marquei <b>“{$target['title']}”</b> como concluída. ✅ Movi para a seção <b>Tarefas concluídas</b>.",
                'replyCard' => null,
                'actions' => [['type' => 'update', 'id' => $target['id'], 'patch' => ['status' => 'concluido', 'completedAt' => true], 'hist' => 'marcou como <b>Concluído</b>']],
            ];
        }

        // ---- PRIORITIZE ----
        if ($verbPrioritize) {
            $pr = $this->detectPriority($text) ?? 'urgente';
            $target = $this->findTargetFromText($text, $tasks, ['prioriz', 'prioridade', 'urgente', 'alta', 'media', 'baixa', 'marca', 'como', 'deixa', 'torna', 'a', 'o', 'tarefa', 'de', 'para']);
            if (! $target) {
                return $this->notFound('priorizar');
            }
            $label = self::PRIO_LABELS[$pr];

            return [
                'reply' => "Atualizei <b>“{$target['title']}”</b> para prioridade <b>{$label}</b> e movi para o topo.",
                'replyCard' => null,
                'actions' => [['type' => 'update', 'id' => $target['id'], 'patch' => ['priority' => $pr], 'hist' => "alterou prioridade para <b>{$label}</b>"]],
            ];
        }

        // ---- MOVE / CHANGE STATUS ----
        $detectedStatus = $this->detectStatus($text);
        if ($verbMove || ($detectedStatus && ! $verbCreate)) {
            $st = $detectedStatus;
            $target = $this->findTargetFromText($text, $tasks, ['move', 'mover', 'passa', 'para', 'muda', 'status', 'coloca', 'em', 'a', 'o', 'tarefa', 'de', $st ?? '']);
            if ($st && $target) {
                if ($st === 'concluido') {
                    return $this->process('concluir ' . $target['title'], $tasks);
                }
                $label = self::STATUS_LABELS[$st];

                return [
                    'reply' => "Movi <b>“{$target['title']}”</b> para <b>{$label}</b>.",
                    'replyCard' => null,
                    'actions' => [['type' => 'update', 'id' => $target['id'], 'patch' => ['status' => $st, 'completedAt' => null], 'hist' => "alterou status para <b>{$label}</b>"]],
                ];
            }
        }

        // ---- DUE / RESCHEDULE ----
        if ($verbDue) {
            $due = $this->detectDue($text);
            $target = $this->findTargetFromText($text, $tasks, ['prazo', 'vence', 'entrega', 'data', 'adia', 'reagenda', 'muda', 'para', 'a', 'o', 'tarefa', 'de', 'ate']);
            if ($target && $due) {
                $fmt = $this->fmtDue($due);

                return [
                    'reply' => "Reagendei <b>“{$target['title']}”</b> para <b>{$fmt}</b>.",
                    'replyCard' => null,
                    'actions' => [['type' => 'update', 'id' => $target['id'], 'patch' => ['due' => $due], 'hist' => "alterou o prazo para <b>{$fmt}</b>"]],
                ];
            }
        }

        // ---- DELETE ----
        if ($verbDelete) {
            $target = $this->findTargetFromText($text, $tasks, ['exclui', 'deleta', 'remove', 'apaga', 'a', 'o', 'tarefa', 'de']);
            if (! $target) {
                return $this->notFound('excluir');
            }

            return [
                'reply' => '',
                'replyCard' => null,
                'actions' => [['type' => 'delete', 'id' => $target['id']]],
                'requer_confirmacao' => true,
                'mensagem_confirmacao' => "Excluir a tarefa “{$this->esc($target['title'])}”?",
            ];
        }

        // ---- NÃO ENTENDIDO (Gatilho A: cair no Gemini, se disponível) ----
        return [
            'unresolved' => true,
            'reply' => 'Entendi parcialmente. Posso <b>criar</b>, <b>abrir</b>, <b>buscar</b>, <b>editar</b>, <b>concluir</b>, <b>mover</b>, <b>reagendar</b>, <b>juntar duplicadas</b>, criar <b>projetos</b>, anotar em <b>Notas</b>, registrar no <b>Diário</b> e <b>desfazer</b>. Ex.: <i>“abrir tarefa sobre DTE”</i> ou <i>“trocar a data do CAD 2 para sexta”</i>.',
            'replyCard' => null,
            'actions' => [],
        ];
    }

    private function reply(string $text): array
    {
        return ['reply' => $text, 'replyCard' => null, 'actions' => []];
    }

    private function notFound(string $verb): array
    {
        return $this->reply("Não encontrei a tarefa que você quer {$verb}. Pode me dizer parte do título? Ex.: <i>“{$verb} a tarefa do Eduardo”</i>.");
    }

    // ---- detection helpers ----
    private function detectPriority(string $text): ?string
    {
        $t = $this->norm($text);
        foreach (self::PRIO_WORDS as $k => $words) {
            foreach ($words as $w) {
                if (str_contains($t, $w)) {
                    return $k;
                }
            }
        }

        return null;
    }

    private function detectStatus(string $text): ?string
    {
        $t = $this->norm($text);
        foreach (self::STATUS_WORDS as $k => $words) {
            foreach ($words as $w) {
                if (str_contains($t, $w)) {
                    return $k;
                }
            }
        }

        return null;
    }

    private function detectProjectSlug(string $text): string
    {
        $t = $this->norm($text);
        if (str_contains($t, 'comunica')) return 'comunicacao';
        if (str_contains($t, 'integra') || str_contains($t, 'api')) return 'integracoes';
        if (str_contains($t, 'sistema') || str_contains($t, 'desenvolv') || str_contains($t, 'codigo') || str_contains($t, 'refator')) return 'sistemas';
        if (str_contains($t, 'projeto') || str_contains($t, 'planej') || str_contains($t, 'processo') || str_contains($t, 'boleto') || str_contains($t, 'orcamento')) return 'processos';

        return 'geral';
    }

    private function detectDue(string $text): ?string
    {
        $t = $this->norm($text);
        $base = strtotime(self::TODAY);
        $iso = fn ($ts) => date('Y-m-d', $ts);

        if (str_contains($t, 'depois de amanha')) return $iso(strtotime('+2 day', $base));
        if (str_contains($t, 'amanha')) return $iso(strtotime('+1 day', $base));
        if (str_contains($t, 'hoje')) return $iso($base);
        if (preg_match('/(proxima semana|semana que vem)/', $t)) return $iso(strtotime('+7 day', $base));
        if (preg_match('/em (\d+) dias?/', $t, $m)) return $iso(strtotime("+{$m[1]} day", $base));
        if (preg_match('#(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?#', $t, $m)) {
            $y = isset($m[3]) ? (strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3]) : (int) date('Y', $base);

            return sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]);
        }
        $dias = ['domingo', 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'];
        foreach ($dias as $i => $d) {
            if (str_contains($t, $d)) {
                $cur = (int) date('w', $base);
                $delta = (($i - $cur + 7) % 7) ?: 7;

                return $iso(strtotime("+{$delta} day", $base));
            }
        }

        return null;
    }

    public function extractTitle(string $text): string
    {
        $t = trim($text);
        $t = preg_replace('/^\s*(por favor|pf|ei|oi|ok)[,\s]+/iu', '', $t);
        $t = preg_replace('/^\s*(adiciona(r|e)?|adicione|cria(r|e)?|crie|nova tarefa|novo card|new task|add|inclui(r)?|registra(r)?|coloca(r)?|anota(r)?|preciso|lembra(r)? de)\s*/iu', '', $t);
        $t = preg_replace('/^\s*(uma |um |a |o |de |que |para )\s*/iu', '', $t);
        $t = preg_replace('/\b(tarefa|task|card|item)\b\s*/iu', '', $t);
        $t = preg_replace('/[,;]?\s*(com|de)?\s*prioridade\s+\w+.*$/iu', '', $t);
        $t = preg_replace('/[,;]?\s*(urgente|priorit\w+)\s*$/iu', '', $t);
        $t = preg_replace('/[,;]?\s*(para|ate|com prazo|prazo|vence|entrega\w*)\s+(hoje|amanha|depois de amanha|proxima semana|semana que vem|segunda|terca|quarta|quinta|sexta|sabado|domingo|em \d+ dias?|\d{1,2}\/\d{1,2}(\/\d{2,4})?).*$/iu', '', $t);
        $t = preg_replace('/[,;]?\s*(na categoria|no projeto)\s+\w+.*$/iu', '', $t);
        $t = trim($t);
        $t = preg_replace('/^["\']|["\']$/u', '', $t);
        $t = preg_replace('/\.$/', '', $t);
        if (mb_strlen($t) > 0) {
            $t = mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
        }

        return $t;
    }

    // ---- task matching ----
    private function findTask(array $tasks, string $query): ?array
    {
        $q = $this->norm($query);
        if ($q === '') {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($tasks as $t) {
            $title = $this->norm($t['title']);
            $score = 0;
            if (str_contains($title, $q)) {
                $score += mb_strlen($q) * 2;
            }
            $qWords = array_filter(preg_split('/\s+/', $q), fn ($w) => mb_strlen($w) > 2);
            foreach ($qWords as $w) {
                if (str_contains($title, $w)) {
                    $score += mb_strlen($w);
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $t;
            }
        }

        return $bestScore >= 3 ? $best : null;
    }

    private function findTargetFromText(string $text, array $tasks, array $stop): ?array
    {
        $q = $this->norm($text);
        foreach ($stop as $w) {
            if ($w !== '') {
                $q = preg_replace('/\b' . preg_quote($w, '/') . '\w*\b/u', ' ', $q);
            }
        }
        $q = trim(preg_replace('/\s+/', ' ', $q));

        return $this->findTask($tasks, $q) ?? $this->findTask($tasks, $text);
    }

    private function doMerge(string $text, array $tasks): array
    {
        $open = array_values(array_filter($tasks, fn ($t) => $t['status'] !== 'concluido' && $t['status'] !== 'cancelado'));
        $a = null;
        $b = null;
        $best = 0;
        for ($i = 0; $i < count($open); $i++) {
            for ($j = $i + 1; $j < count($open); $j++) {
                $s = $this->similarity($open[$i]['title'], $open[$j]['title']);
                if ($s > $best) {
                    $best = $s;
                    $a = $open[$i];
                    $b = $open[$j];
                }
            }
        }
        if (preg_match('/\bel\b|recadastr|api/', $this->norm($text))) {
            $els = array_values(array_filter($open, fn ($t) => preg_match('/recadastr|api da el|api do recadastr|api/', $this->norm($t['title']))));
            if (count($els) >= 2) {
                $cobrar = null;
                foreach ($els as $t) {
                    if (preg_match('/cobrar/', $this->norm($t['title']))) {
                        $cobrar = $t;
                        break;
                    }
                }
                $a = $cobrar ?? $els[0];
                $b = null;
                foreach ($els as $t) {
                    if ($t['id'] !== $a['id']) {
                        $b = $t;
                        break;
                    }
                }
                $b = $b ?? $els[1];
                $best = 1;
            }
        }
        if (! $a || ! $b || $best < 0.15) {
            return $this->reply('Não encontrei tarefas duplicadas o suficiente para juntar. Quer me indicar quais?');
        }
        $keep = self::PRIO_RANK[$a['priority']] <= self::PRIO_RANK[$b['priority']] ? $a : $b;
        $drop = $keep['id'] === $a['id'] ? $b : $a;
        $mergedChecklist = array_merge($keep['checklist'], array_map(fn ($c) => ['text' => $c['text'], 'done' => $c['done']], $drop['checklist']));

        return [
            'reply' => "Encontrei duas tarefas relacionadas:<br>• <b>{$a['title']}</b><br>• <b>{$b['title']}</b><br><br>Juntei tudo em <b>“{$keep['title']}”</b>, combinei os checklists e mantive a prioridade mais alta. 🔗",
            'replyCard' => null,
            'actions' => [
                ['type' => 'update', 'id' => $keep['id'], 'patch' => ['checklist' => $mergedChecklist], 'hist' => "mesclou “{$drop['title']}” nesta tarefa"],
                ['type' => 'delete', 'id' => $drop['id']],
            ],
        ];
    }

    private function doList(string $text, array $tasks): array
    {
        $t = $this->norm($text);
        $subset = $tasks;
        $label = ['no total', 'no total'];
        if (str_contains($t, 'urgent')) {
            $subset = array_filter($tasks, fn ($x) => $x['priority'] === 'urgente' && $x['status'] !== 'concluido');
            $label = ['urgente', 'urgentes'];
        } elseif (str_contains($t, 'hoje')) {
            $subset = array_filter($tasks, fn ($x) => ($x['due'] ?? null) === self::TODAY && $x['status'] !== 'concluido');
            $label = ['para hoje', 'para hoje'];
        } elseif (str_contains($t, 'atras')) {
            $subset = array_filter($tasks, fn ($x) => $this->isOverdue($x));
            $label = ['atrasada', 'atrasadas'];
        } elseif (str_contains($t, 'pendent') || str_contains($t, 'falta')) {
            $subset = array_filter($tasks, fn ($x) => $x['status'] !== 'concluido');
            $label = ['pendente', 'pendentes'];
        } elseif (str_contains($t, 'conclu')) {
            $subset = array_filter($tasks, fn ($x) => $x['status'] === 'concluido');
            $label = ['concluída', 'concluídas'];
        }
        $subset = array_values($subset);
        if (count($subset) === 0) {
            return $this->reply("Você não tem tarefas {$label[1]}. 🎉");
        }
        $one = count($subset) === 1;
        $top = implode('<br>', array_map(function ($x) {
            $pl = self::PRIO_LABELS[$x['priority']];

            return "• <b>{$x['title']}</b> <span style=\"color:var(--ink-4)\">({$pl})</span>";
        }, array_slice($subset, 0, 5)));
        $more = count($subset) > 5 ? '<br><span style="color:var(--ink-4)">…e mais ' . (count($subset) - 5) . '.</span>' : '';
        $word = $one ? 'tarefa' : 'tarefas';
        $lbl = $one ? $label[0] : $label[1];

        return $this->reply('Você tem <b>' . count($subset) . "</b> {$word} {$lbl}:<br><br>{$top}{$more}");
    }

    private function similarity(string $a, string $b): float
    {
        $wa = array_unique(array_filter(preg_split('/\s+/', $this->norm($a)), fn ($w) => mb_strlen($w) > 3));
        $wb = array_unique(array_filter(preg_split('/\s+/', $this->norm($b)), fn ($w) => mb_strlen($w) > 3));
        if (! count($wa) || ! count($wb)) {
            return 0.0;
        }
        $inter = count(array_intersect($wa, $wb));

        return $inter / min(count($wa), count($wb));
    }

    private function isOverdue(array $t): bool
    {
        if (empty($t['due']) || $t['status'] === 'concluido' || $t['status'] === 'cancelado') {
            return false;
        }

        return strtotime($t['due']) < strtotime(self::TODAY);
    }

    private function fmtDue(?string $d): string
    {
        if (! $d) {
            return 'Sem prazo';
        }

        return date('d/m/Y', strtotime($d));
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    // ============================================================
    //  Novos intents — interpretados localmente (sem IA)
    // ============================================================

    private function tryUndoRedo(string $t): ?array
    {
        if (preg_match('/\b(refaz|refazer|refaca|refaça)\b/u', $t)) {
            return ['type' => 'redo', 'reply' => '', 'replyCard' => null, 'actions' => []];
        }
        if (preg_match('/\b(desfaz|desfazer|desfaca|desfaça|reverte|reverter|anula\w*|volta\w* (como|ao que|atras|pra tras|pro que era)|cancela\w* a ultima)\b/u', $t)) {
            return ['type' => 'undo', 'reply' => '', 'replyCard' => null, 'actions' => []];
        }

        return null;
    }

    private function tryDiary(string $text, string $t, array $context): ?array
    {
        $hasOpen = ! empty($context['hasOpenDiary']);

        // excluir entrada do diário (precisa vir antes de "consultar", pois ambos casam com "diario")
        if (preg_match('/\b(exclui\w*|apaga\w*|remove\w*|deleta\w*)\b.*\bentrada\b.*\bdiario\b/u', $t)
            || preg_match('/\b(exclui\w*|apaga\w*|remove\w*|deleta\w*)\b.*\bdiario\b.*\bsobre\b/u', $t)) {
            $q = $this->extractDiaryQuery($text);

            return [
                'reply' => '', 'replyCard' => null,
                'actions' => [['type' => 'delete_diary', 'q' => $q]],
                'requer_confirmacao' => true,
                'mensagem_confirmacao' => 'Excluir esta entrada do diário?',
            ];
        }

        // consultar diário
        if (preg_match('/\bdiario\b/u', $t) || preg_match('/\bo que (eu )?(fiz|trabalhei|fechei)\b/u', $t)) {
            $period = 'hoje';
            if (str_contains($t, 'ontem')) $period = 'ontem';
            elseif (str_contains($t, 'semana')) $period = 'semana';

            return ['reply' => '', 'replyCard' => null, 'actions' => [['type' => 'query_diary', 'period' => $period]]];
        }

        // finalizar entrada aberta (só quando há uma em andamento)
        if ($hasOpen && preg_match('/^(terminei|finalizei|acabei|conclui|concluí|parei|encerrei|fechei)\b/u', $t)) {
            $desc = $this->stripPrefixes($text, ['terminei', 'finalizei', 'acabei de', 'acabei', 'conclui', 'concluí', 'parei de', 'parei', 'encerrei', 'fechei', 'a', 'o', 'de']);

            return ['reply' => '', 'replyCard' => null, 'actions' => [['type' => 'diary_end', 'description' => $desc]]];
        }

        // iniciar entrada
        if (preg_match('/^(comecei|comecando|começando|comecei a|iniciei|iniciando|inicio|estou comecando|to comecando)\b/u', $t)) {
            $desc = $this->stripPrefixes($text, ['comecei a', 'comecei', 'comecando', 'começando', 'iniciei', 'iniciando', 'inicio', 'estou', 'to', 'a fazer', 'a', 'o']);

            return ['reply' => '', 'replyCard' => null, 'actions' => [['type' => 'diary_start', 'description' => $desc]]];
        }

        return null;
    }

    private function tryNotes(string $text, string $t): ?array
    {
        // editar nota (precisa vir antes de "consultar", pois ambos casam com "nota" + "sobre")
        if (preg_match('/\b(muda\w*|edita\w*|altera\w*|atualiza\w*|troca\w*|corrige\w*)\b.*\bnotas?\b/u', $t)
            && preg_match('/\bpara\b\s+(.+)$/iu', $text, $m)) {
            $field = preg_match('/\btitulos?\b/u', $t) ? 'title' : 'body';
            $value = $this->trimPunct($m[1]);
            $before = preg_replace('/\bpara\b.*$/iu', '', $text);
            $q = $this->extractNoteQuery($before);
            if (mb_strlen($q) < 2) {
                return $this->reply('Qual nota você quer editar? Ex.: <i>“muda a nota sobre Elotech para admin/senha novo”</i>.');
            }

            return ['reply' => '', 'replyCard' => null, 'actions' => [['type' => 'update_note', 'q' => $q, 'patch' => [$field => $value]]]];
        }

        // consultar/recuperar nota
        if (preg_match('/\bnotas?\b/u', $t) && preg_match('/\b(qual|quais|que|onde|mostra\w*|busca\w*|procura\w*|recupera\w*|ver|tinha|era|sobre|tem)\b/u', $t)) {
            return ['reply' => '', 'replyCard' => null, 'actions' => [['type' => 'query_note', 'q' => $this->extractNoteQuery($text)]]];
        }
        // excluir nota
        if (preg_match('/\b(exclui\w*|apaga\w*|remove\w*|deleta\w*) (a |essa |minha )?nota\b/u', $t)) {
            $q = $this->extractNoteQuery($text);

            return [
                'reply' => '', 'replyCard' => null,
                'actions' => [['type' => 'delete_note', 'q' => $q]],
                'requer_confirmacao' => true,
                'mensagem_confirmacao' => 'Excluir a nota sobre “' . $this->esc($q) . '”?',
            ];
        }
        // criar nota
        if (preg_match('/^(anota|anotar|anote|guarda|guardar|lembra|lembrar)\b/u', $t)
            || preg_match('/\bnota:/u', $t)
            || preg_match('/\b(cria\w*|crie|nova|novo|adiciona\w*|adicione) (uma |um )?nota\b/u', $t)) {
            $body = $this->extractNoteBody($text);
            if (mb_strlen($body) < 2) {
                return $this->reply('O que você quer anotar? Ex.: <i>“anota que o login do Elotech é admin/1234”</i>.');
            }

            return [
                'reply' => '', 'replyCard' => null,
                'actions' => [['type' => 'create_note', 'title' => $this->deriveNoteTitle($body), 'body' => $body, 'tags' => $this->deriveNoteTitle($body)]],
            ];
        }

        return null;
    }

    private function tryProject(string $text, string $t): ?array
    {
        // criar projeto
        if (preg_match('/\b(cria\w*|crie|adiciona\w*|adicione|nova|novo|add|inclui\w*) (um |uma )?projeto\b/u', $t)) {
            $name = $this->extractProjectName($text);
            if (mb_strlen($name) < 2) {
                return $this->reply('Qual o nome do novo projeto? Ex.: <i>“adicionar projeto Fiscalização 2026”</i>.');
            }

            return ['reply' => '', 'replyCard' => null, 'actions' => [['type' => 'create_project', 'name' => $name]]];
        }
        // renomear projeto
        if (preg_match('/\brenomea\w*\b/u', $t) && str_contains($t, 'projeto')) {
            if (preg_match('/projeto\s+(.+?)\s+(?:para|pra|por|em)\s+(.+)$/iu', $text, $m)) {
                return ['reply' => '', 'replyCard' => null, 'actions' => [['type' => 'rename_project', 'match' => trim($m[1]), 'name' => $this->trimPunct($m[2])]]];
            }

            return $this->reply('Use: <i>“renomear projeto X para Y”</i>.');
        }
        // excluir projeto
        if (preg_match('/\b(exclui\w*|deleta\w*|remove\w*|apaga\w*) (o |esse |meu )?projeto\b/u', $t)) {
            $match = $this->extractAfterWord($text, 'projeto');

            return [
                'reply' => '', 'replyCard' => null,
                'actions' => [['type' => 'delete_project', 'match' => $match]],
                'requer_confirmacao' => true,
                'mensagem_confirmacao' => 'Excluir o projeto “' . $this->esc($match) . '”? As tarefas dele continuam, mas o projeto sai da lista.',
            ];
        }

        return null;
    }

    private function tryEditField(string $text, string $t, array $tasks): ?array
    {
        $field = null;
        $label = '';
        if (preg_match('/\btitulo\b/u', $t)) { $field = 'title'; $label = 'Título'; }
        elseif (preg_match('/\b(categoria|projeto)\b/u', $t)) { $field = 'projectMatch'; $label = 'Projeto'; }
        elseif (preg_match('/\bresponsavel\b/u', $t)) { $field = 'responsible'; $label = 'Responsável'; }
        elseif (preg_match('/\bdescricao\b/u', $t)) { $field = 'description'; $label = 'Descrição'; }
        if (! $field) {
            return null;
        }
        if (! preg_match('/\b(muda\w*|troca\w*|altera\w*|edita\w*|renomeia\w*|coloca\w*|defin[ei]\w*|poe|por)\b/u', $t)) {
            return null;
        }
        if (! preg_match('/\bpara\b\s+(.+)$/iu', $text, $m)) {
            return null;
        }
        $value = $this->trimPunct($m[1]);
        $before = preg_replace('/\bpara\b.*$/iu', '', $text);
        $target = $this->findTargetFromText($before, $tasks, ['muda', 'mudar', 'troca', 'trocar', 'altera', 'alterar', 'edita', 'editar', 'renomeia', 'renomear', 'coloca', 'colocar', 'poe', 'o', 'a', 'titulo', 'categoria', 'projeto', 'responsavel', 'descricao', 'da', 'do', 'de', 'tarefa', 'card']);
        if (! $target) {
            return $this->notFound('editar');
        }

        return [
            'reply' => '', 'replyCard' => null,
            'actions' => [['type' => 'update', 'id' => $target['id'], 'patch' => [$field => $value], 'hist' => "alterou {$label}"]],
        ];
    }

    private function tryOpenOrSearch(string $text, string $t, array $tasks): ?array
    {
        $listKw = (bool) preg_match('/\b(urgent\w*|pendent\w*|atras\w*|conclu\w*|hoje|em andamento|aguardando|todas|atrasad\w*)\b/u', $t);
        $isSearch = (bool) preg_match('/\b(quais tarefas|tarefas que|busca\w* tarefas?|procura\w* tarefas?|tarefas sobre|tarefas de|tarefas com|tarefas falando)\b/u', $t);
        $isOpen = (bool) preg_match('/^(abrir|abre|abra|exibir|exibe|veja|ver)\b/u', $t)
            || (bool) preg_match('/^mostra\w*\s+(o|a)\s+/u', $t);

        if ($isSearch) {
            $q = $this->stripWordsNorm($text, ['quais', 'tarefas', 'tarefa', 'que', 'falam', 'falando', 'sobre', 'de', 'com', 'busca', 'buscar', 'procura', 'procurar', 'as', 'os', 'me', 'mostra', 'mostrar', 'existe', 'existem', 'tem', 'ha']);

            return $this->searchReply($q, $this->searchTasks($tasks, $q));
        }
        if ($isOpen && ! $listKw) {
            $q = $this->stripWordsNorm($text, ['abrir', 'abre', 'abra', 'exibir', 'exibe', 'ver', 'veja', 'mostra', 'mostrar', 'a', 'o', 'as', 'os', 'tarefa', 'tarefas', 'card', 'task', 'sobre', 'do', 'da', 'de', 'dos', 'das']);
            $matches = $this->searchTasks($tasks, $q);
            if (count($matches) === 1) {
                return ['reply' => "Abrindo <b>“{$matches[0]['title']}”</b>…", 'replyCard' => null, 'actions' => [], 'open' => $matches[0]['id']];
            }
            if (count($matches) > 1) {
                return $this->disambiguation($matches, 'abrir');
            }

            return $this->notFound('abrir');
        }

        return null;
    }

    // ---- busca / desambiguação ----
    private function searchTasks(array $tasks, string $q): array
    {
        $qn = $this->norm($q);
        $words = array_values(array_filter(preg_split('/\s+/', $qn), fn ($w) => mb_strlen($w) > 2));
        if (! count($words) && $qn === '') {
            return [];
        }
        $scored = [];
        foreach ($tasks as $task) {
            $title = $this->norm($task['title']);
            $proj = $this->norm($task['projectName'] ?? '');
            $score = 0;
            if ($qn !== '' && str_contains($title, $qn)) $score += 12;
            foreach ($words as $w) {
                if (str_contains($title, $w)) $score += mb_strlen($w);
                elseif (str_contains($proj, $w)) $score += 1;
            }
            if ($score > 0) {
                $scored[] = ['s' => $score, 't' => $task];
            }
        }
        usort($scored, fn ($a, $b) => $b['s'] <=> $a['s']);

        return array_map(fn ($x) => $x['t'], $scored);
    }

    private function searchReply(string $q, array $matches): array
    {
        if (! count($matches)) {
            return $this->reply('Não encontrei tarefas relacionadas a <b>' . $this->esc($q) . '</b>.');
        }
        $top = array_slice($matches, 0, 6);
        $list = implode('<br>', array_map(function ($x) {
            $pl = self::PRIO_LABELS[$x['priority']];

            return "• <b>{$x['title']}</b> <span style=\"color:var(--ink-4)\">({$pl})</span>";
        }, $top));
        $n = count($matches);

        return $this->reply("Encontrei <b>{$n}</b> " . ($n === 1 ? 'tarefa' : 'tarefas') . " relacionada" . ($n === 1 ? '' : 's') . ":<br><br>{$list}");
    }

    private function disambiguation(array $matches, string $verb): array
    {
        $top = array_slice($matches, 0, 5);
        $list = implode('<br>', array_map(fn ($x) => "• <b>{$x['title']}</b>", $top));

        return $this->reply("Encontrei mais de uma tarefa para <b>{$verb}</b>. Qual delas?<br><br>{$list}<br><br><span style=\"color:var(--ink-4)\">Diga o título de forma mais específica.</span>");
    }

    // ---- extração de texto (preservando acentos) ----
    private function trimPunct(string $s): string
    {
        return trim(preg_replace('/[?.!,;:\s]+$/u', '', trim($s)));
    }

    private function stripPrefixes(string $text, array $words): string
    {
        $s = trim($text);
        $alt = implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words));
        $prev = null;
        while ($prev !== $s) {
            $prev = $s;
            $s = preg_replace('/^\s*(?:' . $alt . ')(?=\s|,|:|$)[\s,:]*/iu', '', $s);
        }
        $s = preg_replace('/[\s.,]*\bagora\b[\s.,]*$/iu', '', $s);

        return $this->trimPunct($s);
    }

    private function stripWordsNorm(string $text, array $words): string
    {
        $s = $this->norm($text);
        foreach ($words as $w) {
            $s = preg_replace('/\b' . preg_quote($this->norm($w), '/') . '\b/u', ' ', $s);
        }

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private function extractAfterWord(string $text, string $word): string
    {
        if (preg_match('/\b' . preg_quote($word, '/') . '\b\s*(.+)$/iu', $text, $m)) {
            return $this->trimPunct($m[1]);
        }

        return '';
    }

    private function extractNoteBody(string $text): string
    {
        $b = trim($text);
        $b = preg_replace('/^\s*(por favor|pf)[,\s]+/iu', '', $b);
        $b = preg_replace('/^\s*(anota(r|e)?|anote|guarda(r)?|lembra(r)?|cria(r|e)?|crie|nova|novo|adiciona(r)?|adicione)\s+/iu', '', $b);
        $b = preg_replace('/^\s*(uma |um )?nota[:\s]+/iu', '', $b);
        $b = preg_replace('/^\s*(que|de que|o seguinte|isso|isto|aqui)[:\s]+/iu', '', $b);

        return $this->trimPunct($b);
    }

    private function deriveNoteTitle(string $body): string
    {
        $words = preg_split('/\s+/', trim($body));
        $title = implode(' ', array_slice($words, 0, 6));

        return mb_strlen($title) > 60 ? mb_substr($title, 0, 60) : $title;
    }

    private function extractDiaryQuery(string $text): string
    {
        $q = trim($text);
        if (preg_match('/\bsobre\b\s*(.+)$/iu', $q, $m)) {
            $q = $m[1];
        } else {
            $q = preg_replace('/^\s*(exclui\w*|apaga\w*|remove\w*|deleta\w*)\s+/iu', '', $q);
            $q = preg_replace('/\b(a|o|essa|esse|minha|meu|do|da|no|na)\s+entradas?\b/iu', '', $q);
            $q = preg_replace('/\b(do|no)\s+diario\b|\bdiario\b/iu', '', $q);
        }
        $q = preg_replace('/^\s*(o|a|os|as|do|da|de|dos|das)\s+/iu', '', $q);

        return $this->trimPunct($q);
    }

    private function extractNoteQuery(string $text): string
    {
        $q = trim($text);
        if (preg_match('/\bnotas?\b\s*(.*)$/iu', $q, $m)) {
            $q = $m[1];
        }
        $q = preg_replace('/^\s*(sobre|do|da|de|dos|das|a respeito de|que (fala|falava) (de|sobre)|o|a)\s+/iu', '', $q);
        $q = preg_replace('/^\s*(qual|quais|era|tinha|e|é)\s+/iu', '', $q);

        return $this->trimPunct($q);
    }

    private function extractProjectName(string $text): string
    {
        if (preg_match('/projetos?\s+(?:chamado\s+|de\s+nome\s+|nomeado\s+|com\s+o\s+nome\s+)?(.+)$/iu', $text, $m)) {
            return $this->trimPunct($m[1]);
        }

        return '';
    }
}
