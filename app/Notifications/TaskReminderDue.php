<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Notifications\Notification;

/**
 * Lembrete de tarefa vencido (Frente B2). Registrado via canal database e
 * exibido no sino de avisos (diferente do lembrete de nota, que tem toast
 * próprio). Disparado pelo polling de /api/tasks/reminders/due.
 */
class TaskReminderDue extends Notification
{
    public function __construct(public Task $task)
    {
    }

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        $title = trim((string) $this->task->title) !== '' ? $this->task->title : 'Tarefa sem título';

        return [
            'task_id' => $this->task->id,
            'title'   => $this->task->title,
            'message' => 'Lembrete: ' . $title,
            'kind'    => 'task_reminder',
        ];
    }
}
