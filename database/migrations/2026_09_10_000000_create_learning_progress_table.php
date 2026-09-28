<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_progress', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 100)->unique();
            $table->string('age_group', 50)->default('peques');
            $table->unsignedInteger('stars')->default(0);
            $table->json('completed_exercises');
            $table->string('current_subject', 50)->default('math');
            $table->unsignedInteger('current_index_in_subject')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_progress');
    }
};