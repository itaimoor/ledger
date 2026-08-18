/**
 * Reports.
 *
 * Two questions only: is this project taking in more than it spends, and where is the
 * spending going. Both charts are CSS bars over figures already in the entry list — no
 * library — and every value is printed rather than left to a hover.
 */

import { api } from '../api.js';
import { formatPeriod } from '../format.js';
import { balanceTone, formatPkr } from '../money.js';
import {
  button, card, div, emptyState, h, loading, mount, notice, reportError, span,
} from '../ui.js';

export function reportsView({ orgId }) {
  const root = div({ class: 'page' });
  const state = { interval: 'monthly', projectId: '' };
  let projects = [];

  async function load() {
    mount(root, loading());

    try {
      if (projects.length === 0) {
        const result = await api.get(`/organizations/${orgId}/projects`, { status: '' });
        projects = result.data;
      }

      const query = { interval: state.interval, project_id: state.projectId };
      const [cashflow, categories] = await Promise.all([
        api.get(`/organizations/${orgId}/reports/cashflow`, query),
        api.get(`/organizations/${orgId}/reports/categories`, query),
      ]);

      render(cashflow, categories);
    } catch (error) {
      reportError(error);
      mount(root, notice('Could not load reports.'));
    }
  }

  function render(cashflow, categories) {
    const scope = projects.find((p) => String(p.id) === state.projectId);

    mount(root, 
      div({ class: 'page-head' },
        div({ class: 'page-head-text' },
          h('h1', { class: 'page-title', text: 'Reports' }),
          div({ class: 'meta', text: `${scope ? scope.name : 'All projects'} · ${cashflow.meta.totals.entry_count} entries` }),
        ),
        div({ class: 'page-actions' },
          intervalSelect(),
          scopeSelect(),
          button({ class: 'btn', text: 'Print', onClick: () => window.print() }),
        ),
      ),
      div({ class: 'stat-row' },
        statLine('Total in', cashflow.meta.totals.total_in_paisa),
        statLine('Total out', cashflow.meta.totals.total_out_paisa),
        statLine('Balance', cashflow.meta.totals.balance_paisa, true),
      ),
      cashflowCard(cashflow),
      breakdownCard(categories),
    );
  }

  function statLine(label, paisa, tone = false) {
    return div({ class: 'stat' },
      div({ class: 'stat-label', text: label }),
      div({ class: `money money-lg ${tone ? balanceTone(paisa) : ''}`, text: formatPkr(paisa) }),
    );
  }

  function intervalSelect() {
    const node = h('select', { class: 'select', 'aria-label': 'Interval' },
      ...[['monthly', 'Monthly'], ['weekly', 'Weekly'], ['daily', 'Daily']]
        .map(([value, label]) => h('option', { value, selected: value === state.interval }, label)),
    );
    node.addEventListener('change', () => { state.interval = node.value; load(); });
    return node;
  }

  function scopeSelect() {
    const node = h('select', { class: 'select', 'aria-label': 'Project' },
      h('option', { value: '' }, 'All projects'),
      ...projects.map((p) => h('option', { value: String(p.id), selected: String(p.id) === state.projectId }, p.name)),
    );
    node.addEventListener('change', () => { state.projectId = node.value; load(); });
    return node;
  }

  function cashflowCard(cashflow) {
    const periods = cashflow.data;

    if (periods.length === 0) {
      return card(emptyState('Nothing to chart yet', 'Add some entries and this fills in.'));
    }

    // One shared scale, so an In bar and an Out bar of the same height mean the same money.
    const peak = Math.max(...periods.flatMap((p) => [p.total_in_paisa, p.total_out_paisa]), 1);
    const height = (paisa) => `${Math.max((paisa / peak) * 100, paisa > 0 ? 1.5 : 0)}%`;

    return card(
      div({ class: 'card-head' },
        div({ class: 'card-head-text' }, div({ class: 'card-title', text: 'In vs Out over time' })),
        div({ class: 'legend' },
          div({ class: 'legend-item' }, span({ class: 'legend-swatch bar-in' }), 'In'),
          div({ class: 'legend-item' }, span({ class: 'legend-swatch bar-out' }), 'Out'),
        ),
      ),
      div({ class: 'card-pad' },
        div({ class: 'bars' },
          ...periods.map((period) => div({
            class: 'bar-group',
            title: `${formatPeriod(period.period)} — in ${formatPkr(period.total_in_paisa)}, `
              + `out ${formatPkr(period.total_out_paisa)}`,
          },
            barOf('bar bar-in', height(period.total_in_paisa)),
            barOf('bar bar-out', height(period.total_out_paisa)),
          )),
        ),
        div({ class: 'bar-labels' },
          ...periods.map((period) => div({ class: 'bar-label', text: formatPeriod(period.period) })),
        ),
        div({ class: 'chart-notes' },
          note('Best period', cashflow.meta.best_period, true),
          note('Worst period', cashflow.meta.worst_period, true),
          div({ class: 'chart-note' },
            div({ class: 'chart-note-label', text: 'Average out per period' }),
            div({ class: 'chart-note-value money', text: formatPkr(cashflow.meta.average_out_paisa) }),
            div({ class: 'stat-note', text: `${periods.length} periods · ${cashflow.meta.negative_period_count} negative` }),
          ),
        ),
      ),
    );
  }

  function note(label, period, showNet) {
    if (!period) {
      return div({ class: 'chart-note' },
        div({ class: 'chart-note-label', text: label }),
        div({ class: 'chart-note-value muted', text: '—' }),
      );
    }

    return div({ class: 'chart-note' },
      div({ class: 'chart-note-label', text: label }),
      div({ class: 'chart-note-value', text: formatPeriod(period.period) }),
      showNet
        ? div({ class: `money ${balanceTone(period.net_paisa)}`, text: formatPkr(period.net_paisa, { showPlus: true }) })
        : null,
    );
  }

  function breakdownCard(categories) {
    const total = categories.meta.total_out_paisa;

    if (categories.data.length === 0) {
      return card(emptyState('No money out yet', 'Once there are payments, this shows where they went.'));
    }

    // A single hue at descending opacity, not six colours: rank is the information.
    const step = 1 / Math.max(categories.data.length, 1);

    return card(
      div({ class: 'card-head' },
        div({ class: 'card-head-text' }, div({ class: 'card-title', text: 'Out by category' })),
        span({ class: 'money', text: formatPkr(total) }),
      ),
      div({ class: 'card-pad breakdown' },
        ...categories.data.map((row, index) => {
          const share = total > 0 ? (row.total_out_paisa / total) * 100 : 0;
          const fill = div({ class: 'breakdown-fill' });
          fill.style.width = `${share}%`;
          fill.style.opacity = String(Math.max(1 - index * step * 0.75, 0.25));

          return div({ class: 'breakdown-row' },
            div({ class: 'breakdown-head' },
              span({ class: 'breakdown-name', text: row.category?.name ?? 'Uncategorised (corrections)' }),
              span({ class: 'money', text: formatPkr(row.total_out_paisa) }),
              span({ class: 'breakdown-share', text: `${share.toFixed(1)}%` }),
            ),
            div({ class: 'breakdown-track' }, fill),
          );
        }),
        div({ class: 'stat-note' },
          'Corrections that reverse a receipt are money out with no category, so they are '
          + 'listed separately rather than dropped. The shares always account for the whole total.',
        ),
      ),
    );
  }

  function barOf(className, height) {
    const bar = div({ class: className });
    bar.style.height = height;
    return bar;
  }

  load();
  return root;
}
