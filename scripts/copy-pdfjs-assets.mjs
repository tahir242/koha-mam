// Copies PDF.js runtime data (CMaps, standard fonts, WASM decoders, ICC
// profiles) into public/vendor/pdfjs so the viewer loads them same-origin.
import { cpSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'node_modules', 'pdfjs-dist');
const target = join(root, 'public', 'vendor', 'pdfjs');

rmSync(target, { recursive: true, force: true });
mkdirSync(target, { recursive: true });

for (const dir of ['cmaps', 'standard_fonts', 'wasm', 'iccs']) {
    cpSync(join(source, dir), join(target, dir), { recursive: true });
}

console.log(`PDF.js assets copied to ${target}`);
