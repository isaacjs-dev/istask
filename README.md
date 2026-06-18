# Minhas Tarefas

Sistema web de **gerenciamento inteligente de tarefas** — uma mistura de ChatGPT, Trello e
Todoist. O usuário conversa com uma IA por um chat e, a partir de comandos em linguagem
natural, a IA cria, conclui, prioriza, reagenda, junta duplicadas e reorganiza as tarefas
automaticamente. As tarefas aparecem em **Lista**, **Kanban** e **Calendário**.

Construído com **Laravel 12 (Blade) + HTML + CSS + JavaScript**. O design é exatamente o do
protótipo aprovado em [`../modelo_Tarefas`](../modelo_Tarefas) — nenhuma cor, fonte,
espaçamento ou componente foi alterado; apenas as funcionalidades (banco de dados, API REST
e IA) foram implementadas sobre aquela estrutura visual.

---

## Stack

| Camada     | Tecnologia                                                              |
| ---------- | ----------------------------------------------------------------------- |
| Backend    | Laravel 12, PHP 8.2, API REST                                           |
| Banco      | SQLite por padrão (zero-config). Migrations padrão — compatível com MySQL/PostgreSQL |
| Frontend   | Blade + JavaScript vanilla (sem build step) + CSS do protótipo          |
| IA         | Motor de regras em PHP (PT-BR) por padrão; driver opcional Claude/Anthropic |

## Como rodar

Pré-requisitos: PHP 8.2+, Composer, extensão `pdo_sqlite` habilitada.

```bash
composer install                 # se a pasta vendor/ não existir
php artisan migrate:fresh --seed
php artisan serve
```

Abra **http://127.0.0.1:8000**. Você será levado ao **login**. Entre com a conta de
demonstração (já semeada com 5 projetos e 11 tarefas de exemplo) ou crie a sua:

- **Demonstração:** `demo@taskai.test` · senha `password`

## Autenticação e tarefas por usuário

O sistema é **multiusuário**: cada conta tem **suas próprias tarefas, projetos e assistente**.

- **Login/cadastro/logout** em telas leves (Blade, sem build), coerentes com o design.
- Ao se cadastrar, o usuário nasce com um **quadro vazio**: os 5 projetos padrão (Geral,
  Sistemas, Processos, Integrações, Comunicação) + a mensagem de boas-vindas da IA.
- **Isolamento:** cada usuário vê/cria/edita apenas as próprias tarefas (escopo por
  `tarefa → projeto → usuário`), com guard anti-IDOR nas rotas de tarefa (acesso a tarefa de
  outro usuário retorna 404).
- **Atribuição:** o histórico registra quem criou/editou (nome + `user_id`), e o
  `responsible` de novas tarefas recebe o nome do usuário. A identidade aparece no painel
  (rodapé da sidebar, avatar do chat, autor de comentários).
- *Evolução futura (não implementado):* papéis de administrador e compartilhamento de
  tarefas entre usuários.

## Responsividade, barra de comandos e histórico de conversas

- **Responsivo (desktop → celular):** em telas pequenas a sidebar vira uma **gaveta** (botão
  hambúrguer) e a barra de comandos vira um **bottom-sheet** com o **campo de comando sempre
  visível** — em qualquer aba, inclusive Kanban (que passa a rolar horizontalmente). Barras de
  rolagem discretas reaproveitam a classe `.scroll`.
- **Configurações** (item no menu lateral): escolher a posição da barra de comandos entre
  **Lateral** (padrão) e **Inferior** (estilo ChatGPT). A preferência é salva **no banco**
  (`users.preferences`) e aplicada já no carregamento (sem flash).
- **Redimensionável:** arraste a borda da barra de comandos para ajustar a largura (lateral)
  ou a altura (inferior); o tamanho é persistido.
- **Histórico de conversas** (botão no cabeçalho do chat): várias conversas por usuário —
  **nova**, **alternar**, **renomear** e **arquivar/desarquivar**, num painel centralizado. A
  conversa ganha **título automático** a partir da primeira mensagem. Cada conversa guarda
  suas próprias mensagens.

> O visual dos demais elementos não muda: tudo novo fica atrás de classes no `<body>`
> (`cmd-side`/`cmd-bottom`) e de media queries; o desktop em modo lateral é idêntico ao atual.

## O chat como porta de entrada (interpretação local-first)

O chat é a **porta de entrada única**: tudo o que se faz pela UI também se faz por texto, e os
módulos **Notas** e **Diário** são operados pelo chat. **A IA é o último recurso.**

- **Camada 1 — interpretador local determinístico** (`app/Services/Ai/AiEngine.php`): resolve a
  maioria dos comandos por regras/palavras-chave, **sem nenhuma chamada externa**. Quem aplica é
  sempre o **executor determinístico** (`AiActionApplier`); a IA nunca executa.
- **Camada 2 — Gemini, só por exceção** (`app/Services/Ai/GeminiService.php`, proxy no backend):
  - **Gatilho A:** quando o interpretador local **não entende**, a frase vai ao Gemini, que
    devolve **apenas um JSON** de intenção; o sistema valida e o executor aplica. Todo caso desses
    é gravado em **`comando_logs`** para, no futuro, virar regra local e reduzir as chamadas.
  - **Gatilho B:** ao **criar texto livre** (tarefa/nota/diário), o texto vai ao Gemini só para
    **corrigir ortografia/acentuação**, sem mudar o sentido.
  - Fora desses dois casos, **não chama IA**.

A chave do Gemini fica **só no backend** (`.env` → `config/services.php`); o frontend nunca a vê.
Sem chave, o sistema funciona normalmente (comandos não entendidos pedem para reformular).

```dotenv
GEMINI_API_KEY=sua-chave        # opcional; vazio = só interpretador local
GEMINI_MODEL=gemini-3.5-flash   # centralizado em config para troca fácil
```

### Comandos, echo e desfazer

- **Intents** (todos locais): abrir/buscar tarefa, criar/renomear/excluir **projeto**, criar/editar
  (título, data, prioridade, status, categoria, responsável)/mover/concluir/excluir **tarefa**,
  **Notas** (anotar/consultar/excluir), **Diário** (iniciar/finalizar/consultar) e **desfazer/refazer**.
- **Echo de execução:** toda alteração responde no chat no formato **antes → depois** + referência
  do card afetado + botão **[desfazer]**.
- **Desfazer/refazer:** pilha persistida (`action_logs`) com snapshots; desfaz em sequência e refaz.
- **Confirmação:** ações destrutivas (excluir tarefa/projeto/nota) pedem **sim/não** antes de aplicar.
- **Soft delete** em tarefas, projetos, notas e diário — "excluir" só marca; "desfazer" restaura.

## Módulos Notas e Diário

- **Notas** — `anota que o login do Elotech é admin/1234` salva; `qual era a nota sobre o login do
  Elotech?` recupera. Busca por conteúdo/assunto.
- **Diário de tarefas** — `comecei a revisar o DTE agora` abre uma entrada (hora de início);
  `terminei, ajustei os valores` fecha (hora de fim + descrição); `o que eu fiz hoje?` /
  `mostra o diário de ontem` consultam por período.

## Banco de dados

`users` (+`preferences`), `projects`, `tasks`, `task_steps`, `task_comments`, `task_history`,
`ai_messages` (+`conversation_id`), `conversations`, **`notes`**, **`diary_entries`**,
**`action_logs`** (undo/redo), **`comando_logs`** (Gatilho A). Tarefas/projetos/notas/diário usam
**soft delete** (`deleted_at`). Para MySQL/PostgreSQL, ajuste `DB_*` no `.env` e rode
`php artisan migrate:fresh --seed`.

## API REST

| Método | Rota                       | Ação                                              |
| ------ | -------------------------- | ------------------------------------------------- |
| GET    | `/login` · `/register`     | Telas de login e cadastro (visitantes)            |
| POST   | `/login` · `/register`     | Autentica / cria conta (provisiona o workspace)   |
| POST   | `/logout`                  | Encerra a sessão                                  |
| GET    | `/`                        | SPA (shell do protótipo) — exige autenticação     |
| GET    | `/api/bootstrap`           | Estado inicial do usuário (tarefas, mensagens, projetos) |
| POST   | `/api/tasks`               | Cria tarefa em branco                             |
| PUT    | `/api/tasks/{task}`        | Salva o modal completo (campos + checklist + comentários) |
| DELETE | `/api/tasks/{task}`        | Exclui tarefa                                     |
| POST   | `/api/tasks/{task}/toggle` | Alterna concluída/pendente                        |
| POST   | `/api/tasks/{task}/move`   | Muda o status (drag and drop do Kanban)           |
| POST   | `/api/ai/command`          | Processa um comando do chat (local-first; abre tarefa, desfaz, notas, diário…) |
| POST   | `/api/projects`            | Cria projeto (botão "+" da sidebar; chat usa o mesmo executor) |
| PATCH  | `/api/projects/{project}`  | Renomeia projeto                                  |
| GET    | `/api/conversations`       | Lista conversas (ativas + arquivadas)             |
| POST   | `/api/conversations`       | Cria nova conversa                                |
| PATCH  | `/api/conversations/{id}`  | Renomeia / arquiva / desarquiva                   |
| GET    | `/api/conversations/{id}/messages` | Mensagens de uma conversa (ao alternar)   |
| PUT    | `/api/preferences`         | Salva preferências de UI (posição/tamanho da barra) |

`/` e `/api/*` ficam sob o middleware `auth` (visitante é redirecionado ao login; chamadas
da API sem sessão recebem 401). O front envia o token CSRF via header `X-CSRF-TOKEN`. As
rotas de tarefa e de conversa validam a posse (`project.user_id` / `conversation.user_id`)
antes de alterar.

## Organização do código

```
app/
├── Actions/                 ProvisionWorkspace (projetos + conversa + boas-vindas)
├── Http/Controllers/        AppController, Auth/AuthController, Api/{Task, Ai, Project,
│                            Conversation, Preference}Controller
├── Models/                  User, Project, Task, TaskStep, TaskComment, TaskHistory, AiMessage,
│                            Conversation, Note, DiaryEntry, ActionLog, ComandoLog
├── Services/Ai/             AiEngine (interpretador local), GeminiService (proxy Camada 2),
│                            AiActionApplier (executor + echo), AiCommandService (orquestra)
├── Services/Commands/       ActionRecorder (pilha de desfazer/refazer com snapshots)
└── Support/                 Workspace, TaskRepository, Initials
database/
├── migrations/              ...(+ notes, diary_entries, action_logs, comando_logs, soft deletes)
└── seeders/DatabaseSeeder   usuário demo + 11 tarefas de exemplo
resources/views/
├── app.blade.php            shell (injeta estado + aplica a preferência de layout)
└── auth/                    login.blade.php, register.blade.php
public/app/                  styles.css, icons-v.js, ui.js, render.js, modal-v.js (do protótipo)
                             data.js, main.js (adaptados: localStorage → API REST), auth.css
```

O visual permanece idêntico ao `modelo_Tarefas`: `styles.css` e os módulos de render/modal/ícones
foram copiados sem alteração; apenas `data.js` e `main.js` foram reescritos para persistir via
API em vez de `localStorage`.
