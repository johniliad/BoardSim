import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * The build output goes into public/app so the PHP host can serve it as static
 * files next to the API. Dev proxies /v1 to the PHP dev server, which keeps the
 * same-origin shape in development and production.
 */
export default defineConfig({
  // The build is served from public/app, so asset URLs must be rooted there.
  // Without this, index.html would request /assets/... and every asset would 404.
  base: '/app/',
  plugins: [react()],
  // The date this front-end bundle was produced, shown in the footer byline.
  // Captured once at build time so the artefact records its own provenance.
  define: {
    __BUILD_DATE__: JSON.stringify(new Date().toISOString().slice(0, 10)),
  },
  build: {
    outDir: '../public/app',
    emptyOutDir: true,
    sourcemap: false,
  },
  server: {
    port: 5173,
    proxy: {
      '/v1': {
        // Must match the port the PHP development server is started on:
        //   php -S 127.0.0.1:8100 -t public web/dev-router.php
        // A mismatch here makes every /v1 call fail before it reaches PHP.
        target: 'http://127.0.0.1:8100',
        changeOrigin: false,
      },
    },
  },
});
