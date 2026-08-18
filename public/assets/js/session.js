/**
 * Who is signed in, and which organization they are looking at.
 *
 * Deliberately small and explicit: four fields, a change event, and no framework. Views
 * read what they need and re-render; nothing observes anything implicitly.
 *
 * Tokens live in localStorage. That is readable by script, so it is only defensible
 * because the CSP forbids inline script and every string reaching the DOM goes through a
 * text node — there is no XSS foothold to read them from. The alternative, an
 * HttpOnly cookie, would not work for the mobile client the API is also built for.
 */

const STORAGE_KEY = 'ledger.session';

function load() {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null') ?? {};
  } catch {
    return {};
  }
}

class Session extends EventTarget {
  #state = load();

  get accessToken() { return this.#state.accessToken ?? null; }
  get refreshToken() { return this.#state.refreshToken ?? null; }
  get user() { return this.#state.user ?? null; }
  get orgId() { return this.#state.orgId ?? null; }
  get isSignedIn() { return Boolean(this.#state.refreshToken); }

  setTokens({ access_token, refresh_token }) {
    this.#patch({ accessToken: access_token, refreshToken: refresh_token });
  }

  setUser(user) {
    this.#patch({ user });
  }

  setOrg(orgId) {
    if (this.#state.orgId !== orgId) this.#patch({ orgId });
  }

  clear() {
    this.#state = {};
    localStorage.removeItem(STORAGE_KEY);
    this.dispatchEvent(new CustomEvent('change'));
  }

  #patch(changes) {
    this.#state = { ...this.#state, ...changes };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(this.#state));
    this.dispatchEvent(new CustomEvent('change'));
  }
}

export const session = new Session();
