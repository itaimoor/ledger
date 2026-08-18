/**
 * PKR presentation. This is the only file that turns paisa into something a person reads.
 *
 * Amounts travel as integer paisa everywhere else. A JSON number holds an exact integer up
 * to 2^53, which is Rs 90,071,992,547,409.91 — some hundreds of times Pakistan's GDP, so
 * the ceiling is real but not reachable by a project cashbook.
 */

const groups = new Intl.NumberFormat('en-US');

/**
 * Design rules, verbatim: full figures, never abbreviated. Two decimals only when the
 * amount is not whole. Negative takes a minus sign, because colour is never the only
 * signal.
 */
export function formatPkr(paisa, { showPlus = false } = {}) {
  const value = Number(paisa) || 0;
  const negative = value < 0;
  const absolute = Math.abs(value);
  const rupees = Math.trunc(absolute / 100);
  const paise = absolute % 100;

  const body = groups.format(rupees) + (paise ? '.' + String(paise).padStart(2, '0') : '');
  const sign = negative ? '−' : (showPlus && value > 0 ? '+' : '');

  return `${sign}Rs ${body}`;
}

/**
 * Colour for a balance.
 *
 * A positive balance is plain ink, not green: the design reserves the In hue for money
 * actually received, and a page of forty rows stays readable only because most figures
 * carry no colour at all. Negative takes --out, alongside the minus sign.
 */
export function balanceTone(paisa) {
  if (paisa < 0) return 'money-out';
  return paisa === 0 ? 'money-zero' : '';
}

/** Colour for a directional figure: the In and Out columns, where hue is the meaning. */
export function directionTone(type) {
  return type === 'in' ? 'money-in' : 'money-out';
}

/** "185000" typed into the amount field becomes 18500000 paisa. */
export function rupeesToPaisa(input) {
  const cleaned = String(input).replace(/[,\s]/g, '');
  if (!/^\d+(\.\d{0,2})?$/.test(cleaned)) return null;

  const [whole, fraction = ''] = cleaned.split('.');
  return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
}

const ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
  'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen',
  'nineteen'];
const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

function underThousand(n) {
  if (n === 0) return '';
  if (n < 20) return ONES[n];
  if (n < 100) return TENS[Math.floor(n / 10)] + (n % 10 ? '-' + ONES[n % 10] : '');
  return ONES[Math.floor(n / 100)] + ' hundred' + (n % 100 ? ' ' + underThousand(n % 100) : '');
}

/**
 * The amount written out underneath the field, so a stray zero is caught before it is
 * committed. Lakh and crore, because that is how the figure is read aloud on site.
 */
export function amountInWords(paisa) {
  const rupees = Math.trunc(Math.abs(Number(paisa) || 0) / 100);
  if (rupees === 0) return '';

  const parts = [];
  const crore = Math.floor(rupees / 10000000);
  const lakh = Math.floor((rupees % 10000000) / 100000);
  const thousand = Math.floor((rupees % 100000) / 1000);
  const rest = rupees % 1000;

  if (crore) parts.push(`${underThousand(crore)} crore`);
  if (lakh) parts.push(`${underThousand(lakh)} lakh`);
  if (thousand) parts.push(`${underThousand(thousand)} thousand`);
  if (rest) parts.push(underThousand(rest));

  const words = parts.join(' ') + ' rupees';
  return words.charAt(0).toUpperCase() + words.slice(1);
}
