<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A automação do diário (criar/fechar períodos nas movimentações) é feita
        // explicitamente pelo App\Services\Diary\DiaryService nos controllers e na
        // IA — não via observer, para termos ator, status anterior/novo e
        // idempotência (evita criação duplicada em refresh/repetição).
    }
}
