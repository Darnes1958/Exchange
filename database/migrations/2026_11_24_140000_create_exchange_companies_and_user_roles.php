<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('prog');
            $table->string('company', 64)->nullable()->index();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('connection_name', 64)->unique();
            $table->string('database_name', 128);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['company']);
            $table->dropColumn(['role', 'company']);
        });
    }
};
