'use strict';

/**
 * Small, dependency-free syntax lint for the files that ship or drive the
 * browser checks. It deliberately does not impose a formatter on the
 * feature code; the goal is to fail fast on a broken release artifact.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { spawnSync } = require('child_process');

const ROOT = path.join(__dirname, '..');
const SKIP_DIRECTORIES = new Set([
  '.git',
  'node_modules',
  '_verify',
  'dist',
  'test-results',
  'playwright-report',
  'blob-report',
]);

function walk(directory) {
  const entries = fs.readdirSync(directory, { withFileTypes: true });
  const files = [];

  entries.sort((left, right) => left.name.localeCompare(right.name));
  for (const entry of entries) {
    if (entry.isDirectory()) {
      if (!SKIP_DIRECTORIES.has(entry.name)) {
        files.push(...walk(path.join(directory, entry.name)));
      }
      continue;
    }
    if (entry.isFile()) {
      files.push(path.join(directory, entry.name));
    }
  }

  return files;
}

function run(command, args, file) {
  const result = spawnSync(command, args.concat(file), { encoding: 'utf8' });
  if (result.error) {
    throw new Error(`${command} is unavailable: ${result.error.message}`);
  }
  if (result.status !== 0) {
    const output = [result.stdout, result.stderr].filter(Boolean).join('\n').trim();
    throw new Error(`${path.relative(ROOT, file)}\n${output}`);
  }
}

function checkJavaScript(file) {
  try {
    new vm.Script(fs.readFileSync(file, 'utf8'), { filename: file });
  } catch (error) {
    throw new Error(`${path.relative(ROOT, file)}\n${error.message}`);
  }
}

function main() {
  const files = walk(ROOT);
  const javascriptFiles = files.filter((file) => file.endsWith('.js'));
  const phpFiles = files.filter((file) => file.endsWith('.php'));

  for (const file of javascriptFiles) {
    checkJavaScript(file);
  }

  for (const file of phpFiles) {
    run(process.env.PHP || 'php', ['-l'], file);
  }

  console.log(`Lint passed: ${javascriptFiles.length} JavaScript and ${phpFiles.length} PHP files.`);
}

if (require.main === module) {
  try {
    main();
  } catch (error) {
    console.error(`Lint failed: ${error.message}`);
    process.exitCode = 1;
  }
}

module.exports = { main, walk };
