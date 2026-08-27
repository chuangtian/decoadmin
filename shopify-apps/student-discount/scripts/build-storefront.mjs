import {readFile, writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import {minify} from 'terser';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourcePath = path.join(appRoot, 'scripts/student-discount-storefront.js');
const assetsPath = path.join(appRoot, 'extensions/student-discount-block/assets');
const source = await readFile(sourcePath, 'utf8');
const result = await minify(source, {compress: true, mangle: true});

if (!result.code) {
  throw new Error('Student discount storefront build produced no JavaScript.');
}

// Keep both filenames current. Existing theme blocks can retain the legacy
// asset entry after an extension upgrade, while new blocks use the v2 entry.
await Promise.all([
  writeFile(path.join(assetsPath, 'student-discount-v2.js'), result.code),
  writeFile(path.join(assetsPath, 'student-discount.js'), result.code),
]);

const portal = await readFile(path.join(assetsPath, 'student-discount-portal-v2.js'), 'utf8');
await writeFile(path.join(assetsPath, 'student-discount-portal.js'), portal);
