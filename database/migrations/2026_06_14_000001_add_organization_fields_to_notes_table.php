<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->boolean('pinned')->default(false)->after('color');
            $table->timestamp('archived_at')->nullable()->after('pinned');
            $table->string('type', 16)->default('text')->after('archived_at');
            $table->string('pattern', 16)->nullable()->after('type');
            $table->timestamp('remind_at')->nullable()->after('pattern');
            $table->string('remind_recurrence', 16)->nullable()->after('remind_at');
            $table->timestamp('remind_last_fired_at')->nullable()->after('remind_recurrence');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropColumn([
                'pinned', 'archived_at', 'type', 'pattern',
                'remind_at', 'remind_recurrence', 'remind_last_fired_at',
            ]);
        });
    }
};
