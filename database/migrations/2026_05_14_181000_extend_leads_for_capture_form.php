<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('alternate_phone', 20)->nullable()->after('phone');
            $table->unsignedTinyInteger('children_count')->nullable()->after('email');
            $table->boolean('already_enrolled')->nullable()->after('children_count');
            $table->string('connected_by', 64)->nullable()->after('campaign');
            $table->timestamp('enquiry_at')->nullable()->after('connected_by');
            $table->longText('notes_html')->nullable()->after('next_action_at');
            $table->foreignId('generated_by_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['generated_by_user_id']);
            $table->dropColumn([
                'alternate_phone',
                'children_count',
                'already_enrolled',
                'connected_by',
                'enquiry_at',
                'notes_html',
                'generated_by_user_id',
            ]);
        });
    }
};
