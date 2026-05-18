import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  build: {
    // Generar un manifest.json para que PHP sepa qué archivo cargar en producción
    manifest: true,
    // Carpeta de salida (Production build)
    outDir: 'assets',
    // Limpiar carpeta assets antes de construir
    emptyOutDir: true,
    rollupOptions: {
      // Nuestro punto de entrada (el archivo que inicia React)
      input: path.resolve(__dirname, 'src/main.tsx'),
      output: {
        // Mantener nombres de archivo predecibles
        entryFileNames: 'js/[name]-[hash].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return 'css/[name]-[hash][extname]';
          }
          return 'assets/[name]-[hash][extname]';
        },
      },
    },
  },
  server: {
    // Importante para que funcione dentro de WordPress local (CORS)
    cors: true,
    strictPort: true,
    port: 5173,
    hmr: {
      protocol: 'ws',
      host: 'localhost',
    },
  },
});