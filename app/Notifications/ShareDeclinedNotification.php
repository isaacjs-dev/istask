<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Avisa o dono de um item compartilhado (área, projeto, caderno ou nota) que um
 * convidado recusou/saiu do compartilhamento. Canal database; o front lê via
 * /api/notifications e mostra no sino de avisos (não usa o toast de lembretes).
 */
class ShareDeclinedNotification extends Notification
{
    /** @param string $itemType workspace|project|notebook|note */
    public function __construct(
        public string $itemType,
        public int $itemId,
        public string $itemName,
        public string $byName,
    ) {
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
            'kind'      => 'share_declined',
            'item_type' => $this->itemType,
            'item_id'   => $this->itemId,
            'item_name' => $this->itemName,
            'by_name'   => $this->byName,
            'message'   => $this->message(),
        ];
    }

    private function message(): string
    {
        $labels = [
            'workspace' => 'a área',
            'project'   => 'o projeto',
            'notebook'  => 'o caderno',
            'note'      => 'a nota',
        ];
        $label = $labels[$this->itemType] ?? 'o item';

        return "{$this->byName} recusou o compartilhamento de {$label} «{$this->itemName}».";
    }
}
