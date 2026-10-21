<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('default_country', 2);
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->index('company_id');
            $table->string('name');
            $table->string('federal_state', 2);
            $table->decimal('contract_hours_per_week', 5, 2);
            $table->jsonb('raw_rules')->default('{}');
            $table->timestamps();
        });

        Schema::create('time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->unsignedInteger('break_minutes')->default(0);
            $table->jsonb('raw_payload')->default('{}');
            $table->timestamps();

            $table->index('work_date');
            $table->index(['employee_id', 'work_date']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX companies_settings_gin_idx ON companies USING GIN (settings)');
            DB::statement('CREATE INDEX employees_raw_rules_gin_idx ON employees USING GIN (raw_rules)');
            DB::statement('CREATE INDEX time_logs_raw_payload_gin_idx ON time_logs USING GIN (raw_payload)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_logs');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('companies');
    }
};
