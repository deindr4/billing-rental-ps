<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel Spatie Permission versi UUID + teams (tenant_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $teamKey = $columnNames['team_foreign_key'] ?? 'tenant_id';
        $modelKey = $columnNames['model_morph_key'] ?? 'model_id';

        throw_if(empty($tableNames), 'Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        throw_if(! $teams, 'Error: permission.teams harus true untuk aplikasi multi-tenant.');

        Schema::create($tableNames['permissions'], static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 125);
            $table->string('guard_name', 25);
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], static function (Blueprint $table) use ($teamKey) {
            $table->uuid('id')->primary();
            $table->uuid($teamKey)->nullable();
            $table->string('name', 125);
            $table->string('guard_name', 25);
            $table->timestamps();

            $table->index($teamKey, 'roles_team_foreign_key_index');
            $table->unique([$teamKey, 'name', 'guard_name']);
            $table->foreign($teamKey)->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotPermission, $teamKey, $modelKey) {
            $table->uuid($pivotPermission);
            $table->string('model_type');
            $table->uuid($modelKey);
            $table->uuid($teamKey);

            $table->index([$modelKey, 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->index($teamKey, 'model_has_permissions_team_foreign_key_index');

            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->primary([$teamKey, $pivotPermission, $modelKey, 'model_type'],
                'model_has_permissions_permission_model_type_primary');
        });

        Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($tableNames, $pivotRole, $teamKey, $modelKey) {
            $table->uuid($pivotRole);
            $table->string('model_type');
            $table->uuid($modelKey);
            $table->uuid($teamKey);

            $table->index([$modelKey, 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->index($teamKey, 'model_has_roles_team_foreign_key_index');

            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->primary([$teamKey, $pivotRole, $modelKey, 'model_type'],
                'model_has_roles_role_model_type_primary');
        });

        Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
            $table->uuid($pivotPermission);
            $table->uuid($pivotRole);

            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['model_has_permissions']);
        Schema::dropIfExists($tableNames['roles']);
        Schema::dropIfExists($tableNames['permissions']);
    }
};
