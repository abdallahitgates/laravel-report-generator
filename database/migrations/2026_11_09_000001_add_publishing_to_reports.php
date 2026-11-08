<?php

use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published reports: a permission named after the report (given to roles in
 * the host's permission system) and whether it is listed in the menu.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table(Tables::name(Tables::REPORTS), function (Blueprint $table) {
            $table->string('permission', 191)->nullable()->unique();
            $table->boolean('in_menu')->default(false);
            $table->string('menu_group', 120)->nullable();
            $table->timestamp('published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table(Tables::name(Tables::REPORTS), function (Blueprint $table) {
            $table->dropUnique(Tables::name(Tables::REPORTS).'_permission_unique');
            $table->dropColumn(['permission', 'in_menu', 'menu_group', 'published_at']);
        });
    }
};
