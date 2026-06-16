(function(){
  var config = window.GVAIT_FRONTEND || {};
  var PARAM = config.param || config.paramLegacy || 'gv_lang';
  var COOKIE = config.cookie || config.cookieLegacy || 'gvait_lang';
  var DEFAULT_LANG = cleanLang(config.defaultLang) || 'it';
  var LANGUAGES = [];

  function cleanLang(lang){
    if (typeof lang !== 'string') return '';
    return lang.replace(/^\s+|\s+$/g, '').toLowerCase();
  }

  function addLanguage(lang){
    lang = cleanLang(lang);
    if (!lang || !/^[a-z0-9_-]+$/.test(lang)) return;
    if (LANGUAGES.indexOf(lang) === -1) LANGUAGES.push(lang);
  }

  if (Array.isArray(config.languages)) {
    config.languages.forEach(addLanguage);
  }
  addLanguage(DEFAULT_LANG);

  function allowedLang(lang){
    lang = cleanLang(lang);
    if (!lang || !/^[a-z0-9_-]+$/.test(lang)) return '';
    return LANGUAGES.indexOf(lang) !== -1 ? lang : '';
  }

  var SERVER_LANG = allowedLang(config.currentLang) || DEFAULT_LANG;

  function getParam(name){
    try { return new URL(window.location.href).searchParams.get(name); }
    catch(e){ return null; }
  }

  function setCookie(name, value){
    if (!value) return;
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=31536000; SameSite=Lax';
  }

  function clearCookie(name){
    document.cookie = name + '=; path=/; max-age=0; SameSite=Lax';
  }

  function getCookie(name){
    var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()\[\]\\\/\+^]/g, '\\$&') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  function isInternalUrl(url){
    if (!url || url.charAt(0) === '#') return false;
    if (/^(mailto:|tel:|sms:|javascript:|data:|blob:)/i.test(url)) return false;
    try {
      var u = new URL(url, window.location.href);
      return u.origin === window.location.origin;
    } catch(e) { return false; }
  }

  function isStaticOrTechnical(u){
    return /\.(css|js|json|xml|jpg|jpeg|png|gif|webp|svg|ico|pdf|zip|rar|7z|mp4|mp3|woff2?|ttf|eot)(\?.*)?$/i.test(u.pathname) ||
      /\/wp-admin\//i.test(u.pathname) ||
      /\/wp-login\.php$/i.test(u.pathname) ||
      /admin-ajax\.php$/i.test(u.pathname);
  }

  function currentLang(){
    return allowedLang(getParam(PARAM)) || allowedLang(getCookie(COOKIE)) || SERVER_LANG || DEFAULT_LANG;
  }

  function rememberLang(lang){
    if (!lang || lang === DEFAULT_LANG) clearCookie(COOKIE);
    else setCookie(COOKIE, lang);
  }

  function isProtected(el){
    while (el && el !== document) {
      if (el.matches && (el.matches('[data-gvait-no-translate]') || el.matches('[data-traduttore-no-translate]') || el.matches('.gvait-selector') || el.matches('.traduttore-selector'))) return true;
      el = el.parentElement;
    }
    return false;
  }

  function closestLink(el){
    while (el && el !== document) {
      if (el.tagName && el.tagName.toLowerCase() === 'a' && el.hasAttribute('href')) return el;
      el = el.parentElement;
    }
    return null;
  }

  function normalizeUrl(url, lang){
    if (!isInternalUrl(url)) return url;
    try {
      var u = new URL(url, window.location.href);
      if (isStaticOrTechnical(u)) return url;
      u.searchParams.delete('lang');
      u.searchParams.delete('gt_lang');
      u.searchParams.delete('googtrans');
      if (lang && lang !== DEFAULT_LANG) {
        u.searchParams.set(PARAM, lang);
        // also set legacy param for safety
        if (PARAM === 'traduttore_lang') u.searchParams.set('gv_lang', lang);
      }
      else {
        u.searchParams.delete(PARAM);
        u.searchParams.delete('gv_lang');
      }
      return u.toString();
    } catch(e) { return url; }
  }

  function propagateLinks(){
    var lang = currentLang();
    if (!lang) return;

    document.querySelectorAll('a[href]').forEach(function(a){
      if (isProtected(a)) return;
      var fixed = normalizeUrl(a.getAttribute('href'), lang);
      if (fixed !== a.getAttribute('href')) a.setAttribute('href', fixed);
    });

    document.querySelectorAll('form[action]').forEach(function(form){
      if (isProtected(form)) return;
      var fixed = normalizeUrl(form.getAttribute('action'), lang);
      if (fixed !== form.getAttribute('action')) form.setAttribute('action', fixed);
    });
  }

  var urlLang = allowedLang(getParam(PARAM));
  if (urlLang) rememberLang(urlLang);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', propagateLinks);
  } else {
    propagateLinks();
  }

  document.addEventListener('click', function(e){
    var a = closestLink(e.target);
    if (!a || isProtected(a)) return;
    var lang = currentLang();
    var fixed = normalizeUrl(a.getAttribute('href'), lang);
    if (fixed !== a.getAttribute('href')) a.setAttribute('href', fixed);
  }, true);

  document.addEventListener('submit', function(e){
    var form = e.target;
    if (!form || !form.getAttribute || isProtected(form)) return;
    var action = form.getAttribute('action');
    if (!action) return;
    var fixed = normalizeUrl(action, currentLang());
    if (fixed !== action) form.setAttribute('action', fixed);
  }, true);
})();
