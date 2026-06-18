<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Support\TaskRepository;
use App\Support\Workspace;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(private TaskRepository $repo)
    {
    }

    /** Lista as conversas do usuário (ativas + arquivadas). */
    public function index()
    {
        return response()->json([
            'conversations' => $this->repo->conversationsFor(Workspace::user()),
        ]);
    }

    /** Cria uma nova conversa (vazia) e a devolve. */
    public function store()
    {
        $conversation = Workspace::user()->conversations()->create(['title' => null]);
        $conversation->loadCount('messages');

        return response()->json($conversation->toApiArray(), 201);
    }

    /** Renomeia ou (des)arquiva uma conversa. */
    public function update(Request $request, Conversation $conversation)
    {
        $this->guard($conversation);

        $data = $request->validate([
            'title'    => 'sometimes|nullable|string|max:120',
            'archived' => 'sometimes|boolean',
        ]);

        if (array_key_exists('title', $data)) {
            $conversation->title = $data['title'] ?: null;
        }
        if (array_key_exists('archived', $data)) {
            $conversation->archived_at = $data['archived'] ? now() : null;
        }
        $conversation->save();
        $conversation->loadCount('messages');

        return response()->json($conversation->toApiArray());
    }

    /** Mensagens de uma conversa (ao alternar no histórico). */
    public function messages(Conversation $conversation)
    {
        $this->guard($conversation);

        return response()->json([
            'conversationId' => (string) $conversation->id,
            'messages'       => $this->repo->messagesForConversation($conversation),
        ]);
    }

    private function guard(Conversation $conversation): void
    {
        abort_unless($conversation->user_id === Workspace::user()->id, 404);
    }
}
