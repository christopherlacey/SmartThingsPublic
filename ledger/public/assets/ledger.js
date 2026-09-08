/* Interaction for The Lacey Ledger. */

(function () {
  "use strict";

  var EYE = '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">'
    + '<path d="M1 8s2.5-4.5 7-4.5S15 8 15 8s-2.5 4.5-7 4.5S1 8 1 8Z" stroke="currentColor" stroke-width="1.3"/>'
    + '<circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.3"/></svg>';

  var EYE_OFF = '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">'
    + '<path d="M1 8s2.5-4.5 7-4.5S15 8 15 8s-2.5 4.5-7 4.5S1 8 1 8Z" stroke="currentColor" stroke-width="1.3"/>'
    + '<circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.3"/>'
    + '<path d="M2.5 13.5 13.5 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>';

  /* ------------------------------------------------------ password reveal -- */

  /* The password box starts masked. The control beside it swaps the input type
   * so the value can be checked before signing in — useful on a phone keyboard,
   * and the only way to catch a password manager filling the wrong entry.
   *
   * It is a button, not a checkbox, so it never submits with the form, and its
   * state is announced rather than left to the icon alone. */
  function setupReveal(input) {
    var wrap = document.createElement("div");
    wrap.className = "password-wrap";
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var button = document.createElement("button");
    button.type = "button";
    button.className = "reveal";
    button.setAttribute("aria-pressed", "false");
    button.setAttribute("aria-controls", input.id || "");
    button.title = "Show the password";
    button.innerHTML = EYE + "<span>Show</span>";
    wrap.appendChild(button);

    var status = document.createElement("span");
    status.className = "sr-only";
    status.setAttribute("role", "status");
    wrap.appendChild(status);

    button.addEventListener("click", function () {
      var showing = input.type === "text";

      input.type = showing ? "password" : "text";
      button.setAttribute("aria-pressed", String(!showing));
      button.title = showing ? "Show the password" : "Hide the password";
      button.innerHTML = (showing ? EYE : EYE_OFF)
        + "<span>" + (showing ? "Show" : "Hide") + "</span>";
      status.textContent = showing ? "Password hidden." : "Password showing.";

      // Keep the caret where it was, so revealing mid-typing is not disruptive.
      var at = input.value.length;
      input.focus();
      try { input.setSelectionRange(at, at); } catch (e) { /* type change can reset it */ }
    });

    // Never leave a password on screen after the form is submitted or the tab
    // is left in the background.
    function remask() {
      if (input.type === "text") button.click();
    }
    if (input.form) input.form.addEventListener("submit", remask);
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "hidden") remask();
    });
  }

  /* ------------------------------------------------- sensitive field reveal */

  /* Same idea for record fields flagged sensitive — a policy number or an
   * account number stays masked until it is actually needed, so the page can be
   * open on a screen someone else can see. */
  function setupSensitive(node) {
    var real = node.getAttribute("data-value") || "";
    var shown = false;

    var value = document.createElement("span");
    value.textContent = "••••••••";
    node.appendChild(value);

    var button = document.createElement("button");
    button.type = "button";
    button.className = "table-toggle";
    button.style.marginLeft = "10px";
    button.textContent = "Reveal";
    button.setAttribute("aria-pressed", "false");
    node.appendChild(button);

    button.addEventListener("click", function () {
      shown = !shown;
      value.textContent = shown ? real : "••••••••";
      button.textContent = shown ? "Hide" : "Reveal";
      button.setAttribute("aria-pressed", String(shown));
    });
  }

  /* ------------------------------------------------------------- errands -- */

  /* Ticking something off posts in the background so the list does not jump
   * back to the top mid-aisle. The row is struck through on success and left
   * alone on failure, so the page never claims a write that did not land. */
  function setupShopping() {
    document.querySelectorAll("form[data-item]").forEach(function (form) {
      form.addEventListener("submit", function (ev) {
        if (!window.fetch) return; // let it post normally

        ev.preventDefault();
        var row = form.closest("[data-row]") || form;
        var button = form.querySelector("button");
        if (button) button.disabled = true;

        fetch(form.action, {
          method: "POST",
          body: new FormData(form),
          credentials: "same-origin",
          headers: { "X-Requested-With": "fetch" }
        }).then(function (res) {
          if (!res.ok) throw new Error("write failed");
          row.style.opacity = "0.45";
          row.style.textDecoration = "line-through";
          if (button) button.textContent = "Got it";
        }).catch(function () {
          if (button) {
            button.disabled = false;
            button.textContent = "Try again";
          }
        });
      });
    });
  }

  /* ---------------------------------------------------------------- init -- */

  document.querySelectorAll('input[type="password"][data-reveal]').forEach(setupReveal);
  document.querySelectorAll("[data-sensitive]").forEach(setupSensitive);
  setupShopping();
})();
