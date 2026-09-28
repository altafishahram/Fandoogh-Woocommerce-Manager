'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.join(__dirname, '..');
const runner = path.join(__dirname, 'fixtures/refactor/attributes-runner.php');
const baseline = path.join(__dirname, 'fixtures/refactor/attributes-original.php');
const current = path.join(root, 'app/attributes.php');
const scenarios = ['no-woocommerce', 'no-attributes-api', 'no-taxonomy-api', 'empty', 'populated', 'object-results', 'null-results', 'false-results', 'permission-error'];

function run(file, scenario) {
  const result = spawnSync(process.env.PHP || 'php', [runner, file, scenario], { encoding: 'utf8' });
  assert.ifError(result.error);
  assert.equal(result.status, 0, `${scenario}: ${result.stderr || result.stdout}`);
  assert.equal(result.stderr, '', `${scenario}: unexpected PHP diagnostic`);
  return JSON.parse(result.stdout);
}

for (const scenario of scenarios) {
  const expected = run(baseline, scenario);
  const actual = run(current, scenario);
  assert.deepEqual(actual, expected, `${scenario}: response, hooks, permission or dependency call order changed`);
  if (scenario.startsWith('no-')) {
    assert.equal(actual.response_type, 'WP_Error');
    assert.equal(actual.response.code, 'fandoogh_woocommerce_inactive');
    assert.equal(actual.response.data.status, 503);
  } else {
    assert.equal(actual.response_type, 'WP_REST_Response');
    assert.equal(actual.response.status, 200);
    assert.equal(actual.response.headers['Cache-Control'], 'no-store, no-cache, must-revalidate, max-age=0');
    assert.deepEqual(Object.keys(actual.response.data), ['data']);
  }
  if (scenario === 'populated') {
    assert.equal(actual.response.data.data.length, 3);
    assert.deepEqual(Object.keys(actual.response.data.data[0]), ['id', 'label', 'slug', 'name', 'terms']);
    assert.deepEqual(Object.keys(actual.response.data.data[0].terms[0]), ['id', 'name', 'slug']);
    assert.deepEqual(actual.response.data.data[1].terms, []);
    assert.deepEqual(actual.trace.filter(([name]) => name === 'get_terms').map(([, args]) => args), [
      { taxonomy: 'pa_color', hide_empty: false, number: 200 },
      { taxonomy: 'pa_size', hide_empty: false, number: 200 },
      { taxonomy: 'pa_material', hide_empty: false, number: 200 },
    ]);
  }
  console.log(`PASS attributes legacy parity: ${scenario}`);
}
console.log(`${scenarios.length} isolated legacy/current comparisons passed (stubs, not live WordPress).`);
