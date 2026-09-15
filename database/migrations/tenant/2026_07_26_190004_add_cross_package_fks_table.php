<?php

declare(strict_types=1);

use CoreX\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Final integration migration of the tenant-DB chain (C4/D14, audit F17):
// cross-package FKs the owning packages' own DDL deliberately omit (inline
// FK would fix a publication order between corex/core, corex/tenancy and
// corex/auth that isn't guaranteed — DB_SCHEMA.md §Конфликты C4, resolved
// variant "б"). All four land here, on whichever package's migration runs
// last in the golden-template chain:
//   1) users.department_id            → sys_departments   (nullable, SET NULL)
//   2) sys_departments.workspace_id    → wsp_workspaces     (NOT NULL, RESTRICT)
//   3) sys_departments.head_user_id    → users              (nullable, SET NULL)
//   4) sys_department_user.user_id     → users              (PK column, CASCADE)
return new class extends Migration
{
    public function up(): void
    {
        $departments = Config::departmentsTable();
        $departmentUser = Config::departmentUserTable();

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_department_fk FOREIGN KEY (department_id) REFERENCES {$departments} (id) ON DELETE SET NULL");
        DB::statement("ALTER TABLE {$departments} ADD CONSTRAINT sys_dept_workspace_fk FOREIGN KEY (workspace_id) REFERENCES wsp_workspaces (id) ON DELETE RESTRICT");
        DB::statement("ALTER TABLE {$departments} ADD CONSTRAINT sys_dept_head_user_fk FOREIGN KEY (head_user_id) REFERENCES users (id) ON DELETE SET NULL");
        DB::statement("ALTER TABLE {$departmentUser} ADD CONSTRAINT sys_du_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE");
    }

    public function down(): void
    {
        $departments = Config::departmentsTable();
        $departmentUser = Config::departmentUserTable();

        DB::statement("ALTER TABLE {$departmentUser} DROP CONSTRAINT sys_du_user_fk");
        DB::statement("ALTER TABLE {$departments} DROP CONSTRAINT sys_dept_head_user_fk");
        DB::statement("ALTER TABLE {$departments} DROP CONSTRAINT sys_dept_workspace_fk");
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_department_fk');
    }
};
