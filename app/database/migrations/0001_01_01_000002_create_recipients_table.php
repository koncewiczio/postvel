<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->index();
            $table->uuid('batch_id')->index();
            $table->string('email');
            $table->string('status');
            $table->json('replacements');
            $table->json('headers')->nullable();
            $table->text('debug')->nullable();
            $table->string('postfix_id')
                ->unique()
                ->collation('utf8mb4_bin')
                ->nullable();
            $table->string('postfix_status')->nullable();
            $table->text('postfix_response')->nullable();
            $table->timestamps(6);

            $table->index(['message_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipients');
    }
};
