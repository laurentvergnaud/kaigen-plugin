/**
 * The measurement goals (plugin 1.6.0, ADR 0104): the capture rules the client validated in
 * Tamiro, posed on the page. Each rule sends one named event to Umami through the tracker the
 * plugin serves (`umami.track`); nothing of a form's content is read, only the fact it was sent.
 * On load the script also counts, for each rule, the elements of the page that answer it, and
 * tells the plugin once per rules version and browser session, so that Tamiro can say which
 * rules found nothing on the site.
 *
 * Configuration, printed before this file: window.kaigenGoals = { goals: [...], version, endpoint }.
 */
(function () {
  var config = window.kaigenGoals;
  if (!config || !Array.isArray(config.goals) || config.goals.length === 0) return;

  function track(name) {
    if (window.umami && typeof window.umami.track === 'function') {
      try { window.umami.track(name); } catch (error) { /* the tracker is Umami's: nothing to do */ }
    }
  }

  // A pattern is a path, or a path prefix ending with `*`; compared without the trailing slash.
  function trim(value) { return String(value || '').replace(/\/+$/, '') || '/'; }
  function matchesPattern(value, pattern) {
    if (!pattern) return false;
    var subject = trim(value);
    var wanted = String(pattern);
    if (wanted.slice(-1) === '*') return subject.indexOf(trim(wanted.slice(0, -1))) === 0 || (wanted.slice(0, -1) === '/' );
    return subject === trim(wanted);
  }
  function normalize(text) {
    return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();
  }
  function toArray(list) { return Array.prototype.slice.call(list || []); }
  function query(selector) { try { return toArray(document.querySelectorAll(selector)); } catch (error) { return []; } }

  var path = window.location.pathname;
  var clickables = null;
  function clickableElements() {
    if (clickables === null) clickables = query('a[href], button, input[type="submit"], input[type="button"], [role="button"]');
    return clickables;
  }

  // The elements a click rule answers to: the text as written, the link address, or a selector.
  function clickTargets(rule) {
    if (rule.selector) return query(rule.selector);
    if (rule.text) {
      var wanted = normalize(rule.text);
      return clickableElements().filter(function (element) {
        var text = element.tagName === 'INPUT' ? element.value : element.textContent;
        return normalize(text) === wanted || normalize(element.getAttribute('aria-label')) === wanted;
      });
    }
    if (rule.hrefPattern) {
      var pattern = String(rule.hrefPattern);
      return clickableElements().filter(function (element) {
        var href = element.getAttribute('href');
        if (!href) return false;
        if (pattern.slice(-1) === '*') return href.indexOf(pattern.slice(0, -1)) === 0;
        return href === pattern || matchesPattern(href, pattern);
      });
    }
    return [];
  }

  function formTargets(rule) {
    if (rule.formSelector) return query(rule.formSelector);
    if (rule.urlPattern && matchesPattern(path, rule.urlPattern)) return query('form');
    return [];
  }

  var matches = [];
  var sent = {};
  function once(name) { if (sent[name]) return; sent[name] = true; track(name); }

  config.goals.forEach(function (goal) {
    var rule = goal.rule || {};
    if (goal.kind === 'page_reached') {
      var reached = matchesPattern(path, rule.urlPattern);
      matches.push({ name: goal.name, count: reached ? 1 : 0 });
      if (reached) once(goal.name);
      return;
    }
    if (goal.kind === 'click') {
      var targets = clickTargets(rule);
      matches.push({ name: goal.name, count: targets.length });
      targets.forEach(function (element) { element.addEventListener('click', function () { track(goal.name); }, { passive: true }); });
      return;
    }
    if (goal.kind === 'form_submit') {
      var forms = formTargets(rule);
      matches.push({ name: goal.name, count: forms.length });
      forms.forEach(function (form) {
        form.setAttribute('data-kaigen-goal', goal.name);
        form.addEventListener('submit', function () { once(goal.name); }, { passive: true });
      });
    }
  });

  // The form plugins that send in Ajax say their success themselves: the form stays, the event counts once.
  function formGoal(target) {
    var form = target && target.closest ? target.closest('form[data-kaigen-goal]') : null;
    return form ? form.getAttribute('data-kaigen-goal') : null;
  }
  ['wpcf7mailsent', 'wpformsAjaxSubmitSuccess', 'gform_confirmation_loaded', 'nfFormSubmitResponse', 'elementor/forms/submit_success'].forEach(function (eventName) {
    document.addEventListener(eventName, function (event) { var name = formGoal(event.target); if (name) once(name); }, false);
  });
  if (window.jQuery) {
    try {
      window.jQuery(document).on('gform_confirmation_loaded nfFormSubmitResponse', function (event) { var name = formGoal(event.target); if (name) once(name); });
    } catch (error) { /* without jQuery the native listeners stand */ }
  }

  // What the page holds for each rule, told to the plugin once per rules version and session.
  if (config.endpoint && matches.length > 0) {
    var key = 'kaigen_goals_reported_' + (config.version || '');
    var reported = false;
    try { reported = window.sessionStorage.getItem(key) === '1'; } catch (error) { reported = false; }
    if (!reported) {
      try {
        var body = JSON.stringify({ matches: matches });
        if (navigator.sendBeacon) navigator.sendBeacon(config.endpoint, new Blob([body], { type: 'application/json' }));
        else fetch(config.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'same-origin' });
        window.sessionStorage.setItem(key, '1');
      } catch (error) { /* a report lost is told again next session */ }
    }
  }
})();
