# Proyecto: B2B SaaS multi-tenant (Laravel 13 + Inertia + Vue 3 + Postgres)

> **Para futuras conversaciones**: este archivo se lee automáticamente al
> trabajar en este directorio. Tiene todo el contexto que se necesita. No
> hace falta que el usuario re-explique decisiones.

---

## Stack

- **Backend**: Laravel 13 + PHP 8.3 + PostgreSQL 16 (con extensión `unaccent`)
- **Frontend**: Inertia.js v2 + Vue 3 (Composition API + `<script setup>`) + Ant Design Vue 4 + Tailwind v4
- **Auth**: Sanctum bearer tokens con abilities
- **Permissions**: Spatie Permission con traits custom
- **Tests**: PHPUnit (Feature + Unit), SQLite in-memory para tests, Postgres para perf
- **Queue**: `database` driver (sin Redis — el usuario no usa Redis a propósito)
- **Storage**: `local` disk (sin S3 — el usuario solo guarda logos, imports, fotos perfil)
- **Build**: Vite + esbuild
- **Dev env**: Windows + Laragon (`C:\laragon\bin\nodejs\node-v22`, `C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe`)
- **Prod env**: Digital Ocean droplet (todavía no configurado — el usuario quiere ayuda con eso cuando llegue)

---

## Decisiones de diseño (NO sugerir cambiar)

- **NO Redis**: descartado conscientemente. Sub-1ms con índices Postgres ya cubre. Cache de queries = premature.
- **NO S3 ni storage externo**: solo guarda fotos perfil, logos, imports. Disk `local`.
- **NO webhooks (todavía)**: documentado como feature premium futura.
- **NO observers cross-módulo**: feature futura.
- **NO code splitting agresivo del bundle**: 2.7MB es aceptable hasta tener tráfico.
- **`/dev/null` no aplica**: PowerShell — usar `$null`, `$env:VAR`, backtick para continuación de línea.
- **Comandos que el usuario corre en dev**: solo `php artisan serve`, `npm run dev/build`, `php artisan queue:work`. No quiere más.

---

## Módulo master template: `Customers`

> **PROPÓSITO**: Customers es el **patrón de referencia** que el scaffold
> `php artisan make:module` clona para crear módulos de negocio nuevos
> (Products, Sales, Categories, Brands, etc.).

### Por qué Customer es el master

Customer tiene todo lo que un módulo de negocio multi-tenant necesita:

- `BelongsToTenant` trait → cada workspace ve solo sus registros (con super bypass).
- Rutas con `permission:X.action` por acción (granular).
- `tenant_id` nullable + `HideSuperScope` automático.
- Audit log polimórfico + soft-delete + trash + restore + force-delete.
- Bulk ops auto-async (> 200 registros), undo 60s, duplicate, edit-all batch.
- Exports (CSV streaming + Excel/PDF/Word async) con límites por formato + memory_limit.
- Import 3-layer dedup + preview/commit two-phase.
- Favoritos polimórficos + recent items + saved views + column selector.
- Plan gating vía `FeatureGate` + `config/features.php`.
- Mobile responsive + dark theme + i18n full (es/en).

### Scaffold disponible

```bash
php artisan make:module {Name} --group=BusinessManagement
```

Genera ~50 archivos (controller, service, model, 9 FormRequests, 6 Jobs,
3 Exports, 1 Import, 6 Pages Vue, 13 Components, config + i18n × 2 idiomas,
migration, factory). Auto-registra el módulo en la tabla `system_modules`,
appendea routes, y agrega entries en `config/polymorphic.php` + `config/purge.php`.

El módulo generado trae 2 campos base: `name` (required) + `description` (text nullable).
Las columnas custom del dominio (price, stock, FKs) se agregan a mano post-scaffold
editando la migration.

El comando vive en `app/Console/Commands/MakeModuleCommand.php`.
Detalle completo del scaffold en [`docs/CREATE-MODULE.md`](docs/CREATE-MODULE.md).

### Lo que el scaffold NO automatiza (manual post-scaffold)

- Entrada en sidebar: `resources/js/Layouts/AppLayout.vue` + `resources/lang/{es,en}/sidebar.php`
- Permisos en `database/seeders/RolesAndPermissionsSeeder.php`
- Plan features específicos en `config/features.php`
- Columnas custom de la migration (FKs, índices del dominio)
- Si tiene FKs entrantes: array `dependents()` del modelo
- Capa API REST opcional (Resource + ApiController + rutas en `routes/api.php`).
  Los módulos generados son web-only (Inertia) por defecto. Solo Customer
  expone API hoy, como patrón de referencia.

---

## Cómo correr cosas en este proyecto

### Tests
```bash
# Filtrado por módulo
& cmd /c "C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe artisan test --filter=Customer 2>&1" | Select-Object -Last 5

# Suite completa
& cmd /c "C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe artisan test 2>&1" | Select-Object -Last 5

# Perf (skipea sin Postgres)
& cmd /c "C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe artisan test --group=performance 2>&1"
```

### Build
```powershell
$env:Path = "C:\laragon\bin\nodejs\node-v22;$env:Path"
& cmd /c "npm run build 2>&1" | Select-Object -Last 3
```

### Migrations
```powershell
& cmd /c "C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe artisan migrate --force 2>&1" | Select-Object -Last 8
```

### Si esbuild se cuelga (raro en Windows)
```powershell
Get-Process | Where-Object { $_.Name -match 'esbuild|node' } | Stop-Process -Force
```

---

## Convenciones de feedback que el usuario espera

- Sin emojis (a menos que él los pida)
- Honestidad brutal — si pregunta "está al 100?" y NO está, decírselo
- Sin elogios redundantes ("excelente pregunta!")
- Respuestas cortas a preguntas cortas
- Code edits con `Edit` tool, no `Write` completo
- Validation siempre antes de afirmar "está hecho" (build + tests)
- Cuando él dice "haz todo lo que tengas que hacer", actuar autonomous
- Español neutro estricto: NO argentinismos (vos/tenés/podés/verificá/abrí/hacé/querés/usá/cambiá/editá/probá/acá/etc.) en código NI en respuestas
