<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name', 150);
            $table->json('policy');
        });
        Schema::create('employees', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('unit_id', 64)->index();
            $table->foreign('unit_id')->references('id')->on('units');
            $table->string('email', 200)->unique();
            $table->string('password_hash', 255);
            $table->json('data');
        });
        Schema::create('outlets', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('unit_id', 64)->index();
            $table->foreign('unit_id')->references('id')->on('units');
            $table->json('data');
        });
        Schema::create('cuti_sessions', function (Blueprint $table) {
            $table->string('token', 64)->primary();
            $table->string('employee_id', 64);
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->dateTime('expires_at')->index();
        });
        Schema::create('requests', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('employee_id', 64);
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->string('unit_id', 64);
            $table->foreign('unit_id')->references('id')->on('units');
            $table->string('position', 40);
            $table->string('status', 20);
            $table->string('month', 7);
            $table->json('data');
            $table->index(['unit_id', 'month', 'position', 'status'], 'requests_scope');
        });
        Schema::create('operations', function (Blueprint $table) {
            $table->string('actor_id', 64);
            $table->string('key', 150);
            $table->string('hash', 64);
            $table->json('result');
            $table->primary(['actor_id', 'key']);
        });
        Schema::create('audit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('unit_id', 64)->index();
            $table->string('actor_id', 64);
            $table->string('action', 60);
            $table->string('object_id', 150);
            $table->dateTime('at', 3)->index();
        });
        Schema::create('mail_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_key', 150)->unique();
            $table->string('unit_id', 64)->index();
            $table->string('request_id', 64);
            $table->foreign('request_id')->references('id')->on('requests');
            $table->string('kind', 30);
            $table->dateTime('due_at', 3);
            $table->string('state', 20)->default('QUEUED');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('lease_until', 3)->nullable();
            $table->string('last_error', 100)->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['state', 'due_at']);
        });
        Schema::create('mail_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('job_id');
            $table->foreign('job_id')->references('id')->on('mail_jobs');
            $table->string('recipient', 200);
            $table->string('state', 20);
            $table->string('subject');
            $table->text('body');
            $table->unique(['job_id', 'recipient']);
        });
    }

    public function down(): void
    {
        foreach (['mail_deliveries', 'mail_jobs', 'audit', 'operations', 'requests', 'cuti_sessions', 'outlets', 'employees', 'units'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
