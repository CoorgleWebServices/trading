/*
 * 9Bar DJ Desk - dj/assets/dj.js          (DESIGN-DJ.md 6 and 8)
 *
 * The desk works completely without JavaScript: every state change is a POST
 * round trip carrying a CSRF token, and every navigation is a plain link. This
 * file therefore adds nothing of consequence - only the two conveniences the
 * design allows:
 *
 *   1. data-confirm="..."  a confirm() dialog before a destructive submit.
 *                          Declining cancels the submit; the server sees nothing.
 *   2. data-copy="domId"   copy the contents of a read-only textarea. The
 *                          textarea is selectable by hand, so the button is a
 *                          shortcut and never the only way through.
 *
 * There is no inline <script> and no onclick= anywhere in the desk: the
 * Content-Security-Policy of 8 forbids both, and this file is loaded with
 * `defer` from a same-origin <script src>. Nothing here talks to the network,
 * reads cookies, or touches anything but the DOM of the current page.
 */
(function () {
  'use strict';

  /* ------------------------------------------------------------- confirm */

  /** The confirm text carried by an element, or '' when it carries none. */
  function confirmTextOf(el) {
    if (!el || !el.getAttribute) { return ''; }
    var t = el.getAttribute('data-confirm');
    return typeof t === 'string' ? t.trim() : '';
  }

  /**
   * The element that actually triggered this submit: the button the operator
   * pressed where the browser reports it, otherwise the form's own attribute,
   * otherwise its single data-confirm button (a form built by
   * Render::actionForm() has exactly one).
   */
  function submitterOf(form, event) {
    if (event && event.submitter) { return event.submitter; }
    if (confirmTextOf(form) !== '') { return form; }
    var marked = form.querySelectorAll('[data-confirm]');
    return marked.length === 1 ? marked[0] : null;
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || form.nodeName !== 'FORM') { return; }
    var text = confirmTextOf(submitterOf(form, event));
    if (text !== '' && !window.confirm(text)) {
      event.preventDefault();
    }
  }, false);

  /* A link or a plain button (one that submits nothing) can ask too. */
  document.addEventListener('click', function (event) {
    var el = event.target && event.target.closest ? event.target.closest('[data-confirm]') : null;
    if (!el) { return; }
    if (el.nodeName === 'BUTTON' && (el.type || 'submit').toLowerCase() === 'submit') {
      return; // handled on submit, so the dialog is not asked twice
    }
    if (el.nodeName !== 'A' && el.nodeName !== 'BUTTON') { return; }
    var text = confirmTextOf(el);
    if (text !== '' && !window.confirm(text)) {
      event.preventDefault();
    }
  }, false);

  /* ---------------------------------------------------------------- copy */

  function flash(button, message) {
    var original = button.getAttribute('data-label') || button.textContent;
    button.setAttribute('data-label', original);
    button.textContent = message;
    window.setTimeout(function () { button.textContent = original; }, 1400);
  }

  document.addEventListener('click', function (event) {
    var button = event.target && event.target.closest ? event.target.closest('[data-copy]') : null;
    if (!button) { return; }
    var field = document.getElementById(button.getAttribute('data-copy'));
    if (!field) { return; }
    event.preventDefault();
    field.focus();
    field.select();
    var done = false;
    try {
      done = document.execCommand('copy');
    } catch (e) {
      done = false;
    }
    if (done) {
      flash(button, 'Copied');
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(field.value).then(
        function () { flash(button, 'Copied'); },
        function () { flash(button, 'Select and copy'); }
      );
      return;
    }
    flash(button, 'Select and copy');
  }, false);
}());
