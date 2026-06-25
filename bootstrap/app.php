<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sessão expirada / token CSRF inválido (419): nunca mostrar a página de erro 419.
        // O Laravel já converte TokenMismatchException em HttpException(419) antes dos
        // render callbacks, então casamos pelo status. API recebe JSON 419; navegação
        // web volta ao login com aviso. Demais HttpException seguem o fluxo padrão (null).
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Sua sessão expirou. Entre novamente.'], 419);
            }

            return redirect()->guest(route('login'))
                ->withErrors(['email' => 'Sua sessão expirou. Entre novamente.']);
        });
    })->create();
