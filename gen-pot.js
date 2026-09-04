const fs = require('fs');
const path = require('path');

const FILES = [
  'fandoogh-manager.php',
  ...fs.readdirSync('app').filter(f => f.endsWith('.php')).map(f => 'app/' + f)
].sort();

const FN_RE = /(?:__(?:\s*\(\s*)|\b(?:esc_html__|esc_attr__)(?:\s*\(\s*)|\b(?:esc_html_e|esc_attr_e|_e)(?:\s*\(\s*))/g;

function parseLiteral(src, i) {
  // src starts right after the opening quote
  const quote = src[i];
  if (quote !== "'" && quote !== '"') return null;
  i++;
  let out = '';
  while (i < src.length) {
    const ch = src[i];
    if (ch === '\\') {
      const nxt = src[i + 1];
      if (quote === "'") {
        out += nxt === "'" || nxt === '\\' ? nxt : '\\' + nxt;
      } else {
        if (nxt === 'n') out += '\n';
        else if (nxt === 't') out += '\t';
        else if (nxt === 'r') out += '\r';
        else if (nxt === '"') out += '"';
        else if (nxt === '\\') out += '\\';
        else out += '\\' + nxt;
      }
      i += 2;
      continue;
    }
    if (ch === quote) {
      return { value: out, next: i + 1 };
    }
    out += ch;
    i++;
  }
  return null;
}

function parseArgs(src, start) {
  // src[start] === '(' ; collect up to 3 string-literal args skipping whitespace/commas
  let i = start + 1;
  const args = [];
  while (args.length < 3 && i < src.length) {
    while (i < src.length && /\s/.test(src[i])) i++;
    if (src[i] === ',') { i++; continue; }
    if (src[i] === "'" || src[i] === '"') {
      const lit = parseLiteral(src, i);
      if (!lit) break;
      args.push(lit.value);
      i = lit.next;
      continue;
    }
    // allow sprintf( prefix only for discovery safety; skip complex expressions
    if (src[i] === ')' ) break;
    // non-literal argument (e.g., variable) -> stop (domain must be literal)
    return { args, done: false, next: i };
  }
  return { args, done: true, next: i };
}

function findTranslatorsComment(src, matchIndex) {
  // nearest block comment before the call that mentions translators
  let idx = src.lastIndexOf('/*', matchIndex);
  if (idx < 0) return null;
  const end = src.indexOf('*/', idx);
  if (end < 0 || end > matchIndex) return null;
  const comment = src.slice(idx + 2, end);
  if (!/translators\s*:/i.test(comment)) return null;
  const between = src.slice(end + 2, matchIndex);
  if (between.includes(';')) return null;
  return comment.replace(/^[\s*]*/m, '').trim();
}

const DOMAIN = 'fandoogh-manager';
const entries = []; // { msgid, refs: [], comments: [] }
const seen = new Map();

function addEntry(msgid, ref, comment) {
  if (msgid === '' || msgid.trim() === '') return;
  let e = seen.get(msgid);
  if (!e) {
    e = { msgid, refs: [], comments: [] };
    seen.set(msgid, e);
    entries.push(e);
  }
  if (!e.refs.includes(ref)) e.refs.push(ref);
  if (comment && !e.comments.includes(comment)) e.comments.push(comment);
}

for (const file of FILES) {
  if (file === 'mock-api.php') continue;
  const src = fs.readFileSync(file, 'utf8');
  let m;
  while ((m = FN_RE.exec(src))) {
    const callStart = m.index;
    const open = src.indexOf('(', callStart);
    if (open < 0 || open - callStart > 12) continue;
    const { args } = parseArgs(src, open);
    if (!args || args.length === 0) continue;
    // _e/esc_html_e/esc_attr_e print instead of return; same signature
    const fnName = m[0].replace(/\s+/g, '').replace(/\($/, '');
    let msgid = args[0];
    let domainIdx = 1;
    if (fnName === '_x' || fnName === 'esc_html_x' || fnName === 'esc_attr_x') domainIdx = 2;
    const domain = args[domainIdx];
    if (domain !== DOMAIN && domain !== undefined) continue;
    if (domain === undefined) continue; // require explicit domain
    const line = src.slice(0, callStart).split('\n').length;
    const comment = findTranslatorsComment(src, callStart);
    addEntry(msgid, `${file}:${line}`, comment);
  }
}

function esc(s) {
  return s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\t/g, '\\t').replace(/\r/g, '');
}
function poQuote(s) {
  if (!s.includes('\n')) return '"' + esc(s) + '"';
  const lines = s.split('\n');
  const parts = ['""'];
  lines.forEach((ln, i) => {
    parts.push('"' + esc(ln) + (i < lines.length - 1 ? '\\n' : '') + '"');
  });
  return parts.join('\n');
}

const now = new Date();
const pad = n => String(n).padStart(2, '0');
const stamp = now.getUTCFullYear() + '-' + pad(now.getUTCMonth() + 1) + '-' + pad(now.getUTCDate()) + ' ' + pad(now.getUTCHours()) + ':' + pad(now.getUTCMinutes()) + '+0000';
const lines = [];
lines.push('# Copyright (C) ' + now.getFullYear() + ' Fandoogh\n# This file is distributed under the same license as the Fandoogh Manager package.\n#\n# Translators: the source strings of this plugin are Persian. A fa_IR\n# translation is expected to map each msgid to the identical Persian text\n# (or a reviewed variant), keeping the shipped strings intact for other\n# locales such as en_US.\nmsgid ""\nmsgstr ""');
lines.push('"Project-Id-Version: Fandoogh Manager 1.3.2\\n"');
lines.push('"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/fandoogh-manager\\n"');
lines.push(`"POT-Creation-Date: ${stamp}\\n"`);
lines.push('"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"');
lines.push('"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"');
lines.push('"Language-Team: LANGUAGE <LL@li.org>\\n"');
lines.push('"Language: \\n"');
lines.push('"MIME-Version: 1.0\\n"');
lines.push('"Content-Type: text/plain; charset=UTF-8\\n"');
lines.push('"Content-Transfer-Encoding: 8bit\\n"');
lines.push('"Plural-Forms: nplurals=2; plural=(n != 1);\\n"');
lines.push('"X-Generator: Fandoogh Manager pot generator\\n"');
lines.push('');

entries.forEach(e => {
  if (e.comments[0]) lines.push('#. ' + e.comments[0]);
  e.refs.forEach(r => lines.push('#: ' + r));
  lines.push('msgid ' + poQuote(e.msgid));
  lines.push('msgstr ""');
  lines.push('');
});

const out = lines.join('\n');
fs.mkdirSync('languages', { recursive: true });
fs.writeFileSync(path.join('languages', 'fandoogh-manager.pot'), out);
console.log('entries:', entries.length, '| files:', FILES.length, '| output: languages/fandoogh-manager.pot');
