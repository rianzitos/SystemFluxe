"""Teste local da tela Produção (site e aplicativo): registrar quilos produzidos e desperdiçados por refeição e ver
o efeito no painel, na análise mensal, na balança e no alerta de desperdício por e-mail.

Precisa de: PHP no PATH, banco migrado (config/.env, até a 007), MAIL_DRIVER=log e o servidor rodando em
SICAPDA_TEST_URL (padrão http://127.0.0.1:8000):  php -S 127.0.0.1:8000 -t public app/routes/web.php
Cria e apaga uma empresa temporária (tests/balanca_fixture.php). Não usar em produção.
Executar: python3 tests/producao_http.py
"""
import datetime
import email
import email.policy
import http.cookiejar
import json
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

raiz = Path(__file__).resolve().parent.parent
base = os.getenv('SICAPDA_TEST_URL', 'http://127.0.0.1:8000')
contador = 0


def php(*args):
    return subprocess.run(['php', *map(str, args)], cwd=raiz, check=True, capture_output=True, text=True).stdout


def pedir(caminho, corpo=None, token=None, status=200, metodo=None, abridor=None, cabecalhos=None):
    global contador
    cab = {'Accept': 'application/json', **(cabecalhos or {})}
    dados = None
    if corpo is not None:
        cab['Content-Type'] = 'application/json'
        dados = json.dumps(corpo).encode()
    if token:
        cab['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(base + caminho, data=dados, headers=cab, method=metodo)
    try:
        resp = (abridor or urllib.request.build_opener()).open(req)
    except urllib.error.HTTPError as e:
        resp = e
    texto = resp.read().decode('utf-8')
    assert resp.status == status, (caminho, resp.status, status, texto[:300])
    contador += 1
    return texto


def api(caminho, corpo=None, token=None, status=200, metodo=None):
    j = json.loads(pedir('/api' + caminho, corpo, token, status, metodo))
    assert j['sucesso'] is (status < 400), j
    return j


def emails_novos(antes):
    pasta = raiz / 'storage' / 'emails'
    return sorted(set(p.name for p in pasta.glob('*.eml')) - antes) if pasta.exists() else []


def emails_atuais():
    pasta = raiz / 'storage' / 'emails'
    return set(p.name for p in pasta.glob('*.eml')) if pasta.exists() else set()


fx = json.loads(php('tests/balanca_fixture.php'))
try:
    token = api('/login', {'email': fx['email'], 'senha': fx['senha']})['token']
    hoje = api('/producao', token=token)['dia']['hoje']
    ontem = (datetime.date.fromisoformat(hoje) - datetime.timedelta(days=1)).isoformat()
    amanha = (datetime.date.fromisoformat(hoje) + datetime.timedelta(days=1)).isoformat()

    # ─── Dia vazio ───────────────────────────────────────────────────────────
    dia = api('/producao', token=token)['dia']
    assert dia['data'] == hoje and [r['tipo'] for r in dia['refeicoes']] == ['cafe', 'almoco', 'jantar', 'ceia']
    assert not any(r['registrado'] for r in dia['refeicoes']) and dia['historico'] == []
    assert dia['totais'] == {'produzido': 0, 'desperdicado': 0, 'percentual': None}
    assert dia['metaDesperdicio'] == 8 and dia['temBalanca'] is True
    api('/producao?data=' + amanha, token=token, status=422)
    api('/producao?data=13-11-2026', token=token, status=422)
    api('/producao', token=None, status=401)

    # ─── Validação ───────────────────────────────────────────────────────────
    ruins = [
        {'data': amanha, 'itens': [{'tipo': 'almoco', 'produzido': 10}]},          # futuro
        {'data': hoje, 'itens': []},                                                 # nada
        {'data': hoje, 'itens': [{'tipo': 'lanche', 'produzido': 10}]},             # refeição desconhecida
        {'data': hoje, 'itens': [{'tipo': 'almoco', 'produzido': -1}]},             # negativo
        {'data': hoje, 'itens': [{'tipo': 'almoco', 'produzido': 'abc'}]},          # texto
        {'data': hoje, 'itens': [{'tipo': 'almoco', 'produzido': 200000}]},         # acima do limite
        {'data': hoje, 'itens': [{'tipo': 'almoco', 'produzido': 10, 'desperdicado': 11}]},   # desperdício > produzido
        {'data': hoje, 'itens': [{'tipo': 'almoco', 'produzido': 1}, {'tipo': 'almoco', 'produzido': 2}]},  # repetida
        {'data': hoje, 'itens': [{'tipo': 'almoco', 'produzido': '', 'desperdicado': None}]},  # tudo em branco
    ]
    for corpo in ruins:
        api('/producao', corpo, token, status=422)
    assert 'não pode ser maior' in api('/producao', ruins[6], token, status=422)['mensagem']

    # ─── Registrar ───────────────────────────────────────────────────────────
    antes = emails_atuais()
    r = api('/producao', {'data': hoje, 'itens': [
        {'tipo': 'almoco', 'produzido': '50,5', 'desperdicado': 3},   # vírgula, como se digita no Brasil
        {'tipo': 'cafe', 'produzido': 10},
    ]}, token)
    assert r['mensagem'] == 'Produção salva.'
    d = r['dia']
    por = {x['tipo']: x for x in d['refeicoes']}
    assert por['almoco']['produzido'] == 50.5 and por['almoco']['desperdicado'] == 3 and por['almoco']['registrado']
    assert por['cafe']['produzido'] == 10 and por['cafe']['desperdicado'] == 0
    assert not por['jantar']['registrado']
    assert d['totais'] == {'produzido': 60.5, 'desperdicado': 3, 'percentual': 5.0}, d['totais']
    assert d['historico'][0]['data'] == hoje and d['historico'][0]['produzido'] == 60.5
    assert emails_novos(antes) == []   # 5% está dentro da meta: sem alerta

    # Corrigir só o produzido mantém o desperdício (que pode ter vindo da balança)
    d = api('/producao', {'data': hoje, 'itens': [{'tipo': 'almoco', 'produzido': 55}]}, token)['dia']
    assert {x['tipo']: x for x in d['refeicoes']}['almoco']['desperdicado'] == 3

    # A balança grava o desperdício sem apagar o produzido digitado → 9 kg de 65 = 13,8%: alerta por e-mail
    pedir('/api/iot/desperdicio', {'desperdicio_kg': 9, 'tipo': 'almoco'},
          cabecalhos={'X-Device-Token': fx['balance']['token']})
    d = api('/producao', token=token)['dia']
    almoco = {x['tipo']: x for x in d['refeicoes']}['almoco']
    assert almoco['produzido'] == 55 and almoco['desperdicado'] == 9
    assert d['totais']['percentual'] == 13.8, d['totais']
    novos = emails_novos(antes)
    assert len(novos) == 1, novos
    msg = email.message_from_bytes((raiz / 'storage' / 'emails' / novos[0]).read_bytes(), policy=email.policy.default)
    assert str(msg['Subject']).startswith('Alerta: desperdício alto') and fx['email'] in str(msg['To'])
    assert '13,8% da produção' in msg.get_body(('plain',)).get_content()

    # Um dia anterior também pode ser registrado (e aparece no histórico, do mais recente para o mais antigo)
    d = api('/producao', {'data': ontem, 'itens': [{'tipo': 'jantar', 'produzido': 20, 'desperdicado': 1}]}, token)['dia']
    assert [h['data'] for h in d['historico']] == [hoje, ontem]
    # Zerar uma refeição registrada
    d = api('/producao', {'data': ontem, 'itens': [{'tipo': 'jantar', 'produzido': 0, 'desperdicado': 0}]}, token)['dia']
    assert d['totais']['produzido'] == 0

    # ─── Dashboards leem a mesma tabela ──────────────────────────────────────
    painel = api('/painel', token=token)
    demanda = painel['graficoDemanda'] if 'graficoDemanda' in painel else painel['painel']['graficoDemanda']
    rotulo_hoje = datetime.date.fromisoformat(hoje).strftime('%d/%m')
    assert rotulo_hoje in demanda['labels'] and demanda['realizado'][demanda['labels'].index(rotulo_hoje)] == 65.0, demanda
    analise = api('/analise-mensal?mes=' + hoje[:7], token=token)
    assert '65' in json.dumps(analise, ensure_ascii=False), 'análise mensal não mostra os 65 kg'

    # ─── Site (sessão + CSRF) ────────────────────────────────────────────────
    nav = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    html = pedir('/login', abridor=nav)
    csrf = re.search(r'name="csrf_token" value="([a-f0-9]{64})"', html).group(1)
    corpo = urllib.parse.urlencode({'email': fx['email'], 'senha': fx['senha'], 'csrf_token': csrf}).encode()
    nav.open(urllib.request.Request(base + '/login', data=corpo))
    html = pedir('/producao', abridor=nav)
    assert 'Registrar produção' in html and 'id="dadosProducao"' in html and 'href="/producao"' in html
    token_pagina = re.search(r'name="csrf-token" content="([a-f0-9]{64})"', html).group(1)
    j = json.loads(pedir('/producao/dados?data=' + hoje, abridor=nav))
    assert j['dia']['totais']['produzido'] == 65
    pedir('/producao/salvar', {'data': hoje, 'itens': [{'tipo': 'ceia', 'produzido': 5}]}, abridor=nav, status=403)  # sem CSRF
    j = json.loads(pedir('/producao/salvar', {'data': hoje, 'itens': [{'tipo': 'ceia', 'produzido': 5}]}, abridor=nav,
                         cabecalhos={'X-CSRF-Token': token_pagina}))
    assert j['sucesso'] and j['dia']['totais']['produzido'] == 70
    j = json.loads(pedir('/producao/salvar', {'data': amanha, 'itens': [{'tipo': 'ceia', 'produzido': 5}]}, abridor=nav,
                         cabecalhos={'X-CSRF-Token': token_pagina}, status=422))
    assert 'ainda não chegou' in j['mensagem']
    assert 'href="/producao"' in pedir('/acessos', abridor=nav)       # atalho na análise mensal
    pedir('/producao/dados', status=401)                               # sem sessão

    print(f'PASS: {contador} requisições; produção pelo app e pelo site, validação, balança, alerta e dashboards.')
finally:
    php('tests/emails_fixture.php', 'limpar-empresa', fx['company'])
