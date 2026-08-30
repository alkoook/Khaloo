<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->string('title');
            $table->text('message');
            $table->string('severity', 20)->default('info')->index();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('alert_states', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('alert_type', 40);
            $table->boolean('active')->default(false)->index();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'alert_type'], 'alert_subject_unique');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');
        Schema::dropIfExists('notifications');
    }
};
