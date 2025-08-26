<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('egg_metadata', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nest_id');
            $table->string('nest_name');
            $table->unsignedBigInteger('egg_id');
            $table->string('egg_name');
            $table->unsignedInteger('port_min');
            $table->unsignedInteger('port_max');
            $table->json('environment_json');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['nest_id', 'egg_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egg_metadata');
    }
};


