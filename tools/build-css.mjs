// Build public/assets/app.css from tools/tailwind.css by scanning the PHP
// templates and JS for Tailwind classes. Run after changing classes:
//
//   cd tools && npm install && cd .. && node tools/build-css.mjs
//
// (Set TW_NODE_MODULES to reuse another node_modules that has tailwindcss,
//  @tailwindcss/node and @tailwindcss/oxide installed.)
import fs from "node:fs";
import path from "node:path";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, "..");
const nm = process.env.TW_NODE_MODULES ? path.resolve(process.env.TW_NODE_MODULES) : path.join(here, "node_modules");
const require = createRequire(path.join(nm, "noop.js"));

const { compile, optimize } = require("@tailwindcss/node");
const { Scanner } = require("@tailwindcss/oxide");

const inputPath = path.join(here, "tailwind.css");
const input = fs.readFileSync(inputPath, "utf8");

const compiler = await compile(input, {
  base: path.dirname(nm), // where `@import "tailwindcss"` resolves from
  from: inputPath,
  onDependency() {},
});

// Resolve @source globs relative to tools/.
const sources = compiler.sources.map((s) => ({ ...s, base: here }));
const scanner = new Scanner({ sources });
const candidates = scanner.scan();

let css = compiler.build(candidates);
if (process.argv.includes("--no-minify") === false && optimize) {
  css = optimize(css, { minify: true }).code;
}
const out = path.join(root, "public", "assets", "app.css");
fs.writeFileSync(out, css);
console.log(`Wrote ${path.relative(root, out)} (${(css.length / 1024).toFixed(1)} KB, ${candidates.length} candidates)`);
