/** Sign in, create an organization, accept an invite. The three ways in. */

import { api } from '../api.js';
import { session } from '../session.js';
import { navigate } from '../router.js';
import { formatDate } from '../format.js';
import {
  button, card, clear, div, field, h, mount, notice, passwordInput, reportError, span,
  textInput,
} from '../ui.js';

function shell(...children) {
  return div({ class: 'auth' }, div({ class: 'auth-card' }, ...children));
}

function brand() {
  return div({ class: 'auth-brand' },
    h('img', { class: 'brand', src: '/assets/img/logo.svg', alt: '' }),
    div({ class: 'auth-brand-name', text: 'Ledger' }),
  );
}

/**
 * Every form here submits through a real <form>, so Enter works, browsers offer to save
 * the password, and the labels are wired to their controls.
 */
function form(onSubmit, ...children) {
  const node = h('form', { class: 'form-grid', novalidate: true }, ...children);

  node.addEventListener('submit', (event) => {
    event.preventDefault();
    onSubmit();
  });

  return node;
}

export function signIn() {
  const email = textInput({ type: 'email', autocomplete: 'username', required: true });
  const password = passwordInput();
  const errors = div();
  const submit = button({ class: 'btn btn-primary btn-lg btn-block', text: 'Sign in', type: 'submit' });

  // No email in this phase, so there is nothing to send a reset link through. The honest
  // answer is the admin-reset path, stated where the person is stuck.
  const forgot = button({ class: 'link-button', text: 'Forgot?' });
  forgot.addEventListener('click', () => {
    mount(errors, notice(
      'Nothing can be emailed to you in this phase. Ask an owner or admin of your '
      + 'organization to reset your password from the Members screen — they will pass you '
      + 'a one-time password, and you choose your own when you sign in with it.',
      'info',
    ));
  });

  async function attempt() {
    submit.disabled = true;
    clear(errors);

    try {
      const { data } = await api.publicPost('/auth/login', {
        email: email.value.trim(),
        password: password.input.value,
      });

      session.setTokens(data);
      const me = await api.get('/me');
      session.setUser(me.data);
      navigate(data.must_change_password ? '/password' : '/projects', { replace: true });
    } catch (error) {
      const fields = reportError(error);
      if (fields.email || fields.password) {
        mount(errors, notice(fields.email ?? fields.password));
      }
      submit.disabled = false;
    }
  }

  const mobileBrand = brand();
  mobileBrand.classList.add('auth-pane-brand');

  const point = (text) => div({ class: 'auth-point' },
    span({ class: 'auth-point-tick', text: '✓' }), text);

  const bookRow = (label, sub, amount, tone) => div({ class: 'glass-row' },
    div({},
      div({ class: 'glass-label', text: label }),
      div({ class: 'glass-sub', text: sub }),
    ),
    span({ class: `money glass-${tone}`, text: amount }),
  );

  const sideBrand = brand();
  sideBrand.classList.add('rise');

  const scene = (title, ...content) => div({ class: 'auth-scene' },
    h('p', { class: 'auth-side-title' }, ...(Array.isArray(title) ? title : [title])),
    ...content,
  );

  // The default categories take turns in the headline; the last repeats the first so
  // the loop's snap back to the top is invisible.
  const rotator = span({ class: 'word-rotator' },
    span({ class: 'word-rotator-track' },
      ...['labour', 'material', 'transport', 'fuel', 'rent', 'labour']
        .map((word) => span({ class: 'word', text: word })),
    ),
  );

  const side = div({ class: 'auth-side' },
    div({ class: 'aurora' }, span({ class: 'aurora-a' }), span({ class: 'aurora-b' }), span({ class: 'aurora-c' })),
    div({ class: 'auth-glow' }),
    div({ class: 'auth-side-content' },
      sideBrand,
      div({ class: 'auth-scenes' },
        scene('The book writes itself as your site spends.',
          div({ class: 'auth-glass' },
            bookRow('Steel and cement', 'DHA Phase 6 · Material', '− Rs 184,500', 'out'),
            bookRow('Client payment', 'Gulberg Office · Receipt', '+ Rs 1,250,000', 'in'),
            bookRow('Diesel for generator', 'DHA Phase 6 · Fuel', '− Rs 38,200', 'out'),
            div({ class: 'glass-balance' },
              span({ class: 'glass-sub', text: 'Running balance' }),
              span({ class: 'money glass-bal', text: 'Rs 1,027,300' }),
            ),
          ),
        ),
        scene('See where the money goes, month by month.',
          div({ class: 'auth-glass' },
            div({ class: 'glass-chart' },
              ...['in', 'out', 'in', 'out', 'in', 'out', 'in', 'out']
                .map((tone) => div({ class: `glass-bar glass-bar-${tone}` })),
            ),
            div({ class: 'glass-legend' },
              span({}, span({ class: 'glass-swatch glass-bar-in' }), 'Money in'),
              span({}, span({ class: 'glass-swatch glass-bar-out' }), 'Money out'),
            ),
          ),
        ),
        scene(['One book for every project,', h('br'), 'every rupee of ', rotator],
          div({ class: 'auth-side-points' },
            point('Works on the phone you already carry'),
            point('Site staff record, you review'),
            point('PKR only. No conversion, no confusion'),
          ),
        ),
      ),
      div({ class: 'auth-side-foot rise rise-5', text: 'PKR project cashbooks for builders and contractors.' }),
    ),
  );

  // The panel light tracks the pointer. Written through the CSSOM — not an inline style,
  // so the CSP has no objection — and the listener dies with the element.
  side.addEventListener('mousemove', (event) => {
    const box = side.getBoundingClientRect();
    side.style.setProperty('--mx', `${(((event.clientX - box.left) / box.width) * 100).toFixed(1)}%`);
    side.style.setProperty('--my', `${(((event.clientY - box.top) / box.height) * 100).toFixed(1)}%`);
  });

  return div({ class: 'auth-split' },
    side,
    div({ class: 'auth-pane' },
      div({ class: 'auth-pane-form rise rise-2' },
        mobileBrand,
        div({ class: 'form-grid' },
          h('h1', { class: 'auth-title', text: 'Sign in' }),
          h('p', { class: 'auth-sub', text: 'Use the email your organization invited.' }),
        ),
        errors,
        form(attempt,
          field('Email', email),
          h('label', { class: 'field' },
            div({ class: 'field-label-row' },
              span({ class: 'field-label', text: 'Password' }),
              forgot,
            ),
            password,
          ),
          submit,
        ),
        div({ class: 'auth-foot' },
          'No organization yet? ',
          h('a', { href: '/signup', text: 'Create one' }),
        ),
      ),
    ),
  );
}

/**
 * The design's create-organization screen, plus a password field.
 *
 * The screen as drawn collects a name, an email and an organization. With no mail being
 * sent there is nothing that could deliver a password afterwards, so the person sets one
 * here or they could never sign back in.
 */
export function signUp() {
  const orgName = textInput({ required: true, placeholder: 'Rehman Builders (Pvt) Ltd' });
  const name = textInput({ autocomplete: 'name', required: true });
  const email = textInput({ type: 'email', autocomplete: 'username', required: true });
  const password = passwordInput({ autocomplete: 'new-password' });
  const errors = div();
  const submit = button({ class: 'btn btn-primary btn-lg btn-block', text: 'Create organization', type: 'submit' });

  const currency = textInput({ value: 'PKR — Rs', disabled: true });

  async function attempt() {
    submit.disabled = true;
    clear(errors);

    try {
      const { data } = await api.publicPost('/auth/register', {
        name: name.value.trim(),
        email: email.value.trim(),
        password: password.input.value,
        organization_name: orgName.value.trim(),
      });

      session.setTokens(data);
      session.setOrg(data.organization.id);
      const me = await api.get('/me');
      session.setUser(me.data);
      navigate('/projects', { replace: true });
    } catch (error) {
      const fields = reportError(error);
      mount(errors, ...Object.values(fields).map((message) => notice(message)));
      submit.disabled = false;
    }
  }

  return div({ class: 'auth' },
    div({ class: 'auth-card auth-card-wide' },
      brand(),
      div({ class: 'steps' },
        span({ class: 'step-num step-num-active', text: '1' }), 'Organization',
        span({ class: 'muted', text: '›' }),
        span({ class: 'step-num', text: '2' }), 'Invite team',
      ),
      div({ class: 'form-grid' },
        h('h1', { class: 'auth-title', text: 'Create your organization' }),
        h('p', {
          class: 'auth-sub',
          text: 'You will be the Owner. You can invite the rest of your team in the next step.',
        }),
      ),
      errors,
      form(attempt,
        field('Organization name', orgName),
        div({ class: 'field-row' },
          field('Your name', name),
          field('Currency', currency, { hint: 'Fixed' }),
        ),
        field('Work email', email),
        field('Password', password, { hint: 'At least 12 characters.' }),
        notice(
          'Starts with the default categories: Labour, Material, Transport, Fuel, Rent, Misc. '
          + 'You can edit them later.',
          'info',
        ),
        submit,
      ),
      div({ class: 'auth-foot' },
        'Already have an account? ',
        h('a', { href: '/signin', text: 'Sign in' }),
      ),
    ),
  );
}

const ROLE_CAPABILITIES = {
  admin: [
    [true, 'Manage projects, members and categories'],
    [true, 'Add entries and reconcile any entry'],
    [true, 'View every project and report'],
    [false, 'Billing and deleting the organization'],
  ],
  accountant: [
    [true, 'Add entries to any project'],
    [true, 'Correct mistakes with a reconciling entry'],
    [true, 'View every project and report'],
    [false, 'Change project settings or members'],
  ],
  viewer: [
    [true, 'View every project, entry and report'],
    [true, 'Export and print'],
    [false, 'Add or correct entries'],
    [false, 'Change project settings or members'],
  ],
};

/** The invite screen states the org and role before the person commits, so nobody joins blind. */
export function acceptInvite({ params }) {
  const token = params.token;
  const root = div({ class: 'auth' }, div({ class: 'auth-card' }, brand(), div({ class: 'skeleton' })));

  api.publicGet(`/invites/${encodeURIComponent(token)}`)
    .then(({ data }) => mount(root, inviteCard(data, token)))
    .catch(() => mount(root, div({ class: 'auth-card' },
      brand(),
      h('h1', { class: 'auth-title', text: 'This invitation is no longer valid' }),
      h('p', {
        class: 'auth-sub',
        text: 'It may have expired, been withdrawn, or already been used. Ask whoever invited you for a new link.',
      }),
      h('a', { class: 'btn btn-lg btn-block', href: '/signin', text: 'Go to sign in' }),
    )));

  return root;
}

function inviteCard(invite, token) {
  const name = textInput({ autocomplete: 'name', required: true });
  const password = passwordInput({ autocomplete: 'new-password' });
  const errors = div();
  const accept = button({
    class: 'btn btn-primary btn-lg btn-block',
    text: invite.account_exists ? 'Accept invitation' : 'Accept and set password',
    type: 'submit',
  });

  async function attempt() {
    accept.disabled = true;
    clear(errors);

    try {
      const body = invite.account_exists
        ? {}
        : { name: name.value.trim(), password: password.input.value };

      await api.publicPost(`/invites/${encodeURIComponent(token)}/accept`, body);
      navigate('/signin', { replace: true });
    } catch (error) {
      const fields = reportError(error);
      mount(errors, ...Object.values(fields).map((message) => notice(message)));
      accept.disabled = false;
    }
  }

  async function decline() {
    try {
      await api.publicPost(`/invites/${encodeURIComponent(token)}/decline`, {});
    } catch (error) {
      reportError(error);
    }
    navigate('/signin', { replace: true });
  }

  const capabilities = ROLE_CAPABILITIES[invite.role] ?? [];

  return div({ class: 'auth-card' },
    div({ class: 'avatar avatar-round', text: invite.organization.name.slice(0, 2).toUpperCase() }),
    h('h1', { class: 'auth-title' },
      `${invite.invited_by} invited you to`, h('br'), invite.organization.name,
    ),
    span({ class: 'role-pill', text: `Role: ${invite.role}` }),
    card(
      div({ class: 'card-pad form-grid' },
        div({ class: 'col-head', text: `As ${invite.role === 'viewer' ? 'a' : 'an'} ${invite.role} you can` }),
        div({ class: 'capability-list' },
          ...capabilities.map(([allowed, label]) => div({ class: 'capability' },
            span({ class: allowed ? 'capability-yes' : 'capability-no', text: allowed ? '✓' : '✕' }),
            label,
          )),
        ),
      ),
    ),
    errors,
    invite.account_exists
      ? notice('You already have a Ledger account on this address. Accepting adds this organization to it.', 'info')
      : null,
    form(attempt,
      ...(invite.account_exists ? [] : [field('Your name', name), field('Choose a password', password, {
        hint: 'At least 12 characters.',
      })]),
      accept,
      button({ class: 'btn btn-block', text: 'Decline', onClick: decline }),
    ),
    div({ class: 'meta', text: `Invite expires ${formatDate(invite.expires_at)}` }),
  );
}

/** Forced after an admin-provisioned account signs in with its one-time password. */
export function changePassword() {
  const current = passwordInput({ autocomplete: 'current-password' });
  const next = passwordInput({ autocomplete: 'new-password' });
  const errors = div();
  const submit = button({ class: 'btn btn-primary btn-lg btn-block', text: 'Set new password', type: 'submit' });

  async function attempt() {
    submit.disabled = true;
    clear(errors);

    try {
      await api.post('/auth/password', {
        current_password: current.input.value,
        new_password: next.input.value,
      });

      const me = await api.get('/me');
      session.setUser(me.data);
      navigate('/projects', { replace: true });
    } catch (error) {
      const fields = reportError(error);
      mount(errors, ...Object.values(fields).map((message) => notice(message)));
      submit.disabled = false;
    }
  }

  return shell(
    brand(),
    div({ class: 'form-grid' },
      h('h1', { class: 'auth-title', text: 'Choose your own password' }),
      h('p', {
        class: 'auth-sub',
        text: 'You signed in with a one-time password an admin gave you. Replace it with one only you know.',
      }),
    ),
    errors,
    form(attempt,
      field('One-time password', current),
      field('New password', next, { hint: 'At least 12 characters.' }),
      submit,
    ),
  );
}
