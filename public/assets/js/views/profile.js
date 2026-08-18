/** The signed-in person's own account: name and password. Email is the sign-in identity. */

import { api } from '../api.js';
import { session } from '../session.js';
import {
  button, card, clear, div, field, h, mount, notice, passwordInput, reportError,
  textInput, toast,
} from '../ui.js';

export function profileView() {
  const name = textInput({ value: session.user?.name ?? '', autocomplete: 'name', required: true });
  const email = textInput({ value: session.user?.email ?? '', disabled: true });
  const nameErrors = div();

  const current = passwordInput({ autocomplete: 'current-password' });
  const next = passwordInput({ autocomplete: 'new-password' });
  const passwordErrors = div();

  async function saveName() {
    clear(nameErrors);

    try {
      const { data } = await api.patch('/me', { name: name.value.trim() });
      session.setUser(data);
      toast('Name updated', 'ok');
      // Repaint the current route so the header shows the new name immediately.
      window.dispatchEvent(new CustomEvent('routechange'));
    } catch (error) {
      const fields = reportError(error);
      mount(nameErrors, ...Object.values(fields).map((message) => notice(message)));
    }
  }

  async function changePassword() {
    clear(passwordErrors);

    try {
      await api.post('/auth/password', {
        current_password: current.input.value,
        new_password: next.input.value,
      });

      current.input.value = '';
      next.input.value = '';
      toast('Password changed', 'ok');
    } catch (error) {
      const fields = reportError(error);
      mount(passwordErrors, ...Object.values(fields).map((message) => notice(message)));
    }
  }

  return div({ class: 'page' },
    div({ class: 'page-head' },
      div({ class: 'page-head-text' },
        h('h1', { class: 'page-title', text: 'Your profile' }),
        div({ class: 'meta', text: 'How you appear in every organization you belong to.' }),
      ),
    ),
    card(div({ class: 'card-pad form-grid' },
      nameErrors,
      field('Your name', name),
      field('Email', email, { hint: 'Your sign-in email cannot be changed in this phase.' }),
      div({ class: 'page-actions' },
        button({ class: 'btn btn-primary', text: 'Save changes', onClick: saveName }),
      ),
    )),
    card(
      div({ class: 'card-head' }, div({ class: 'card-title', text: 'Change password' })),
      div({ class: 'card-pad form-grid' },
        passwordErrors,
        field('Current password', current),
        field('New password', next, { hint: 'At least 12 characters.' }),
        div({ class: 'page-actions' },
          button({ class: 'btn btn-primary', text: 'Change password', onClick: changePassword }),
        ),
      ),
    ),
  );
}
