<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('storage');
            $table->string('bucket')->nullable();
            $table->string('operation')->nullable();
            $table->text('source');
            $table->text('destination')->nullable();
            $table->json('options')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_configs');
    }
};
