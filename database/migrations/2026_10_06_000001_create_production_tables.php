<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_users', function (Blueprint $table) {
            $table->string('username', 100)->primary();
            $table->string('name');
            $table->string('password');
            $table->string('role', 20)->default('user');
            $table->boolean('active')->default(true);
            $table->json('permissions');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('production_tokens', function (Blueprint $table) {
            $table->string('hash', 64)->primary();
            $table->string('username', 100);
            $table->foreign('username')->references('username')->on('production_users')->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
        });
        Schema::create('production_records', function (Blueprint $table) {
            $table->bigIncrements('sequence');
            $table->string('kind', 30);
            $table->string('record_id', 191);
            $table->json('payload');
            $table->unique(['kind', 'record_id']);
        });
        Schema::create('production_locks', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('production_locks')->insert(['id' => 1]);
        Schema::create('production_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner', 100)->index();
            $table->string('mime', 30);
            $table->longText('content');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['production_photos', 'production_locks', 'production_records', 'production_tokens', 'production_users'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
