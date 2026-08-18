/**
 * Activity log.
 *
 * Because the book is append-only, this has only two kinds of money event: an entry was
 * added, or an entry was added to reconcile an earlier one. Nothing was ever changed or
 * removed. Visible to every role, including Viewer.
 */

import { api } from '../api.js';
import { formatDate, formatTime } from '../format.js';
import { formatPkr } from '../money.js';
import {
  button, card, div, emptyState, h, loading, mount, notice, reportError, span,
} from '../ui.js';

/**
 * How each action reads, and how it is tagged. Money events take In/Out colour; membership
 * and settings changes take accent, since they are neither money in nor money out.
 */
const ACTIONS = {
  'entry.created': { tag: 'Entry', tone: 'chip-in', text: 'added an entry' },
  'entry.reconciled': { tag: 'Correction', tone: 'chip-warn', text: 'corrected an entry' },
  'project.created': { tag: 'Project', tone: 'chip-accent', text: 'created a project' },
  'project.updated': { tag: 'Project', tone: 'chip-accent', text: 'updated project settings' },
  'project.archived': { tag: 'Project', tone: 'chip-accent', text: 'archived a project' },
  'project.deleted': { tag: 'Project', tone: 'chip-out', text: 'deleted a project' },
  'category.created': { tag: 'Category', tone: 'chip-accent', text: 'added a category' },
  'category.updated': { tag: 'Category', tone: 'chip-accent', text: 'changed a category' },
  'category.deleted': { tag: 'Category', tone: 'chip-out', text: 'deleted a category' },
  'member.added': { tag: 'Member', tone: 'chip-accent', text: 'added a member' },
  'member.removed': { tag: 'Member', tone: 'chip-out', text: 'removed a member' },
  'member.role_changed': { tag: 'Member', tone: 'chip-accent', text: 'changed a role' },
  'member.password_reset': { tag: 'Member', tone: 'chip-warn', text: "reset a member's password" },
  'invite.created': { tag: 'Invite', tone: 'chip-accent', text: 'created an invite' },
  'invite.accepted': { tag: 'Invite', tone: 'chip-in', text: 'accepted an invite' },
  'invite.revoked': { tag: 'Invite', tone: 'chip-accent', text: 'withdrew an invite' },
  'invite.declined': { tag: 'Invite', tone: 'chip-accent', text: 'declined an invite' },
  'organization.created': { tag: 'Org', tone: 'chip-accent', text: 'created the organization' },
  'organization.renamed': { tag: 'Org', tone: 'chip-accent', text: 'renamed the organization' },
  'organization.deleted': { tag: 'Org', tone: 'chip-out', text: 'deleted the organization' },
  'permission.denied': { tag: 'Refused', tone: 'chip-out', text: 'was refused an action' },
  'auth.login': { tag: 'Sign in', tone: 'chip', text: 'signed in' },
  'auth.registered': { tag: 'Sign up', tone: 'chip-accent', text: 'created an account' },
  'auth.password_changed': { tag: 'Password', tone: 'chip-accent', text: 'changed their password' },
  'user.renamed': { tag: 'Profile', tone: 'chip-accent', text: 'changed their name' },
};

const FILTERABLE = Object.keys(ACTIONS);

export function activityView({ orgId }) {
  const root = div({ class: 'page' });
  const state = { userId: '', action: '' };

  let rows = [];
  let cursor = null;
  let members = [];

  async function load(append = false) {
    if (!append) mount(root, loading());

    try {
      if (members.length === 0) {
        const result = await api.get(`/organizations/${orgId}/members`).catch(() => null);
        members = result?.data?.members ?? [];
      }

      const result = await api.get(`/organizations/${orgId}/activity`, {
        user_id: state.userId,
        action: state.action,
        cursor: append ? cursor : '',
        limit: 30,
      });

      rows = append ? [...rows, ...result.data] : result.data;
      cursor = result.meta.next_cursor;
      render();
    } catch (error) {
      reportError(error);
      mount(root, notice('Could not load the activity log.'));
    }
  }

  function render() {
    mount(root, 
      div({ class: 'page-head' },
        div({ class: 'page-head-text' },
          h('h1', { class: 'page-title', text: 'Activity' }),
          div({ class: 'meta', text: `${rows.length} event${rows.length === 1 ? '' : 's'} shown` }),
        ),
        div({ class: 'page-actions' }, memberSelect(), actionSelect()),
      ),
      card(
        rows.length === 0
          ? emptyState('Nothing recorded yet', 'Every entry, correction and role change lands here.')
          : div({},
            ...rows.map(activityRow),
            cursor
              ? div({ class: 'load-more' },
                button({ class: 'btn', text: 'Load 30 more', onClick: () => load(true) }))
              : null,
          ),
      ),
    );
  }

  function memberSelect() {
    const node = h('select', { class: 'select', 'aria-label': 'Filter by member' },
      h('option', { value: '' }, 'All members'),
      ...members.map((m) => h('option', { value: String(m.id), selected: String(m.id) === state.userId }, m.name)),
    );
    node.addEventListener('change', () => { state.userId = node.value; rows = []; cursor = null; load(); });
    return node;
  }

  function actionSelect() {
    const node = h('select', { class: 'select', 'aria-label': 'Filter by action' },
      h('option', { value: '' }, 'All actions'),
      ...FILTERABLE.map((action) => h('option', {
        value: action, selected: action === state.action,
      }, ACTIONS[action].tag + ' — ' + ACTIONS[action].text)),
    );
    node.addEventListener('change', () => { state.action = node.value; rows = []; cursor = null; load(); });
    return node;
  }

  function activityRow(event) {
    const meaning = ACTIONS[event.action] ?? { tag: 'Event', tone: 'chip', text: event.action };

    return div({ class: 'activity-row' },
      div({ class: 'activity-tag' }, span({ class: `chip ${meaning.tone}`, text: meaning.tag })),
      div({ class: 'activity-text' },
        div({ class: 'activity-what' },
          h('strong', { text: event.actor?.name ?? 'Someone' }),
          ' ' + meaning.text,
        ),
        event.project ? div({ class: 'activity-where', text: event.project.name }) : null,
        diffOf(event),
      ),
      div({ class: 'activity-when' },
        div({ class: 'activity-date', text: formatDate(event.created_at) }),
        div({ class: 'activity-time', text: formatTime(event.created_at) }),
      ),
    );
  }

  /**
   * A correction shows the figure it corrects struck through beside the net result. It is
   * a reading aid only — both entries are real rows and the original is never touched.
   */
  function diffOf(event) {
    if (event.action === 'entry.reconciled' && event.before?.amount_paisa) {
      return div({ class: 'activity-diff' },
        span({ class: 'before', text: formatPkr(event.before.amount_paisa) }),
        span({ class: 'arrow', text: '→' }),
        span({ text: formatPkr(event.after?.amount_paisa ?? 0) }),
      );
    }

    if (event.action === 'entry.created' && event.after?.amount_paisa) {
      return div({ class: 'activity-diff' },
        span({ class: event.after.type === 'in' ? 'money-in' : 'money-out',
          text: `${event.after.type === 'in' ? '+' : '−'}${formatPkr(event.after.amount_paisa)}` }),
      );
    }

    if (event.before?.role && event.after?.role) {
      return div({ class: 'activity-diff' },
        span({ class: 'before', text: event.before.role }),
        span({ class: 'arrow', text: '→' }),
        span({ text: event.after.role }),
      );
    }

    return null;
  }

  load();
  return root;
}
