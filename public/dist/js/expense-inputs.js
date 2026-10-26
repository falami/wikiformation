(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.WikiExpenseInputs = api;
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  function parseNumber(value) {
    if (value === null || value === undefined || String(value).trim() === '') return 0;
    let text = String(value).trim().replace(/\s/g, '').replace(/€$/, '');
    // Accept French decimals, grouped French/European amounts, or decimal dots.
    if (text.includes(',')) {
      if (!/^-?(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}$/.test(text)) return NaN;
      text = text.replace(/\./g, '').replace(',', '.');
    } else if (!/^-?\d+(?:\.\d{1,2})?$/.test(text)) {
      return NaN;
    }
    const number = Number(text);
    return Number.isFinite(number) ? number : NaN;
  }

  function toCents(value) {
    const number = parseNumber(value);
    const cents = Math.round((number + Math.sign(number) * Number.EPSILON) * 100);
    return Number.isSafeInteger(cents) ? cents : NaN;
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
  }

  function receiptAmounts(proposal) {
    const read = name => {
      const value = proposal?.[name];
      return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0 ? value : null;
    };
    return { ht: read('montantHtCents'), tva: read('montantTvaCents'), ttc: read('montantTtcCents') };
  }

  function receiptRate(proposal) {
    const { ht, tva, ttc } = receiptAmounts(proposal);
    if (proposal?.multipleVatRates || proposal?.tauxTva === null || proposal?.tauxTva === undefined) return null;
    if (ht === null || tva === null || !ttc || ht + tva !== ttc) return null;
    const matches = [0, 2.1, 5.5, 10, 20].filter(rate => Math.abs(Math.round(ttc / (1 + rate / 100)) - ht) <= 1);
    const supplied = proposal?.tauxTva == null ? NaN : parseNumber(proposal.tauxTva);
    return matches.includes(supplied) ? supplied : null;
  }

  function validIsoDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value))) return false;
    const date = new Date(value + 'T12:00:00Z');
    return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
  }

  function localDuplicateKeys(rows) {
    const counts = new Map();
    rows.forEach(row => {
      if (row.removed || !validIsoDate(row.date) || !Number.isSafeInteger(row.ttcCents) || row.ttcCents <= 0) return;
      const key = row.date + '|' + row.ttcCents;
      counts.set(key, (counts.get(key) || 0) + 1);
    });
    return new Set(Array.from(counts).filter(([, count]) => count > 1).map(([key]) => key));
  }

  // Bind a proposal to exactly the file that was analysed; old responses cannot win.
  function createScanState() {
    let version = 0;
    let file = null;
    let proposal = null;
    return {
      select(next) { version++; file = next; proposal = null; },
      begin() { proposal = null; return { version: ++version, file }; },
      current(request) { return request.version === version && request.file === file; },
      complete(request, result) {
        if (!this.current(request)) return false;
        proposal = result;
        return true;
      },
      get file() { return file; },
      get proposal() { return proposal; },
      applied() { proposal = null; },
    };
  }

  return { parseNumber, toCents, escapeHtml, receiptAmounts, receiptRate, validIsoDate, localDuplicateKeys, createScanState };
});
