<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TaskRepository;
use App\Support\Workspace;

/** Avisos in-app (sino): notificações database não lidas, exceto lembretes de nota. */
class NotificationController extends Controller
{
    public function __construct(private TaskRepository $repo)
    {
    }

    public function index()
    {
        $user = Workspace::user();

        return response()->json(['notifications' => $this->repo->notificationsPayload($user)]);
    }

    public function read(string $id)
    {
        $user = Workspace::user();
        $user->unreadNotifications()->where('id', $id)->get()->markAsRead();

        return response()->json(['notifications' => $this->repo->notificationsPayload($user)]);
    }

    public function readAll()
    {
        $user = Workspace::user();
        $user->unreadNotifications->markAsRead();

        return response()->json(['notifications' => $this->repo->notificationsPayload($user)]);
    }
}
