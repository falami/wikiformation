(() => {
  'use strict';
  if (window.WikiCookieConsent) return;

  const VERSION = '2026-10-25';
  const MAX_AGE = 180 * 24 * 60 * 60;
  const ANALYTICS_ID = 'G-KKCHBDBP0H';
  const getCookie = name => document.cookie.split(';').map(part => part.trim())
    .find(part => part.startsWith(`${name}=`))?.slice(name.length + 1);
  const setCookie = (name, value, age, domain = '') => {
    document.cookie = `${name}=${value}; Path=/; Max-Age=${age}; SameSite=Lax${domain ? `; Domain=${domain}` : ''}${location.protocol === 'https:' ? '; Secure' : ''}`;
  };
  const choice = () => getCookie('cookieConsentVersion') === VERSION
    && ['accepted', 'rejected'].includes(getCookie('cookieConsent')) ? getCookie('cookieConsent') : null;

  function stopAnalytics() {
    window[`ga-disable-${ANALYTICS_ID}`] = true;
    if (typeof window.gtag === 'function') {
      window.gtag('consent', 'update', {analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'});
    }
    // GA may have used a parent-domain cookie. Never delete authentication or session cookies.
    const hostParts = location.hostname.split('.');
    const domains = [''];
    for (let index = 0; index < hostParts.length - 1; index++) domains.push(hostParts.slice(index).join('.'));
    document.cookie.split(';').map(part => part.trim().split('=')[0])
      .filter(name => /^(_ga(?:_|$)|_gid$|_gat(?:_|$))/.test(name))
      .forEach(name => domains.forEach(domain => setCookie(name, '', 0, domain)));
  }

  function show(open) {
    const banner = document.getElementById('cookie-banner');
    if (banner) banner.hidden = !open;
    const manage = document.getElementById('cookie-preferences');
    if (manage) manage.hidden = open;
  }

  function save(value) {
    const previous = choice();
    if (value === 'rejected') stopAnalytics();
    setCookie('cookieConsent', value, MAX_AGE);
    setCookie('cookieConsentVersion', VERSION, MAX_AGE);
    show(false);
    // A full reload unloads already-injected trackers after withdrawal, including Turbo's persistent head.
    if (value === 'accepted' || previous === 'accepted' || typeof window.gtag === 'function') location.reload();
  }

  function init() {
    if (choice() !== 'accepted') stopAnalytics();
    show(!choice());
  }

  document.addEventListener('click', event => {
    const target = event.target.closest?.('#accept-cookies, #reject-cookies, #manage-cookies, [data-manage-cookies]');
    if (!target) return;
    event.preventDefault();
    if (target.id === 'accept-cookies') save('accepted');
    else if (target.id === 'reject-cookies') save('rejected');
    else {
      show(true);
      document.getElementById('reject-cookies')?.focus();
    }
  });
  document.addEventListener('DOMContentLoaded', init);
  document.addEventListener('turbo:load', init);
  window.addEventListener('pageshow', init);
  window.WikiCookieConsent = {init};
  if (document.readyState !== 'loading') init();
})();
