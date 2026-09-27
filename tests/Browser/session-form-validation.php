<?php
// Test navigateur sans dépendance, donnée réelle ni enregistrement :
// php -S 127.0.0.1:8766 -t tests/Browser
// Ouvrir /session-form-validation.php puis « Tester les boutons Enregistrer ».
$template = file_get_contents(__DIR__ . '/../../templates/administrateur/session/form.html.twig');
if (!preg_match('~<script>\s*(function initFormValidation\([\s\S]*?)</script>~', $template, $matches)) {
    throw new RuntimeException('Fonction de validation du formulaire introuvable.');
}
?>
<!doctype html>
<html lang="fr">
<meta charset="utf-8">
<title>Vérification du formulaire de session</title>
<style>
    body { font: 16px system-ui; max-width: 900px; margin: 30px auto; }
    label { display: block; margin-top: 10px; }
    input, select, button { padding: 8px; margin: 4px; }
    .d-none { display: none; }
    .alert { background: #fff0f0; padding: 16px; }
    .is-invalid { outline: 2px solid #b00; }
    #results { white-space: pre-wrap; }
</style>
<h1>Validation du formulaire de session</h1>
<p>Fixture isolée utilisant la fonction JavaScript réelle du template. Aucune requête ni donnée sauvegardée.</p>
<button type="button" id="run">Tester les boutons Enregistrer</button>
<pre id="results" role="status">Tests en attente.</pre>
<button form="session" type="submit" id="hero">Enregistrer — en-tête</button>
<form id="session" name="session" novalidate>
    <label for="formation">Formation</label><select id="formation" required><option value=""></option><option value="1">Formation test</option></select>
    <label for="site">Site</label><select id="site" required><option value=""></option><option value="1">Salle test</option></select>
    <label for="organisme">Organisme de formation</label><select id="organisme" disabled><option value=""></option><option value="1">Organisme test</option></select>
    <label for="libre">Intitulé libre</label><input id="libre" disabled>
    <label for="capacite">Capacité</label><input id="capacite" type="number" min="1" value="8" required>
    <div id="jours-collection"></div>
    <div id="inscriptions-collection"></div>
    <button type="submit" id="bottom">Enregistrer — bas</button>
    <button type="submit" id="right">Enregistrer — résumé</button>
</form>
<script>
<?= $matches[1] ?>
</script>
<script>
const form = document.getElementById('session');
const field = id => document.getElementById(id);
let attempted = false;
let accepted = false;
initFormValidation({ formName: 'session', tomSelectFields: [
    { id: 'formation', label: 'Formation' }, { id: 'site', label: 'Site' }, { id: 'organisme', label: 'Organisme de formation' },
] });
form.addEventListener('submit', event => {
    attempted = true;
    accepted = !event.defaultPrevented;
    event.preventDefault(); // La fixture ne quitte jamais la page et ne sauvegarde rien.
});
function reset() {
    field('formation').disabled = false; field('formation').required = true; field('formation').value = '1';
    field('site').value = '1';
    field('organisme').disabled = true; field('organisme').required = false; field('organisme').value = '';
    field('libre').disabled = true; field('libre').required = false; field('libre').value = '';
    field('capacite').value = '8';
    field('jours-collection').innerHTML = '<div class="jour-item"><label for="debut">Début</label><input class="flatpickr-datetime" id="debut" value="05/10/2026 08:30" required><label for="fin">Fin</label><input class="flatpickr-datetime" id="fin" value="05/10/2026 17:00" required></div>';
    attempted = false; accepted = false;
}
function check(condition, message) { if (!condition) throw new Error(message); }
function clickSave(button = 'bottom') {
    field(button).click();
    check(attempted, 'Le bouton doit déclencher la soumission.');
}
field('run').addEventListener('click', () => {
    const results = [];
    function scenario(name, run) {
        reset();
        try { run(); results.push('OK — ' + name); }
        catch (error) { results.push('ÉCHEC — ' + name + ': ' + error.message); }
    }
    ['hero', 'bottom', 'right'].forEach(button => scenario('Enregistrer sans stagiaire : ' + button, () => {
        clickSave(button); check(accepted, 'Une session valide sans inscription doit être acceptée.');
    }));
    scenario('Site absent : erreur visible et focus', () => {
        field('site').value = ''; clickSave();
        check(!accepted, 'Le site absent doit bloquer la soumission.');
        check(!field('form-error-alert').classList.contains('d-none'), 'Une erreur doit être visible.');
        check(document.activeElement === field('site'), 'Le champ site doit recevoir le focus.');
    });
    scenario('Aucune journée : erreur visible et focus', () => {
        field('jours-collection').replaceChildren(); clickSave();
        check(!accepted, 'Une journée est requise.');
        check(field('form-error-alert').textContent.includes('Ajouter au moins une journée'), 'Le motif doit être indiqué.');
        check(document.activeElement === field('form-error-alert'), 'L’erreur doit recevoir le focus.');
    });
    scenario('Horaire absent : soumission bloquée', () => {
        field('fin').value = ''; clickSave(); check(!accepted, 'La fin du créneau est requise.');
    });
    scenario('Capacité invalide : contrainte native respectée', () => {
        field('capacite').value = '0'; clickSave(); check(!accepted, 'Une capacité nulle doit être refusée.');
    });
    scenario('Sous-traitance : formation interne désactivée ignorée', () => {
        field('formation').value = ''; field('formation').disabled = true;
        field('organisme').disabled = false; field('organisme').required = true; field('organisme').value = '1';
        field('libre').disabled = false; field('libre').required = true; field('libre').value = 'Formation externe';
        clickSave(); check(accepted, 'Un formulaire de sous-traitance complet doit être accepté.');
    });
    scenario('Correction après erreur : soumission autorisée', () => {
        field('site').value = ''; clickSave(); check(!accepted, 'Le premier essai doit être refusé.');
        field('site').value = '1'; attempted = false; clickSave();
        check(accepted, 'La correction doit permettre d’enregistrer.');
        check(field('form-error-alert').classList.contains('d-none'), 'L’ancienne erreur doit être masquée.');
    });
    const failures = results.filter(result => result.startsWith('ÉCHEC')).length;
    field('results').textContent = `${results.length - failures}/${results.length} scénarios réussis\n` + results.join('\n');
    reset();
});
reset();
</script>
</html>
