<?php

use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboards: widgets (KPIs, charts, tables) over saved reports, published
 * like reports (a permission given to roles, a menu entry).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create(Tables::name(Tables::DASHBOARDS), function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('visibility', 16)->default('private'); // private | public
            $table->longText('layout'); // {"context": {...}, "widgets": [...]}
            $table->string('permission', 191)->nullable()->unique();
            $table->boolean('in_menu')->default(false);
            $table->string('menu_group', 120)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(Tables::name(Tables::DASHBOARDS));
    }
};
