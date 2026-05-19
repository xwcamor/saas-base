<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * php artisan make:module {Name} [--group=BusinessManagement]
 *
 * Clona el módulo Customer (master template per-tenant, BusinessManagement)
 * hacia un módulo nuevo usando find-replace de identificadores. NO usa stubs
 * reducidos — copia los archivos reales de Customer 1:1 para garantizar paridad
 * de features.
 *
 * Customer trae name + cod + country_id + is_active. Como `cod` y `country_id`
 * son específicos del dominio "cliente comercial" (RUC/RFC/CUIT + país), el
 * scaffold los QUITA — el módulo nuevo arranca con SOLO `name` como campo
 * de dominio. El usuario después agrega columnas custom (price, stock, FKs,
 * etc.) editando la migration, model, FormRequests, Form.vue, Show.vue,
 * columns.js, lang files y exports/imports.
 *
 * Operaciones:
 *   1. Validar nombre (PascalCase singular) y que NO sea Customer (master).
 *   2. Calcular reemplazos (singular/plural, PascalCase/snake_case).
 *   3. Verificar que el módulo no exista (idempotencia).
 *   4. Clonar archivos backend (controller, service, model, requests,
 *      jobs, imports, exports, migration, factory).
 *   5. Clonar archivos frontend (pages, components, config).
 *   6. Clonar tests (si existen en Customer).
 *   7. Clonar config + lang (es/en).
 *   8. Append routes al archivo del grupo (crear si no existe).
 *   9. Insertar entradas en config/polymorphic.php y config/purge.php.
 *  10. Auto-registrar fila en system_modules (si la tabla existe).
 *  11. Aplicar post-procesado: quitar cod/country_id, description y todas las
 *      referencias a esos campos en form/show/columns/lang/exports/import.
 *  12. Imprimir checklist de pasos manuales.
 *
 * En caso de error a mitad, rollback automático (borrar archivos creados).
 *
 * Ejemplos:
 *   php artisan make:module Patient  --group=HealthManagement
 *   php artisan make:module Doctor   --group=HealthManagement
 *   php artisan make:module Provider --group=BusinessManagement
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : Nombre del módulo en singular PascalCase (ej. Patient, Doctor)}
        {--group=BusinessManagement : Grupo de namespace/routing (ej. HealthManagement)}';

    protected $description = 'Clona el módulo Customer hacia un módulo nuevo con find-replace 1:1';

    /** Archivos creados durante este run — usado para rollback si algo falla. */
    protected array $createdFiles = [];

    /** Backup de archivos modificados (path => contenido original) para rollback. */
    protected array $modifiedFiles = [];

    /** Tabla de reemplazos calculada. */
    protected array $replacements = [];

    /** Nombre original (PascalCase singular). */
    protected string $module;

    /** Grupo de routing/namespace (PascalCase). */
    protected string $group;

    public function handle(): int
    {
        $this->module = $this->argument('name');
        $this->group  = $this->option('group');

        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $this->module)) {
            $this->error("El nombre debe estar en PascalCase singular (ej. Patient, Doctor). Recibido: {$this->module}");
            return self::FAILURE;
        }

        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $this->group)) {
            $this->error("El grupo debe estar en PascalCase (ej. HealthManagement). Recibido: {$this->group}");
            return self::FAILURE;
        }

        // No permitir override del master template.
        if ($this->module === 'Customer') {
            $this->error("No se puede generar un módulo llamado Customer — ese es el master template.");
            return self::FAILURE;
        }

        $this->replacements = $this->buildReplacements();

        // Idempotencia: si el módulo ya existe, abort sin tocar nada.
        if ($this->moduleAlreadyExists()) {
            $this->error("El módulo {$this->module} ya existe. Para regenerarlo, borra los archivos manualmente primero.");
            return self::FAILURE;
        }

        $this->info("Generando módulo {$this->module} (grupo: {$this->group})");
        $this->newLine();

        try {
            $this->cloneBackend();
            $this->cloneFrontend();
            $this->cloneTests();
            $this->cloneConfigAndLang();
            $this->cloneMigrationAndFactory();
            $this->appendRoutes();
            $this->registerInPolymorphicConfig();
            $this->registerInPurgeConfig();
            $this->registerInSystemModulesTable();
            $this->applyFieldTransformations();
        } catch (\Throwable $e) {
            $this->error("ERROR durante la generación: {$e->getMessage()}");
            $this->warn("Iniciando rollback automático...");
            $this->rollback();
            return self::FAILURE;
        }

        $this->printChecklist();
        return self::SUCCESS;
    }

    /**
     * Calcula todos los reemplazos. ORDEN crítico: plurales primero,
     * sino el replace de `Customer` también afecta `Customers`.
     */
    protected function buildReplacements(): array
    {
        // Names del módulo nuevo.
        $newSingular = $this->module;                       // Patient
        $newPlural   = $this->plural($newSingular);         // Patients
        $newLower    = Str::camel($newSingular);            // patient
        $newLowerPl  = Str::camel($newPlural);              // patients

        // Group names.
        $newGroup    = $this->group;                        // HealthManagement
        $newGroupLower = Str::snake($newGroup);             // health_management

        // ORDEN: primero todo lo plural/largo, después singular/corto.
        // PHP str_replace aplica los reemplazos en el orden del array y NO
        // re-procesa los strings ya reemplazados, así que es seguro.
        return [
            // ─── Request class names (renombrado completo Customer → New) ───
            // Los archivos de Customer ya están renombrados como Store{Customer}Request.php
            // así que aquí solo necesitamos mapear "Customer" en el class name.
            // Estos van primero para evitar el doble-replace del fallback final.
            'BulkDeleteCustomerRequest'     => "BulkDelete{$newSingular}Request",
            'BulkSetActiveCustomerRequest'  => "BulkSetActive{$newSingular}Request",
            'BulkRestoreCustomerRequest'    => "BulkRestore{$newSingular}Request",
            'EditAllUpdateCustomerRequest'  => "EditAllUpdate{$newSingular}Request",
            'ForceDeleteCustomerRequest'    => "ForceDelete{$newSingular}Request",
            'DeleteCustomerRequest'         => "Delete{$newSingular}Request",
            'UpdateCustomerRequest'         => "Update{$newSingular}Request",
            'StoreCustomerRequest'          => "Store{$newSingular}Request",
            'ImportCustomerRequest'         => "Import{$newSingular}Request",

            // ─── Class names específicos (más largos primero) ───
            'BaseCustomerExportJob'      => "Base{$newSingular}ExportJob",
            'BulkCustomersActionJob'     => "Bulk{$newPlural}ActionJob",
            'GenerateCustomersCsvJob'    => "Generate{$newPlural}CsvJob",
            'GenerateCustomersExcelJob'  => "Generate{$newPlural}ExcelJob",
            'GenerateCustomersPdfJob'    => "Generate{$newPlural}PdfJob",
            'GenerateCustomersWordJob'   => "Generate{$newPlural}WordJob",
            'CustomersImportTemplate'    => "{$newPlural}ImportTemplate",
            'CustomersExport'            => "{$newPlural}Export",
            'CustomersImport'            => "{$newPlural}Import",
            'CustomersWord'              => "{$newPlural}Word",
            'CustomerResource'           => "{$newSingular}Resource",
            'CustomerController'         => "{$newSingular}Controller",
            'CustomerService'            => "{$newSingular}Service",
            'CustomerFactory'            => "{$newSingular}Factory",

            // ─── Component / Vue file names (más largos primero) ───
            'CustomersTrashBulkBar'      => "{$newPlural}TrashBulkBar",
            'CustomersBulkDeleteModal'   => "{$newPlural}BulkDeleteModal",
            'CustomersFavoriteCell'      => "{$newPlural}FavoriteCell",
            'CustomersEditAllTable'      => "{$newPlural}EditAllTable",
            'CustomersMobileBottomBar'   => "{$newPlural}MobileBottomBar",
            'CustomersForceDeleteModal'  => "{$newPlural}ForceDeleteModal",
            'CustomersMobileDrawers'     => "{$newPlural}MobileDrawers",
            'CustomersDetailDrawer'      => "{$newPlural}DetailDrawer",
            'CustomersActionsCell'       => "{$newPlural}ActionsCell",
            'CustomersPageHeader'        => "{$newPlural}PageHeader",
            'CustomersEmptyState'        => "{$newPlural}EmptyState",
            'CustomersBulkBar'           => "{$newPlural}BulkBar",

            // ─── Config exports / config filters function names ───
            'customersFilterFields'      => "{$newLowerPl}FilterFields",
            'customersEmptyFilters'      => "{$newLowerPl}EmptyFilters",
            'hydrateCustomersFilters'    => "hydrate{$newPlural}Filters",
            'customersFiltersToQuery'    => "{$newLowerPl}FiltersToQuery",
            'customersFiltersSummary'    => "{$newLowerPl}FiltersSummary",
            'customersTableColumns'      => "{$newLowerPl}TableColumns",
            'customersTrashColumns'      => "{$newLowerPl}TrashColumns",
            'customersExportableColumns' => "{$newLowerPl}ExportableColumns",
            'customersExportEndpoints'   => "{$newLowerPl}ExportEndpoints",
            'customersTourSteps'         => "{$newLowerPl}TourSteps",

            // ─── Plurales (Pages folder, Components folder) ───
            'Customers/'                 => "{$newPlural}/",
            "\\Customers\\"              => "\\{$newPlural}\\",
            'Customers\\'                => "{$newPlural}\\",
            '"Customers"'                => "\"{$newPlural}\"",
            "'Customers'"                => "'{$newPlural}'",
            'Customers '                 => "{$newPlural} ",
            ' Customers'                 => " {$newPlural}",

            // ─── Singulares (path Customer/, namespace \Customer\) ───
            'Customer/'                  => "{$newSingular}/",
            "\\Customer\\"               => "\\{$newSingular}\\",
            'Customer\\'                 => "{$newSingular}\\",
            'Customer::class'            => "{$newSingular}::class",
            '\App\Models\Customer'       => "\\App\\Models\\{$newSingular}",
            'App\Models\Customer'        => "App\\Models\\{$newSingular}",
            'App\Models\\Customer'       => "App\\Models\\{$newSingular}",
            'use App\Models\Customer;'   => "use App\\Models\\{$newSingular};",
            ' Customer '                 => " {$newSingular} ",
            ' Customer,'                 => " {$newSingular},",
            ' Customer;'                 => " {$newSingular};",
            ' Customer$'                 => " {$newSingular}\$",
            '(Customer '                 => "({$newSingular} ",
            'Customer $'                 => "{$newSingular} \$",
            'extends Customer'           => "extends {$newSingular}",

            // ─── Group namespace (PascalCase) ───
            'BusinessManagement\\Customer'  => "{$newGroup}\\{$newSingular}",
            'BusinessManagement\\Customers' => "{$newGroup}\\{$newPlural}",
            'Controllers\\BusinessManagement' => "Controllers\\{$newGroup}",
            'Services\\BusinessManagement'    => "Services\\{$newGroup}",
            'Jobs\\BusinessManagement'        => "Jobs\\{$newGroup}",
            'Imports\\BusinessManagement'     => "Imports\\{$newGroup}",
            'Exports\\BusinessManagement'     => "Exports\\{$newGroup}",
            'Requests\\BusinessManagement'    => "Requests\\{$newGroup}",
            'Tests\\Feature\\BusinessManagement' => "Tests\\Feature\\{$newGroup}",
            'Feature\\BusinessManagement'     => "Feature\\{$newGroup}",
            'namespace App\Http\Controllers\BusinessManagement' => "namespace App\\Http\\Controllers\\{$newGroup}",
            'namespace Tests\Feature\BusinessManagement' => "namespace Tests\\Feature\\{$newGroup}",

            // ─── snake_case plural (table name, route prefix, slug) ───
            // `customers` como table → tiene que ir antes de `customer` singular.
            'customers_tenant_name_unique_active' => "{$newLowerPl}_tenant_name_unique_active",
            'customers_tenant_cod_unique'        => "{$newLowerPl}_tenant_cod_unique",
            'idx_customers_'             => "idx_{$newLowerPl}_",
            'customers.name'             => "{$newLowerPl}.name",
            'customers.cod'              => "{$newLowerPl}.cod",
            'customers.country_id'       => "{$newLowerPl}.country_id",
            'customers.is_active'        => "{$newLowerPl}.is_active",
            'customers.tenant_id'        => "{$newLowerPl}.tenant_id",
            'customers.created_at'       => "{$newLowerPl}.created_at",
            'customers.updated_at'       => "{$newLowerPl}.updated_at",
            'customers.created_by'       => "{$newLowerPl}.created_by",
            'customers.deleted_at'       => "{$newLowerPl}.deleted_at",
            'customers.deleted_by'       => "{$newLowerPl}.deleted_by",
            'customers.id'               => "{$newLowerPl}.id",
            'customers.slug'             => "{$newLowerPl}.slug",
            'customers.show'             => "{$newLowerPl}.show",
            'customers.index'            => "{$newLowerPl}.index",
            'customers.store'            => "{$newLowerPl}.store",
            'customers.update'           => "{$newLowerPl}.update",
            'customers.edit'             => "{$newLowerPl}.edit",
            'customers.create'           => "{$newLowerPl}.create",
            'customers.destroy'          => "{$newLowerPl}.destroy",
            'customers.trash'            => "{$newLowerPl}.trash",
            'customers.restore'          => "{$newLowerPl}.restore",
            'customers.force_delete'     => "{$newLowerPl}.force_delete",
            'customers.export_'          => "{$newLowerPl}.export_",
            'customers.bulk_'            => "{$newLowerPl}.bulk_",
            'customers.import'           => "{$newLowerPl}.import",
            'customers.undo_'            => "{$newLowerPl}.undo_",
            'customers.delete'           => "{$newLowerPl}.delete",
            'customers.deleteSave'       => "{$newLowerPl}.deleteSave",
            'customers.duplicate'        => "{$newLowerPl}.duplicate",
            'customers.edit_all'         => "{$newLowerPl}.edit_all",
            "'customers'"                => "'{$newLowerPl}'",
            '"customers"'                => "\"{$newLowerPl}\"",
            '/customers'                 => "/{$newLowerPl}",
            'customers/'                 => "{$newLowerPl}/",
            'customers:'                 => "{$newLowerPl}:",
            'on customers'               => "on {$newLowerPl}",
            'on `customers`'             => "on `{$newLowerPl}`",
            "Schema::create('customers'" => "Schema::create('{$newLowerPl}'",
            "Schema::table('customers'"  => "Schema::table('{$newLowerPl}'",
            'create_customers_table'     => "create_{$newLowerPl}_table",
            'dropIfExists(\'customers\')' => "dropIfExists('{$newLowerPl}')",
            'lang/customers'             => "lang/{$newLowerPl}",
            'customers.php'              => "{$newLowerPl}.php",
            ' customers.'                => " {$newLowerPl}.",
            '__(\'customers.'            => "__('{$newLowerPl}.",
            '__("customers.'             => "__(\"{$newLowerPl}.",
            't(\'customers.'             => "t('{$newLowerPl}.",
            't("customers.'              => "t(\"{$newLowerPl}.",
            "trans('customers."          => "trans('{$newLowerPl}.",

            // ─── snake_case singular ───
            // `customer` solito como param de route, var, etc.
            'Customer $customer'         => "{$newSingular} \${$newLower}",
            '$customer '                 => "\${$newLower} ",
            '$customer->'                => "\${$newLower}->",
            '$customer,'                 => "\${$newLower},",
            '$customer)'                 => "\${$newLower})",
            '$customer;'                 => "\${$newLower};",
            "'customer'"                 => "'{$newLower}'",
            '"customer"'                 => "\"{$newLower}\"",
            ' customer '                 => " {$newLower} ",
            '/{customer}'                => "/{{$newLower}}",
            '{customer}'                 => "{{$newLower}}",
            'customer:slug'              => "{$newLower}:slug",
            'customer_id'                => "{$newLower}_id",

            // ─── Group URL prefix (snake_case) ───
            'business_management.'       => "{$newGroupLower}.",
            "'business_management'"      => "'{$newGroupLower}'",
            'business_management/'       => "{$newGroupLower}/",
            'prefix(\'business_management\')' => "prefix('{$newGroupLower}')",
            'name(\'business_management.\')'  => "name('{$newGroupLower}.')",

            // ─── auditModule (string en model) ───
            "auditModule = 'customers'"  => "auditModule = '{$newLowerPl}'",
            "'module'         => 'customers'" => "'module'         => '{$newLowerPl}'",
            "'module' => 'customers'"    => "'module' => '{$newLowerPl}'",

            // ─── Test class names ───
            'class CustomerTestCase'     => "class {$newSingular}TestCase",
            'class CustomerCrudTest'     => "class {$newSingular}CrudTest",
            'class CustomerSoftDeleteTest' => "class {$newSingular}SoftDeleteTest",
            'class CustomerPermissionTest' => "class {$newSingular}PermissionTest",
            'class CustomerImportTest'   => "class {$newSingular}ImportTest",
            'class CustomerExportJobTest' => "class {$newSingular}ExportJobTest",
            'class CustomerAuditLogTest' => "class {$newSingular}AuditLogTest",
            'class CustomerPerformanceTest' => "class {$newSingular}PerformanceTest",
            'class CustomerAdvancedFeaturesTest' => "class {$newSingular}AdvancedFeaturesTest",
            'class CustomerDuplicatePreventionTest' => "class {$newSingular}DuplicatePreventionTest",
            'class CustomerTest '        => "class {$newSingular}Test ",
            'class CustomerServiceTest'  => "class {$newSingular}ServiceTest",
            'CustomerTestCase'           => "{$newSingular}TestCase",

            // ─── Fallback: cualquier Customer/customer restante (al final) ───
            // Cuidado: el orden anterior ya capturó los casos seguros.
            // Solo dejamos un fallback genérico para identificadores no listados.
            'Customers'                  => $newPlural,
            'Customer'                   => $newSingular,
            'customers'                  => $newLowerPl,
            'customer'                   => $newLower,
        ];
    }

    protected function plural(string $singular): string
    {
        // Casos comunes en español que Doctrine inflector no pluraliza bien.
        $map = [
            'Patient'     => 'Patients',
            'Doctor'      => 'Doctors',
            'Transformer' => 'Transformers',
            'Provider'    => 'Providers',
            'Paciente'    => 'Pacientes',
            'Producto'    => 'Productos',
            'Cliente'     => 'Clientes',
        ];
        if (isset($map[$singular])) {
            return $map[$singular];
        }
        return Str::plural($singular);
    }

    protected function moduleAlreadyExists(): bool
    {
        $checks = [
            "app/Models/{$this->module}.php",
            "app/Http/Controllers/{$this->group}/{$this->module}Controller.php",
            "app/Services/{$this->group}/{$this->module}Service.php",
            "config/" . $this->snakePlural() . ".php",
            "resources/js/Pages/{$this->plural($this->module)}/Index.vue",
        ];
        foreach ($checks as $rel) {
            if (File::exists(base_path($rel))) {
                $this->error("Archivo ya existe: {$rel}");
                return true;
            }
        }
        return false;
    }

    protected function snakePlural(): string
    {
        return Str::snake($this->plural($this->module));
    }

    /**
     * Clona un archivo aplicando find-replace.
     */
    protected function cloneFile(string $sourceRel, string $destRel): void
    {
        $sourceAbs = base_path($sourceRel);
        $destAbs   = base_path($destRel);

        if (!File::exists($sourceAbs)) {
            throw new \RuntimeException("Source no existe: {$sourceRel}");
        }
        if (File::exists($destAbs)) {
            $this->warn("  SKIP (ya existe): {$destRel}");
            return;
        }

        $content = file_get_contents($sourceAbs);
        $content = str_replace(array_keys($this->replacements), array_values($this->replacements), $content);

        File::ensureDirectoryExists(dirname($destAbs));
        // Escritura sin BOM — crítico en Windows PowerShell.
        file_put_contents($destAbs, $content);

        $this->createdFiles[] = $destAbs;
        $this->line("  CREADO: {$destRel}");
    }

    protected function cloneBackend(): void
    {
        $this->info('Backend...');
        $singular = $this->module;
        $plural   = $this->plural($singular);
        $group    = $this->group;

        // Controller.
        $this->cloneFile(
            'app/Http/Controllers/BusinessManagement/CustomerController.php',
            "app/Http/Controllers/{$group}/{$singular}Controller.php"
        );

        // Service.
        $this->cloneFile(
            'app/Services/BusinessManagement/CustomerService.php',
            "app/Services/{$group}/{$singular}Service.php"
        );

        // Model.
        $this->cloneFile(
            'app/Models/Customer.php',
            "app/Models/{$singular}.php"
        );

        // NOTA: NO clonamos CustomerResource. La capa API (Resource + ApiController
        // + rutas en routes/api.php) es opcional y específica del módulo — se
        // implementa a mano post-scaffold solo si el módulo va a exponerse via API.
        // Los módulos generados por defecto son web-only (Inertia).

        // FormRequests (9 archivos).
        // Customer ya los tiene renombrados como Store{Customer}Request.php,
        // los reemplazos del array buildReplacements() se encargan del rename
        // a Store{NewName}Request.
        $requests = [
            'StoreCustomerRequest.php'         => "Store{$singular}Request.php",
            'UpdateCustomerRequest.php'        => "Update{$singular}Request.php",
            'DeleteCustomerRequest.php'        => "Delete{$singular}Request.php",
            'ForceDeleteCustomerRequest.php'   => "ForceDelete{$singular}Request.php",
            'BulkDeleteCustomerRequest.php'    => "BulkDelete{$singular}Request.php",
            'BulkSetActiveCustomerRequest.php' => "BulkSetActive{$singular}Request.php",
            'BulkRestoreCustomerRequest.php'   => "BulkRestore{$singular}Request.php",
            'EditAllUpdateCustomerRequest.php' => "EditAllUpdate{$singular}Request.php",
            'ImportCustomerRequest.php'        => "Import{$singular}Request.php",
        ];
        foreach ($requests as $src => $dst) {
            $srcRel = "app/Http/Requests/BusinessManagement/Customer/{$src}";
            if (File::exists(base_path($srcRel))) {
                $this->cloneFile(
                    $srcRel,
                    "app/Http/Requests/{$group}/{$singular}/{$dst}"
                );
            }
        }

        // Imports.
        $this->cloneFile(
            'app/Imports/BusinessManagement/Customers/CustomersImport.php',
            "app/Imports/{$group}/{$plural}/{$plural}Import.php"
        );

        // Exports (3 archivos).
        $exports = [
            'CustomersExport.php'         => "{$plural}Export.php",
            'CustomersWord.php'           => "{$plural}Word.php",
            'CustomersImportTemplate.php' => "{$plural}ImportTemplate.php",
        ];
        foreach ($exports as $src => $dst) {
            $srcRel = "app/Exports/BusinessManagement/Customers/{$src}";
            if (File::exists(base_path($srcRel))) {
                $this->cloneFile(
                    $srcRel,
                    "app/Exports/{$group}/{$plural}/{$dst}"
                );
            }
        }

        // Jobs (6 archivos).
        $jobs = [
            'BaseCustomerExportJob.php'    => "Base{$singular}ExportJob.php",
            'BulkCustomersActionJob.php'   => "Bulk{$plural}ActionJob.php",
            'GenerateCustomersCsvJob.php'  => "Generate{$plural}CsvJob.php",
            'GenerateCustomersExcelJob.php' => "Generate{$plural}ExcelJob.php",
            'GenerateCustomersPdfJob.php'  => "Generate{$plural}PdfJob.php",
            'GenerateCustomersWordJob.php' => "Generate{$plural}WordJob.php",
        ];
        foreach ($jobs as $src => $dst) {
            $srcRel = "app/Jobs/BusinessManagement/Customers/{$src}";
            if (File::exists(base_path($srcRel))) {
                $this->cloneFile(
                    $srcRel,
                    "app/Jobs/{$group}/{$plural}/{$dst}"
                );
            }
        }
    }

    protected function cloneFrontend(): void
    {
        $this->info('Frontend...');
        $plural = $this->plural($this->module);

        // Pages (6 archivos: Index, Show, Form, Delete, Trash, EditAll).
        foreach (['Index', 'Show', 'Form', 'Delete', 'Trash', 'EditAll'] as $page) {
            $src = "resources/js/Pages/Customers/{$page}.vue";
            $dst = "resources/js/Pages/{$plural}/{$page}.vue";
            if (File::exists(base_path($src))) {
                $this->cloneFile($src, $dst);
            }
        }

        // Page configs (5 archivos).
        foreach (['columns', 'filters', 'exports', 'tour', 'trashColumns'] as $cfg) {
            $src = "resources/js/Pages/Customers/config/{$cfg}.js";
            $dst = "resources/js/Pages/{$plural}/config/{$cfg}.js";
            if (File::exists(base_path($src))) {
                $this->cloneFile($src, $dst);
            }
        }

        // Components (todos los Customers/Customers*.vue).
        $componentDir = base_path('resources/js/Components/Customers');
        if (File::isDirectory($componentDir)) {
            foreach (File::files($componentDir) as $file) {
                $filename = $file->getFilename(); // CustomersBulkBar.vue
                // Renombrar Customers* a {Plural}*.
                $newFilename = str_replace('Customers', $plural, $filename);
                $src = "resources/js/Components/Customers/{$filename}";
                $dst = "resources/js/Components/{$plural}/{$newFilename}";
                $this->cloneFile($src, $dst);
            }
        }
    }

    protected function cloneTests(): void
    {
        $this->info('Tests...');
        $singular = $this->module;
        $plural   = $this->plural($singular);
        $group    = $this->group;

        // Feature tests (si existen — Customer puede no tener tests todavía).
        $testDir = base_path('tests/Feature/BusinessManagement/Customers');
        if (File::isDirectory($testDir)) {
            foreach (File::files($testDir) as $file) {
                $filename = $file->getFilename();
                // CustomerCrudTest.php → PatientCrudTest.php
                $newFilename = preg_replace('/^Customer/', $singular, $filename);
                $src = "tests/Feature/BusinessManagement/Customers/{$filename}";
                $dst = "tests/Feature/{$group}/{$plural}/{$newFilename}";
                $this->cloneFile($src, $dst);
            }
        } else {
            $this->line('  SKIP tests/Feature/BusinessManagement/Customers (no existe — Customer aun sin tests)');
        }

        // Unit tests.
        if (File::exists(base_path('tests/Unit/Models/CustomerTest.php'))) {
            $this->cloneFile(
                'tests/Unit/Models/CustomerTest.php',
                "tests/Unit/Models/{$singular}Test.php"
            );
        }
        if (File::exists(base_path('tests/Unit/Services/CustomerServiceTest.php'))) {
            $this->cloneFile(
                'tests/Unit/Services/CustomerServiceTest.php',
                "tests/Unit/Services/{$singular}ServiceTest.php"
            );
        }
    }

    protected function cloneConfigAndLang(): void
    {
        $this->info('Config + i18n...');
        $newLowerPl = $this->snakePlural();

        // config.
        if (File::exists(base_path('config/customers.php'))) {
            $this->cloneFile('config/customers.php', "config/{$newLowerPl}.php");
        }

        // lang es / en.
        foreach (['es', 'en'] as $locale) {
            $src = "resources/lang/{$locale}/customers.php";
            if (File::exists(base_path($src))) {
                $this->cloneFile($src, "resources/lang/{$locale}/{$newLowerPl}.php");
            }
        }
    }

    protected function cloneMigrationAndFactory(): void
    {
        $this->info('Database...');
        $newLowerPl = $this->snakePlural();
        $singular   = $this->module;

        // Migration con timestamp nuevo.
        $sourceMigration = $this->findCustomersMigration();
        if ($sourceMigration === null) {
            throw new \RuntimeException("No se encontró migration create_customers_table");
        }
        $timestamp = date('Y_m_d_His');
        $destMigration = "database/migrations/{$timestamp}_create_{$newLowerPl}_table.php";
        $this->cloneFile($sourceMigration, $destMigration);

        // Factory.
        if (File::exists(base_path('database/factories/CustomerFactory.php'))) {
            $this->cloneFile(
                'database/factories/CustomerFactory.php',
                "database/factories/{$singular}Factory.php"
            );
        }
    }

    protected function findCustomersMigration(): ?string
    {
        $dir = base_path('database/migrations');
        foreach (File::files($dir) as $file) {
            if (str_contains($file->getFilename(), 'create_customers_table')) {
                return 'database/migrations/' . $file->getFilename();
            }
        }
        return null;
    }

    /**
     * Append a routes/{group}.php un bloque de rutas para el módulo nuevo.
     * Si el archivo no existe, lo crea con el scaffold base.
     */
    protected function appendRoutes(): void
    {
        $this->info('Routes...');
        $singular = $this->module;
        $plural   = $this->plural($singular);
        $group    = $this->group;
        $groupSnake = Str::snake($group);
        $newLowerPl = $this->snakePlural();
        $newLower   = Str::camel($singular);

        $routeFile = base_path("routes/{$groupSnake}.php");
        $newFile   = !File::exists($routeFile);

        $controllerFqcn = "App\\Http\\Controllers\\{$group}\\{$singular}Controller";

        if ($newFile) {
            // Crear archivo con scaffold base.
            $header = <<<PHP
<?php

use Illuminate\Support\Facades\Route;
use {$controllerFqcn};

/*
|--------------------------------------------------------------------------
| {$group}
|--------------------------------------------------------------------------
| Modulos generados con make:module. Cada modulo se gobierna por permisos
| Spatie: {$newLowerPl}.view, {$newLowerPl}.create, etc.
|
| ORDEN DE RUTAS CRITICO: las rutas con paths estaticos ({$newLowerPl}/create,
| {$newLowerPl}/trash, {$newLowerPl}/export_*) DEBEN ir ANTES que {$newLowerPl}/{{$newLower}}.
*/

Route::prefix('{$groupSnake}')->name('{$groupSnake}.')->group(function () {

PHP;
            file_put_contents($routeFile, $header);
            $this->createdFiles[] = $routeFile;
            $this->line("  CREADO: routes/{$groupSnake}.php");
        } else {
            $this->modifiedFiles[$routeFile] = file_get_contents($routeFile);
        }

        $block = $this->buildRoutesBlock();

        if ($newFile) {
            // Cerramos el group function.
            file_put_contents($routeFile, $block . "\n});\n", FILE_APPEND);
        } else {
            // Insertar antes del último `});` que cierra el `Route::prefix(...)` group.
            $existing = file_get_contents($routeFile);
            // Buscar el último `});` del archivo.
            $lastClose = strrpos($existing, '});');
            if ($lastClose === false) {
                // Archivo no tiene el patrón esperado — append al final.
                file_put_contents($routeFile, "\n" . $block . "\n", FILE_APPEND);
            } else {
                $before = substr($existing, 0, $lastClose);
                $after  = substr($existing, $lastClose);
                file_put_contents($routeFile, $before . "\n" . $block . "\n" . $after);
            }

            // Insertar use statement si no está.
            $current = file_get_contents($routeFile);
            if (!str_contains($current, "use {$controllerFqcn};")) {
                $current = preg_replace(
                    '/(use Illuminate\\\\Support\\\\Facades\\\\Route;)/',
                    "$1\nuse {$controllerFqcn};",
                    $current,
                    1
                );
                file_put_contents($routeFile, $current);
            }

            $this->line("  MODIFICADO: routes/{$groupSnake}.php (block appended)");
        }
    }

    protected function buildRoutesBlock(): string
    {
        $singular   = $this->module;
        $plural     = $this->plural($singular);
        $newLowerPl = $this->snakePlural();
        $newLower   = Str::camel($singular);
        $groupSnake = Str::snake($this->group);
        $ctrl       = "{$singular}Controller";

        return <<<PHP

    // ── {$plural} ──
    // Bloque generado por make:module. Reordena o ajusta permisos según tu dominio.

    // 1) Trash + restore + force_delete (super only — defense in depth)
    Route::middleware('role:super')->group(function () {
        Route::get('{$newLowerPl}/trash',                  [{$ctrl}::class, 'trash'])->name('{$newLowerPl}.trash');
        Route::post('{$newLowerPl}/bulk_restore',          [{$ctrl}::class, 'bulkRestore'])->name('{$newLowerPl}.bulk_restore');
        Route::post('{$newLowerPl}/{slug}/restore',        [{$ctrl}::class, 'restore'])->name('{$newLowerPl}.restore');
        Route::get('{$newLowerPl}/{slug}/restore',         fn () => redirect()->route('{$groupSnake}.{$newLowerPl}.trash'));
        Route::delete('{$newLowerPl}/{slug}/force_delete', [{$ctrl}::class, 'forceDelete'])->name('{$newLowerPl}.force_delete');
    });

    // 2) Exports (gated por plan_feature por formato)
    Route::middleware('permission:{$newLowerPl}.view')->group(function () {
        Route::middleware(['throttle:5,1', 'plan_feature:export_excel'])
            ->post('{$newLowerPl}/export_excel', [{$ctrl}::class, 'exportExcel'])->name('{$newLowerPl}.export_excel');
        Route::middleware(['throttle:5,1', 'plan_feature:export_pdf'])
            ->post('{$newLowerPl}/export_pdf',   [{$ctrl}::class, 'exportPdf'])->name('{$newLowerPl}.export_pdf');
        Route::middleware(['throttle:5,1', 'plan_feature:export_word'])
            ->post('{$newLowerPl}/export_word',  [{$ctrl}::class, 'exportWord'])->name('{$newLowerPl}.export_word');
        Route::middleware('throttle:5,1')
            ->post('{$newLowerPl}/export_csv',   [{$ctrl}::class, 'exportCsv'])->name('{$newLowerPl}.export_csv');
    });

    // 3) Imports
    Route::middleware(['permission:{$newLowerPl}.create', 'plan_feature:bulk_operations'])->group(function () {
        Route::post('{$newLowerPl}/import',          [{$ctrl}::class, 'import'])->name('{$newLowerPl}.import');
        Route::get('{$newLowerPl}/import_template',  [{$ctrl}::class, 'importTemplate'])->name('{$newLowerPl}.import_template');
    });

    // 4) Bulk operations
    Route::middleware(['permission:{$newLowerPl}.delete', 'plan_feature:bulk_operations', 'throttle:10,1'])->group(function () {
        Route::post('{$newLowerPl}/bulk_delete',     [{$ctrl}::class, 'bulkDelete'])->name('{$newLowerPl}.bulk_delete');
        Route::post('{$newLowerPl}/bulk_set_active', [{$ctrl}::class, 'bulkSetActive'])->name('{$newLowerPl}.bulk_set_active');
    });

    // Undo del ultimo borrado (60s window)
    Route::middleware('permission:{$newLowerPl}.delete')->group(function () {
        Route::post('{$newLowerPl}/undo_last_delete', [{$ctrl}::class, 'undoLastDelete'])->name('{$newLowerPl}.undo_last_delete');
    });

    // Edit All
    Route::middleware('permission:{$newLowerPl}.edit')->group(function () {
        Route::get('{$newLowerPl}/edit_all',         [{$ctrl}::class, 'editAll'])->name('{$newLowerPl}.edit_all');
        Route::post('{$newLowerPl}/edit_all/update', [{$ctrl}::class, 'editAllUpdate'])->name('{$newLowerPl}.edit_all.update');
    });

    // 5) CRUD principal — paths estaticos PRIMERO.
    Route::middleware('permission:{$newLowerPl}.create')->group(function () {
        Route::get('{$newLowerPl}/create', [{$ctrl}::class, 'create'])->name('{$newLowerPl}.create');
        Route::post('{$newLowerPl}',       [{$ctrl}::class, 'store'])->name('{$newLowerPl}.store');
        Route::post('{$newLowerPl}/{{$newLower}}/duplicate', [{$ctrl}::class, 'duplicate'])->name('{$newLowerPl}.duplicate');
    });

    Route::middleware('permission:{$newLowerPl}.view')->group(function () {
        Route::get('{$newLowerPl}',                [{$ctrl}::class, 'index'])->name('{$newLowerPl}.index');
        Route::get('{$newLowerPl}/{{$newLower}}',  [{$ctrl}::class, 'show'])->name('{$newLowerPl}.show');
    });
    Route::middleware('permission:{$newLowerPl}.edit')->group(function () {
        Route::get('{$newLowerPl}/{{$newLower}}/edit', [{$ctrl}::class, 'edit'])->name('{$newLowerPl}.edit');
        Route::put('{$newLowerPl}/{{$newLower}}',      [{$ctrl}::class, 'update'])->name('{$newLowerPl}.update');
    });
    Route::middleware('permission:{$newLowerPl}.delete')->group(function () {
        Route::get('{$newLowerPl}/{{$newLower}}/delete',        [{$ctrl}::class, 'delete'])->name('{$newLowerPl}.delete');
        Route::delete('{$newLowerPl}/{{$newLower}}/deleteSave', [{$ctrl}::class, 'deleteSave'])->name('{$newLowerPl}.deleteSave');
    });
PHP;
    }

    protected function registerInPolymorphicConfig(): void
    {
        $path = base_path('config/polymorphic.php');
        if (!File::exists($path)) return;

        $content = file_get_contents($path);
        $newLowerPl = $this->snakePlural();
        $singular   = $this->module;
        $groupSnake = Str::snake($this->group);

        // Si ya está registrado (uso real, no comentario), skip.
        if (preg_match("/'{$newLowerPl}'\\s*=>\\s*\\[\\s*\\n/m", $content)) {
            $this->line("  SKIP polymorphic ({$newLowerPl} ya registrado)");
            return;
        }

        $entry = "'{$newLowerPl}' => [\n" .
                 "            'model'      => \\App\\Models\\{$singular}::class,\n" .
                 "            'show_route' => '{$groupSnake}.{$newLowerPl}.show',\n" .
                 "        ],\n        ";

        $marker = "// Agrega modulos nuevos aqui";
        if (str_contains($content, $marker)) {
            $this->modifiedFiles[$path] = $content;
            $new = str_replace($marker, $entry . $marker, $content);
            file_put_contents($path, $new);
            $this->line("  MODIFICADO: config/polymorphic.php (entry agregada)");
        } else {
            $this->modifiedFiles[$path] = $content;
            $pattern = "/(\\s+\\],\\s*\\];\\s*$)/";
            $new = preg_replace($pattern, "\n" . $entry . "    ],\n];\n", $content, 1);
            file_put_contents($path, $new);
            $this->line("  MODIFICADO: config/polymorphic.php (entry agregada al final)");
        }
    }

    protected function registerInPurgeConfig(): void
    {
        $path = base_path('config/purge.php');
        if (!File::exists($path)) return;

        $content = file_get_contents($path);
        $newLowerPl = $this->snakePlural();
        $singular   = $this->module;

        if (preg_match("/'{$newLowerPl}'\\s*=>\\s*\\[\\s*\\n/m", $content)) {
            $this->line("  SKIP purge ({$newLowerPl} ya registrado)");
            return;
        }

        $entry = "'{$newLowerPl}' => [\n" .
                 "            'model' => \\App\\Models\\{$singular}::class,\n" .
                 "            'days'  => 90,\n" .
                 "        ],\n        ";

        $marker = "// Suma modulos nuevos aqui:";
        if (str_contains($content, $marker)) {
            $this->modifiedFiles[$path] = $content;
            $new = str_replace($marker, $entry . $marker, $content);
            file_put_contents($path, $new);
            $this->line("  MODIFICADO: config/purge.php (entry agregada)");
        } else {
            $this->modifiedFiles[$path] = $content;
            $pattern = "/(\\s+\\],\\s*\\];\\s*$)/";
            $new = preg_replace($pattern, "\n" . $entry . "    ],\n];\n", $content, 1);
            file_put_contents($path, $new);
            $this->line("  MODIFICADO: config/purge.php (entry agregada al final)");
        }
    }

    /**
     * Auto-registra el módulo en system_modules (si la tabla existe).
     * Es idempotente — si la fila para el módulo nuevo ya existe, skip.
     */
    protected function registerInSystemModulesTable(): void
    {
        if (!Schema::hasTable('system_modules')) {
            $this->warn('Tabla system_modules no existe — skip auto-registro.');
            return;
        }

        $newSingular = $this->module;

        // Idempotente: skip si ya existe una fila con ese name.
        $exists = DB::table('system_modules')->where('name', $newSingular)->exists();
        if ($exists) {
            $this->line("  SKIP system_modules ({$newSingular} ya registrado)");
            return;
        }

        $slug = Str::random(22);
        $newLowerPl = $this->snakePlural();

        // permission_key es UNIQUE y obligatorio. Usamos el patron estandar
        // `{plural}.view` que cualquier rol custom puede asignar via Spatie.
        $permissionKey = "{$newLowerPl}.view";

        // Si el permission_key ya existe (segunda corrida del scaffold con el
        // mismo modulo limpiado), skipear para no chocar contra la unique.
        if (DB::table('system_modules')->where('permission_key', $permissionKey)->exists()) {
            $this->line("  SKIP system_modules ({$permissionKey} ya existe)");
            return;
        }

        DB::table('system_modules')->insert([
            'slug'           => $slug,
            'name'           => $newSingular,
            'permission_key' => $permissionKey,
            'is_active'      => true,
            'created_by'     => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        $this->line("  CREADO en system_modules: {$newSingular} (permission_key={$permissionKey})");
    }

    /**
     * Post-procesado: quita las columnas `cod` y `country_id` (específicas del
     * dominio "cliente comercial") + todas sus referencias en migration, model,
     * Form.vue, Show.vue, columns.js, FormRequests, factory, lang, exports,
     * import. El módulo nuevo queda con SOLO `name` como campo de dominio.
     *
     * Si un patrón no matchea exactamente, emite warning y continúa — no falla.
     */
    protected function applyFieldTransformations(): void
    {
        $this->info('Post-procesado (quitar cod/country_id, dejar solo name)...');

        $singular   = $this->module;
        $plural     = $this->plural($singular);
        $newLower   = Str::camel($singular);
        $newLowerPl = $this->snakePlural();
        $group      = $this->group;

        // Migration generada.
        $migrationPath = $this->findGeneratedMigration($newLowerPl);
        if ($migrationPath !== null) {
            $this->patchMigration($migrationPath, $newLowerPl);
        } else {
            $this->warn("  No se encontró migration generada para {$newLowerPl} — skip patch migration");
        }

        // Model generado.
        $this->patchModel(base_path("app/Models/{$singular}.php"));

        // Form.vue generado.
        $this->patchFormVue(base_path("resources/js/Pages/{$plural}/Form.vue"), $newLower);

        // Show.vue generado.
        $this->patchShowVue(base_path("resources/js/Pages/{$plural}/Show.vue"), $newLower, $newLowerPl);

        // Index columns config.
        $this->patchIndexColumns(base_path("resources/js/Pages/{$plural}/config/columns.js"));

        // FormRequest Store / Update.
        $this->patchFormRequest(base_path("app/Http/Requests/{$group}/{$singular}/Store{$singular}Request.php"));
        $this->patchFormRequest(base_path("app/Http/Requests/{$group}/{$singular}/Update{$singular}Request.php"));

        // Factory.
        $this->patchFactory(base_path("database/factories/{$singular}Factory.php"));

        // Lang files (es/en) — quita keys de cod/country. El módulo queda
        // con keys de name + is_active + sistema. El dev agrega las keys
        // del dominio (price, stock, etc.) post-scaffold.
        $this->patchLangFile(base_path("resources/lang/es/{$newLowerPl}.php"));
        $this->patchLangFile(base_path("resources/lang/en/{$newLowerPl}.php"));

        // Exports + Import.
        $this->patchExportXlsx(base_path("app/Exports/{$group}/{$plural}/{$plural}Export.php"), $newLowerPl);
        $this->patchExportWord(base_path("app/Exports/{$group}/{$plural}/{$plural}Word.php"), $newLowerPl);
        $this->patchImportTemplate(base_path("app/Exports/{$group}/{$plural}/{$plural}ImportTemplate.php"));
        $this->patchImport(base_path("app/Imports/{$group}/{$plural}/{$plural}Import.php"), $singular, $newLowerPl);
    }

    protected function findGeneratedMigration(string $newLowerPl): ?string
    {
        $dir = base_path('database/migrations');
        $matches = [];
        foreach (File::files($dir) as $file) {
            if (str_contains($file->getFilename(), "create_{$newLowerPl}_table")) {
                $matches[] = $file->getRealPath();
            }
        }
        if (empty($matches)) return null;
        rsort($matches);
        return $matches[0];
    }

    /**
     * Helper: aplica un set de transformaciones a un archivo y reporta.
     * $transforms: array de [pattern, replacement, description, isRegex=bool].
     */
    protected function applyPatches(string $file, array $transforms): void
    {
        if (!File::exists($file)) {
            $this->warn("  Could not patch — archivo no existe: " . $this->relPath($file));
            return;
        }
        $rel = $this->relPath($file);
        $content = file_get_contents($file);
        $original = $content;
        $applied = [];
        $missed  = [];

        foreach ($transforms as $t) {
            [$search, $replace, $desc] = [$t[0], $t[1], $t[2]];
            $isRegex = $t[3] ?? false;
            $before = $content;
            if ($isRegex) {
                $result = preg_replace($search, $replace, $content);
                if ($result === null) {
                    $this->warn("  Could not patch (regex error) {$desc} in {$rel}");
                    $missed[] = $desc;
                    continue;
                }
                $content = $result;
            } else {
                $content = str_replace($search, $replace, $content);
            }
            if ($content !== $before) {
                $applied[] = $desc;
            } else {
                $missed[] = $desc;
            }
        }

        if ($content !== $original) {
            file_put_contents($file, $content);
            foreach ($applied as $d) {
                $this->line("  POST: {$d} in {$rel}");
            }
        }
        foreach ($missed as $d) {
            $this->warn("  Could not patch '{$d}' in {$rel}");
        }
    }

    protected function relPath(string $abs): string
    {
        return str_replace(base_path() . DIRECTORY_SEPARATOR, '', $abs);
    }

    protected function patchMigration(string $file, string $newLowerPl): void
    {
        $transforms = [
            // Borrar bloque comentario+linea de cod (2 lineas de comment + 1 statement).
            [
                '/\s*\/\/ `cod` representa el identificador comercial.*?\n\s*\/\/.*?\n\s*\$table->string\(\'cod\',\s*50\)->nullable\(\)->index\(\);\s*\n/s',
                "\n",
                'removed cod column block',
                true,
            ],
            // Country block (comment + unsignedBigInteger).
            [
                '/\s*\/\/ País del cliente\..*?\n\s*\/\/.*?\n\s*\$table->unsignedBigInteger\(\'country_id\'\)->nullable\(\);\s*\n/s',
                "\n",
                'removed country_id unsigned block',
                true,
            ],
            // Foreign key del country (multilinea).
            [
                '/\s*\$table->foreign\(\'country_id\'\)\s*\n\s*->references\(\'id\'\)->on\(\'countries\'\)\s*\n\s*->nullOnDelete\(\);\s*\n/',
                "\n",
                'removed country_id foreign key',
                true,
            ],
            // Unique de cod.
            [
                '/\s*\/\/ Unicidad de cod dentro de cada tenant\.\s*\n\s*\$table->unique\(\[\'tenant_id\',\s*\'cod\'\],\s*\'[^\']+_tenant_cod_unique\'\);\s*\n/',
                "\n",
                'removed cod unique index',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // El módulo generado queda con SOLO `name` como campo de dominio.
        // El dev agrega las columnas custom del dominio editando la migration
        // a mano post-scaffold (price/stock/sku/FKs/etc.). NO agregamos
        // `description` automáticamente — el usuario lo pidió.
    }

    protected function patchModel(string $file): void
    {
        $transforms = [
            // Reemplazar fillable. El Customer model parte el fillable en
            // múltiples líneas: usar regex que tolere espacios/saltos entre
            // 'name' y 'cod', 'country_id'. Conserva las keys de auditoría
            // que viven en la segunda línea (created_by, deleted_by, etc.).
            // Módulo nuevo queda con SOLO `name` como campo de dominio.
            [
                "/'slug',\s*'name',\s*'cod',\s*'country_id',\s*'is_active',\s*'tenant_id',/",
                "'slug', 'name', 'is_active', 'tenant_id',",
                'fillable: removed cod/country_id',
                true,
            ],
            // Remove country() relation method.
            [
                '/\s*public function country\(\): BelongsTo\s*\{\s*return \$this->belongsTo\(Country::class\);\s*\}\s*\n/',
                "\n",
                'removed country() relation method',
                true,
            ],
            // Remove Country use import.
            [
                "/^use App\\\\Models\\\\Country;\s*\n/m",
                '',
                'removed Country import',
                true,
            ],
            // Quitar scope filter de cod.
            [
                '/\s*\$query->when\(\$request->filled\(\'cod\'\), function \(\$q\) use \(\$request, \$tbl\) \{\s*\n\s*\$q->where\("\{\$tbl\}\.cod", \'like\', \'%\' \. \$request->cod \. \'%\'\);\s*\n\s*\}\);\s*\n/',
                "\n",
                'removed scope filter for cod',
                true,
            ],
            // Quitar scope filter de country_id.
            [
                '/\s*\$query->when\(\$request->filled\(\'country_id\'\), function \(\$q\) use \(\$request, \$tbl\) \{\s*\n\s*\$ids = is_array\(\$request->country_id\) \? \$request->country_id : \[\$request->country_id\];\s*\n\s*\$ids = array_filter\(\$ids\);\s*\n\s*if \(!empty\(\$ids\)\) \$q->whereIn\("\{\$tbl\}\.country_id", \$ids\);\s*\n\s*\}\);\s*\n/',
                "\n",
                'removed scope filter for country_id',
                true,
            ],
            // sort whitelist: quitar 'cod'.
            [
                "['id', 'name', 'cod', 'is_active', 'created_at', 'updated_at']",
                "['id', 'name', 'is_active', 'created_at', 'updated_at']",
                'removed cod from sort whitelist',
            ],
            // filterSchema(): quitar la línea de 'cod'.
            [
                "/\s*\['key'\s*=>\s*'cod',[^\]]*'operators'\s*=>\s*\[[^\]]+\]\],\s*\n/",
                "\n",
                'removed cod from filterSchema',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);
    }

    protected function patchFormVue(string $file, string $newLower): void
    {
        if (!File::exists($file)) {
            $this->warn("  Could not patch — Form.vue no existe: " . $this->relPath($file));
            return;
        }

        $transforms = [
            // useForm: quitar cod y country_id.
            [
                "/\s*cod:\s*props\.{$newLower}\?\.cod \?\?\s*'',\s*\n/",
                "\n",
                'useForm: removed cod',
                true,
            ],
            [
                "/\s*country_id:\s*props\.{$newLower}\?\.country_id \?\?\s*null,\s*\n/",
                "\n",
                'useForm: removed country_id',
                true,
            ],
            // Quitar countryOptions de defineProps.
            [
                "/,\s*\n\s*countryOptions:\s*\{\s*type:\s*Array,\s*default:\s*\(\)\s*=>\s*\[\]\s*\},/",
                ',',
                'defineProps: removed countryOptions',
                true,
            ],
            // Quitar FormItem de cod (Col + FormItem).
            [
                '/\s*<Col :xs="24" :md="8">\s*\n\s*<FormItem\s*\n\s*:label="\$t\(\'[^\']+\.cod\'\)"[\s\S]*?<\/FormItem>\s*\n\s*<\/Col>\s*\n/',
                "\n",
                'removed cod FormItem',
                true,
            ],
            // Quitar FormItem de country.
            [
                '/\s*<Col :xs="24" :md="16">\s*\n\s*<FormItem\s*\n\s*:label="\$t\(\'[^\']+\.country\'\)"[\s\S]*?<\/FormItem>\s*\n\s*<\/Col>\s*\n/',
                "\n",
                'removed country FormItem',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Form.vue queda con solo el FormItem de `name`. El dev suma los
        // FormItems del dominio post-scaffold (ver checklist printChecklist).
    }

    protected function patchShowVue(string $file, string $newLower, string $newLowerPl): void
    {
        if (!File::exists($file)) {
            $this->warn("  Could not patch — Show.vue no existe: " . $this->relPath($file));
            return;
        }

        $transforms = [
            // Quitar DescriptionsItem cod.
            [
                '/\s*<DescriptionsItem :label="\$t\(\'[^\']+\.cod\'\)">[\s\S]*?<\/DescriptionsItem>\s*\n/',
                "\n",
                'removed cod DescriptionsItem',
                true,
            ],
            // Quitar DescriptionsItem country.
            [
                '/\s*<DescriptionsItem :label="\$t\(\'[^\']+\.country\'\)">[\s\S]*?<\/DescriptionsItem>\s*\n/',
                "\n",
                'removed country DescriptionsItem',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Show.vue queda con solo el DescriptionsItem de `name` + las columnas
        // de sistema (slug, is_active, timestamps). El dev suma los items del
        // dominio post-scaffold.
    }

    protected function patchIndexColumns(string $file): void
    {
        $transforms = [
            [
                '/\s*\{ title: t\(\'[^\']+\.cod\'\)[^}]*\},\s*\n/',
                "\n",
                'removed cod column',
                true,
            ],
            [
                '/\s*\{ title: t\(\'[^\']+\.country\'\)[^}]*\},\s*\n/',
                "\n",
                'removed country column',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);
    }

    protected function patchFormRequest(string $file): void
    {
        if (!File::exists($file)) return;

        $transforms = [
            // Borrar bloque rule de cod (multi-linea con Rule::unique).
            [
                "/\s*'cod'\s*=>\s*\[\s*\n\s*'nullable',\s*'string',\s*'max:50',\s*\n\s*Rule::unique\([^)]+\)[\s\S]*?\->where\(fn[\s\S]*?\),\s*\n\s*\],\s*\n/",
                "\n",
                'removed cod rule block',
                true,
            ],
            // Borrar la rule de country_id.
            [
                "/\s*'country_id'\s*=>\s*\['nullable',\s*'integer',\s*'exists:countries,id'\],\s*\n/",
                "\n",
                'removed country_id rule',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Module nuevo queda con solo la rule de `name` (que ya viene del
        // Customer master). El dev suma las rules del dominio post-scaffold.

        // Quitar `use Rule` si ya no se usa — al quitar la rule de cod
        // (que era la única que usaba `Rule::unique`), el import queda huérfano.
        $content = file_get_contents($file);
        if (!str_contains($content, 'Rule::')) {
            $newContent = preg_replace("/^use Illuminate\\\\Validation\\\\Rule;\s*\n/m", '', $content);
            if ($newContent !== null && $newContent !== $content) {
                file_put_contents($file, $newContent);
            }
        }
    }

    protected function patchFactory(string $file): void
    {
        if (!File::exists($file)) return;

        $transforms = [
            // CustomerFactory tiene 'cod' con valor `'C-' . strtoupper(Str::random(6))`
            // — no es siempre faker. Regex genérico que mata cualquier expresión
            // de una sola línea entre 'cod' => y la coma final.
            [
                "/\s*'cod'\s*=>\s*[^\n]+,\s*\n/",
                "\n",
                'removed cod from factory definition',
                true,
            ],
            [
                "/\s*'country_id'\s*=>\s*[^\n]+,\s*\n/",
                "\n",
                'removed country_id from factory definition',
                true,
            ],
            [
                "/^use App\\\\Models\\\\Country;\s*\n/m",
                '',
                'removed Country import from factory',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Factory queda con solo `name` + `is_active`. El dev suma campos
        // del dominio post-scaffold (matching the migration columns).
    }

    /**
     * Helper genérico para patchear lang files: quita keys cod/country/cod_*
     * (incluyendo `*_help` / `*_placeholder` que el master template tiene desde
     * que los formularios usan tooltips de ayuda). El módulo nuevo queda con
     * las keys de `name`, `is_active` y demás del sistema.
     */
    protected function patchLangFile(string $file): void
    {
        if (!File::exists($file)) return;

        $transforms = [
            [
                "/\s*'cod'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'cod' key",
                true,
            ],
            [
                "/\s*'cod_hint'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'cod_hint' key",
                true,
            ],
            [
                "/\s*'cod_help'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'cod_help' key",
                true,
            ],
            [
                "/\s*'cod_placeholder'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'cod_placeholder' key",
                true,
            ],
            [
                "/\s*'country'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'country' key",
                true,
            ],
            [
                "/\s*'country_help'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'country_help' key",
                true,
            ],
            [
                "/\s*'country_id_help'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'country_id_help' key",
                true,
            ],
            [
                "/\s*'country_placeholder'\s*=>\s*'[^']*',\s*\n/",
                "\n",
                "removed 'country_placeholder' key",
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);
    }

    /**
     * Patch XLSX export: quitar entries cod/country del columnDefs, agregar description.
     */
    protected function patchExportXlsx(string $file, string $newLowerPl): void
    {
        if (!File::exists($file)) return;

        $transforms = [
            [
                "/\s*'cod'\s*=>\s*\['heading'\s*=>\s*__\([^)]+\),\s*'value'\s*=>\s*fn\([^)]+\)\s*=>[^]]+\],\s*\n/",
                "\n",
                'removed cod from columnDefs',
                true,
            ],
            [
                "/\s*'country'\s*=>\s*\['heading'\s*=>\s*__\([^)]+\),\s*'value'\s*=>\s*fn\([^)]+\)\s*=>[^]]+\],\s*\n/",
                "\n",
                'removed country from columnDefs',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Export queda con columnas de name + sistema (id, slug, is_active,
        // created_at, etc.). El dev suma las columnas del dominio post-scaffold.
    }

    protected function patchExportWord(string $file, string $newLowerPl): void
    {
        if (!File::exists($file)) return;

        $transforms = [
            [
                "/\s*'cod'\s*=>\s*\['heading'\s*=>\s*__\([^)]+\),\s*'value'\s*=>\s*fn\([^)]+\)\s*=>[^]]+\],\s*\n/",
                "\n",
                'removed cod from Word columnDefs',
                true,
            ],
            [
                "/\s*'country'\s*=>\s*\['heading'\s*=>\s*__\([^)]+\),\s*'value'\s*=>\s*fn\([^)]+\)\s*=>[^]]+\],\s*\n/",
                "\n",
                'removed country from Word columnDefs',
                true,
            ],
        ];
        $this->applyPatches($file, $transforms);

        // Word export queda con columnas de name + sistema. El dev suma las
        // del dominio post-scaffold.
    }

    /**
     * Patch import template: reescribir columnas a [name, description, is_active].
     */
    protected function patchImportTemplate(string $file): void
    {
        if (!File::exists($file)) return;

        $content = file_get_contents($file);
        $original = $content;

        // Reescribir el método array() a [name, is_active] — el módulo nuevo
        // arranca con SOLO name como campo de dominio. El dev expande el
        // template con las columnas del dominio editando este archivo.
        $newArray = "    public function array(): array\n    {\n        return [\n            ['name', 'is_active'],\n            ['Ejemplo 1', '1'],\n            ['Ejemplo 2', '1'],\n            ['Ejemplo 3', '0'],\n        ];\n    }";
        $content = preg_replace(
            '/public function array\(\): array\s*\{\s*\n\s*return \[\s*\n[\s\S]*?\];\s*\n\s*\}/',
            $newArray,
            $content,
            1
        );

        // Cambiar A1:D1 → A1:B1, ['A','B','C','D'] → ['A','B'].
        $content = str_replace("'A1:D1'", "'A1:B1'", $content);
        $content = str_replace("['A', 'B', 'C', 'D']", "['A', 'B']", $content);

        // Quitar comments de cod (B1) e ISO (C1) que vienen del template Customer.
        $content = preg_replace(
            '/\s*\$commentCod\s*=\s*\$sheet->getComment\(\'B1\'\);[\s\S]*?\$commentCod->setHeight\(\'60pt\'\);\s*\n/',
            "\n",
            $content
        );
        $content = preg_replace(
            '/\s*\$commentIso\s*=\s*\$sheet->getComment\(\'C1\'\);[\s\S]*?\$commentIso->setHeight\(\'80pt\'\);\s*\n/',
            "\n",
            $content
        );
        // Re-targetear comment is_active de D1 a B1.
        $content = str_replace("\$sheet->getComment('D1')", "\$sheet->getComment('B1')", $content);

        if ($content !== $original) {
            file_put_contents($file, $content);
            $this->line("  POST: rewrote template columns to [name, is_active] in " . $this->relPath($file));
        } else {
            $this->warn("  Could not patch import template columns in " . $this->relPath($file));
        }
    }

    /**
     * Patch import: quita la lectura de cod/country_iso del template Customer.
     * El módulo nuevo arranca con SOLO `name` + `is_active`. El dev agrega
     * las columnas del dominio post-scaffold editando este archivo.
     */
    protected function patchImport(string $file, string $singular, string $newLowerPl): void
    {
        if (!File::exists($file)) return;

        $content = file_get_contents($file);
        $original = $content;

        // Quitar import de Country.
        $content = preg_replace("/^use App\\\\Models\\\\Country;\s*\n/m", '', $content);

        // Quitar property countryIsoCache.
        $content = preg_replace(
            "/\s*\/\*\* Cache iso_code → country_id[^*]*\*\/\s*\n\s*protected array \\\$countryIsoCache = \[\];\s*\n/",
            "\n",
            $content
        );
        // Quitar la precarga ISO → id en el constructor.
        $content = preg_replace(
            "/\s*\/\/ Precarga ISO → id[\s\S]*?->each\(function \(\\\$c\) \{\s*\n\s*\\\$this->countryIsoCache\[mb_strtoupper\(trim\(\\\$c->iso_code\)\)\] = \\\$c->id;\s*\n\s*\}\);\s*\n/",
            "\n",
            $content
        );

        // Quitar dedup de cod.
        $content = preg_replace(
            "/\s*\/\/ Dedup intra-archivo por cod[\s\S]*?\\\$seenInFileByCod\s*=\s*\[\];\s*\n/",
            "\n",
            $content
        );
        $content = preg_replace(
            "/\s*\\\$cod = \\\$this->normalizeCod\(\\\$row\['cod'\] \?\? null\);[\s\S]*?\\\$seenInFileByCod\[\\\$cod\] = \\\$absoluteRow;\s*\n\s*\}\s*\n/",
            "\n",
            $content
        );

        // Quitar countryIso + countryId reads del loop.
        $content = preg_replace(
            "/\s*\\\$countryIso = \\\$this->normalizeIso\(\\\$row\['country_iso'\] \?\? null\);\s*\n\s*\\\$countryId\s*=\s*\\\$countryIso !== null \? \(\\\$this->countryIsoCache\[\\\$countryIso\] \?\? null\) : null;\s*\n/",
            "\n",
            $content
        );

        // Update block: quitar las líneas de cod/country_id del $patch (sin
        // reemplazar por description ni nada — el módulo nuevo solo update name).
        $content = preg_replace(
            "/\s*if \(\\\$cod !== null && \\\$existing->cod !== \\\$cod\)\s*\\\$patch\['cod'\]\s*=\s*\\\$cod;\s*\n\s*if \(\\\$countryId !== null && \\\$existing->country_id !== \\\$countryId\) \{\s*\n\s*\\\$patch\['country_id'\] = \\\$countryId;\s*\n\s*\}\s*\n/",
            "\n",
            $content
        );

        // create() block: solo name + is_active + created_by.
        $createPattern = "/(Customer|{$singular})::create\(\[\s*\n\s*'name'\s*=>\s*\\\$name,\s*\n\s*'cod'\s*=>\s*\\\$cod,\s*\n\s*'country_id'\s*=>\s*\\\$countryId,\s*\n\s*'is_active'\s*=>\s*\\\$isActive,\s*\n\s*'created_by'\s*=>\s*Auth::id\(\),/";
        $content = preg_replace(
            $createPattern,
            "{$singular}::create([\n                        'name'        => \$name,\n                        'is_active'   => \$isActive,\n                        'created_by'  => Auth::id(),",
            $content
        );

        // Limpiar entries 'cod' / 'country_iso' del preview array.
        $content = preg_replace("/\s*'cod'\s*=>\s*\\\$cod,\s*\n/", "\n", $content);
        $content = preg_replace("/\s*'country_iso'\s*=>\s*\\\$countryIso,\s*\n/", "\n", $content);

        // Quitar helpers normalizeCod y normalizeIso (ya no se usan).
        $content = preg_replace(
            "/\s*protected function normalizeCod\([\s\S]*?return \\\$cod === '' \? null : \\\$cod;\s*\n\s*\}\s*\n/",
            "\n",
            $content
        );
        $content = preg_replace(
            "/\s*protected function normalizeIso\([\s\S]*?return \\\$iso === '' \? null : \\\$iso;\s*\n\s*\}\s*\n/",
            "\n",
            $content
        );

        // Actualizar phpdoc del array preview: row con solo name + is_active + action.
        $content = preg_replace(
            "/@var array<int, array\{row:int, name:string, cod:\?string, country_iso:\?string, is_active:bool, action:string\}>/",
            "@var array<int, array{row:int, name:string, is_active:bool, action:string}>",
            $content
        );

        if ($content !== $original) {
            file_put_contents($file, $content);
            $this->line("  POST: rewrote import to [name, is_active] only in " . $this->relPath($file));
        } else {
            $this->warn("  Could not patch import in " . $this->relPath($file));
        }
    }

    /**
     * Rollback: borra archivos creados y restaura modificados.
     */
    protected function rollback(): void
    {
        foreach ($this->createdFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
                $this->line("  REVERTIDO (deleted): " . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path));
            }
        }
        foreach ($this->modifiedFiles as $path => $original) {
            file_put_contents($path, $original);
            $this->line("  REVERTIDO (restored): " . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path));
        }
    }

    protected function printChecklist(): void
    {
        $singular    = $this->module;
        $plural      = $this->plural($singular);
        $newLowerPl  = $this->snakePlural();
        $newLower    = Str::camel($singular);
        $groupSnake  = Str::snake($this->group);

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info("  MODULO {$this->module} GENERADO");
        $this->info('═══════════════════════════════════════════════════════════');
        $this->line("  Archivos creados:     " . count($this->createdFiles));
        $this->line("  Archivos modificados: " . count($this->modifiedFiles));
        $this->newLine();

        $this->line("Ubicaciones principales:");
        $this->line("  Backend:    app/Http/Controllers/{$this->group}/{$singular}Controller.php");
        $this->line("  Service:    app/Services/{$this->group}/{$singular}Service.php");
        $this->line("  Model:      app/Models/{$singular}.php");
        $this->line("  Migration:  database/migrations/*_create_{$newLowerPl}_table.php");
        $this->line("  Factory:    database/factories/{$singular}Factory.php");
        $this->line("  Frontend:   resources/js/Pages/{$plural}/  (6 paginas + 5 configs)");
        $this->line("  Components: resources/js/Components/{$plural}/  (13 componentes)");
        $this->line("  Tests:      tests/Feature/{$this->group}/{$plural}/");
        $this->line("  Routes:     routes/{$groupSnake}.php  (bloque appendeado)");
        $this->line("  Config:     config/{$newLowerPl}.php");
        $this->line("  Lang:       resources/lang/{es,en}/{$newLowerPl}.php");
        $this->newLine();

        $this->warn("PASOS MANUALES OBLIGATORIOS (sin esto el modulo no funciona):");
        $this->newLine();

        $this->line("  1) Migrar la base de datos");
        $this->line("     php artisan migrate");
        $this->line("     Si la migracion falla, revisa el archivo generado y reintenta.");
        $this->newLine();

        $this->line("  2) Permisos Spatie en el seeder de roles");
        $this->line("     Editar: database/seeders/RolesAndPermissionsSeeder.php");
        $this->line("     Agregar los 4 permisos basicos al array de permisos:");
        $this->line("         '{$newLowerPl}.view'");
        $this->line("         '{$newLowerPl}.create'");
        $this->line("         '{$newLowerPl}.edit'");
        $this->line("         '{$newLowerPl}.delete'");
        $this->line("     Asignar al rol super (y a admin/user segun corresponda).");
        $this->line("     Luego correr: php artisan db:seed --class=RolesAndPermissionsSeeder");
        $this->newLine();

        $this->line("  3) Sidebar (icono + entrada en el menu lateral)");
        $this->line("     resources/js/Layouts/AppLayout.vue");
        $this->line("       Importar el icono deseado de @ant-design/icons-vue.");
        $this->line("       Agregar el item en el array correspondiente al grupo:");
        $this->line("         { key: '{$newLowerPl}',");
        $this->line("           label: t('sidebar.{$newLowerPl}'),");
        $this->line("           icon: TuIcono,");
        $this->line("           href: route('{$groupSnake}.{$newLowerPl}.index'),");
        $this->line("           inertia: true,");
        $this->line("           visible: () => can('{$newLowerPl}.view') }");
        $this->line("     Agregar la traduccion en los 2 archivos de lang:");
        $this->line("       resources/lang/es/sidebar.php  =>  '{$newLowerPl}' => 'Nombre en espanol'");
        $this->line("       resources/lang/en/sidebar.php  =>  '{$newLowerPl}' => 'Name in english'");
        $this->newLine();

        $this->line("  4) Verificar build y limpieza de cache");
        $this->line("     npm run build");
        $this->line("     php artisan config:clear");
        $this->line("     php artisan route:clear");
        $this->newLine();

        $this->warn("PASOS RECOMENDADOS (segun el dominio del modulo):");
        $this->newLine();

        $this->line("  5) Columnas del dominio en la migracion");
        $this->line("     El scaffold solo trae 'name' (required) como campo de dominio,");
        $this->line("     mas las columnas del sistema (slug, is_active, tenant_id, audit).");
        $this->line("     Editar la migracion para sumar campos especificos:");
        $this->line("         price, stock, sku, description, birth_date, FKs, etc.");
        $this->line("     Tambien sumarlas al fillable del modelo, casts, factory,");
        $this->line("     Form.vue, Show.vue, columns.js, lang files, exports, imports");
        $this->line("     y las reglas de los FormRequests.");
        $this->newLine();

        $this->line("  6) Relaciones del modelo (FKs salientes)");
        $this->line("     Si el modulo tiene FKs hacia otras tablas, agregar el metodo");
        $this->line("     belongsTo() correspondiente en app/Models/{$singular}.php");
        $this->line("     y cargar la relacion con with() en el Service.");
        $this->newLine();

        $this->line("  7) Dependientes (FKs entrantes a este modulo)");
        $this->line("     Si OTROS modelos referencian a {$singular} con FK, declarar el");
        $this->line("     metodo dependents() para que el sistema avise antes de borrar:");
        $this->line("       app/Models/{$singular}.php  =>  public function dependents(): array");
        $this->newLine();

        $this->line("  8) Plan gating (si el modulo es premium)");
        $this->line("     Si el modulo debe estar disponible solo en planes pro/enterprise:");
        $this->line("       a) Sumar la feature en config/features.php (matrix de planes).");
        $this->line("       b) Aplicar middleware('plan_feature:nombre_feature') en las");
        $this->line("          rutas correspondientes en routes/{$groupSnake}.php");
        $this->line("       c) Sumar canUsePlanFeature() al visible del item del sidebar.");
        $this->newLine();

        $this->line("  9) Filtros avanzados (opcional)");
        $this->line("     Para usar el query builder de filtros avanzados, declarar:");
        $this->line("       app/Models/{$singular}.php  =>  public static function filterSchema(): array");
        $this->line("     Ejemplo en app/Models/Customer.php (master template).");
        $this->newLine();

        $this->line(" 10) Data source para Automatizaciones (opcional)");
        $this->line("     Si quieres que las automatizaciones puedan consultar este modulo:");
        $this->line("       a) Crear app/Services/Automations/DataSources/{$plural}DataSource.php");
        $this->line("          implementando DataSourceContract.");
        $this->line("       b) Registrarlo en DataSourceRegistry::register().");
        $this->line("     Detalles en docs/AUTOMATIONS.md (seccion 8).");
        $this->newLine();

        $this->line(" 11) Capa API REST (opcional — el scaffold NO la genera)");
        $this->line("     Por defecto los modulos generados son web-only (Inertia).");
        $this->line("     Si necesitas exponer el modulo via API REST:");
        $this->line("       a) Crear app/Http/Resources/{$singular}Resource.php");
        $this->line("          (mirar CustomerResource.php como referencia).");
        $this->line("       b) Crear app/Http/Controllers/Api/V1/{$singular}ApiController.php");
        $this->line("          (mirar CustomerApiController.php como referencia).");
        $this->line("       c) Agregar las rutas en routes/api.php con abilities Sanctum:");
        $this->line("          {$newLowerPl}:read / {$newLowerPl}:write / {$newLowerPl}:delete");
        $this->line("       d) Documentar con anotaciones Scribe y regenerar docs:");
        $this->line("          php artisan scribe:generate");
        $this->newLine();

        $this->line(" 12) Tests");
        $this->line("     El scaffold clona los tests si Customer los tiene. Verificar:");
        $this->line("       php artisan test --filter={$singular}");
        $this->newLine();

        $this->info("URL del modulo nuevo: /{$groupSnake}/{$newLowerPl}");
        $this->info("Documentacion completa: docs/CREATE-MODULE.md");
        $this->info('═══════════════════════════════════════════════════════════');
    }
}
