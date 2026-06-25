<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DiaryEntryController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\NoteCollaboratorController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\NoteItemController;
use App\Http\Controllers\Api\NotebookController;
use App\Http\Controllers\Api\NotebookMemberController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProjectMemberController;
use App\Http\Controllers\Api\WorkspaceMemberController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Support\TaskRepository;
use Illuminate\Support\Facades\Route;

// ---- Autenticação (visitantes) ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Home: landing pública para visitantes; SPA (shell + estado) para autenticados.
// A ramificação fica em AppController::index — a rota é pública para a landing ser a página inicial.
Route::get('/', [AppController::class, 'index'])->name('home');

/*
| API REST consumida pelo front (autenticada). Herda sessão e proteção CSRF do
| middleware "web" (o front envia o token via header X-CSRF-TOKEN).
*/
Route::middleware('auth')->prefix('api')->group(function () {
        Route::get('/bootstrap', fn (TaskRepository $repo) => response()->json($repo->bootstrap()));
        Route::get('/sync', [SyncController::class, 'index']);

        Route::get('/tasks/reminders/due', [TaskController::class, 'remindersDue']);
        Route::post('/tasks/import', [TaskController::class, 'import']);
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::put('/tasks/{task}', [TaskController::class, 'sync']);
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
        Route::post('/tasks/{task}/toggle', [TaskController::class, 'toggle']);
        Route::post('/tasks/{task}/move', [TaskController::class, 'move']);
        Route::post('/tasks/{task}/archive', [TaskController::class, 'archive']);
        Route::post('/tasks/{task}/duplicate', [TaskController::class, 'duplicate']);
        Route::patch('/tasks/{task}/comments/{comment}', [TaskController::class, 'updateComment']);
        Route::delete('/tasks/{task}/comments/{comment}', [TaskController::class, 'destroyComment']);
        Route::post('/tasks/{task}/links', [TaskController::class, 'addLink']);
        Route::delete('/tasks/{task}/links/{link}', [TaskController::class, 'removeLink']);
        Route::post('/tasks/{task}/relations', [TaskController::class, 'addRelation']);
        Route::delete('/tasks/{task}/relations/{relation}', [TaskController::class, 'removeRelation']);

        Route::post('/ai/command', [AiController::class, 'command']);

        // Registro de atividades / timeline (e visão de time via ?workspace=)
        Route::get('/activities', [ActivityController::class, 'index']);

        // Áreas de Trabalho
        Route::post('/workspaces/reorder', [WorkspaceController::class, 'reorder']);
        Route::post('/workspaces', [WorkspaceController::class, 'store']);
        Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update']);
        Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy']);
        Route::post('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'store']);
        Route::delete('/workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'destroy']);
        Route::post('/workspaces/{workspace}/transfer', [WorkspaceController::class, 'transfer']);

        // Cadernos (organização das notas por área)
        Route::post('/notebooks/reorder', [NotebookController::class, 'reorder']);
        Route::post('/notebooks', [NotebookController::class, 'store']);
        Route::patch('/notebooks/{notebook}', [NotebookController::class, 'update']);
        Route::post('/notebooks/{notebook}/cover', [NotebookController::class, 'uploadCover']);
        Route::delete('/notebooks/{notebook}', [NotebookController::class, 'destroy']);
        Route::post('/notebooks/{notebook}/members', [NotebookMemberController::class, 'store']);
        Route::delete('/notebooks/{notebook}/members/{user}', [NotebookMemberController::class, 'destroy']);

        // Projetos (também criáveis/renomeáveis pelo chat)
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::post('/projects/{project}/move', [ProjectController::class, 'move']);
        Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store']);
        Route::delete('/projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy']);
        Route::post('/projects/{project}/transfer', [ProjectController::class, 'transfer']);
        Route::patch('/projects/{project}', [ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

        // Notas (também criáveis/editáveis/excluíveis pelo chat)
        Route::get('/notes/trash', [NoteController::class, 'trash']);
        Route::get('/notes/reminders/due', [NoteController::class, 'remindersDue']);
        Route::get('/notes/reminders', [NoteController::class, 'reminders']);
        Route::post('/notes/import', [NoteController::class, 'import']);
        Route::post('/notes', [NoteController::class, 'store']);
        Route::get('/notes/{note}', [NoteController::class, 'show']);
        Route::patch('/notes/{note}', [NoteController::class, 'update']);
        Route::delete('/notes/{note}', [NoteController::class, 'destroy']);
        Route::post('/notes/{note}/pin', [NoteController::class, 'pin']);
        Route::post('/notes/{note}/archive', [NoteController::class, 'archive']);
        Route::post('/notes/{note}/restore', [NoteController::class, 'restore'])->withTrashed();
        Route::delete('/notes/{note}/force', [NoteController::class, 'forceDestroy'])->withTrashed();
        Route::post('/notes/{note}/labels', [NoteController::class, 'syncLabels']);
        Route::post('/notes/{note}/move', [NoteController::class, 'move']);
        Route::post('/notes/{note}/reminder', [NoteController::class, 'setReminder']);
        Route::post('/notes/{note}/convert', [NoteController::class, 'convert']);
        Route::post('/notes/{note}/collaborators', [NoteCollaboratorController::class, 'store']);
        Route::delete('/notes/{note}/collaborators/{user}', [NoteCollaboratorController::class, 'destroy']);

        // Itens de checklist da nota
        Route::post('/notes/{note}/items/reorder', [NoteItemController::class, 'reorder']);
        Route::post('/notes/{note}/items', [NoteItemController::class, 'store']);
        Route::patch('/notes/{note}/items/{item}', [NoteItemController::class, 'update']);
        Route::delete('/notes/{note}/items/{item}', [NoteItemController::class, 'destroy']);

        // Etiquetas (organização das notas)
        Route::post('/labels', [LabelController::class, 'store']);
        Route::patch('/labels/{label}', [LabelController::class, 'update']);
        Route::delete('/labels/{label}', [LabelController::class, 'destroy']);

        // Diário (também criável/editável/excluível pelo chat)
        Route::get('/diary', [DiaryEntryController::class, 'index']);
        Route::post('/diary', [DiaryEntryController::class, 'store']);
        Route::patch('/diary/{entry}', [DiaryEntryController::class, 'update']);
        Route::delete('/diary/{entry}', [DiaryEntryController::class, 'destroy']);
        Route::post('/diary/{entry}/attachments/import', [AttachmentController::class, 'importFromTask']);

        // Anexos (tarefas e entradas do diário)
        Route::post('/attachments', [AttachmentController::class, 'store']);
        Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy']);

        // Histórico de conversas
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);

        // Avisos in-app (sino)
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read']);

        // Preferências de UI
        Route::put('/preferences', [PreferenceController::class, 'update']);

        // Perfil do usuário
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
});
