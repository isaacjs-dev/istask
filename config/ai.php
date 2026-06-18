<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Driver do assistente de IA
    |--------------------------------------------------------------------------
    |
    | "rules"     -> motor de regras local (interpreta comandos em PT-BR sem
    |                custo nem chave de API). É o padrão e sempre funciona.
    | "anthropic" -> usa a API da Anthropic (Claude) para interpretar o comando
    |                em ações estruturadas, com fallback automático para o motor
    |                de regras caso a chamada falhe ou não haja chave.
    |
    */
    'driver' => env('AI_DRIVER', 'rules'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model'   => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'max_tokens' => 1024,
    ],
];
