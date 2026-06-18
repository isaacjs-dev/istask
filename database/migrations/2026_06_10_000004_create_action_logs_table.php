<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pilha de ações (undo/redo). Cada ação que muda estado guarda o snapshot
     * "antes" e "depois" da entidade afetada, permitindo reverter ao status quo.
     */
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');          // create | update | delete
            $table->string('entity_type');   // task | project | note | diary
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('summary');       // resumo legível ("Data de entrega: 15/06 → 20/06")
            $table->json('echo')->nullable(); // payload de eco já formatado para o chat
            $table->boolean('undone')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'undone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};
