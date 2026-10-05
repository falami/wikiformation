(() => {
 const canvas = document.getElementById('personal-canvas'); if (!canvas) return;
 const ctx = canvas.getContext('2d'), field = document.getElementById('signature-image'), file = document.getElementById('signature-file'), error = document.getElementById('signature-error'), preview = document.getElementById('signature-upload-preview');
 let drawing = false, ink = false, reading = false;
 ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.strokeStyle = '#203345';
 const point = e => { const r = canvas.getBoundingClientRect(); return [(e.clientX-r.left)*canvas.width/r.width, (e.clientY-r.top)*canvas.height/r.height]; };
 canvas.addEventListener('pointerdown', e => { drawing = true; canvas.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.moveTo(...point(e)); field.value = ''; file.value = ''; preview.hidden = true; });
 canvas.addEventListener('pointermove', e => { if (!drawing) return; ctx.lineTo(...point(e)); ctx.stroke(); ink = true; });
 ['pointerup','pointercancel'].forEach(type => canvas.addEventListener(type, () => drawing = false));
 document.getElementById('clear-signature').onclick = () => { ctx.clearRect(0,0,canvas.width,canvas.height); ink = false; field.value = ''; file.value = ''; preview.hidden = true; error.textContent = ''; };
 file.onchange = () => { const f = file.files[0]; field.value = ''; preview.hidden = true; error.textContent = ''; if (!f) return;
 if (!['image/png','image/jpeg'].includes(f.type) || f.size > 512000) { error.textContent = 'Choisissez une image PNG ou JPEG de moins de 500 Ko.'; file.value = ''; return; }
 reading = true; const reader = new FileReader(); reader.onload = () => { field.value = reader.result; preview.src = reader.result; preview.hidden = false; reading = false; }; reader.onerror = () => { reading = false; error.textContent = 'Impossible de lire cette image.'; }; reader.readAsDataURL(f);
 };
 document.getElementById('personal-signature-form').onsubmit = e => { if (reading || (!field.value && !ink)) { e.preventDefault(); error.textContent = reading ? 'Chargement de l’image en cours.' : 'Dessinez ou importez votre signature avant de l’enregistrer.'; return; } if (!field.value) field.value = canvas.toDataURL('image/png'); document.getElementById('save-personal-signature').disabled = true; };
})();
