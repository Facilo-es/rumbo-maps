// Builds dist/: Vite for the lazy ES module chunks, esbuild for the two classic scripts and the CSS.
import { build as vite } from 'vite';
import { build as esb } from 'esbuild';
import { copyFileSync, mkdirSync, readdirSync, rmSync } from 'node:fs';

const out = 'dist';
for (const f of readdirSync(out)) if (f !== 'fonts') rmSync(out + '/' + f, { recursive: true, force: true });
mkdirSync(out + '/fonts', { recursive: true });

await vite({ configFile: 'vite.config.js', logLevel: 'warn' });

// Classic scripts (plain <script src>, no type=module needed): the front-end loader and the CP field loader.
await esb({
  entryPoints: { 'rumbo-loader': 'src/loader-entry.js', 'rumbo-field': 'src/field-entry.js' },
  outdir: out, bundle: true, format: 'iife', minify: true, target: 'es2018', legalComments: 'none',
});

await esb({
  entryPoints: { 'rumbo-locator': 'src/locator.css', 'rumbo-map': 'src/map.css', 'rumbo-field': 'src/field.css' },
  outdir: out, bundle: true, minify: true, loader: { '.woff2': 'file' }, external: ['fonts/*'], logLevel: 'warning',
});

copyFileSync('node_modules/maplibre-gl/dist/maplibre-gl-csp-worker.js', out + '/maplibre-gl-csp-worker.js');
copyFileSync('src/geist.woff2', out + '/fonts/geist.woff2');
console.log('built', readdirSync(out).join(' '));
