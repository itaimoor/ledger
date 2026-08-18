/**
 * History-API routing.
 *
 * Real paths rather than hashes, because an invite link is /join/{token} and has to be
 * something an admin can paste into WhatsApp. The front controller serves the shell for
 * every non-API path, so a refresh on any route works.
 */

const routes = [];

export function route(pattern, view) {
  const names = [];
  const regex = new RegExp(
    '^' + pattern.replace(/:(\w+)/g, (_, name) => {
      names.push(name);
      return '([^/]+)';
    }) + '/?$',
  );

  routes.push({ regex, names, view });
}

export function navigate(path, { replace = false } = {}) {
  if (path === currentPath()) return;
  window.history[replace ? 'replaceState' : 'pushState']({}, '', path);
  window.dispatchEvent(new CustomEvent('routechange'));
}

export function currentPath() {
  return window.location.pathname.replace(/\/+$/, '') || '/';
}

export function resolve(path = currentPath()) {
  for (const { regex, names, view } of routes) {
    const match = regex.exec(path);
    if (!match) continue;

    const params = {};
    names.forEach((name, index) => { params[name] = decodeURIComponent(match[index + 1]); });
    return { view, params };
  }

  return null;
}

/**
 * Any anchor with a same-origin href is handled in-app, so views can write ordinary
 * links. Modified clicks and anything marked data-external fall through to the browser.
 */
export function interceptLinks() {
  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey
      || event.shiftKey || event.altKey) return;

    const anchor = event.target.closest?.('a[href]');
    if (!anchor || anchor.hasAttribute('data-external') || anchor.target === '_blank') return;

    const url = new URL(anchor.href, window.location.origin);
    if (url.origin !== window.location.origin) return;

    event.preventDefault();
    navigate(url.pathname + url.search);
  });

  window.addEventListener('popstate', () => {
    window.dispatchEvent(new CustomEvent('routechange'));
  });
}
