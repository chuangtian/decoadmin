import { readdir, readFile } from 'node:fs/promises';
import { join, relative } from 'node:path';

const roots = ['resources/js', 'resources/css', 'resources/views/errors', 'resources/views/student-discounts', 'shopify-apps/community-reviews/frontend', 'shopify-apps/deco-referral/resources/admin'];
const rules = [
    { name: 'utility', pattern: /text-\[([\d.]+)(px|rem)\]/g },
    { name: 'CSS', pattern: /font-size\s*:\s*([\d.]+)(px|rem)/g },
    { name: 'SVG', pattern: /font-size=["']([\d.]+)(px)?["']/g },
    { name: 'theme token', pattern: /--text-(?:xs|sm|base|lg|xl|[2-6]xl)\s*:\s*([\d.]+)(px|rem)/g },
];
let checked = 0;
const failures = [];

async function scan(directory) {
    for (const entry of await readdir(directory, { withFileTypes: true })) {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) {
            if (!['node_modules', 'dist', '.git'].includes(entry.name)) await scan(path);
        } else if (/\.(?:vue|css|ts|js|php)$/.test(entry.name)) {
            const source = await readFile(path, 'utf8');
            checked++;
            for (const { name, pattern } of rules) {
                for (const match of source.matchAll(pattern)) {
                    const pixels = Number(match[1]) * (match[2] === 'rem' ? 16 : 1);
                    if (pixels < 12) {
                        const line = source.slice(0, match.index).split('\n').length;
                        failures.push(`${relative(process.cwd(), path)}:${line}: ${name} declares ${pixels}px (minimum 12px)`);
                    }
                }
            }
            if (/font-size\s*:\s*(?:80|75)%/.test(source)) failures.push(`${path}: inherited font shrinking can break the 12px minimum`);
        }
    }
}

for (const root of roots) await scan(root);
if (failures.length) {
    console.error(failures.join('\n'));
    process.exitCode = 1;
} else {
    console.log(`Typography audit passed: ${checked} interface source files; no authored font size below 12px.`);
}
