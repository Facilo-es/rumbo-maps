// Heavy ES module chunks. Every file lands directly in dist/ with a stable name and no hash,
// so the loader and the MapLibre worker URL can resolve them relative to each other.
import { defineConfig } from 'vite';

export default defineConfig({
  publicDir: false,
  build: {
    outDir: 'dist',
    emptyOutDir: false,
    target: 'es2020',
    minify: 'esbuild',
    sourcemap: false,
    modulePreload: false,
    copyPublicDir: false,
    rollupOptions: {
      input: {
        map: 'src/map.js',
        locator: 'src/locator.js',
        'field-app': 'src/field-app.js',
      },
      preserveEntrySignatures: 'exports-only',
      output: {
        format: 'es',
        entryFileNames: 'rumbo-[name].js',
        chunkFileNames: 'rumbo-[name].js',
        assetFileNames: 'rumbo-[name][extname]',
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['test/**/*.test.js'],
  },
});
