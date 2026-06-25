<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A raiz é a landing pública: visitante vê a página inicial (não é redirecionado).
     */
    public function test_guest_sees_public_landing_at_root(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Começar grátis');
    }
}
