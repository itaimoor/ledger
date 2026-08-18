/**
 * Money presentation is the one piece of browser logic worth pinning down: it is the last
 * step before a figure reaches a person, and a wrong one is a wrong number on a cashbook.
 *
 *   node --test tests/js
 *
 * Uses node:test from the standard library. The PHP suite is the primary one; this exists
 * because paisa arithmetic deserves a check on both sides of the wire.
 */

import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { amountInWords, balanceTone, formatPkr, rupeesToPaisa } from '../../public/assets/js/money.js';

describe('formatPkr', () => {
  it('prints full figures, never abbreviated', () => {
    assert.equal(formatPkr(125000000), 'Rs 1,250,000');
    assert.equal(formatPkr(1797500000), 'Rs 17,975,000');
  });

  it('shows decimals only when the amount is not whole', () => {
    assert.equal(formatPkr(9641250), 'Rs 96,412.50');
    assert.equal(formatPkr(100), 'Rs 1');
    assert.equal(formatPkr(105), 'Rs 1.05');
    assert.equal(formatPkr(1), 'Rs 0.01');
  });

  it('marks a negative with a minus sign, not only with colour', () => {
    assert.equal(formatPkr(-16000000), '−Rs 160,000');
  });

  it('can show an explicit plus for a net figure', () => {
    assert.equal(formatPkr(75400000, { showPlus: true }), '+Rs 754,000');
    assert.equal(formatPkr(-21000000, { showPlus: true }), '−Rs 210,000');
    assert.equal(formatPkr(0, { showPlus: true }), 'Rs 0');
  });

  it('handles zero and nullish input without producing NaN', () => {
    assert.equal(formatPkr(0), 'Rs 0');
    assert.equal(formatPkr(null), 'Rs 0');
    assert.equal(formatPkr(undefined), 'Rs 0');
  });
});

describe('balanceTone', () => {
  it('leaves a positive balance in plain ink, reserving the In hue for receipts', () => {
    assert.equal(balanceTone(322350000), '');
  });

  it('colours a negative balance', () => {
    assert.equal(balanceTone(-1), 'money-out');
  });

  it('greys an exact zero', () => {
    assert.equal(balanceTone(0), 'money-zero');
  });
});

describe('rupeesToPaisa', () => {
  it('converts whole rupees', () => {
    assert.equal(rupeesToPaisa('185000'), 18500000);
  });

  it('accepts the grouping separators people type', () => {
    assert.equal(rupeesToPaisa('1,250,000'), 125000000);
    assert.equal(rupeesToPaisa(' 96 412 '), 9641200);
  });

  it('converts paise exactly, without floating point', () => {
    assert.equal(rupeesToPaisa('96412.50'), 9641250);
    assert.equal(rupeesToPaisa('0.07'), 7);
    assert.equal(rupeesToPaisa('1.1'), 110);
  });

  it('refuses anything that is not an amount', () => {
    for (const bad of ['', 'abc', '-500', '1.234', '1e3', '.', '1..2']) {
      assert.equal(rupeesToPaisa(bad), null, `expected ${JSON.stringify(bad)} to be rejected`);
    }
  });
});

describe('amountInWords', () => {
  it('reads figures the way they are said on site', () => {
    assert.equal(amountInWords(18500000), 'One lakh eighty-five thousand rupees');
    assert.equal(amountInWords(150000000), 'Fifteen lakh rupees');
    assert.equal(amountInWords(1000000000), 'One crore rupees');
  });

  it('combines crore, lakh, thousand and the remainder', () => {
    // 1,234,567,800 paisa is Rs 12,345,678 — one crore, twenty-three lakh, and change.
    assert.equal(
      amountInWords(1234567800),
      'One crore twenty-three lakh forty-five thousand six hundred seventy-eight rupees',
    );
  });

  it('says nothing for an empty amount, so the hint stays blank while typing', () => {
    assert.equal(amountInWords(0), '');
    assert.equal(amountInWords(50), '');
  });
});
