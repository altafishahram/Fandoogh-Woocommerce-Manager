/*
 * Audit the frontend asset contract without touching WordPress or a browser.
 * The PHP icon map is the server-side source of truth for replaceable icons;
 * data-icon-name values are the client-side contract. Archives and _verify are
 * intentionally outside this audit because they are not release sources.
 */
"use strict";

const fs = require("fs");
const path = require("path");

const root = path.join(__dirname, "..");
const iconDir = path.join(root, "assets", "icon");
const php = fs.readFileSync(path.join(root, "fandoogh-manager.php"), "utf8");
const html = fs.readFileSync(path.join(root, "app", "index.html"), "utf8");

const iconFiles = fs.readdirSync(iconDir).filter((name) => name.endsWith(".svg")).sort();
const iconMap = new Map();
const mapPattern = /['"]([a-z][a-z0-9-]{1,39})['"]\s*=>\s*['"]([^'"]+\.svg)['"]/g;
let match;
while ((match = mapPattern.exec(php)) !== null) {
  iconMap.set(match[1], match[2]);
}

const htmlIconNames = new Set();
const namePattern = /data-icon-name=["']([a-z][a-z0-9-]{1,39})["']/g;
while ((match = namePattern.exec(html)) !== null) {
  htmlIconNames.add(match[1]);
}

const mappedFiles = [...iconMap.values()];
const missingFiles = mappedFiles.filter((name) => !iconFiles.includes(name));
const unreferencedFiles = iconFiles.filter((name) => !mappedFiles.includes(name));
const missingMapEntries = [...htmlIconNames].filter((name) => name !== "refresh" && !iconMap.has(name));

console.log(`Mapped UI icons: ${iconMap.size}`);
console.log(`Bundled icon files: ${iconFiles.length}`);
console.log(`Inline-only icon names: ${[...htmlIconNames].filter((name) => name === "refresh").join(", ") || "none"}`);
console.log(`Unreferenced bundled files: ${unreferencedFiles.join(", ") || "none"}`);

if (missingFiles.length || unreferencedFiles.length || missingMapEntries.length) {
  if (missingFiles.length) console.error(`Missing files from PHP map: ${missingFiles.join(", ")}`);
  if (missingMapEntries.length) console.error(`HTML names missing from PHP map: ${missingMapEntries.join(", ")}`);
  if (unreferencedFiles.length) console.error(`Remove or map unused files: ${unreferencedFiles.join(", ")}`);
  process.exitCode = 1;
}
