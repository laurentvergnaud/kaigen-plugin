(function () {
  'use strict';

  if (typeof window === 'undefined' || !window.kaigenTrackerConfig) {
    return;
  }

  var config = window.kaigenTrackerConfig;
  var storage = null;

  try {
    storage = window.localStorage;
  } catch (e) {
    storage = null;
  }

  if (!config.endpoint || !config.projectId || !config.token || !config.tokenDay) {
    return;
  }

  var VISITOR_KEY = 'kaigen_visitor_id';
  var VISITOR_EXP_KEY = 'kaigen_visitor_exp';
  var SESSION_KEY = 'kaigen_session_id';
  var SESSION_LAST_KEY = 'kaigen_session_last_seen';
  var QUEUE_KEY = 'kaigen_track_queue';
  var COOKIE_VISITOR_ID = 'kaigen_visitor_id';
  var COOKIE_SESSION_ID = 'kaigen_session_id';
  var COOKIE_UTM_SOURCE = 'kaigen_utm_source';
  var COOKIE_UTM_MEDIUM = 'kaigen_utm_medium';
  var COOKIE_UTM_CAMPAIGN = 'kaigen_utm_campaign';
  var COOKIE_REFERRER = 'kaigen_referrer';
  var COOKIE_SOURCE_AUTOMATION_ID = 'kaigen_source_automation_id';
  var COOKIE_SOURCE_AUTOMATION_ITEM_ID = 'kaigen_source_automation_item_id';
  var COOKIE_SOURCE_TERM_ID = 'kaigen_source_term_id';
  var COOKIE_SOURCE_BATCH_ID = 'kaigen_source_batch_id';
  var COOKIE_SOURCE_POST_ID = 'kaigen_source_post_id';
  var COOKIE_SOURCE_POST_TYPE = 'kaigen_source_post_type';
  var COOKIE_SOURCE_POST_SLUG = 'kaigen_source_post_slug';
  var TRACK_PAYLOAD_VERSION = 'wp_tracker_v1';
  var EMAIL_REGEX = /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i;
  var PHONE_REGEX = /(?:\+?\d[\d().\s-]{6,}\d)/;

  function nowMs() {
    return Date.now();
  }

  function safeJsonParse(value, fallback) {
    if (!value) {
      return fallback;
    }

    try {
      return JSON.parse(value);
    } catch (e) {
      return fallback;
    }
  }

  function randomId(prefix) {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return prefix + '_' + window.crypto.randomUUID().replace(/-/g, '');
    }

    return prefix + '_' + Math.random().toString(36).slice(2) + String(nowMs());
  }

  function getCookie(name) {
    if (!name || typeof document === 'undefined') {
      return null;
    }

    var escaped = name.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&');
    var match = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
    if (!match || !match[1]) {
      return null;
    }

    try {
      return decodeURIComponent(match[1]);
    } catch (e) {
      return match[1];
    }
  }

  function setCookie(name, value, maxAgeSeconds) {
    if (!name || typeof document === 'undefined') {
      return;
    }

    if (value === null || value === undefined || value === '') {
      document.cookie = name + '=; path=/; max-age=0; SameSite=Lax';
      return;
    }

    var cookie = name + '=' + encodeURIComponent(String(value)) + '; path=/; SameSite=Lax';
    if (window.location && window.location.protocol === 'https:') {
      cookie += '; Secure';
    }
    if (typeof maxAgeSeconds === 'number' && maxAgeSeconds > 0) {
      cookie += '; max-age=' + String(Math.floor(maxAgeSeconds));
    }

    document.cookie = cookie;
  }

  function keepCookieValue(name, value, maxAgeSeconds, maxLen) {
    if (!value || typeof value !== 'string') {
      return;
    }

    var cleaned = value.trim();
    if (!cleaned) {
      return;
    }

    setCookie(name, cleaned.slice(0, maxLen || 255), maxAgeSeconds);
  }

  function getVisitorId() {
    var visitorTtlDays = Number(config.visitorTtlDays || 395);
    var visitorTtlSeconds = Math.max(60, Math.floor(visitorTtlDays * 24 * 60 * 60));
    var cookieVisitor = getCookie(COOKIE_VISITOR_ID);

    if (!storage) {
      if (cookieVisitor) {
        setCookie(COOKIE_VISITOR_ID, cookieVisitor, visitorTtlSeconds);
        return cookieVisitor;
      }

      var transientId = randomId('v');
      setCookie(COOKIE_VISITOR_ID, transientId, visitorTtlSeconds);
      return transientId;
    }

    var existing = storage.getItem(VISITOR_KEY);
    var expiresAtRaw = storage.getItem(VISITOR_EXP_KEY);
    var expiresAt = expiresAtRaw ? parseInt(expiresAtRaw, 10) : 0;

    if (existing && expiresAt && expiresAt > nowMs()) {
      setCookie(COOKIE_VISITOR_ID, existing, visitorTtlSeconds);
      return existing;
    }

    if (cookieVisitor) {
      var cookieExpiresAt = nowMs() + visitorTtlSeconds * 1000;
      storage.setItem(VISITOR_KEY, cookieVisitor);
      storage.setItem(VISITOR_EXP_KEY, String(cookieExpiresAt));
      setCookie(COOKIE_VISITOR_ID, cookieVisitor, visitorTtlSeconds);
      return cookieVisitor;
    }

    var id = randomId('v');
    var nextExpiresAt = nowMs() + visitorTtlSeconds * 1000;

    storage.setItem(VISITOR_KEY, id);
    storage.setItem(VISITOR_EXP_KEY, String(nextExpiresAt));
    setCookie(COOKIE_VISITOR_ID, id, visitorTtlSeconds);

    return id;
  }

  function getSessionId() {
    var ttlMs = Number(config.sessionTtlMs || 1800000);
    var sessionTtlSeconds = Math.max(60, Math.floor(ttlMs / 1000));
    var cookieSession = getCookie(COOKIE_SESSION_ID);

    if (!storage) {
      if (cookieSession) {
        setCookie(COOKIE_SESSION_ID, cookieSession, sessionTtlSeconds);
        return cookieSession;
      }

      var transientId = randomId('s');
      setCookie(COOKIE_SESSION_ID, transientId, sessionTtlSeconds);
      return transientId;
    }

    var existing = storage.getItem(SESSION_KEY);
    var lastSeenRaw = storage.getItem(SESSION_LAST_KEY);
    var lastSeen = lastSeenRaw ? parseInt(lastSeenRaw, 10) : 0;

    if (existing && lastSeen && nowMs() - lastSeen < ttlMs) {
      storage.setItem(SESSION_LAST_KEY, String(nowMs()));
      setCookie(COOKIE_SESSION_ID, existing, sessionTtlSeconds);
      return existing;
    }

    if (cookieSession) {
      storage.setItem(SESSION_KEY, cookieSession);
      storage.setItem(SESSION_LAST_KEY, String(nowMs()));
      setCookie(COOKIE_SESSION_ID, cookieSession, sessionTtlSeconds);
      return cookieSession;
    }

    var id = randomId('s');
    storage.setItem(SESSION_KEY, id);
    storage.setItem(SESSION_LAST_KEY, String(nowMs()));
    setCookie(COOKIE_SESSION_ID, id, sessionTtlSeconds);
    return id;
  }

  function getQueue() {
    if (!storage) {
      return [];
    }

    return safeJsonParse(storage.getItem(QUEUE_KEY), []);
  }

  function setQueue(queue) {
    if (!storage) {
      return;
    }

    storage.setItem(QUEUE_KEY, JSON.stringify(queue.slice(0, 30)));
  }

  function enqueue(eventPayload) {
    var queue = getQueue();
    queue.unshift(eventPayload);
    setQueue(queue);
  }

  function dequeue(eventId) {
    var queue = getQueue();
    var filtered = [];

    for (var i = 0; i < queue.length; i += 1) {
      if (queue[i] && queue[i].event_id !== eventId) {
        filtered.push(queue[i]);
      }
    }

    setQueue(filtered);
  }

  function sanitizeUrl(raw) {
    try {
      var parsed = new URL(raw);
      parsed.hash = '';
      return parsed.toString();
    } catch (e) {
      return null;
    }
  }

  function normalizeId(raw, maxLen) {
    if (!raw || typeof raw !== 'string') {
      return null;
    }

    var cleaned = raw.trim();
    if (!cleaned) {
      return null;
    }

    return cleaned.slice(0, maxLen || 128);
  }

  function pickParam(params, names) {
    if (!params || !names || !names.length) {
      return null;
    }

    for (var i = 0; i < names.length; i += 1) {
      var value = params.get(names[i]);
      if (value && typeof value === 'string' && value.trim()) {
        return value.trim();
      }
    }

    return null;
  }

  function getSourceHints(currentUrl) {
    var params = currentUrl ? currentUrl.searchParams : null;
    var ctx = (config && config.wpContext && typeof config.wpContext === 'object') ? config.wpContext : {};

    var automationId = normalizeId(
      pickParam(params, ['kaigen_automation_id', 'automation_id', 'aid']) || (ctx.automationId || ''),
      64
    );
    var automationItemId = normalizeId(
      pickParam(params, ['kaigen_automation_item_id', 'automation_item_id', 'item_id', 'aiid']) || (ctx.automationItemId || ''),
      64
    );
    var termId = normalizeId(
      pickParam(params, ['kaigen_term_id', 'term_id', 'tid']) || (ctx.termId || ''),
      64
    );
    var batchId = normalizeId(
      pickParam(params, ['kaigen_batch_id', 'batch_id', 'bid']) || (ctx.batchId || ''),
      64
    );

    var postId = normalizeId(String(ctx.postId || ''), 100);
    var postType = normalizeId(String(ctx.postType || ''), 64);
    var postSlug = normalizeId(String(ctx.postSlug || ''), 128);

    return {
      source_automation_id: automationId,
      source_automation_item_id: automationItemId,
      source_term_id: termId,
      source_batch_id: batchId,
      source_post_id: postId,
      source_post_type: postType,
      source_post_slug: postSlug
    };
  }

  function getBasePayload(eventType) {
    var current = sanitizeUrl(window.location.href);
    if (!current) {
      return null;
    }

    var currentUrl = new URL(current);
    var params = currentUrl.searchParams;
    var sourceHints = getSourceHints(currentUrl);
    var visitorId = getVisitorId();
    var sessionId = getSessionId();
    var referrer = sanitizeUrl(document.referrer || '');
    var utmSource = params.get('utm_source');
    var utmMedium = params.get('utm_medium');
    var utmCampaign = params.get('utm_campaign');

    keepCookieValue(COOKIE_UTM_SOURCE, utmSource, 30 * 24 * 60 * 60, 255);
    keepCookieValue(COOKIE_UTM_MEDIUM, utmMedium, 30 * 24 * 60 * 60, 255);
    keepCookieValue(COOKIE_UTM_CAMPAIGN, utmCampaign, 30 * 24 * 60 * 60, 255);
    keepCookieValue(COOKIE_REFERRER, referrer, 7 * 24 * 60 * 60, 1024);
    keepCookieValue(COOKIE_SOURCE_AUTOMATION_ID, sourceHints.source_automation_id, 7 * 24 * 60 * 60, 128);
    keepCookieValue(COOKIE_SOURCE_AUTOMATION_ITEM_ID, sourceHints.source_automation_item_id, 7 * 24 * 60 * 60, 128);
    keepCookieValue(COOKIE_SOURCE_TERM_ID, sourceHints.source_term_id, 7 * 24 * 60 * 60, 128);
    keepCookieValue(COOKIE_SOURCE_BATCH_ID, sourceHints.source_batch_id, 7 * 24 * 60 * 60, 128);
    keepCookieValue(COOKIE_SOURCE_POST_ID, sourceHints.source_post_id, 30 * 24 * 60 * 60, 128);
    keepCookieValue(COOKIE_SOURCE_POST_TYPE, sourceHints.source_post_type, 30 * 24 * 60 * 60, 64);
    keepCookieValue(COOKIE_SOURCE_POST_SLUG, sourceHints.source_post_slug, 30 * 24 * 60 * 60, 128);

    return {
      event_id: randomId('evt'),
      event_type: eventType || 'pageview',
      payload_version: TRACK_PAYLOAD_VERSION,
      page_url: current,
      happened_at: new Date().toISOString(),
      visitor_id: visitorId,
      session_id: sessionId,
      referrer: referrer,
      utm_source: utmSource,
      utm_medium: utmMedium,
      utm_campaign: utmCampaign,
      title: document.title || null,
      language: document.documentElement ? document.documentElement.lang : null,
      source_automation_id: sourceHints.source_automation_id,
      source_automation_item_id: sourceHints.source_automation_item_id,
      source_term_id: sourceHints.source_term_id,
      source_batch_id: sourceHints.source_batch_id,
      source_post_id: sourceHints.source_post_id,
      source_post_type: sourceHints.source_post_type,
      source_post_slug: sourceHints.source_post_slug,
      token_day: config.tokenDay,
      token: config.token
    };
  }

  function normalizePhone(raw) {
    if (!raw || typeof raw !== 'string') {
      return null;
    }

    var cleaned = raw.replace(/[^\d+]/g, '');
    var digits = cleaned.replace(/[^\d]/g, '');
    if (digits.length < 8 || digits.length > 16) {
      return null;
    }

    return cleaned.slice(0, 32);
  }

  function normalizeName(raw) {
    if (!raw || typeof raw !== 'string') {
      return null;
    }

    var cleaned = raw.replace(/[^\p{L}\s'-]/gu, ' ').replace(/\s+/g, ' ').trim();
    if (!cleaned) {
      return null;
    }

    return cleaned.slice(0, 120);
  }

  function detectContactDetails(form) {
    var contact = {
      email: null,
      phone: null,
      first_name: null,
      last_name: null
    };

    if (!form || typeof FormData === 'undefined') {
      return contact;
    }

    var formData = new FormData(form);
    formData.forEach(function (value, key) {
      if (typeof value !== 'string') {
        return;
      }

      var candidate = value.trim();
      if (!candidate) {
        return;
      }

      if (!contact.email) {
        var emailMatch = candidate.match(EMAIL_REGEX);
        if (emailMatch && emailMatch[0]) {
          contact.email = emailMatch[0].slice(0, 255);
        }
      }

      if (!contact.phone) {
        var phoneMatch = candidate.match(PHONE_REGEX);
        if (phoneMatch && phoneMatch[0]) {
          contact.phone = normalizePhone(phoneMatch[0]);
        }
      }

      var normalizedKey = String(key || '').toLowerCase();
      if (!contact.first_name && (normalizedKey.indexOf('prenom') !== -1 || normalizedKey.indexOf('first_name') !== -1 || normalizedKey.indexOf('firstname') !== -1 || normalizedKey.indexOf('given_name') !== -1)) {
        contact.first_name = normalizeName(candidate);
      }

      if (!contact.last_name && (normalizedKey.indexOf('nom') !== -1 || normalizedKey.indexOf('last_name') !== -1 || normalizedKey.indexOf('lastname') !== -1 || normalizedKey.indexOf('family_name') !== -1 || normalizedKey.indexOf('surname') !== -1)) {
        contact.last_name = normalizeName(candidate);
      }
    });

    return contact;
  }

  function buildPageviewPayload() {
    return getBasePayload('pageview');
  }

  function buildFormSubmitPayload(form) {
    var basePayload = getBasePayload('form_submit');
    if (!basePayload) {
      return null;
    }

    var contact = detectContactDetails(form);
    var actionUrl = sanitizeUrl((form && form.getAttribute && form.getAttribute('action')) || '') || basePayload.page_url;

    basePayload.form_action = actionUrl;
    basePayload.form_method = form && form.method ? String(form.method).toUpperCase().slice(0, 16) : 'GET';
    basePayload.form_id = form && form.id ? String(form.id).slice(0, 128) : null;
    basePayload.form_name = form && form.name ? String(form.name).slice(0, 128) : null;
    basePayload.contact_email = contact.email;
    basePayload.contact_phone = contact.phone;
    basePayload.contact_first_name = contact.first_name;
    basePayload.contact_last_name = contact.last_name;

    return basePayload;
  }

  function send(payload, options) {
    if (!payload) {
      return Promise.resolve(false);
    }

    var preferBeacon = options && options.preferBeacon === true;
    if ((preferBeacon || typeof fetch !== 'function') && navigator.sendBeacon) {
      try {
        var blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
        var queued = navigator.sendBeacon(config.endpoint, blob);
        return queued ? Promise.resolve(true) : Promise.reject(new Error('beacon_failed'));
      } catch (e) {
        return Promise.reject(e);
      }
    }

    return fetch(config.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('track_failed_' + response.status);
      }

      return true;
    });
  }

  function flushQueue() {
    var queue = getQueue();
    if (!queue.length) {
      return;
    }

    for (var i = 0; i < queue.length; i += 1) {
      (function (eventPayload) {
        send(eventPayload)
          .then(function () {
            dequeue(eventPayload.event_id);
          })
          .catch(function () {
            // Keep item in queue for next page view.
          });
      })(queue[i]);
    }
  }

  function trackPageview() {
    var payload = buildPageviewPayload();
    if (!payload) {
      return;
    }

    send(payload)
      .catch(function () {
        enqueue(payload);
      })
      .then(function () {
        flushQueue();
      });
  }

  function trackFormSubmit(event) {
    var target = event && event.target ? event.target : null;
    var form = target && target.closest ? target.closest('form') : null;
    if (!form || form.nodeName !== 'FORM') {
      return;
    }

    var payload = buildFormSubmitPayload(form);
    if (!payload) {
      return;
    }

    send(payload, { preferBeacon: true })
      .catch(function () {
        enqueue(payload);
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', trackPageview, { once: true });
  } else {
    trackPageview();
  }

  document.addEventListener('submit', trackFormSubmit, true);
})();
