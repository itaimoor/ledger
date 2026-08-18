/** Pieces used on more than one screen. */

import { append, button, clear, div, h, mount, span } from './dom.js';
import { ApiError } from './api.js';
import { balanceTone, formatPkr } from './money.js';

export function card(...children) {
  return div({ class: 'card' }, ...children);
}

export function statTile(label, paisa, note, { tone = false } = {}) {
  return div({ class: 'stat' },
    div({ class: `stat-label`, text: label }),
    div({ class: `money money-lg ${tone ? balanceTone(paisa) : ''}`.trim(), text: formatPkr(paisa) }),
    note ? div({ class: 'stat-note', text: note }) : null,
  );
}

export function statusChip(status) {
  return span({ class: `chip chip-${status}` },
    span({ class: 'chip-dot' }),
    status.charAt(0).toUpperCase() + status.slice(1),
  );
}

export function emptyState(title, note, action) {
  return div({ class: 'empty' },
    div({ class: 'empty-title', text: title }),
    note ? div({ class: 'empty-note', text: note }) : null,
    action ?? null,
  );
}

export function loading(rows = 6) {
  return div({ class: 'card', 'aria-busy': 'true' },
    div({ class: 'form-grid card-pad' },
      ...Array.from({ length: rows }, () => div({ class: 'skeleton', 'aria-hidden': 'true' })),
    ),
  );
}

export function field(label, control, { hint, error, id } = {}) {
  // A composite control (the password field) exposes its real input; id and validity
  // belong on that, not on the wrapper.
  const target = control.input ?? control;
  if (id) target.id = id;
  if (error) target.setAttribute('aria-invalid', 'true');

  return h('label', { class: 'field' },
    span({ class: 'field-label', text: label }),
    control,
    hint && !error ? span({ class: 'field-hint', text: hint }) : null,
    error ? span({ class: 'field-error', text: error }) : null,
  );
}

export function textInput(props = {}) {
  return h('input', { class: 'input', type: 'text', ...props });
}

/** A password box with a Show/Hide toggle inside it, sized to match .input exactly. */
export function passwordInput(props = {}) {
  const input = h('input', { type: 'password', autocomplete: 'current-password', ...props });
  const toggle = button({ class: 'password-toggle', text: 'Show' });

  toggle.addEventListener('click', () => {
    const revealed = input.type === 'text';
    input.type = revealed ? 'password' : 'text';
    toggle.textContent = revealed ? 'Show' : 'Hide';
  });

  const wrap = div({ class: 'password-field' }, input, toggle);
  wrap.input = input;
  return wrap;
}

export function select(options, props = {}) {
  const node = h('select', { class: 'select', ...props });

  for (const option of options) {
    node.appendChild(h('option', { value: option.value, selected: option.value === props.value },
      option.label));
  }

  return node;
}

export function notice(message, kind = 'error') {
  return div({ class: `notice notice-${kind}`, text: message });
}

/** Toasts are the only place a caught error is allowed to surface unstructured. */
const stack = div({ class: 'toast-stack' });

export function toast(message, kind = '') {
  if (!stack.isConnected) document.body.appendChild(stack);

  const node = div({ class: `toast ${kind ? `toast-${kind}` : ''}`, role: 'status', text: message });
  stack.appendChild(node);
  setTimeout(() => node.remove(), 4000);
}

/**
 * Turns a failure into something the person can act on: field errors go back to the form,
 * anything else becomes a toast.
 *
 * @returns {Record<string,string>} field errors, empty when the failure was not a 422
 */
export function reportError(error) {
  if (error instanceof ApiError) {
    if (error.isValidation && Object.keys(error.fields).length > 0) return error.fields;
    toast(error.message, 'error');
    return {};
  }

  toast('Could not reach the server. Check your connection.', 'error');
  return {};
}

export function dialog({ title, body, confirmLabel, confirmClass = 'btn-danger', onConfirm, requireText }) {
  const wrap = div({ class: 'dialog-wrap', role: 'dialog', 'aria-modal': 'true' });
  const confirm = button({ class: `btn ${confirmClass}`, text: confirmLabel, disabled: Boolean(requireText) });

  const close = () => wrap.remove();

  const panel = div({ class: 'dialog' },
    div({ class: 'dialog-title', text: title }),
    div({ class: 'dialog-body' }, ...(Array.isArray(body) ? body : [body])),
  );

  if (requireText) {
    const input = textInput({ placeholder: requireText, autocomplete: 'off' });
    input.addEventListener('input', () => { confirm.disabled = input.value.trim() !== requireText; });
    panel.appendChild(field(`Type ${requireText} to confirm`, input));
  }

  confirm.addEventListener('click', async () => {
    confirm.disabled = true;
    try {
      await onConfirm();
      close();
    } catch (error) {
      reportError(error);
      confirm.disabled = false;
    }
  });

  panel.appendChild(div({ class: 'dialog-actions' },
    button({ class: 'btn', text: 'Cancel', onClick: close }),
    confirm,
  ));

  wrap.addEventListener('click', (event) => { if (event.target === wrap) close(); });
  document.addEventListener('keydown', function escape(event) {
    if (event.key === 'Escape') { close(); document.removeEventListener('keydown', escape); }
  });

  wrap.appendChild(panel);
  document.body.appendChild(wrap);
  return wrap;
}

/** A slide-over on desktop, a full-screen sheet on a phone. Same node, CSS decides. */
export function sheet({ title, subtitle, body, footer }) {
  const overlay = div({ class: 'overlay', role: 'dialog', 'aria-modal': 'true' });
  const close = () => overlay.remove();

  const panel = div({ class: 'sheet' },
    div({ class: 'sheet-head' },
      div({ class: 'sheet-head-text' },
        div({ class: 'sheet-title', text: title }),
        subtitle ? div({ class: 'meta', text: subtitle }) : null,
      ),
      button({ class: 'icon-btn', text: '✕', 'aria-label': 'Close', onClick: close }),
    ),
    div({ class: 'sheet-body' }, ...body),
    footer ? div({ class: 'sheet-foot' }, ...footer(close)) : null,
  );

  overlay.addEventListener('click', (event) => { if (event.target === overlay) close(); });
  document.addEventListener('keydown', function escape(event) {
    if (event.key === 'Escape') { close(); document.removeEventListener('keydown', escape); }
  });

  overlay.appendChild(panel);
  document.body.appendChild(overlay);
  panel.querySelector('input, select, textarea, button')?.focus();

  return { overlay, close };
}

export function copyField(value) {
  const copy = button({ class: 'btn btn-sm', text: 'Copy' });

  copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(value);
      copy.textContent = 'Copied';
      setTimeout(() => { copy.textContent = 'Copy'; }, 1500);
    } catch {
      toast('Copy failed — select the text and copy it by hand.', 'error');
    }
  });

  return div({ class: 'copy-field' }, h('code', { text: value }), copy);
}

export { append, clear, div, h, mount, span, button };
