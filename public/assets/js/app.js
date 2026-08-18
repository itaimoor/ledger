/**
 * Entry point: resolve the route, make sure the caller is signed in and has an
 * organization selected, then hand the page to a view.
 *
 * State is four fields on `session` plus whatever a view keeps in its own closure. Views
 * never reach into one another; navigation is the only way between them.
 */

import { api } from './api.js';
import { session } from './session.js';
import { currentPath, interceptLinks, navigate, resolve, route } from './router.js';
import {
  button, clear, div, field, h, mount, notice, reportError, textInput, toast,
} from './ui.js';
import { header } from './views/shell.js';
import { acceptInvite, changePassword, signIn, signUp } from './views/auth.js';
import { projectsView } from './views/projects.js';
import { projectView } from './views/project.js';
import { categoriesView, membersView, projectSettingsView } from './views/admin.js';
import { profileView } from './views/profile.js';
import { reportsView } from './views/reports.js';
import { activityView } from './views/activity.js';

route('/signin', { view: signIn, public: true });
route('/signup', { view: signUp, public: true });
route('/join/:token', { view: acceptInvite, public: true });
route('/password', { view: changePassword, chrome: false });

route('/', { redirect: '/projects' });
route('/projects', { view: projectsView });
route('/projects/:id', { view: projectView });
route('/projects/:id/settings', { view: projectSettingsView, manageOnly: true });
route('/reports', { view: reportsView });
route('/activity', { view: activityView });
route('/profile', { view: profileView });
route('/members', { view: membersView, manageOnly: true });
route('/categories', { view: categoriesView, manageOnly: true });
route('/settings', { view: orgSettingsView, manageOnly: true });
route('/organizations/new', { view: createOrgView, chrome: false });

const root = document.getElementById('app');

/** Held for the life of the page and dropped whenever the list could have changed. */
let organizations = null;

function forgetOrganizations() {
  organizations = null;
}

async function loadOrganizations() {
  organizations ??= (await api.get('/organizations')).data;
  return organizations;
}

async function render() {
  const match = resolve();

  if (!match) return paint(notFound(), false);

  const { view: config, params } = match;

  if (config.redirect) return navigate(config.redirect, { replace: true });
  if (config.public) return paint(config.view({ params }), false);

  if (!session.isSignedIn) return navigate('/signin', { replace: true });

  try {
    if (!session.user) session.setUser((await api.get('/me')).data);
  } catch (error) {
    return handleAuthFailure(error);
  }

  // An account provisioned with a one-time password cannot go anywhere else first.
  if (session.user?.must_change_password && currentPath() !== '/password') {
    return navigate('/password', { replace: true });
  }

  if (config.chrome === false) return paint(config.view({ params }), false);

  try {
    const orgs = await loadOrganizations();

    if (orgs.length === 0) return paint(createOrgView({ first: true }), false);

    const current = orgs.find((org) => org.id === session.orgId) ?? orgs[0];
    session.setOrg(current.id);

    const manages = current.role === 'owner' || current.role === 'admin';

    const content = config.manageOnly && !manages
      ? div({ class: 'page' },
        notice('That screen is only for owners and admins.', 'warn'),
        h('a', { class: 'btn', href: '/projects', text: 'Back to projects' }),
      )
      : config.view({ orgId: current.id, role: current.role, params });

    paint(content, true, orgs, current);
  } catch (error) {
    handleAuthFailure(error);
  }
}

function handleAuthFailure(error) {
  if (error?.status === 401) {
    session.clear();
    return navigate('/signin', { replace: true });
  }

  reportError(error);
  paint(div({ class: 'page' }, notice('Could not load your account. Try refreshing.')), false);
}

function paint(content, withChrome, orgs = [], current = null) {
  mount(root, withChrome ? header(orgs, current) : null, content);
  window.scrollTo(0, 0);
}

function notFound() {
  return div({ class: 'page' },
    h('h1', { class: 'page-title', text: 'Page not found' }),
    h('p', { class: 'auth-sub', text: 'That address does not lead anywhere in Ledger.' }),
    h('a', { class: 'btn', href: '/projects', text: 'Back to projects' }),
  );
}

function createOrgView({ first = false } = {}) {
  const name = textInput({ required: true, placeholder: 'Rehman Builders (Pvt) Ltd' });
  const errors = div();
  const submit = button({ class: 'btn btn-primary btn-lg btn-block', text: 'Create organization', type: 'submit' });

  const form = h('form', { class: 'form-grid', novalidate: true },
    field('Organization name', name),
    notice('Starts with the default categories: Labour, Material, Transport, Fuel, Rent, Misc.', 'info'),
    submit,
  );

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    submit.disabled = true;
    clear(errors);

    try {
      const { data } = await api.post('/organizations', { name: name.value.trim() });
      forgetOrganizations();
      session.setOrg(data.id);
      toast(`${data.name} created`, 'ok');
      navigate('/projects');
      render();
    } catch (error) {
      mount(errors, ...Object.values(reportError(error)).map((message) => notice(message)));
      submit.disabled = false;
    }
  });

  return div({ class: 'auth' },
    div({ class: 'auth-card' },
      div({ class: 'auth-brand' },
        h('img', { class: 'brand', src: '/assets/img/logo.svg', alt: '' }),
        div({ class: 'auth-brand-name', text: 'Ledger' }),
      ),
      h('h1', { class: 'auth-title', text: first ? 'Create your first organization' : 'Create an organization' }),
      h('p', { class: 'auth-sub', text: 'You will be its owner.' }),
      errors,
      form,
      first ? null : h('a', { class: 'auth-foot', href: '/projects', text: 'Back to projects' }),
    ),
  );
}

function orgSettingsView({ orgId, role }) {
  const page = div({ class: 'page' });

  api.get(`/organizations/${orgId}`)
    .then(({ data }) => {
      const name = textInput({ value: data.name, disabled: role !== 'owner' });
      const currency = textInput({ value: 'PKR — Rs', disabled: true });

      mount(page, 
        div({ class: 'page-head' },
          div({ class: 'page-head-text' },
            h('h1', { class: 'page-title', text: 'Organization settings' }),
            div({ class: 'meta', text: `${data.name} · you are ${data.your_role}` }),
          ),
        ),
        div({ class: 'card' },
          div({ class: 'card-pad form-grid' },
            field('Organization name', name),
            field('Currency', currency, { hint: 'Ledger is PKR only.' }),
            role === 'owner'
              ? div({ class: 'page-actions' },
                button({
                  class: 'btn btn-primary',
                  text: 'Save changes',
                  onClick: async () => {
                    try {
                      await api.patch(`/organizations/${orgId}`, { name: name.value.trim() });
                      forgetOrganizations();
                      toast('Saved', 'ok');
                      render();
                    } catch (error) {
                      reportError(error);
                    }
                  },
                }),
              )
              : notice('Only the owner can rename the organization.', 'info'),
          ),
        ),
      );
    })
    .catch(() => mount(page, notice('Could not load organization settings.')));

  return page;
}

interceptLinks();
window.addEventListener('routechange', render);

render();
