/**
 * The persistent header and nav.
 *
 * The organization name sits top-left and is always a button, because someone working for
 * two firms needs to know which book they are writing in before they type an amount. Their
 * role in the current org is printed next to their name.
 */

import { session } from '../session.js';
import { api } from '../api.js';
import { navigate, currentPath } from '../router.js';
import { initials } from '../format.js';
import { button, div, h, span } from '../ui.js';

const NAV = [
  { href: '/projects', label: 'Projects' },
  { href: '/reports', label: 'Reports' },
  { href: '/activity', label: 'Activity' },
  { gap: true },
  { href: '/members', label: 'Members', manageOnly: true },
  { href: '/categories', label: 'Categories', manageOnly: true },
  { href: '/settings', label: 'Org settings', manageOnly: true },
];

export function header(organizations, currentOrg) {
  const role = currentOrg?.role ?? '';
  const manages = role === 'owner' || role === 'admin';

  let switcher = null;

  const orgButton = button({
    class: 'org-button',
    'aria-haspopup': 'true',
    'aria-expanded': 'false',
    onClick: (event) => {
      event.stopPropagation();
      switcher ? closeSwitcher() : openSwitcher();
    },
  },
    span({ class: 'avatar', text: initials(currentOrg?.name ?? '?') }),
    span({ text: currentOrg?.name ?? 'No organization' }),
    span({ class: 'muted', text: '▾' }),
  );

  const bar = div({ class: 'header' },
    h('a', { href: '/projects', 'aria-label': 'Ledger' },
      h('img', { class: 'brand', src: '/assets/img/logo.svg', alt: '' })),
    orgButton,
    div({ class: 'header-spacer' }),
    button({ class: 'org-button', 'aria-label': 'Your profile', onClick: () => navigate('/profile') },
      div({ class: 'who' },
        div({ class: 'who-name', text: session.user?.name ?? '' }),
        div({ class: 'who-role', text: role }),
      ),
      span({ class: 'avatar avatar-round', text: initials(session.user?.name) }),
    ),
    button({ class: 'btn btn-quiet btn-sm', text: 'Sign out', onClick: signOut }),
  );

  function openSwitcher() {
    switcher = div({ class: 'switcher' },
      div({ class: 'col-head switcher-label', text: 'Your organizations' }),
      ...organizations.map((org) => button({
        class: 'switcher-item',
        onClick: () => {
          closeSwitcher();
          session.setOrg(org.id);
          // navigate() is a no-op when already on /projects — the usual place to switch
          // from — so force the repaint that shows the newly selected organization.
          if (currentPath() === '/projects') {
            window.dispatchEvent(new CustomEvent('routechange'));
          } else {
            navigate('/projects');
          }
        },
      },
        span({ class: 'avatar', text: initials(org.name) }),
        span({ class: 'switcher-item-text' },
          span({ class: 'switcher-item-name', text: org.name }),
          span({ class: 'switcher-item-meta', text: org.role }),
        ),
        org.id === currentOrg?.id ? span({ class: 'switcher-check', text: '✓' }) : null,
      )),
      button({
        class: 'switcher-new',
        onClick: () => { closeSwitcher(); navigate('/organizations/new'); },
      }, span({ text: '+' }), 'Create organization'),
    );

    orgButton.setAttribute('aria-expanded', 'true');
    bar.appendChild(switcher);
    document.addEventListener('click', closeSwitcher, { once: true });
  }

  function closeSwitcher() {
    switcher?.remove();
    switcher = null;
    orgButton.setAttribute('aria-expanded', 'false');
  }

  const path = currentPath();

  const nav = div({ class: 'nav' },
    ...NAV.map((item) => {
      if (item.gap) return div({ class: 'nav-gap' });
      if (item.manageOnly && !manages) return null;

      // Absent, not greyed: a role that cannot reach a screen is not shown a door to it.
      return h('a', {
        class: 'nav-item',
        href: item.href,
        text: item.label,
        'aria-current': path.startsWith(item.href) ? 'page' : null,
      });
    }),
  );

  return div({}, bar, nav);
}

async function signOut() {
  try {
    if (session.refreshToken) {
      await api.post('/auth/logout', { refresh_token: session.refreshToken });
    }
  } catch {
    // Signing out locally matters more than the server acknowledging it.
  }

  session.clear();
  navigate('/signin', { replace: true });
}
