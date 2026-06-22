<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capa do caderno (estilo Zoho Notebook): além da cor já existente, permite uma
 * capa do tipo cor sólida, gradiente, padrão decorativo ou imagem enviada.
 * cover_type ∈ color|gradient|pattern|image ; cover_value guarda o valor (cor,
 * id do gradiente/padrão ou caminho do arquivo no disco public).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->string('cover_type', 16)->nullable()->after('color');
            $table->string('cover_value')->nullable()->after('cover_type');
        });
    }

    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn(['cover_type', 'cover_value']);
        });
    }
};
