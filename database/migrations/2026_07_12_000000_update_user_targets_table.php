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
        Schema::table('user_targets', function (Blueprint $table) {
            $table->string('product_name')->nullable()->change();
            $table->string('target_type')->default('qualified_leads')->after('user_id');
            $table->string('period')->default('monthly')->after('target_type');
            $table->integer('target_value')->default(0)->after('period');
            
            // Drop old manual entry columns if they exist
            if (Schema::hasColumn('user_targets', 'target')) {
                $table->dropColumn('target');
            }
            if (Schema::hasColumn('user_targets', 'achieved')) {
                $table->dropColumn('achieved');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_targets', function (Blueprint $table) {
            $table->string('product_name')->nullable(false)->change();
            $table->dropColumn(['target_type', 'period', 'target_value']);
            $table->integer('target')->default(0);
            $table->integer('achieved')->default(0);
        });
    }
};
