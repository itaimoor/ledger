/** Dates, initials and relative time. Presentation only. */

const DAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * The server sends 'YYYY-MM-DD' and 'YYYY-MM-DD HH:MM:SS', neither carrying a zone.
 *
 * They mean different things and are parsed differently. A timestamp is UTC — the server
 * pins PHP and MySQL to it — so it is converted into the reader's own zone. A date is a
 * calendar date: 17 August is 17 August in Lahore and in London alike, and shifting it by
 * a UTC offset would move an entry into the previous day for anyone west of Greenwich.
 */
function parse(value) {
  if (!value) return null;

  const [date, time] = String(value).split(' ');
  const [y, m, d] = date.split('-').map(Number);
  if (!y || !m || !d) return null;

  if (!time) return new Date(y, m - 1, d);

  const [hh, mm, ss] = time.split(':').map(Number);
  return new Date(Date.UTC(y, m - 1, d, hh || 0, mm || 0, ss || 0));
}

/** "17 Aug 2026" */
export function formatDate(value) {
  const date = parse(value);
  return date ? `${date.getDate()} ${MONTH[date.getMonth()]} ${date.getFullYear()}` : '—';
}

/** "17 Aug" — for a dense column where the year is implied. */
export function formatDayMonth(value) {
  const date = parse(value);
  return date ? `${date.getDate()} ${MONTH[date.getMonth()]}` : '—';
}

export function formatTime(value) {
  const date = parse(value);
  if (!date) return '';
  return date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

export function formatRelative(value) {
  const date = parse(value);
  if (!date) return 'Never';

  const seconds = Math.round((Date.now() - date.getTime()) / 1000);
  if (seconds < 60) return 'Now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
  if (seconds < 86400) {
    const hours = Math.floor(seconds / 3600);
    return `${hours} hour${hours === 1 ? '' : 's'} ago`;
  }
  if (seconds < 172800) return 'Yesterday';
  if (seconds < 604800) return `${Math.floor(seconds / 86400)} days ago`;
  return formatDate(value);
}

/** "2026-08" and "2026-W33" as printed under the chart. */
export function formatPeriod(period) {
  const month = /^(\d{4})-(\d{2})$/.exec(period);
  if (month) return `${MONTH[Number(month[2]) - 1]} ${month[1].slice(2)}`;

  const week = /^(\d{4})-W(\d{2})$/.exec(period);
  if (week) return `W${week[2]} ${week[1].slice(2)}`;

  const day = parse(period);
  return day ? `${day.getDate()} ${MONTH[day.getMonth()]}` : period;
}

export function initials(name) {
  return String(name ?? '')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || '?';
}

export function today() {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export const DAY_NAMES = DAY;
