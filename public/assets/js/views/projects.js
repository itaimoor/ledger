/**
 * Projects list.
 *
 * Org-wide totals first, then one row per project. A table on desktop because the value of
 * this screen is comparing balances down a column; the same table stacks into cards below
 * 900px because a seven-column table on a phone is unusable.
 */

import { api } from '../api.js';
import { formatRelative } from '../format.js';
import { balanceTone, formatPkr } from '../money.js';
import { navigate } from '../router.js';
import {
  button, card, clear, div, emptyState, field, h, loading, mount, notice, reportError,
  sheet, span, statTile, statusChip, textInput, toast,
} from '../ui.js';

const FILTERS = [
  { value: 'active', label: 'Active' },
  { value: 'completed', label: 'Completed' },
  { value: 'archived', label: 'Archived' },
  { value: '', label: 'All' },
];

export function projectsView({ orgId, role }) {
  const root = div({ class: 'page' });
  const manages = role === 'owner' || role === 'admin';

  const state = { status: 'active', search: '', sort: 'last_activity' };
  let debounce;

  async function load() {
    const body = div({}, loading());
    render(body, null);

    try {
      const result = await api.get(`/organizations/${orgId}/projects`, {
        status: state.status,
        search: state.search,
        sort: state.sort,
      });

      render(table(result.data), result.meta);
    } catch (error) {
      reportError(error);
      render(notice('Could not load projects.'), null);
    }
  }

  function render(content, meta) {
    mount(root, 
      div({ class: 'page-head' },
        div({ class: 'page-head-text' },
          h('h1', { class: 'page-title', text: 'Projects' }),
          div({ class: 'meta', text: summaryLine(meta) }),
        ),
        manages
          ? div({ class: 'page-actions' },
            button({ class: 'btn btn-primary', text: '+ New project', onClick: () => newProject(orgId, load) }))
          : null,
      ),
      meta
        ? div({ class: 'stat-row' },
          statTile('Total in — all projects', meta.totals.total_in_paisa),
          statTile('Total out — all projects', meta.totals.total_out_paisa),
          statTile('Balance', meta.totals.balance_paisa, null, { tone: true }),
        )
        : null,
      card(filterBar(), content),
    );
  }

  function summaryLine(meta) {
    if (!meta) return 'Loading…';
    const counts = meta.status_counts;
    const total = counts.active + counts.completed + counts.archived;
    return `${total} project${total === 1 ? '' : 's'} · ${counts.active} active`;
  }

  function filterBar() {
    const search = textInput({
      placeholder: 'Search project or client…',
      value: state.search,
      'aria-label': 'Search projects',
    });

    search.addEventListener('input', () => {
      clearTimeout(debounce);
      debounce = setTimeout(() => { state.search = search.value.trim(); load(); }, 250);
    });

    const sort = h('select', { class: 'select', 'aria-label': 'Sort projects' },
      ...[['last_activity', 'Last activity'], ['name', 'Name'], ['balance', 'Balance'], ['entries', 'Entries']]
        .map(([value, label]) => h('option', { value, selected: value === state.sort }, label)),
    );
    sort.addEventListener('change', () => { state.sort = sort.value; load(); });

    return div({ class: 'filters' },
      div({ class: 'segmented' },
        ...FILTERS.map((filter) => button({
          text: filter.label,
          'aria-pressed': String(state.status === filter.value),
          onClick: () => { state.status = filter.value; load(); },
        })),
      ),
      div({ class: 'search' }, span({ class: 'search-icon', text: '⌕' }), search),
      div({ class: 'filters-gap' }),
      sort,
    );
  }

  function table(projects) {
    if (projects.length === 0) {
      return emptyState(
        state.search ? 'No project matches that search' : 'No projects yet',
        state.search
          ? 'Try a different name, or clear the search.'
          : 'A project is one job with its own book of money in and out.',
        manages && !state.search
          ? button({ class: 'btn btn-primary', text: '+ New project', onClick: () => newProject(orgId, load) })
          : null,
      );
    }

    return div({ class: 'table-wrap' },
      h('table', { class: 'table table-stack' },
        h('thead', {}, h('tr', {},
          h('th', { text: 'Project' }),
          h('th', { text: 'Status' }),
          h('th', { class: 'num', text: 'Total in' }),
          h('th', { class: 'num', text: 'Total out' }),
          h('th', { class: 'num', text: 'Balance' }),
          h('th', { class: 'num', text: 'Entries' }),
          h('th', { text: 'Last activity' }),
        )),
        h('tbody', {}, ...projects.map(projectRow)),
      ),
    );
  }

  function projectRow(project) {
    const money = (label, paisa, tone = false) => h('td', { class: 'num', 'data-label': label },
      span({ class: `money ${tone ? balanceTone(paisa) : ''}`, text: formatPkr(paisa) }));

    return h('tr', {},
      h('td', { 'data-label': 'Project' },
        div({}, h('a', { class: 'row-link', href: `/projects/${project.id}`, text: project.name })),
        project.client_name ? div({ class: 'row-sub', text: project.client_name }) : null,
      ),
      h('td', { 'data-label': 'Status' }, statusChip(project.status)),
      money('Total in', project.total_in_paisa),
      money('Total out', project.total_out_paisa),
      money('Balance', project.balance_paisa, true),
      h('td', { class: 'num', 'data-label': 'Entries', text: String(project.entry_count) }),
      h('td', { 'data-label': 'Last activity', text: formatRelative(project.last_entry_at) }),
    );
  }

  load();
  return root;
}

export function newProject(orgId, onCreated) {
  const name = textInput({ required: true, placeholder: 'DHA Phase 6, Villa 214' });
  const client = textInput({ placeholder: 'Maj. (R) Tariq Aziz' });
  const description = h('textarea', { class: 'textarea', rows: '3' });
  const errors = div();

  const { close } = sheet({
    title: 'New project',
    subtitle: 'One job, one book.',
    body: [
      errors,
      field('Project name', name),
      field('Client name', client, { hint: 'Optional.' }),
      field('Description', description, { hint: 'Optional.' }),
    ],
    footer: (dismiss) => [
      button({ class: 'btn', text: 'Cancel', onClick: dismiss }),
      div({ class: 'sheet-foot-gap' }),
      button({ class: 'btn btn-primary', text: 'Create project', onClick: submit }),
    ],
  });

  async function submit() {
    clear(errors);

    try {
      const { data } = await api.post(`/organizations/${orgId}/projects`, {
        name: name.value.trim(),
        client_name: client.value.trim() || null,
        description: description.value.trim() || null,
      });

      close();
      toast(`${data.name} created`, 'ok');
      onCreated ? onCreated() : navigate(`/projects/${data.id}`);
    } catch (error) {
      const fields = reportError(error);
      mount(errors, ...Object.values(fields).map((message) => notice(message)));
    }
  }
}
