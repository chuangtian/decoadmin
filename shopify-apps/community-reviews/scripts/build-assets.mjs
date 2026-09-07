import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
await build({entryPoints:[resolve(root,'frontend/carousel.js')],outfile:resolve(root,'extensions/community-reviews/assets/community-reviews.js'),minify:true,target:['es2022'],legalComments:'none'});
