(function(){
  const TOTAL_STEPS = 6;
  let current = 1;
  const STORAGE_KEY = 'sicapda_signup_v1';

  const $ = (s, ctx) => (ctx||document).querySelector(s);
  const $$ = (s, ctx) => Array.from((ctx||document).querySelectorAll(s));

  const panels = $$('.step-panel');
  const stepItems = $$('.step-item');
  const btnNext = $('#btnNext');
  const btnBack = $('#btnBack');
  const progressFill = $('#progressFill');
  const autosavePill = $('#autosavePill');
  const kbdHint = $('#kbdHint');
  const wizardForm = $('#wizardForm');

  /* ---------- masks ---------- */
  function maskCPF(v){
    v = v.replace(/\D/g,'').slice(0,11);
    v = v.replace(/(\d{3})(\d)/,'$1.$2');
    v = v.replace(/(\d{3})(\d)/,'$1.$2');
    v = v.replace(/(\d{3})(\d{1,2})$/,'$1-$2');
    return v;
  }
  function maskCNPJ(v){
    v = v.replace(/\D/g,'').slice(0,14);
    v = v.replace(/(\d{2})(\d)/,'$1.$2');
    v = v.replace(/(\d{3})(\d)/,'$1.$2');
    v = v.replace(/(\d{3})(\d)/,'$1/$2');
    v = v.replace(/(\d{4})(\d{1,2})$/,'$1-$2');
    return v;
  }
  function maskPhone(v){
    v = v.replace(/\D/g,'').slice(0,11);
    if(v.length > 10) v = v.replace(/(\d{2})(\d{5})(\d{4})/,'($1) $2-$3');
    else v = v.replace(/(\d{2})(\d{4})(\d{0,4})/,'($1) $2-$3');
    return v.trim().replace(/-$/,'');
  }
  function maskCEP(v){
    v = v.replace(/\D/g,'').slice(0,8);
    v = v.replace(/(\d{5})(\d{1,3})/,'$1-$2');
    return v;
  }
  $('#admCpf').addEventListener('input', e => { e.target.value = maskCPF(e.target.value); });
  $('#admTelefone').addEventListener('input', e => { e.target.value = maskPhone(e.target.value); });
  $('#telEmpresa').addEventListener('input', e => { e.target.value = maskPhone(e.target.value); });
  $('#cnpj').addEventListener('input', e => { e.target.value = maskCNPJ(e.target.value); });
  $('#cep').addEventListener('input', e => { e.target.value = maskCEP(e.target.value); });

  /* ---------- CEP autofill ---------- */
  const cepStatus = $('#cepStatus');
  $('#cep').addEventListener('blur', async (e) => {
    const digits = e.target.value.replace(/\D/g,'');
    if(digits.length !== 8) return;
    cepStatus.style.display = 'block';
    cepStatus.textContent = 'Buscando endereço...';
    try{
      const res = await fetch(`https://viacep.com.br/ws/${digits}/json/`);
      const data = await res.json();
      if(!data.erro){
        $('#cidade').value = data.localidade || '';
        $('#estado').value = data.uf || '';
        if(data.logradouro){
          $('#endereco').value = `${data.logradouro}${data.bairro ? ', ' + data.bairro : ''}`;
        }
        cepStatus.textContent = 'Endereço preenchido automaticamente ✓';
        clearError($('#cidade')); clearError($('#estado'));
      } else {
        cepStatus.textContent = 'CEP não encontrado, preencha manualmente.';
      }
    }catch(err){
      cepStatus.textContent = 'Não foi possível buscar o CEP automaticamente.';
    }
  });

  /* ---------- password strength ---------- */
  const senhaEl = $('#senha');
  const bars = $$('.strength-bar span');
  const strengthLabel = $('#strengthLabel');
  const checklist = $$('#checklist li');

  function evalPassword(v){
    const rules = {
      len: v.length >= 8,
      upper: /[A-Z]/.test(v),
      lower: /[a-z]/.test(v),
      num: /[0-9]/.test(v),
      special: /[^A-Za-z0-9]/.test(v)
    };
    checklist.forEach(li => {
      const rule = li.dataset.rule;
      const ok = rules[rule];
      li.classList.toggle('ok', ok);
      li.innerHTML = `<span class="dot">${ok ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 13 4 4L19 7"/></svg>' : ''}</span>${li.textContent.trim()}`;
    });
    const score = Object.values(rules).filter(Boolean).length;
    bars.forEach((b,i) => {
      b.style.background = i < score ? (score <=2 ? '#e0483f' : score <=3 ? '#F4B400' : '#1f9d55') : 'var(--gray-200)';
    });
    const labels = ['Muito fraca','Muito fraca','Fraca','Boa','Forte','Muito forte'];
    strengthLabel.textContent = v.length ? labels[score] : 'Força da senha';
    strengthLabel.style.color = score >= 4 ? 'var(--success)' : score >=3 ? 'var(--yellow-dark)' : 'var(--danger)';
    return score === 5;
  }
  senhaEl.addEventListener('input', e => evalPassword(e.target.value));

  /* password visibility toggles */
  $$('.toggle-eye').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = $('#' + btn.dataset.target);
      target.type = target.type === 'password' ? 'text' : 'password';
    });
  });

  /* ---------- 2FA radio cards ---------- */
  function wireRadioCards(name, panelEl, showValue){
    $$(`input[name=${name}]`).forEach(radio => {
      radio.addEventListener('change', () => {
        $$(`input[name=${name}]`).forEach(r => r.closest('.radio-card').classList.toggle('selected', r.checked));
        if(panelEl) panelEl.classList.toggle('show', radio.value === showValue && radio.checked);
      });
    });
  }
  wireRadioCards('fa2', $('#fa2options'), 'sim');
  wireRadioCards('refeitorio', $('#refeitorioPanel'), 'sim');

  /* checkbox visual state */
  $$('.check-item input').forEach(cb => {
    cb.addEventListener('change', () => cb.closest('.check-item').classList.toggle('checked', cb.checked));
  });

  /* ---------- validation ---------- */
  function isValidCPF(cpf){
    cpf = cpf.replace(/\D/g,'');
    if(cpf.length !== 11 || /^(\d)\1+$/.test(cpf)) return false;
    let sum=0;
    for(let i=0;i<9;i++) sum += parseInt(cpf[i]) * (10-i);
    let rev = 11 - (sum % 11); if(rev>=10) rev=0;
    if(rev !== parseInt(cpf[9])) return false;
    sum=0;
    for(let i=0;i<10;i++) sum += parseInt(cpf[i]) * (11-i);
    rev = 11 - (sum % 11); if(rev>=10) rev=0;
    return rev === parseInt(cpf[10]);
  }
  function isValidCNPJ(cnpj){
    cnpj = cnpj.replace(/\D/g,'');
    return cnpj.length === 14 && !/^(\d)\1+$/.test(cnpj);
  }
  function isValidEmail(v){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
  function isValidPhone(v){ return v.replace(/\D/g,'').length >= 10; }

  function showError(input, show){
    const wrap = input.closest('.field') || input.closest('.checkbox-row');
    if(!wrap) return;
    input.classList.toggle('error', show);
    const msg = wrap.querySelector('.error-msg') || document.getElementById('err' + (input.id === 'admAutoriza' ? 'Autoriza' : 'Termos'));
    if(msg) msg.classList.toggle('show', show);
  }
  function clearError(input){ showError(input, false); }

  const validators = {
    admNome: v => v.trim().length >= 3,
    admCpf: v => isValidCPF(v),
    admTelefone: v => isValidPhone(v),
    admEmail: v => isValidEmail(v),
    admCargo: v => v.trim().length >= 2,
    razaoSocial: v => v.trim().length >= 2,
    nomeFantasia: v => v.trim().length >= 2,
    cnpj: v => isValidCNPJ(v),
    segmento: v => v.trim() !== '',
    qtdColab: v => parseInt(v) > 0,
    endereco: v => v.trim().length >= 4,
    cep: v => v.replace(/\D/g,'').length === 8,
    cidade: v => v.trim().length >= 2,
    estado: v => v.trim() !== '',
    telEmpresa: v => isValidPhone(v),
    horarioFunc: v => v.trim().length >= 3,
    responsavel: v => v.trim().length >= 3,
    emailInst: v => isValidEmail(v),
    confirmaSenha: v => v === senhaEl.value && v.length > 0,
  };

  function validateField(input){
    const validator = validators[input.id];
    if(!validator) return true;
    const ok = validator(input.value);
    showError(input, !ok);
    input.classList.toggle('valid', ok && input.value.length>0);
    return ok;
  }

  const stepFieldMap = {
    1: ['admNome','admCpf','admTelefone','admEmail','admCargo'],
    2: ['confirmaSenha'],
    3: ['razaoSocial','nomeFantasia','cnpj','segmento','qtdColab','endereco','cep','cidade','estado','telEmpresa','horarioFunc','responsavel','emailInst'],
    4: [],
    5: [],
  };

  $$('input, select').forEach(el => {
    el.addEventListener('blur', () => { if(validators[el.id]) validateField(el); });
  });

  function validateStep(step){
    let valid = true;
    (stepFieldMap[step]||[]).forEach(id => {
      const el = document.getElementById(id);
      if(el && !validateField(el)) valid = false;
    });
    if(step === 1){
      const authEl = $('#admAutoriza');
      if(!authEl.checked){ valid = false; $('#errAutoriza').classList.add('show'); } else { $('#errAutoriza').classList.remove('show'); }
    }
    if(step === 2){
      if(!evalPassword(senhaEl.value)) valid = false;
    }
    if(step === 5){
      const termos = $('#aceiteTermos');
      if(!termos.checked){ valid = false; $('#errTermos').classList.add('show'); } else { $('#errTermos').classList.remove('show'); }
    }
    return valid;
  }

  /* ---------- stepper / navigation ---------- */
  function renderStepper(){
    stepItems.forEach(item => {
      const n = parseInt(item.dataset.step);
      item.classList.toggle('active', n === current);
      item.classList.toggle('done', n < current);
      item.querySelector('.circle').innerHTML = n < current
        ? '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 13 4 4L19 7"/></svg>'
        : n;
    });
    progressFill.style.width = (current/TOTAL_STEPS*100) + '%';
  }

  function goToStep(n, opts){
    opts = opts || {};
    if(!opts.skipValidation && n > current && !validateStep(current)) return;
    current = n;
    panels.forEach(p => p.classList.toggle('active', parseInt(p.dataset.panel) === current));
    renderStepper();
    updateFooter();
    if(current === 5) renderReview();
    if(current === 6) fillDone();
    $('.card').scrollIntoView({behavior:'smooth', block:'start'});
    saveState();
  }

  function updateFooter(){
    const btnRow = $('#btnRow');
    btnBack.style.visibility = current === 1 ? 'hidden' : 'visible';
    if(current === TOTAL_STEPS){
      btnRow.style.display = 'none';
      kbdHint.style.display = 'none';
    } else {
      btnRow.style.display = 'flex';
      btnNext.textContent = current === 5 ? 'Criar Conta ✓' : 'Próximo →';
      kbdHint.style.display = 'block';
    }
  }

  /* ---------- envio real pro backend (via fetch, sem recarregar a página) ---------- */
  const formErro = $('#formErro');

  function setSubmitting(isSubmitting){
    btnNext.disabled = isSubmitting;
    btnBack.disabled = isSubmitting;
    btnNext.textContent = isSubmitting ? 'Enviando...' : 'Criar Conta ✓';
  }

  function mostrarErroForm(mensagem){
    formErro.textContent = mensagem;
    formErro.style.display = 'block';
    formErro.scrollIntoView({behavior:'smooth', block:'start'});
  }

  function esconderErroForm(){
    formErro.style.display = 'none';
    formErro.textContent = '';
  }

  async function enviarCadastro(){
    esconderErroForm();
    setSubmitting(true);
    try{
      // wizardForm.action fica vazio de propósito: isso faz o fetch enviar
      // pra própria URL que está aberta no navegador, funcionando tanto em
      // http://localhost:8000/cadastro quanto em .../app/views/cadastro.php
      const resposta = await fetch(wizardForm.action || window.location.href, {
        method: 'POST',
        body: new FormData(wizardForm),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      let dados;
      try{
        dados = await resposta.json();
      }catch(erroParse){
        mostrarErroForm('O servidor respondeu de um jeito inesperado. Confira se o PHP e o banco de dados estão rodando.');
        return;
      }

      if(dados.sucesso){
        localStorage.removeItem(STORAGE_KEY);
        window.__cadastroResultado = dados; // usado por fillDone()
        goToStep(6, {skipValidation:true});
      } else {
        mostrarErroForm(dados.mensagem || 'Não foi possível concluir o cadastro. Tente novamente.');
      }
    }catch(erroRede){
      mostrarErroForm('Não foi possível conectar ao servidor. Verifique se ele está rodando.');
    }finally{
      setSubmitting(false);
    }
  }

  btnNext.addEventListener('click', () => {
    // Passo 5 -> envia o formulário de verdade pro backend via fetch.
    // O passo 6 só aparece se o servidor confirmar sucesso.
    if(current === 5){
      if(!validateStep(5)) return;
      enviarCadastro();
      return;
    }
    if(current < TOTAL_STEPS) goToStep(current + 1);
  });
  btnBack.addEventListener('click', () => {
    if(current > 1) goToStep(current - 1, {skipValidation:true});
  });
  stepItems.forEach(item => {
    item.addEventListener('click', () => {
      const n = parseInt(item.dataset.step);
      if(n < current || n === current) goToStep(n, {skipValidation:true});
    });
  });
  $$('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => goToStep(parseInt(btn.dataset.goto), {skipValidation:true}));
  });

  document.addEventListener('keydown', (e) => {
    if(e.key === 'Enter' && current < TOTAL_STEPS){
      const tag = document.activeElement.tagName;
      if(tag === 'SELECT') return;
      e.preventDefault();
      btnNext.click();
    }
    if(e.key === 'Escape' && current > 1){
      btnBack.click();
    }
  });

  /* ---------- review ---------- */
  function renderReview(){
    $('#rv-nome').textContent = $('#admNome').value || '—';
    $('#rv-email').textContent = $('#admEmail').value || '—';
    $('#rv-tel').textContent = $('#admTelefone').value || '—';
    $('#rv-cargo').textContent = $('#admCargo').value || '—';

    $('#rv-razao').textContent = $('#razaoSocial').value || '—';
    $('#rv-cnpj').textContent = $('#cnpj').value || '—';
    $('#rv-segmento').textContent = $('#segmento').value || '—';
    $('#rv-colab').textContent = $('#qtdColab').value || '—';

    const acesso = $$('.check-grid')[0] ? $$('.check-grid')[0].querySelectorAll('input:checked') : [];
    const acessoWrap = $('#rv-acesso');
    acessoWrap.innerHTML = acesso.length ? Array.from(acesso).map(c => `<span class="tag">${c.value}</span>`).join('') : '<span class="v">Nenhum</span>';

    const refSim = $('input[name=refeitorio]:checked').value === 'sim';
    $('#rv-refeitorio').textContent = refSim ? 'Sim, possui refeitório' : 'Não possui refeitório';

    const integ = $$('.check-grid')[1] ? $$('.check-grid')[1].querySelectorAll('input:checked') : [];
    const integWrap = $('#rv-integ');
    integWrap.innerHTML = integ.length ? Array.from(integ).map(c => `<span class="tag">${c.value}</span>`).join('') : '<span class="v">Nenhuma</span>';
  }

  // O passo 6 só é exibido depois que o backend confirma sucesso (ver
  // enviarCadastro()). window.__cadastroResultado guarda o que o servidor
  // devolveu; se por algum motivo não existir, cai pros valores do form.
  function fillDone(){
    const r = window.__cadastroResultado || {};
    $('#done-empresa').textContent = r.empresa || $('#nomeFantasia').value || $('#razaoSocial').value || '—';
    $('#done-admin').textContent = r.admin || $('#admNome').value || '—';
    $('#done-email').textContent = r.email || $('#admEmail').value || '—';
    $('#done-data').textContent = new Date().toLocaleDateString('pt-BR', {day:'2-digit', month:'long', year:'numeric'});
  }

  $('#btnEntrar').addEventListener('click', () => {
    localStorage.removeItem(STORAGE_KEY);
    // Ajuste esse caminho se o seu login.php estiver em outro lugar.
    window.location.href = 'login.php';
  });
  $('#btnVoltarLogin').addEventListener('click', () => {
    localStorage.removeItem(STORAGE_KEY);
    window.location.href = 'login.php';
  });

  /* ---------- autosave ---------- */
  let saveTimeout;
  function saveState(){
    clearTimeout(saveTimeout);
    saveTimeout = setTimeout(() => {
      const data = {};
      $$('#wizardForm input, #wizardForm select').forEach(el => {
        if(el.type === 'checkbox') data[el.id || el.name + '_' + el.value] = el.checked;
        else if(el.type === 'radio'){ if(el.checked) data[el.name] = el.value; }
        else if(el.type !== 'password' && el.type !== 'file') data[el.id] = el.value;
      });
      data.currentStep = current;
      try{ localStorage.setItem(STORAGE_KEY, JSON.stringify(data)); }catch(e){}
      autosavePill.classList.add('show');
      setTimeout(() => autosavePill.classList.remove('show'), 1600);
    }, 500);
  }
  function loadState(){
    let saved;
    try{ saved = JSON.parse(localStorage.getItem(STORAGE_KEY)); }catch(e){}
    if(!saved) return;
    Object.keys(saved).forEach(key => {
      if(key === 'currentStep') return;
      const el = document.getElementById(key);
      if(el && el.type === 'checkbox') el.checked = saved[key];
      else if(el) el.value = saved[key];
    });
    // radios
    ['fa2','refeitorio'].forEach(name => {
      if(saved[name]){
        const r = document.querySelector(`input[name=${name}][value="${saved[name]}"]`);
        if(r){ r.checked = true; r.dispatchEvent(new Event('change')); }
      }
    });
    $$('.check-item input').forEach(cb => cb.closest('.check-item').classList.toggle('checked', cb.checked));
    if(senhaEl.value) evalPassword(senhaEl.value);
    // Se a página recarregou por causa de um erro do servidor, volta pro
    // passo salvo (normalmente o 5) em vez de reiniciar do zero.
    if(saved.currentStep) goToStep(saved.currentStep, {skipValidation:true});
  }

  $$('#wizardForm input, #wizardForm select').forEach(el => {
    el.addEventListener('input', saveState);
    el.addEventListener('change', saveState);
  });

  $('#admFoto').addEventListener('change', (e) => {
    if(e.target.files && e.target.files[0]){
      $('#fotoLabel').textContent = e.target.files[0].name;
    }
  });

  /* init */
  loadState();
  renderStepper();
  updateFooter();
})();