import { mkdir, stat } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "esbuild";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const destination = resolve(root, "extensions/deco-reviews/assets");
await mkdir(destination, { recursive: true });

const javascriptOutput = resolve(destination, "storefront.js");
const stylesheetOutput = resolve(destination, "storefront.css");

await Promise.all([
  build({
    entryPoints: [resolve(root, "frontend/storefront.js")],
    outfile: javascriptOutput,
    bundle: true,
    minify: true,
    platform: "browser",
    target: ["es2022"],
    legalComments: "none",
  }),
  build({
    entryPoints: [resolve(root, "frontend/storefront.css")],
    outfile: stylesheetOutput,
    bundle: true,
    minify: true,
    legalComments: "none",
  }),
]);

const javascriptBytes = (await stat(javascriptOutput)).size;
if (javascriptBytes >= 10_000) {
  throw new Error(`Theme app block JavaScript is ${javascriptBytes} bytes; it must remain below 10000 bytes.`);
}
