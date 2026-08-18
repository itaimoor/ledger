/**
 * Element construction.
 *
 * There is no innerHTML anywhere in this application. Every string that reaches the page
 * goes through document.createTextNode or a property assignment, so a project named
 * `<img onerror=...>` is text and stays text. That is the escaping helper: the absence of
 * a path that would need one.
 */

/**
 * h('div', { class: 'card' }, 'text', childNode)
 *
 * Props: `class`, `text`, `html` is deliberately not supported, `on*` become listeners,
 * `data*`/`aria*` become attributes, anything else is set as an attribute unless it is a
 * known DOM property.
 */
export function h(tag, props = {}, ...children) {
  const node = document.createElement(tag);

  for (const [key, value] of Object.entries(props)) {
    if (value === null || value === undefined || value === false) continue;

    if (key === 'style') {
      // A parsed style attribute is blocked by the CSP. Assign through node.style.* after
      // construction instead — CSSOM writes are not inline styles and are permitted.
      throw new Error('Set styles through node.style.*, not a style attribute.');
    }

    if (key.startsWith('on') && typeof value === 'function') {
      node.addEventListener(key.slice(2).toLowerCase(), value);
    } else if (key === 'class') {
      node.className = value;
    } else if (key === 'text') {
      node.textContent = String(value);
    } else if (key === 'value' || key === 'checked' || key === 'disabled') {
      node[key] = value;
    } else {
      node.setAttribute(key, value === true ? '' : String(value));
    }
  }

  append(node, children);
  return node;
}

export function append(parent, children) {
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    parent.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return parent;
}

export function clear(node) {
  node.replaceChildren();
  return node;
}

export function mount(node, ...children) {
  clear(node);
  return append(node, children);
}

/** Shorthand for the tags used often enough that h('div', …) becomes noise. */
export const div = (props, ...kids) => h('div', props, ...kids);
export const span = (props, ...kids) => h('span', props, ...kids);
export const button = (props, ...kids) => h('button', { type: 'button', ...props }, ...kids);

export function fragment(...children) {
  return append(document.createDocumentFragment(), children);
}
