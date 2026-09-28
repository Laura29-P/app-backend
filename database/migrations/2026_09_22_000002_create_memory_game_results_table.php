<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_game_results', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 100)->index();
            $table->unsignedInteger('score');
            $table->unsignedInteger('moves');
            $table->unsignedInteger('duration_seconds');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_game_results');
    }
};
