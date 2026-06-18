<?php

namespace Database\Seeders;

use App\Actions\ProvisionWorkspace;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Cria o usuário demo (demo@taskai.test / password), provisiona seu
     * workspace (5 projetos + boas-vindas) e semeia as 11 tarefas de exemplo
     * do protótipo `modelo_Tarefas`. Novos usuários (via cadastro) nascem com
     * o workspace provisionado, porém sem tarefas de exemplo.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@taskai.test'],
            ['name' => 'Demonstração', 'password' => Hash::make('password')]
        );

        $projects = (new ProvisionWorkspace())->for($user);

        foreach ($this->seedTasks() as $i => $t) {
            $projectSlug = $this->categoryToProject($t['category']);
            $task = Task::create([
                'project_id'   => $projects[$projectSlug]->id,
                'title'        => $t['title'],
                'description'  => $t['description'],
                'status'       => $t['status'],
                'priority'     => $t['priority'],
                'category'     => $t['category'],
                'section'      => $t['section'],
                'due_date'     => $t['due'],
                'responsible'  => 'Você',
                'position'     => $i,
                'completed_at' => $t['completedAt'] ? Carbon::parse($t['completedAt']) : null,
                'created_at'   => Carbon::parse($t['createdAt']),
                'updated_at'   => Carbon::parse($t['createdAt']),
            ]);

            foreach ($t['checklist'] as $ci => $c) {
                $task->steps()->create([
                    'title'    => $c['text'],
                    'status'   => $c['done'] ? 'done' : 'pending',
                    'position' => $ci,
                ]);
            }

            foreach ($t['comments'] as $c) {
                $task->comments()->create([
                    'user_id'    => ($c['ai'] ?? false) ? null : ($c['author'] === 'Você' ? $user->id : null),
                    'comment'    => $c['text'],
                    'author'     => $c['author'],
                    'initials'   => $c['initials'],
                    'color'      => $c['color'],
                    'is_ai'      => $c['ai'] ?? false,
                    'created_at' => Carbon::parse($c['at']),
                    'updated_at' => Carbon::parse($c['at']),
                ]);
            }

            foreach ($t['history'] as $h) {
                $task->history()->create([
                    'user_id'    => $h['by'] === 'Você' ? $user->id : null,
                    'actor'      => $h['by'],
                    'action'     => $h['action'],
                    'created_at' => Carbon::parse($h['at']),
                ]);
            }
        }
    }

    private function categoryToProject(string $cat): string
    {
        $c = mb_strtolower($cat);
        if (str_contains($c, 'comunica')) return 'comunicacao';
        if (str_contains($c, 'integra')) return 'integracoes';
        if (str_contains($c, 'desenvolv') || str_contains($c, 'sistema')) return 'sistemas';
        if (str_contains($c, 'processo') || str_contains($c, 'projeto')) return 'processos';
        return 'geral';
    }

    private function seedTasks(): array
    {
        return [
            [
                'section' => 'concluidas',
                'title' => 'Montar orçamento e enviar para Stenio',
                'description' => '<p>Consolidar os valores levantados, revisar os itens da planilha e enviar a versão final do orçamento para o <b>Stenio</b> por e-mail, confirmando o recebimento.</p>',
                'status' => 'concluido', 'priority' => 'media', 'category' => 'Processos',
                'due' => '2026-06-06',
                'checklist' => [
                    ['text' => 'Levantar custos', 'done' => true],
                    ['text' => 'Revisar planilha', 'done' => true],
                    ['text' => 'Enviar para Stenio', 'done' => true],
                ],
                'comments' => [
                    ['author' => 'Stenio', 'initials' => 'ST', 'color' => 'linear-gradient(135deg,#10b981,#0ea5e9)', 'text' => 'Recebido, obrigado! Vou analisar e retorno.', 'at' => '2026-06-06T14:20:00Z'],
                ],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-02T09:00:00Z'],
                    ['action' => 'concluiu a checklist', 'by' => 'Você', 'at' => '2026-06-06T11:00:00Z'],
                    ['action' => 'marcou como <b>Concluído</b>', 'by' => 'Você', 'at' => '2026-06-06T13:40:00Z'],
                ],
                'createdAt' => '2026-06-02T09:00:00Z', 'completedAt' => '2026-06-06T13:40:00Z',
            ],
            [
                'section' => 'prioridade',
                'title' => 'Criar informações de comunicação para envio ao Cláudio',
                'description' => '<p>Reunir os dados necessários e preparar o material de comunicação a ser enviado ao <b>Cláudio</b>, garantindo clareza, consistência e alinhamento das informações antes do envio.</p>',
                'status' => 'andamento', 'priority' => 'urgente', 'category' => 'Comunicação',
                'due' => '2026-06-08',
                'checklist' => [
                    ['text' => 'Reunir dados', 'done' => true],
                    ['text' => 'Redigir comunicado', 'done' => false],
                    ['text' => 'Revisar e enviar ao Cláudio', 'done' => false],
                ],
                'comments' => [],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-05T08:30:00Z'],
                ],
                'createdAt' => '2026-06-05T08:30:00Z', 'completedAt' => null,
            ],
            [
                'section' => 'prioridade',
                'title' => 'Responder ao Eduardo sobre o TR',
                'description' => '<p>Elaborar resposta ao <b>Eduardo</b> com as informações referentes ao <b>TR</b>, esclarecendo a situação atual, os encaminhamentos já realizados e eventuais pendências que dependem de outras áreas.</p>',
                'status' => 'pendente', 'priority' => 'alta', 'category' => 'Comunicação',
                'due' => '2026-06-09',
                'checklist' => [
                    ['text' => 'Levantar status do TR', 'done' => true],
                    ['text' => 'Redigir resposta ao Eduardo', 'done' => false],
                ],
                'comments' => [
                    ['author' => 'IA', 'initials' => 'AI', 'color' => 'linear-gradient(145deg,#7c3aed,#4f46e5)', 'ai' => true, 'text' => 'Substituí “TER” por “TR” na descrição conforme solicitado.', 'at' => '2026-06-07T10:05:00Z'],
                ],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-05T16:10:00Z'],
                    ['action' => 'corrigiu termos: TER → <b>TR</b>', 'by' => 'IA', 'at' => '2026-06-07T10:05:00Z'],
                ],
                'createdAt' => '2026-06-05T16:10:00Z', 'completedAt' => null,
            ],
            [
                'section' => 'prioridade',
                'title' => 'Cobrar a API do recadastramento para a EL',
                'description' => '<p>Entrar em contato com a <b>EL</b> para cobrar a disponibilização da API de recadastramento, alinhar prazos de entrega e registrar o retorno para acompanhamento.</p>',
                'status' => 'aguardando', 'priority' => 'alta', 'category' => 'Integrações',
                'due' => '2026-06-09',
                'checklist' => [],
                'comments' => [],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-05T16:12:00Z'],
                    ['action' => 'alterou status para <b>Aguardando terceiros</b>', 'by' => 'Você', 'at' => '2026-06-06T09:00:00Z'],
                ],
                'createdAt' => '2026-06-05T16:12:00Z', 'completedAt' => null,
            ],
            [
                'section' => 'pendencias',
                'title' => 'Corrigir emissão de boleto com ISA',
                'description' => '<p>Identificar a falha na emissão de boletos junto à <b>ISA</b>, validar a correção em ambiente de homologação e confirmar o funcionamento do fluxo em produção.</p>',
                'status' => 'andamento', 'priority' => 'alta', 'category' => 'Processos',
                'due' => '2026-06-10',
                'checklist' => [
                    ['text' => 'Reproduzir o erro', 'done' => true],
                    ['text' => 'Aplicar correção', 'done' => false],
                    ['text' => 'Homologar com ISA', 'done' => false],
                ],
                'comments' => [],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-06T11:40:00Z'],
                ],
                'createdAt' => '2026-06-06T11:40:00Z', 'completedAt' => null,
            ],
            [
                'section' => 'pendencias',
                'title' => 'Ajustar o painel com as informações do Ricardo',
                'description' => '<p>Atualizar o painel incorporando as informações repassadas pelo <b>Ricardo</b>, validar os indicadores exibidos e conferir a consistência dos dados com a fonte original.</p>',
                'status' => 'pendente', 'priority' => 'media', 'category' => 'Processos',
                'due' => '2026-06-11',
                'checklist' => [],
                'comments' => [],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-06T11:42:00Z'],
                ],
                'createdAt' => '2026-06-06T11:42:00Z', 'completedAt' => null,
            ],
            [
                'section' => 'projetos',
                'title' => 'Elaborar projeto do Inovar para o ISA',
                'description' => '<p>Estruturar a proposta do projeto <b>Inovar</b> voltada para a <b>ISA</b>, definindo escopo, objetivos, entregáveis e um cronograma preliminar de execução.</p>',
                'status' => 'pendente', 'priority' => 'media', 'category' => 'Projetos',
                'due' => '2026-06-18',
                'checklist' => [],
                'comments' => [],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-04T15:00:00Z'],
                ],
                'createdAt' => '2026-06-04T15:00:00Z', 'completedAt' => null,
            ],
            [
                'section' => 'projetos',
                'title' => 'Elaborar projeto do Inovar para o Eu Assino',
                'description' => '<p>Estruturar a proposta do projeto <b>Inovar</b> voltada para o <b>Eu Assino</b>, definindo escopo, objetivos, entregáveis e indicadores de sucesso.</p>',
                'status' => 'pendente', 'priority' => 'media', 'category' => 'Projetos',
                'due' => '2026-06-20',
                'checklist' => [],
                'comments' => [],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-04T15:05:00Z'],
                ],
                'createdAt' => '2026-06-04T15:05:00Z', 'completedAt' => null,
            ],
            [
                'section' => 'integracoes',
                'title' => 'Criar sistema de atualização cadastral/recadastramento com a API da EL',
                'description' => '<p>Desenvolver um sistema de atualização cadastral e recadastramento utilizando a <b>API da EL</b>, integrado diretamente ao banco de dados e vinculado ao login via <b>Gov.br</b>.</p>',
                'status' => 'andamento', 'priority' => 'alta', 'category' => 'Desenvolvimento',
                'due' => '2026-07-05',
                'checklist' => [
                    ['text' => 'Definir escopo técnico', 'done' => true],
                    ['text' => 'Validar API da EL', 'done' => false],
                    ['text' => 'Integrar login via Gov.br', 'done' => false],
                    ['text' => 'Criar versão inicial', 'done' => false],
                    ['text' => 'Realizar testes', 'done' => false],
                    ['text' => 'Ajustar falhas', 'done' => false],
                ],
                'comments' => [
                    ['author' => 'IA', 'initials' => 'AI', 'color' => 'linear-gradient(145deg,#7c3aed,#4f46e5)', 'ai' => true, 'text' => 'Detectei que esta tarefa pode se sobrepor a “Cobrar a API do recadastramento para a EL”. Quer que eu vincule as duas?', 'at' => '2026-06-07T10:10:00Z'],
                ],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-03T10:00:00Z'],
                    ['action' => 'adicionou 6 etapas ao checklist', 'by' => 'IA', 'at' => '2026-06-03T10:02:00Z'],
                ],
                'createdAt' => '2026-06-03T10:00:00Z', 'completedAt' => null,
            ],
            [
                'section' => 'integracoes',
                'title' => 'Criar sistema de notificação para o NAT',
                'description' => '<p>Desenvolver um sistema de notificações para o <b>NAT</b>, definindo gatilhos, canais de envio (e-mail e push) e modelos de mensagem padronizados.</p>',
                'status' => 'pendente', 'priority' => 'media', 'category' => 'Integrações',
                'due' => '2026-07-05',
                'checklist' => [],
                'comments' => [],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-06T17:20:00Z'],
                ],
                'createdAt' => '2026-06-06T17:20:00Z', 'completedAt' => null,
            ],
            [
                'section' => 'integracoes',
                'title' => 'Refatorar sistema de consulta e incluir novas bases de dados',
                'description' => '<p>Refatorar o sistema de consulta para melhorar desempenho e legibilidade do código, além de incluir novas bases de dados no escopo da pesquisa.</p>',
                'status' => 'pendente', 'priority' => 'alta', 'category' => 'Integrações',
                'due' => '2026-07-07',
                'checklist' => [],
                'comments' => [],
                'history' => [
                    ['action' => 'criou a tarefa', 'by' => 'Você', 'at' => '2026-06-06T17:25:00Z'],
                ],
                'createdAt' => '2026-06-06T17:25:00Z', 'completedAt' => null,
            ],
        ];
    }
}
