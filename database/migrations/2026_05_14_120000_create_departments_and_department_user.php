<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120)->unique();
            $table->string('category', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('department_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->primary(['user_id', 'department_id']);
        });

        $now = now();
        DB::table('departments')->insert([
            ['code' => 'PM', 'name' => 'Performance Marketing', 'category' => 'Internal', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'IM', 'name' => 'Influence Marketing', 'category' => 'Internal', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SALES', 'name' => 'Sales', 'category' => 'Internal', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'OPS', 'name' => 'Operations', 'category' => 'Internal', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('department', 32)->nullable()->change();
        });

        $deptByCode = DB::table('departments')->pluck('id', 'code')->all();

        $users = DB::table('users')->whereNotNull('department')->whereNull('deleted_at')->get(['id', 'department']);
        foreach ($users as $row) {
            $code = (string) $row->department;
            if (! isset($deptByCode[$code])) {
                continue;
            }
            $deptId = $deptByCode[$code];
            DB::table('department_user')->insertOrIgnore([
                'user_id' => $row->id,
                'department_id' => $deptId,
                'is_primary' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('departments');
        // users.department stays VARCHAR(32); reverting to ENUM can break after custom codes exist.
    }
};
