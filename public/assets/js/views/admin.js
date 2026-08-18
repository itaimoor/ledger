/**
 * Categories, members and project settings. Owner and Admin only — for other roles the
 * nav items are absent, not greyed.
 */

import { api } from '../api.js';
import { formatDate, formatRelative, initials } from '../format.js';
import { formatPkr } from '../money.js';
import { navigate } from '../router.js';
import { session } from '../session.js';
import {
  button, card, clear, copyField, dialog, div, emptyState, field, h, loading, mount,
  notice, passwordInput, reportError, sheet, span, textInput, toast,
} from '../ui.js';

const INVITABLE = [
  { value: 'admin', label: 'Admin', note: 'Manage projects, members and categories. Reconcile any entry.' },
  { value: 'accountant', label: 'Accountant', note: 'Add entries and reconcile any entry. No project or member settings.' },
  { value: 'viewer', label: 'Viewer', note: 'Read-only. Can export and print.' },
];

/* ------------------------------------------------------------------ categories */

export function categoriesView({ orgId }) {
  const root = div({ class: 'page' });

  async function load() {
    mount(root, loading());

    try {
      const { data } = await api.get(`/organizations/${orgId}/categories`, { include_archived: 'true' });
      render(data);
    } catch (error) {
      reportError(error);
      mount(root, notice('Could not load categories.'));
    }
  }

  function render(categories) {
    const name = textInput({ placeholder: 'Machinery hire', 'aria-label': 'New category name' });
    const type = h('select', { class: 'select', 'aria-label': 'Category type' },
      h('option', { value: 'out' }, 'Out'),
      h('option', { value: 'in' }, 'In'),
      h('option', { value: 'both' }, 'Both'),
    );

    const add = async () => {
      if (!name.value.trim()) return;
      try {
        await api.post(`/organizations/${orgId}/categories`, { name: name.value.trim(), type: type.value });
        toast('Category added', 'ok');
        await load();
      } catch (error) {
        reportError(error);
      }
    };

    mount(root, 
      div({ class: 'page-head' },
        div({ class: 'page-head-text' },
          h('h1', { class: 'page-title', text: 'Categories' }),
          div({ class: 'meta', text: 'Shared across every project in this organization.' }),
        ),
      ),
      card(
        div({ class: 'filters' }, name, type, button({ class: 'btn btn-primary', text: 'Add', onClick: add })),
        categories.length === 0
          ? emptyState('No categories', 'Categories label where money went. Add the first one above.')
          : div({ class: 'table-wrap' },
            h('table', { class: 'table table-stack' },
              h('thead', {}, h('tr', {},
                h('th', { text: 'Category' }),
                h('th', { text: 'Type' }),
                h('th', { class: 'num', text: 'Entries' }),
                h('th', { class: 'num', text: 'Total out' }),
                h('th', {}, span({ class: 'visually-hidden', text: 'Actions' })),
              )),
              h('tbody', {}, ...categories.map(categoryRow)),
            ),
          ),
      ),
    );
  }

  function categoryRow(category) {
    // Usage is shown before the delete decision. A category with entries against it can be
    // renamed but not deleted, so that row offers Rename only.
    const inUse = category.entry_count > 0;

    return h('tr', {},
      h('td', { 'data-label': 'Category' },
        span({ text: category.name }),
        category.is_archived ? span({ class: 'chip chip-archived', text: 'Archived' }) : null,
      ),
      h('td', { 'data-label': 'Type' }, span({ class: `chip chip-${category.type === 'both' ? '' : category.type}`.trim(), text: category.type })),
      h('td', { class: 'num', 'data-label': 'Entries', text: String(category.entry_count) }),
      h('td', { class: 'num', 'data-label': 'Total out' },
        span({ class: 'money', text: formatPkr(category.total_out_paisa) })),
      h('td', { class: 'row-actions' },
        button({ class: 'btn btn-sm', text: 'Rename', onClick: () => rename(category) }),
        button({
          class: 'btn btn-sm',
          text: category.is_archived ? 'Restore' : 'Archive',
          onClick: () => setArchived(category, !category.is_archived),
        }),
        inUse ? null : button({ class: 'btn btn-sm', text: 'Delete', onClick: () => remove(category) }),
      ),
    );
  }

  async function setArchived(category, archived) {
    try {
      await api.patch(`/organizations/${orgId}/categories/${category.id}`, { is_archived: archived });
      await load();
    } catch (error) {
      reportError(error);
    }
  }

  function rename(category) {
    const input = textInput({ value: category.name });

    dialog({
      title: `Rename ${category.name}`,
      body: field('Category name', input),
      confirmLabel: 'Rename',
      confirmClass: 'btn-primary',
      onConfirm: async () => {
        await api.patch(`/organizations/${orgId}/categories/${category.id}`, { name: input.value.trim() });
        toast('Renamed', 'ok');
        await load();
      },
    });
  }

  function remove(category) {
    dialog({
      title: `Delete ${category.name}?`,
      body: 'Nothing has been recorded against it, so nothing is lost.',
      confirmLabel: 'Delete',
      onConfirm: async () => {
        await api.delete(`/organizations/${orgId}/categories/${category.id}`);
        toast('Deleted', 'ok');
        await load();
      },
    });
  }

  load();
  return root;
}

/* --------------------------------------------------------------------- members */

export function membersView({ orgId, role }) {
  const root = div({ class: 'page' });
  const isOwner = role === 'owner';

  async function load() {
    mount(root, loading());

    try {
      const { data } = await api.get(`/organizations/${orgId}/members`);
      render(data);
    } catch (error) {
      reportError(error);
      mount(root, notice('Could not load members.'));
    }
  }

  function render(data) {
    mount(root, 
      div({ class: 'page-head' },
        div({ class: 'page-head-text' },
          h('h1', { class: 'page-title', text: 'Members' }),
          div({
            class: 'meta',
            text: `${data.members.length} members · ${data.pending_invites.length} invite pending`,
          }),
        ),
        div({ class: 'page-actions' },
          button({ class: 'btn', text: 'Generate invite link', onClick: () => inviteSheet(orgId, load) }),
          button({ class: 'btn btn-primary', text: '+ Add member', onClick: () => addMemberSheet(orgId, load) }),
        ),
      ),
      card(
        div({ class: 'table-wrap' },
          h('table', { class: 'table table-stack' },
            h('thead', {}, h('tr', {},
              h('th', { text: 'Member' }),
              h('th', { text: 'Role' }),
              h('th', { text: 'Last active' }),
              h('th', {}, span({ class: 'visually-hidden', text: 'Actions' })),
            )),
            h('tbody', {},
              ...data.members.map(memberRow),
              ...data.pending_invites.map(inviteRow),
            ),
          ),
        ),
      ),
      card(
        div({ class: 'card-head' }, div({ class: 'card-title', text: 'What each role can do' })),
        div({ class: 'card-pad form-grid' },
          ...INVITABLE.map((r) => div({},
            div({ class: 'field-label', text: r.label }),
            div({ class: 'field-hint', text: r.note }),
          )),
        ),
      ),
    );
  }

  function memberRow(member) {
    const isSelf = member.id === session.user?.id;
    // The owner row has no Remove action and no role dropdown; ownership transfer is a
    // separate, deliberate action.
    const locked = member.role === 'owner';

    const roleCell = locked
      ? span({ class: 'role-pill role-pill-owner', text: member.role })
      : (() => {
        const picker = h('select', { class: 'select', 'aria-label': `Role for ${member.name}` },
          ...INVITABLE.map((r) => h('option', { value: r.value, selected: r.value === member.role }, r.label)),
        );
        picker.addEventListener('change', async () => {
          try {
            await api.patch(`/organizations/${orgId}/members/${member.id}`, { role: picker.value });
            toast(`${member.name} is now ${picker.value}`, 'ok');
            await load();
          } catch (error) {
            reportError(error);
            await load();
          }
        });
        return picker;
      })();

    return h('tr', {},
      h('td', { 'data-label': 'Member' },
        div({ class: 'member-cell' },
          span({ class: 'avatar avatar-round', text: initials(member.name) }),
          div({},
            div({ class: 'member-name' }, member.name, isSelf ? span({ class: 'muted', text: ' (you)' }) : null),
            div({ class: 'row-sub', text: member.email }),
          ),
        ),
      ),
      h('td', { 'data-label': 'Role' }, roleCell),
      h('td', { 'data-label': 'Last active', text: formatRelative(member.last_seen_at) }),
      h('td', { class: 'row-actions' },
        locked || isSelf
          ? span({ class: 'muted', text: '—' })
          : [
            button({ class: 'btn btn-sm', text: 'Reset password', onClick: () => resetPasswordSheet(orgId, member) }),
            button({ class: 'btn btn-sm', text: 'Remove', onClick: () => removeMember(member) }),
          ],
      ),
    );
  }

  function inviteRow(invite) {
    return h('tr', {},
      h('td', { 'data-label': 'Member' },
        div({ class: 'member-cell' },
          span({ class: 'avatar avatar-round', text: '?' }),
          div({},
            div({ class: 'member-name', text: invite.email }),
            div({ class: 'row-sub', text: `Invited by ${invite.invited_by} · expires ${formatDate(invite.expires_at)}` }),
          ),
        ),
      ),
      h('td', { 'data-label': 'Role' }, span({ class: 'role-pill', text: invite.role })),
      h('td', { 'data-label': 'Last active' }, span({ class: 'chip chip-warn', text: 'Invite pending' })),
      h('td', { class: 'row-actions' },
        button({
          class: 'btn btn-sm',
          text: 'Withdraw',
          onClick: async () => {
            try {
              await api.delete(`/organizations/${orgId}/invites/${invite.id}`);
              await load();
            } catch (error) {
              reportError(error);
            }
          },
        }),
      ),
    );
  }

  function removeMember(member) {
    dialog({
      title: `Remove ${member.name}?`,
      body: 'They lose access immediately. Entries they added stay in the book, attributed to them.',
      confirmLabel: 'Remove',
      onConfirm: async () => {
        await api.delete(`/organizations/${orgId}/members/${member.id}`);
        toast(`${member.name} removed`, 'ok');
        await load();
      },
    });
  }

  load();
  return root;
}

function roleSelect() {
  return h('select', { class: 'select', 'aria-label': 'Role' },
    ...INVITABLE.map((r) => h('option', { value: r.value, selected: r.value === 'accountant' }, r.label)),
  );
}

function addMemberSheet(orgId, onDone) {
  const name = textInput({ required: true });
  const email = textInput({ type: 'email', required: true });
  const role = roleSelect();
  const password = passwordInput({ autocomplete: 'new-password' });
  const errors = div();
  const result = div();

  const { close } = sheet({
    title: 'Add a member',
    subtitle: 'Creates the account now and shows a one-time password once.',
    body: [
      errors,
      result,
      field('Name', name),
      field('Email', email),
      field('Role', role),
      field('Password', password, {
        hint: 'Optional. Leave blank to generate a one-time password. '
          + 'At least 12 characters either way — they replace it on first sign-in.',
      }),
    ],
    footer: (dismiss) => [
      button({ class: 'btn', text: 'Close', onClick: () => { dismiss(); onDone(); } }),
      div({ class: 'sheet-foot-gap' }),
      button({ class: 'btn btn-primary', text: 'Create account', onClick: submit }),
    ],
  });

  async function submit() {
    clear(errors);

    try {
      const { data } = await api.post(`/organizations/${orgId}/members`, {
        name: name.value.trim(),
        email: email.value.trim(),
        role: role.value,
        ...(password.input.value ? { password: password.input.value } : {}),
      });

      mount(result,
        data.one_time_password
          ? div({ class: 'form-grid' },
            notice('Copy this now. It is not stored and cannot be shown again.', 'warn'),
            div({ class: 'password-reveal' }, h('code', { text: data.one_time_password })),
            copyField(data.one_time_password),
          )
          : data.account_created
            ? notice('Account created with the password you set. They will be asked to replace it when they first sign in.', 'in')
            : notice(`${data.user.email} already had an account and has been added to this organization.`, 'info'),
      );
    } catch (error) {
      const fields = reportError(error);
      mount(errors, ...Object.values(fields).map((message) => notice(message)));
    }
  }
}

function resetPasswordSheet(orgId, member) {
  const password = passwordInput({ autocomplete: 'new-password' });
  const errors = div();
  const result = div();

  sheet({
    title: `Reset password — ${member.name}`,
    subtitle: 'Signs them out everywhere. They choose their own on next sign-in.',
    body: [
      errors,
      result,
      field('New password', password, {
        hint: 'Optional. Leave blank to generate a one-time password shown once. At least 12 characters.',
      }),
    ],
    footer: (dismiss) => [
      button({ class: 'btn', text: 'Close', onClick: dismiss }),
      div({ class: 'sheet-foot-gap' }),
      button({ class: 'btn btn-primary', text: 'Reset password', onClick: submit }),
    ],
  });

  async function submit() {
    clear(errors);

    try {
      const { data } = await api.post(
        `/organizations/${orgId}/members/${member.id}/password`,
        password.input.value ? { password: password.input.value } : {},
      );

      mount(result, data.one_time_password
        ? div({ class: 'form-grid' },
          notice('Copy this now. It is not stored and cannot be shown again.', 'warn'),
          div({ class: 'password-reveal' }, h('code', { text: data.one_time_password })),
          copyField(data.one_time_password),
        )
        : notice(`Password set. ${member.name} has been signed out everywhere and will choose their own on next sign-in.`, 'in'));
    } catch (error) {
      const fields = reportError(error);
      mount(errors, ...Object.values(fields).map((message) => notice(message)));
    }
  }
}

function inviteSheet(orgId, onDone) {
  const email = textInput({ type: 'email', required: true });
  const role = roleSelect();
  const errors = div();
  const result = div();

  const { close } = sheet({
    title: 'Generate an invite link',
    subtitle: 'You deliver the link yourself — nothing is emailed.',
    body: [errors, result, field('Email', email), field('Role', role)],
    footer: (dismiss) => [
      button({ class: 'btn', text: 'Close', onClick: () => { dismiss(); onDone(); } }),
      div({ class: 'sheet-foot-gap' }),
      button({ class: 'btn btn-primary', text: 'Generate link', onClick: submit }),
    ],
  });

  async function submit() {
    clear(errors);

    try {
      const { data } = await api.post(`/organizations/${orgId}/invites`, {
        email: email.value.trim(), role: role.value,
      });

      mount(result, div({ class: 'form-grid' },
        notice(`Valid until ${formatDate(data.expires_at)}. Only its fingerprint is stored, so it cannot be shown again.`, 'warn'),
        copyField(data.signup_url),
      ));
    } catch (error) {
      const fields = reportError(error);
      mount(errors, ...Object.values(fields).map((message) => notice(message)));
    }
  }
}

/* ------------------------------------------------------------- project settings */

export function projectSettingsView({ orgId, params }) {
  const projectId = Number(params.id);
  const root = div({ class: 'page' });

  async function load() {
    mount(root, loading());

    try {
      const [{ data: project }, { data: summary }] = await Promise.all([
        api.get(`/organizations/${orgId}/projects/${projectId}`),
        api.get(`/projects/${projectId}/summary`),
      ]);
      render(project, summary);
    } catch (error) {
      reportError(error);
      mount(root, notice('Could not load this project.'));
    }
  }

  function render(project, summary) {
    const name = textInput({ value: project.name });
    const client = textInput({ value: project.client_name ?? '' });
    const description = h('textarea', { class: 'textarea', rows: '3' });
    description.value = project.description ?? '';

    const statuses = [
      ['active', 'Active', 'Accepts new entries. Counts toward org totals.'],
      ['completed', 'Completed', 'Still accepts entries. Hidden from the default Active filter.'],
      ['archived', 'Archived', 'Read-only for everyone, including admins. Reversible.'],
    ];

    const status = h('select', { class: 'select', 'aria-label': 'Status' },
      ...statuses.map(([value, label]) => h('option', { value, selected: value === project.status }, label)),
    );
    const statusNote = div({ class: 'field-hint' });
    const paintNote = () => {
      statusNote.textContent = statuses.find(([value]) => value === status.value)?.[2] ?? '';
    };
    status.addEventListener('change', paintNote);
    paintNote();

    mount(root, 
      div({ class: 'breadcrumb' },
        h('a', { href: '/projects', text: 'Projects' }),
        span({ class: 'sep', text: '/' }),
        h('a', { href: `/projects/${projectId}`, text: project.name }),
        span({ class: 'sep', text: '/' }),
        span({ text: 'Settings' }),
      ),
      div({ class: 'page-head' },
        div({ class: 'page-head-text' },
          h('h1', { class: 'page-title', text: 'Project settings' }),
          div({ class: 'meta', text: project.name }),
        ),
      ),
      card(div({ class: 'card-pad form-grid' },
        field('Project name', name),
        field('Client name', client),
        field('Description', description),
        h('label', { class: 'field' },
          span({ class: 'field-label', text: 'Status' }),
          status,
          statusNote,
        ),
        div({ class: 'page-actions' },
          button({ class: 'btn btn-primary', text: 'Save changes', onClick: save }),
          h('a', { class: 'btn', href: `/projects/${projectId}`, text: 'Discard' }),
        ),
      )),
      div({ class: 'danger-zone' },
        div({ class: 'danger-title', text: 'Delete this project' }),
        div({ class: 'field-hint' },
          `${summary.entry_count} entries will be removed from the workspace along with this `
          + 'project. The records themselves are retained and nothing is destroyed, but nobody '
          + 'will be able to reach them. Archive instead if you only want it out of the way.',
        ),
        div({ class: 'page-actions' },
          button({ class: 'btn btn-danger', text: 'Delete project', onClick: () => confirmDelete(project, summary) }),
          button({
            class: 'btn',
            text: 'Archive instead',
            onClick: async () => {
              await api.patch(`/organizations/${orgId}/projects/${projectId}`, { status: 'archived' });
              toast('Project archived', 'ok');
              navigate(`/projects/${projectId}`);
            },
          }),
        ),
      ),
    );

    async function save() {
      try {
        await api.patch(`/organizations/${orgId}/projects/${projectId}`, {
          name: name.value.trim(),
          client_name: client.value.trim() || null,
          description: description.value.trim() || null,
          status: status.value,
        });
        toast('Saved', 'ok');
        navigate(`/projects/${projectId}`);
      } catch (error) {
        reportError(error);
      }
    }
  }

  function confirmDelete(project, summary) {
    dialog({
      title: `Delete ${project.name}?`,
      body: [
        div({ class: 'dialog-body' },
          `This removes ${summary.entry_count} entries, the project's reports and its activity `
          + 'history from the workspace. Members lose access immediately.',
        ),
        div({ class: 'stat-row' },
          div({ class: 'stat' },
            div({ class: 'stat-label', text: 'Total in' }),
            span({ class: 'money', text: formatPkr(summary.total_in_paisa) })),
          div({ class: 'stat' },
            div({ class: 'stat-label', text: 'Total out' }),
            span({ class: 'money', text: formatPkr(summary.total_out_paisa) })),
        ),
      ],
      requireText: project.name,
      confirmLabel: 'Delete permanently',
      onConfirm: async () => {
        await api.delete(`/organizations/${orgId}/projects/${projectId}`);
        toast('Project deleted', 'ok');
        navigate('/projects');
      },
    });
  }

  load();
  return root;
}
