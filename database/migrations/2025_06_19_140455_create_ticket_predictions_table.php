<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('ticket_predictions', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id')->index();
            $table->string('company_name');
            $table->text('subject');
            $table->text('description')->nullable();
            $table->string('ticket_type');
            $table->string('channel');
            $table->json('ticket_data'); // Tutti i dati del ticket in formato JSON
            $table->integer('predicted_minutes')->nullable();
            $table->float('confidence_score', 8, 4)->nullable();
            $table->string('model_version')->nullable();
            $table->json('model_response')->nullable(); // Risposta completa del modello
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('predicted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('ticket_predictions');
    }
};
