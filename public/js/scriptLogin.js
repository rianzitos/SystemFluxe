// Seleciona os elementos do HTML
const toggleSenha = document.getElementById('toggleSenha');
const inputSenha = document.getElementById('senha');
const iconeSenha = document.getElementById('iconeSenha');
// Alterna entre mostrar e esconder a senha ao clicar
toggleSenha?.addEventListener('click', () => {
      // Verifica se a senha está visível
    const visivel = inputSenha.type === 'text';
    // Troca o tipo do input entre password e text
    inputSenha.type = visivel ? 'password' : 'text';
        // Altera o ícone conforme o estado da senha
    iconeSenha.className = visivel ? 'bi bi-eye' : 'bi bi-eye-slash';
});
// Tela de carregamento: some 1,5 segundo depois que o HTML da página chegou.
// Antes ela esperava o evento "load", que só acontece quando TODOS os recursos terminam de baixar
// (GIF, imagens, ícones do CDN) — se um deles demorasse ou falhasse, a tela ficava para sempre.
function esconderCarregamento() {
  const screen = document.getElementById('loading-screen');
  if (!screen) return;
  screen.classList.add('hidden');
  // Remove o elemento da tela após a animação
  setTimeout(() => screen.remove(), 500);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => setTimeout(esconderCarregamento, 1500));
} else {
  setTimeout(esconderCarregamento, 1500);
}
