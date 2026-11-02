'use strict';
// CJS stub for cookie v2 (ESM-only) consumed by light-my-request in Jest ESM mode.
function parse(str) {
  const result = {};
  if (!str) return result;
  for (const pair of str.split(';')) {
    const idx = pair.indexOf('=');
    if (idx < 0) continue;
    const key = pair.slice(0, idx).trim();
    const val = pair.slice(idx + 1).trim();
    try { result[key] = decodeURIComponent(val.replace(/^"|"$/g, '')); } catch { result[key] = val; }
  }
  return result;
}
function serialize(name, val, options) {
  let str = `${name}=${encodeURIComponent(val)}`;
  if (options && options.maxAge) str += `; Max-Age=${options.maxAge}`;
  if (options && options.path) str += `; Path=${options.path}`;
  if (options && options.httpOnly) str += '; HttpOnly';
  if (options && options.secure) str += '; Secure';
  return str;
}
module.exports = { parse, serialize };
