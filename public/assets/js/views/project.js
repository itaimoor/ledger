/**
 * Project detail — the screen people live in.
 *
 * Three summary figures, then the book itself: newest entry at the top, In and Out in
 * their own columns, and the running balance as of each entry. The book is append-only, so
 * the only row action is Reconcile.
 */

import { api } from '../api.js';
import { formatDate, formatDayMonth, today } from '../format.js';
import { amountInWords, balanceTone, formatPkr, rupeesToPaisa } from '../money.js';
import { navigate } from '../router.js';
import {
  button, card, clear, div, emptyState, field, h, loading, mount, notice, reportError,
  sheet, span, statTile, statusChip, textInput, toast,
} from '../ui.js';

const TYPE_FILTERS = [
  { value: '', label: 'All' },
  { value: 'in', label: 'In' },
  { value: 'out', label: 'Out' },
];

export function projectView({ orgId, role, params }) {
  const projectId = Number(params.id);
  const root = div({ class: 'page' });

  const canWrite = role !== 'viewer';
  const manages = role === 'owner' || role === 'admin';

  const state = { type: '', from: '', to: '', search: '', categoryId: '' };
  let project = null;
  let categories = [];
  let rows = [];
  let cursor = null;
  let debounce;

  async function boot() {
    mount(root, loading());

    try {
      const [projectResult, categoryResult] = await Promise.all([
        api.get(`/organizations/${orgId}/projects/${projectId}`),
        api.get(`/organizations/${orgId}/categories`),
      ]);

      project = projectResult.data;
      categories = categoryResult.data;
      await reload();
    } catch (error) {
      reportError(error);
      mount(root, notice('Could not load this project.'));
    }
  }

  async function reload() {
    rows = [];
    cursor = null;
    const [summary, page] = await Promise.all([fetchSummary(), fetchPage(null)]);
    render(summary, page);
  }

  const fetchSummary = () => api.get(`/projects/${projectId}/summary`).then((r) => r.data);

  async function fetchPage(from) {
    const result = await api.get(`/projects/${projectId}/entries`, {
      type: state.type,
      category_id: state.categoryId,
      from: state.from,
      to: state.to,
      search: state.search,
      cursor: from,
      limit: 50,
    });

    rows = from ? [...rows, ...result.data] : result.data;
    cursor = result.meta.next_cursor;
    return result;
  }

  function render(summary, page) {
    const archived = project.status === 'archived';

    mount(root, 
      div({ class: 'breadcrumb' },
        h('a', { href: '/projects', text: 'Projects' }),
        span({ class: 'sep', text: '/' }),
        span({ text: project.name }),
      ),
      div({ class: 'page-head' },
        div({ class: 'page-head-text' },
          div({ class: 'page-actions' },
            h('h1', { class: 'page-title', text: project.name }),
            statusChip(project.status),
          ),
          div({ class: 'meta', text: subtitle(summary) }),
        ),
        div({ class: 'page-actions' },
          button({ class: 'btn', text: 'Export CSV', onClick: exportCsv }),
          button({ class: 'btn', text: 'Print', onClick: () => window.print() }),
          manages
            ? button({
              class: 'btn',
              text: 'Settings',
              onClick: () => navigate(`/projects/${projectId}/settings`),
            })
            : null,
          canWrite && !archived
            ? button({ class: 'btn btn-primary', text: '+ Add entry', onClick: openAddEntry })
            : null,
        ),
      ),
      archived
        ? notice('This project is archived. It is read-only for everyone, including admins.', 'warn')
        : null,
      div({ class: 'stat-row' },
        statTile('Total in', summary.total_in_paisa, `${summary.in_count} receipts`),
        statTile('Total out', summary.total_out_paisa, `${summary.out_count} payments`),
        statTile('Balance', summary.balance_paisa,
          summary.as_of ? `as of ${formatDate(summary.as_of)}` : 'no entries yet', { tone: true }),
      ),
      card(filterBar(summary), book()),
    );
  }

  function subtitle(summary) {
    const bits = [project.client_name, `${summary.entry_count} entries`];
    if (summary.first_entry_date) bits.push(`started ${formatDate(summary.first_entry_date)}`);
    return bits.filter(Boolean).join(' · ');
  }

  function filterBar(summary) {
    const search = textInput({
      placeholder: 'Search description…',
      value: state.search,
      'aria-label': 'Search descriptions',
    });

    search.addEventListener('input', () => {
      clearTimeout(debounce);
      debounce = setTimeout(async () => {
        state.search = search.value.trim();
        render(await fetchSummary(), await fetchPage(null));
      }, 250);
    });

    const from = h('input', { class: 'input', type: 'date', value: state.from, 'aria-label': 'From date' });
    const to = h('input', { class: 'input', type: 'date', value: state.to, 'aria-label': 'To date' });

    const onDate = async () => {
      state.from = from.value;
      state.to = to.value;
      render(await fetchSummary(), await fetchPage(null));
    };
    from.addEventListener('change', onDate);
    to.addEventListener('change', onDate);

    const category = h('select', { class: 'select', 'aria-label': 'Filter by category' },
      h('option', { value: '' }, 'All categories'),
      ...categories.map((c) => h('option', { value: String(c.id), selected: String(c.id) === state.categoryId },
        c.name)),
    );
    category.addEventListener('change', async () => {
      state.categoryId = category.value;
      render(await fetchSummary(), await fetchPage(null));
    });

    return div({ class: 'filters' },
      div({ class: 'segmented' },
        ...TYPE_FILTERS.map((filter) => button({
          text: filter.label,
          'aria-pressed': String(state.type === filter.value),
          onClick: async () => {
            state.type = filter.value;
            render(await fetchSummary(), await fetchPage(null));
          },
        })),
      ),
      from, to, category,
      div({ class: 'search' }, span({ class: 'search-icon', text: '⌕' }), search),
      div({ class: 'filters-gap' }),
      div({ class: 'filter-count', text: `${rows.length} of ${summary.entry_count} shown` }),
    );
  }

  function book() {
    if (rows.length === 0) {
      return emptyState('Nothing in this book yet',
        'Entries you add will appear here newest first, with the balance after each one.');
    }

    const table = h('table', { class: 'table table-stack' },
      h('thead', {}, h('tr', {},
        h('th', { text: 'Date' }),
        h('th', { text: 'Description' }),
        h('th', { text: 'Category' }),
        h('th', { text: 'Added by' }),
        h('th', { class: 'num', text: 'In' }),
        h('th', { class: 'num', text: 'Out' }),
        h('th', { class: 'num', text: 'Balance' }),
        h('th', {}, span({ class: 'visually-hidden', text: 'Actions' })),
      )),
      h('tbody', {}, ...rows.map(entryRow)),
    );

    return div({},
      div({ class: 'table-wrap' }, table),
      cursor
        ? div({ class: 'load-more' },
          button({ class: 'btn', text: 'Load 50 more', onClick: loadMore }))
        : null,
    );
  }

  async function loadMore() {
    const page = await fetchPage(cursor);
    render(await fetchSummary(), page);
  }

  function entryRow(entry) {
    const isIn = entry.type === 'in';

    return h('tr', {},
      h('td', { 'data-label': 'Date' },
        span({ title: formatDate(entry.entry_date), text: formatDayMonth(entry.entry_date) })),
      h('td', { 'data-label': 'Description' },
        div({ text: entry.description ?? '—' }),
        entry.reconciles_entry_id
          ? div({ class: 'row-sub', text: `Reconciles entry #${entry.reconciles_entry_id}` })
          : null,
        entry.is_reconciled ? span({ class: 'chip chip-warn', text: 'Reconciled' }) : null,
      ),
      h('td', { 'data-label': 'Category' },
        entry.category ? span({ class: 'chip', text: entry.category.name }) : span({ class: 'muted', text: '—' })),
      h('td', { 'data-label': 'Added by', text: entry.created_by.name }),
      h('td', { class: 'num', 'data-label': 'In' },
        isIn ? span({ class: 'money money-in', text: formatPkr(entry.amount_paisa) }) : null),
      h('td', { class: 'num', 'data-label': 'Out' },
        isIn ? null : span({ class: 'money money-out', text: formatPkr(entry.amount_paisa) })),
      h('td', { class: 'num', 'data-label': 'Balance' },
        span({ class: `money ${balanceTone(entry.running_balance_paisa)}`,
          text: formatPkr(entry.running_balance_paisa) })),
      h('td', { class: 'row-actions' },
        canWrite && project.status !== 'archived' && !entry.is_reconciled
          ? button({ class: 'btn btn-sm', text: 'Reconcile', onClick: () => openReconcile(entry) })
          : null),
    );
  }

  /* ------------------------------------------------------------- add entry */

  function openAddEntry() {
    let type = 'out';

    const amount = h('input', {
      inputmode: 'decimal', autocomplete: 'off', 'aria-label': 'Amount in rupees', placeholder: '0',
    });
    const words = div({ class: 'amount-words' });
    const date = h('input', { class: 'input', type: 'date', value: today() });
    const description = textInput({ placeholder: 'What was this for?' });
    const errors = div();

    const categorySelect = h('select', { class: 'select', 'aria-label': 'Category' });

    function paintCategories() {
      const previous = categorySelect.value;

      mount(categorySelect, 
        h('option', { value: '' }, type === 'out' ? 'Choose a category' : 'No category'),
        ...categories
          .filter((c) => !c.is_archived && (c.type === 'both' || c.type === type))
          .map((c) => h('option', { value: String(c.id) }, c.name)),
      );

      categorySelect.value = previous;
    }

    const typeButton = (value, label, note) => {
      const node = button({
        class: 'type-option', 'data-type': value, 'aria-pressed': String(type === value),
        onClick: () => {
          type = value;
          for (const other of toggle.children) {
            other.setAttribute('aria-pressed', String(other.dataset.type === value));
          }
          paintCategories();
        },
      },
        span({ class: 'type-option-name', text: label }),
        span({ class: 'type-option-note', text: note }),
      );
      return node;
    };

    const toggle = div({ class: 'type-toggle' },
      typeButton('in', 'In', 'money received'),
      typeButton('out', 'Out', 'money spent'),
    );

    amount.addEventListener('input', () => {
      const paisa = rupeesToPaisa(amount.value);
      words.textContent = paisa === null ? '' : amountInWords(paisa);
    });

    paintCategories();

    const { close } = sheet({
      title: 'Add entry',
      subtitle: project.name,
      body: [
        errors,
        toggle,
        h('label', { class: 'field' },
          span({ class: 'field-label', text: 'Amount' }),
          div({ class: 'amount-field' }, span({ class: 'amount-prefix', text: 'Rs' }), amount),
          words,
        ),
        div({ class: 'field-row' },
          field('Date', date),
          // Two differences for an accountant, per the design: no way to add a category
          // here, replaced by a line naming who can.
          field('Category', categorySelect, {
            hint: manages ? 'Required for Out.' : 'Required for Out. Only an Admin can add categories.',
          }),
        ),
        field('Description', description),
        notice(
          'Once saved, this entry cannot be edited or deleted — by you or by an Admin. '
          + 'If it is wrong, add a reconciling entry against it.',
          'warn',
        ),
      ],
      footer: (dismiss) => [
        button({ class: 'btn', text: 'Cancel', onClick: dismiss }),
        div({ class: 'sheet-foot-gap' }),
        button({ class: 'btn', text: 'Save & add another', onClick: () => save(true) }),
        button({ class: 'btn btn-primary', text: 'Save entry', onClick: () => save(false) }),
      ],
    });

    async function save(again) {
      clear(errors);
      const paisa = rupeesToPaisa(amount.value);

      if (paisa === null || paisa <= 0) {
        mount(errors, notice('Enter an amount greater than zero.'));
        amount.focus();
        return;
      }

      try {
        await api.post(`/projects/${projectId}/entries`, {
          type,
          amount_paisa: paisa,
          entry_date: date.value,
          category_id: categorySelect.value ? Number(categorySelect.value) : null,
          description: description.value.trim() || null,
        });

        toast('Entry saved', 'ok');

        if (again) {
          // Keeps type, date and category; clears amount and description; back to amount.
          amount.value = '';
          words.textContent = '';
          description.value = '';
          amount.focus();
          await reload();
        } else {
          close();
          await reload();
        }
      } catch (error) {
        const fields = reportError(error);
        mount(errors, ...Object.values(fields).map((message) => notice(message)));
      }
    }
  }

  /* ------------------------------------------------------------ reconcile */

  function openReconcile(entry) {
    const opposite = entry.type === 'in' ? 'out' : 'in';
    const amount = h('input', {
      inputmode: 'decimal',
      value: String(Math.trunc(entry.amount_paisa / 100)),
      'aria-label': 'Correction amount in rupees',
    });
    const words = div({ class: 'amount-words', text: amountInWords(entry.amount_paisa) });
    const date = h('input', { class: 'input', type: 'date', value: today() });
    const description = textInput({ value: `Reconciles entry #${entry.id}` });
    const errors = div();

    amount.addEventListener('input', () => {
      const paisa = rupeesToPaisa(amount.value);
      words.textContent = paisa === null ? '' : amountInWords(paisa);
    });

    const { close } = sheet({
      title: 'Reconcile entry',
      subtitle: `Correcting #${entry.id} · ${formatDate(entry.entry_date)}`,
      body: [
        errors,
        notice(
          `The original stays in the book. This posts a matching ${opposite.toUpperCase()} entry `
          + 'against it, and the pair remains visible.',
          'info',
        ),
        div({ class: 'card card-pad' },
          div({ class: 'col-head', text: 'Entry being corrected' }),
          div({ text: entry.description ?? '—' }),
          span({ class: `money ${entry.type === 'in' ? 'money-in' : 'money-out'}`,
            text: formatPkr(entry.amount_paisa) }),
        ),
        h('label', { class: 'field' },
          span({ class: 'field-label', text: `Amount to correct (${opposite})` }),
          div({ class: 'amount-field' }, span({ class: 'amount-prefix', text: 'Rs' }), amount),
          words,
        ),
        field('Date', date),
        field('Description', description),
      ],
      footer: (dismiss) => [
        button({ class: 'btn', text: 'Cancel', onClick: dismiss }),
        div({ class: 'sheet-foot-gap' }),
        button({ class: 'btn btn-primary', text: 'Post correction', onClick: submit }),
      ],
    });

    async function submit() {
      clear(errors);
      const paisa = rupeesToPaisa(amount.value);

      if (paisa === null || paisa <= 0) {
        mount(errors, notice('Enter an amount greater than zero.'));
        return;
      }

      try {
        await api.post(`/projects/${projectId}/entries/${entry.id}/reconcile`, {
          amount_paisa: paisa,
          entry_date: date.value,
          description: description.value.trim() || null,
        });

        close();
        toast('Correction posted', 'ok');
        await reload();
      } catch (error) {
        const fields = reportError(error);
        mount(errors, ...Object.values(fields).map((message) => notice(message)));
      }
    }
  }

  /* --------------------------------------------------------------- export */

  /**
   * Built in the browser from rows already fetched. No endpoint, no temporary file, and
   * nothing written to the server — which the brief rules out for this phase anyway.
   */
  async function exportCsv() {
    let all = rows;
    let next = cursor;

    while (next) {
      const result = await api.get(`/projects/${projectId}/entries`, { cursor: next, limit: 200 });
      all = [...all, ...result.data];
      next = result.meta.next_cursor;
    }

    const header = ['Date', 'Description', 'Category', 'Added by', 'In', 'Out', 'Balance'];
    const cell = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;

    const lines = [header.map(cell).join(',')];

    for (const entry of all) {
      lines.push([
        entry.entry_date,
        entry.description ?? '',
        entry.category?.name ?? '',
        entry.created_by.name,
        entry.type === 'in' ? (entry.amount_paisa / 100).toFixed(2) : '',
        entry.type === 'out' ? (entry.amount_paisa / 100).toFixed(2) : '',
        (entry.running_balance_paisa / 100).toFixed(2),
      ].map(cell).join(','));
    }

    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = h('a', { href: url, download: `${project.name.replace(/[^\w-]+/g, '-')}.csv` });

    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  boot();
  return root;
}
