<?php

namespace App\Notifications;

use App\Models\Note;
use Illuminate\Notifications\Notification;

/**
 * Lembrete de nota vencido (Fase 4). Registrado via canal database (histórico);
 * o aviso in-app ao usuário é entregue pelo polling de /api/notes/reminders/due,
 * que retorna as notas disparadas para o front exibir um toast.
 */
class NoteReminderDue extends Notification
{
    public function __construct(public Note $note)
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
        return [
            'note_id' => $this->note->id,
            'title'   => $this->note->title,
            'kind'    => 'note_reminder',
        ];
    }
}
