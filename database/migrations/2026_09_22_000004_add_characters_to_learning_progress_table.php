<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_progress', function (Blueprint $table) {
            $table->json('owned_characters')->nullable()->after('current_index_in_subject');
            $table->string('selected_character')->default('luna')->after('owned_characters');
        });
    }

    public function down(): void
    {
        Schema::table('learning_progress', function (Blueprint $table) {
            $table->dropColumn(['owned_characters', 'selected_character']);
        });
    }
};
