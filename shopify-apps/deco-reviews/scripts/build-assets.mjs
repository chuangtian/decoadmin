import { mkdir, readFile, stat } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "esbuild";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const destination = resolve(root, "extensions/deco-reviews/assets");
await mkdir(destination, { recursive: true });

const javascriptOutput = resolve(destination, "storefront.js");
const formOutput = resolve(destination, "form.js");
const widgetsOutput = resolve(destination, "widgets.js");
const organicOutput = resolve(destination, "organic.js");
const stylesheetOutput = resolve(destination, "storefront.css");
const [storefrontSource, extras] = (await readFile(resolve(root, "frontend/storefront.js"), "utf8")).split("/* DECO_REVIEWS_FORM */");
const [formSource, widgetsSource] = extras.split("/* DECO_REVIEWS_WIDGETS */");

await Promise.all([
  build({
    stdin: { contents: storefrontSource, sourcefile: "storefront.js", resolveDir: root },
    outfile: javascriptOutput,
    bundle: true,
    minify: true,
    platform: "browser",
    target: ["es2022"],
    charset: "utf8",
    legalComments: "none",
  }),
  build({
    stdin: { contents: formSource, sourcefile: "form.js", resolveDir: root },
    outfile: formOutput,
    bundle: true,
    minify: true,
    platform: "browser",
    target: ["es2022"],
    charset: "utf8",
    legalComments: "none",
  }),
  build({
    stdin: { contents: widgetsSource, sourcefile: "widgets.js", resolveDir: root },
    outfile: widgetsOutput,
    bundle: true,
    minify: true,
    platform: "browser",
    target: ["es2022"],
    charset: "utf8",
    legalComments: "none",
  }),
  build({
    entryPoints: [resolve(root, "frontend/organic.js")],
    outfile: organicOutput,
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

for (const output of [javascriptOutput, formOutput, widgetsOutput, organicOutput]) {
  const javascriptBytes = (await stat(output)).size;
  if (javascriptBytes >= 10_000) {
    throw new Error(`Theme app extension JavaScript ${output} is ${javascriptBytes} bytes; each asset must remain below 10000 bytes.`);
  }
}
