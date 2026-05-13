<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('title', 160);
            $table->decimal('amount', 12, 2);
            $table->date('spent_at');
            $table->string('department', 80)->nullable();
            $table->string('reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id');
            $table->index('spent_at');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
