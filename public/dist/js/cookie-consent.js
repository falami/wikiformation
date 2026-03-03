// public/dist/js/cookie-consent.js
document.addEventListener('DOMContentLoaded', function () {
  const banner = document.getElementById('cookie-banner');
  if (!banner) return; // si la bannière n'est pas sur la page, on sort

  // ——— Helpers cookie ———
  function getCookie(name) {
    return document.cookie
      .split('; ')
      .find(row => row.startsWith(name + '='))
      ?.split('=')[1];
  }
  function setCookie(name, value, maxAgeSeconds) {
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${value}; path=/; max-age=${maxAgeSeconds}; SameSite=Lax${secure}`;
  }

  // Afficher la bannière si aucun choix
  if (!getCookie('cookieConsent')) {
    banner.style.display = 'block';
  }

  // Bouton Accepter
  const acceptBtn = document.getElementById('accept-cookies');
  if (acceptBtn) {
    acceptBtn.addEventListener('click', function () {
      setCookie('cookieConsent', 'accepted', 31536000); // 1 an
      banner.style.display = 'none';
      // Twig lit les cookies côté serveur => rechargement pour appliquer (ex: GA)
      location.reload();
    });
  }

  // Bouton Refuser
  const rejectBtn = document.getElementById('reject-cookies');
  if (rejectBtn) {
    rejectBtn.addEventListener('click', function () {
      setCookie('cookieConsent', 'rejected', 31536000);
      banner.style.display = 'none';
      // Pas de reload nécessaire sauf si tu veux aussi couper des scripts déjà injectés
    });
  }

  // Lien "Gérer les cookies" (pas toujours présent)
  const manageLink = document.getElementById('manage-cookies');
  if (manageLink) {
    manageLink.addEventListener('click', function (e) {
      e.preventDefault();
      // Efface le consentement actuel
      setCookie('cookieConsent', '', 0);
      // Ré-affiche la bannière
      banner.style.display = 'block';
    });
  }
});
