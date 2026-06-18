<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;

/**
 * Resolve o "usuário atual" do workspace. O protótipo aprovado não possui tela
 * de login, então operamos sempre como o usuário demo semeado. A estrutura
 * (FKs em users) permite plugar autenticação real depois sem mudar o front.
 */
class Workspace
{
    public static function user(): User
    {
        return auth()->user() ?? User::query()->orderBy('id')->firstOrFail();
    }

    public static function defaultProject(): Project
    {
        $user = static::user();

        return Project::query()
            ->where('user_id', $user->id)
            ->where('slug', 'geral')
            ->first()
            ?? Project::query()->where('user_id', $user->id)->orderBy('position')->firstOrFail();
    }
}
