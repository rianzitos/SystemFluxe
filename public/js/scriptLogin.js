// Seleciona os elementos do HTML
const toggleSenha = document.getElementById('toggleSenha');
const inputSenha = document.getElementById('senha');
const iconeSenha = document.getElementById('iconeSenha');
// Alterna entre mostrar e esconder a senha ao clicar
toggleSenha.addEventListener('click', () => {
      // Verifica se a senha está visível
    const visivel = inputSenha.type === 'text';
    // Troca o tipo do input entre password e text
    inputSenha.type = visivel ? 'password' : 'text';
        // Altera o ícone conforme o estado da senha
    iconeSenha.className = visivel ? 'bi bi-eye' : 'bi bi-eye-slash';
});
// Executa quando a página terminar de carregar
window.addEventListener('load', () => {
  // Mantém a tela de carregamento por 1,5 segundos
  setTimeout(() => {
    const screen = document.getElementById('loading-screen');
    screen.classList.add('hidden');
        // Remove o elemento da tela após a animação

    setTimeout(() => screen.remove(), 500);
  }, 1500);
});