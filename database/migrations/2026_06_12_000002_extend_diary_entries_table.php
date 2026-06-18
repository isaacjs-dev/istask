<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diary_entries', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('task_id')->constrained()->nullOnDelete();
            $table->string('title')->nullable()->after('project_id');
            $table->string('activity_type', 1)->nullable()->after('title');     // D/A/R/C/T/P/S/V/E/O
            $table->string('status_from')->nullable()->after('activity_type');
            $table->string('status_to')->nullable()->after('status_from');
            $table->string('source')->default('manual')->after('status_to');    // manual | auto | auto_split
            $table->string('movement_key')->nullable()->after('source');
            $table->string('moved_by')->nullable()->after('movement_key');
            $table->text('observations')->nullable()->after('description');
            $table->text('results')->nullable()->after('observations');
            $table->text('difficulties')->nullable()->after('results');
            $table->text('next_steps')->nullable()->after('difficulties');
            $table->unsignedTinyInteger('progress')->nullable()->after('next_steps');
            $table->integer('duration_minutes')->nullable()->after('progress');

            $table->unique('movement_key');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('diary_entries', function (Blueprint $table) {
            $table->dropUnique(['movement_key']);
            $table->dropIndex(['source']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn([
                'title', 'activity_type', 'status_from', 'status_to', 'source',
                'movement_key', 'moved_by', 'observations', 'results', 'difficulties',
                'next_steps', 'progress', 'duration_minutes',
            ]);
        });
    }
};
