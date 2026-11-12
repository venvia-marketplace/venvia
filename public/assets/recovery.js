'use strict';
const button = document.getElementById('saveRecovery');
button?.addEventListener('click', () => {
  const key = document.getElementById('recovery')?.textContent?.trim();
  if (!key) return;
  const text = 'VenVia - Recovery Key Amministratore Tecnico\n\n' + key + '\n\nConservare offline e non condividere.\n';
  const url = URL.createObjectURL(new Blob([text], {type:'text/plain;charset=utf-8'}));
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = 'venvia-recovery-key.txt';
  document.body.appendChild(anchor);
  anchor.click(); anchor.remove(); URL.revokeObjectURL(url);
});
