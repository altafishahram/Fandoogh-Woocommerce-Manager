'use strict';

/**
 * Build the installable WordPress plugin archive from an explicit allowlist.
 * The archive contains no test, CI, Node, mock-server, or source-control
 * files. Entries are sorted and use a fixed DOS timestamp/permission set so
 * rebuilding the same source produces the same ZIP bytes.
 */

const fs = require('fs');
const path = require('path');
const AdmZip = require('adm-zip');

const ROOT = path.join(__dirname, '..');
const OUTPUT = path.join(ROOT, 'dist', 'fandoogh-manager.zip');
const ROOT_FILES = ['fandoogh-manager.php', 'manifest.webmanifest', 'package-readme.md'];
const PACKAGE_DIRECTORIES = ['app', 'assets', 'languages'];
// These source references help future maintenance, but are not plugin runtime
// files and must not inflate the installable WordPress archive.
const EXCLUDED_PACKAGE_PATHS = new Set([
  'app/UI-EDITING-GUIDE.md',
  'assets/ASSET-STRUCTURE.md',
  'assets/twa/assetlinks.example.json',
]);
const FIXED_DATE = new Date(1980, 0, 1, 0, 0, 0);

function collectDirectoryFiles(directory, relativeDirectory) {
  const entries = fs.readdirSync(directory, { withFileTypes: true });
  const files = [];

  entries.sort((left, right) => left.name.localeCompare(right.name));
  for (const entry of entries) {
    const absolutePath = path.join(directory, entry.name);
    const relativePath = path.posix.join(relativeDirectory, entry.name);
    if (entry.isDirectory()) {
      files.push(...collectDirectoryFiles(absolutePath, relativePath));
    } else if (entry.isFile()) {
      files.push(relativePath);
    } else {
      throw new Error(`Unsupported package entry: ${relativePath}`);
    }
  }

  return files;
}

function getPackageFiles(root = ROOT) {
  const files = ROOT_FILES.map((file) => {
    const absolutePath = path.join(root, file);
    if (!fs.existsSync(absolutePath) || !fs.lstatSync(absolutePath).isFile()) {
      throw new Error(`Required package file is missing: ${file}`);
    }
    return file;
  });

  for (const directory of PACKAGE_DIRECTORIES) {
    const absolutePath = path.join(root, directory);
    if (!fs.existsSync(absolutePath) || !fs.lstatSync(absolutePath).isDirectory()) {
      throw new Error(`Required package directory is missing: ${directory}/`);
    }
    files.push(...collectDirectoryFiles(absolutePath, directory));
  }

  return files
    .filter((file) => !EXCLUDED_PACKAGE_PATHS.has(file))
    .sort((left, right) => (left < right ? -1 : left > right ? 1 : 0));
}

function buildPackage({ root = ROOT, output = OUTPUT } = {}) {
  const packageFiles = getPackageFiles(root);
  const zip = new AdmZip();

  for (const relativePath of packageFiles) {
    const entry = zip.addFile(relativePath, fs.readFileSync(path.join(root, relativePath)), '', 0o644);
    // adm-zip otherwise uses the current time and the host OS in the header.
    entry.header.time = FIXED_DATE;
    entry.header.made = 0x0314; // Unix, version 2.0; stable across hosts.
    entry.header.version = 20;
  }

  fs.mkdirSync(path.dirname(output), { recursive: true });
  fs.writeFileSync(output, zip.toBuffer());
  return { output, packageFiles };
}

if (require.main === module) {
  try {
    const result = buildPackage({ output: process.env.PACKAGE_OUT || OUTPUT });
    console.log(`Package written: ${path.relative(ROOT, result.output)}`);
    console.log(`Included ${result.packageFiles.length} files.`);
  } catch (error) {
    console.error(`Package failed: ${error.message}`);
    process.exitCode = 1;
  }
}

module.exports = {
  ROOT,
  OUTPUT,
  ROOT_FILES,
  PACKAGE_DIRECTORIES,
  EXCLUDED_PACKAGE_PATHS,
  getPackageFiles,
  buildPackage,
};
