document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-trainer-photo]').forEach(root => {
    const input = root.querySelector('[data-photo-input]'), remove = root.querySelector('[data-photo-remove]');
    const preview = root.querySelector('[data-photo-preview]'), placeholder = root.querySelector('[data-photo-placeholder]');
    const drop = root.querySelector('[data-photo-drop]'), status = root.querySelector('[data-photo-status]'), del = root.querySelector('[data-photo-delete]');
    const original = preview.getAttribute('src');
    let url;
    const show = src => { preview.hidden = !src; placeholder.hidden = !!src; del.disabled = !src; if(src) preview.src = src; else preview.removeAttribute('src'); };
    const release = () => { if(url) URL.revokeObjectURL(url); url = null; };
    const pick = () => input.click();
    root.querySelector('[data-photo-choose]').addEventListener('click', pick);
    drop.addEventListener('click', pick);
    drop.addEventListener('keydown', e => { if(['Enter',' '].includes(e.key)) {e.preventDefault();pick();} });
    input.addEventListener('change', () => {
      const file = input.files[0]; if(!file) return;
      if(!['image/jpeg','image/png','image/webp'].includes(file.type) || file.size > 4000000) {
        input.value = ''; release(); show(remove.checked ? null : original);
        status.textContent = 'Choisissez une image JPG, PNG ou WebP de 4 Mo maximum.'; return;
      }
      release(); url = URL.createObjectURL(file); remove.checked = false; show(url);
      status.textContent = file.name + ' · Nouvelle photo prête à enregistrer.';
    });
    del.addEventListener('click', () => {release();input.value='';remove.checked=true;show(null);status.textContent='La photo sera supprimée après enregistrement.';});
    ['dragover','dragenter'].forEach(type => drop.addEventListener(type,e=>{e.preventDefault();drop.classList.add('is-over');}));
    drop.addEventListener('dragleave',()=>drop.classList.remove('is-over'));
    drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('is-over');const files=e.dataTransfer.files;if(files.length!==1){status.textContent='Déposez une seule photo à la fois.';return;}const transfer=new DataTransfer();transfer.items.add(files[0]);input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));});
    show(remove.checked ? null : original);
    window.addEventListener('pagehide',release);
  });
});
