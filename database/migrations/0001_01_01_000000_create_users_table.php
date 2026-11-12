<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('identity_code', 20)->unique();
            $table->string('name', 120);
            $table->string('position', 120)->nullable();
            $table->string('entity', 10);
            $table->string('role', 30);
            $table->boolean('is_active')->default(true);
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            $table->index(['role', 'entity']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_entity_check CHECK (entity IN ('jmto','jmj','jmtm'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('field_officer','mcs_supervisor','maintenance_unit','maintenance_officer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
