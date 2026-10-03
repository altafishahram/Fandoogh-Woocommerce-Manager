'use strict';

/** Verify that an installable ZIP exactly matches the package allowlist. */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const AdmZip = require('adm-zip');
const { ROOT, OUTPUT, getPackageFiles } = require('./package-plugin');

function sha256(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex');
}

function verifyPackage(zipPath = OUTPUT, root = ROOT) {
  if (!fs.existsSync(zipPath)) {
    throw new Error(`ZIP not found: ${path.relative(root, zipPath)}`);
  }

  const expectedFiles = getPackageFiles(root);
  const zip = new AdmZip(zipPath);
  const entries = zip.getEntries().filter((entry) => !entry.isDirectory);
  const actualFiles = entries.map((entry) => entry.entryName).sort((left, right) => (left < right ? -1 : left > right ? 1 : 0));

  if (actualFiles.length !== expectedFiles.length || actualFiles.some((file, index) => file !== expectedFiles[index])) {
    throw new Error([
      'ZIP entries do not match the package allowlist.',
      `Expected: ${expectedFiles.join(', ')}`,
      `Actual: ${actualFiles.join(', ')}`,
    ].join('\n'));
  }

  for (const relativePath of expectedFiles) {
    const source = fs.readFileSync(path.join(root, relativePath));
    const packed = zip.readFile(relativePath);
    if (!packed || sha256(source) !== sha256(packed)) {
      throw new Error(`Packaged file differs from source: ${relativePath}`);
    }
  }

  console.log(`Package verified: ${path.relative(root, zipPath)}`);
  console.log(`Verified ${expectedFiles.length} files; no development/test files are included.`);
  console.log(`SHA-256: ${sha256(fs.readFileSync(zipPath))}`);
  return true;
}

if (require.main === module) {
  try {
    verifyPackage(process.env.PACKAGE_OUT || OUTPUT);
  } catch (error) {
    console.error(`Package verification failed: ${error.message}`);
    process.exitCode = 1;
  }
}

module.exports = { verifyPackage, sha256 };
