<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro dos comandos que o interpretador LOCAL não entendeu e que caíram
     * no Gemini (Gatilho A). Serve para, no futuro, transformar os padrões mais
     * frequentes em regras locais e reduzir as chamadas externas.
     */
    public function up(): void
    {
        Schema::create('comando_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('frase_original');
            $table->string('intent_resolvido')->nullable();
            $table->json('parametros')->nullable();
            $table->float('confianca')->nullable();
            $table->boolean('executado')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comando_logs');
    }
};
