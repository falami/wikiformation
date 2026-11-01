// The native radios handle selection and keyboard navigation, even without JavaScript.
document.addEventListener('click', (event) => {
  const button = event.target.closest('.sr-clear');
  if (!button) return;
  const selected = button.closest('.sr-rating').querySelector('input[type="radio"]:checked');
  if (!selected) return;
  selected.checked = false;
  selected.dispatchEvent(new Event('change', { bubbles: true }));
  selected.focus();
});
