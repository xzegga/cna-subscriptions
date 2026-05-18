# 01_PHASE_1_SETUP.md: Andamiaje y Entorno

## Objetivo
Configurar la estructura de archivos y el sistema de compilación para que React funcione dentro de WordPress.

## Tareas

### 1. Estructura de Directorios
Crear en `wp-content/plugins/cna-subscriptions/`:
- `/assets` (Build output)
- `/includes`
  - `/Core` (Loaders, Activators, Cron)
  - `/Admin` (Settings, UI)
  - `/API` (REST Endpoints, Webhooks)
  - `/Model` (DB, Scheduler Logic)
- `/src` (React Source)
  - `main.tsx`
  - `/components`
  - `/services`
- `vite.config.ts`
- `cna-subscriptions.php`

### 2. Configuración Vite (`vite.config.ts`)
- Configurar `@vitejs/plugin-react`.
- Habilitar `build.manifest = true`.
- Configurar `server.cors = true` y puerto `5173`.
- Input principal: `src/main.tsx`.

### 3. Bridge PHP (`includes/Core/class-cna-assets.php`)
Crear lógica de encolado (`wp_enqueue_scripts`):
- **Dev Mode:** Si `localhost:5173` responde, cargar script tipo módulo desde ahí.
- **Prod Mode:** Si no, leer `assets/.vite/manifest.json` y cargar los archivos compilados.

### 4. Entry Point React (`src/main.tsx`)
- Implementar función que busque DIVs por ID (`cna-product-app`, `cna-checkout-app`, `cna-my-account`).
- Si el DIV existe, montar el componente correspondiente usando `ReactDOM.createRoot`.