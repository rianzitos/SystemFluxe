"""Integração local das rotas da API usadas pelas telas novas do aplicativo (fluxe_app 1.2):
perfil, senha e foto; preferências; sessões do site; sino; tempo real; dispositivos IoT; importar planilha.

Precisa de: PHP no PATH, banco migrado (config/.env) e o servidor rodando em SICAPDA_TEST_URL
(padrão http://127.0.0.1:8000, por exemplo: php -S 127.0.0.1:8000 -t public app/routes/web.php).
Cria duas empresas temporárias (tests/balanca_fixture.php) e apaga tudo ao terminar. Não usar em produção.
Executar: python3 tests/api_app_http.py
"""
import base64
import datetime
import http.cookiejar
import json
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

fixture = str(Path(__file__).with_name('balanca_fixture.php'))
base = os.getenv('SICAPDA_TEST_URL', 'http://127.0.0.1:8000')
navegador = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
sem_cookie = urllib.request.build_opener()
contador = 0

# PNG 1x1 válido (a foto do perfil só aceita JPG ou PNG de verdade, conferido pelo conteúdo)
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')


def api(caminho, status=200, corpo=None, token=None, metodo=None, bruto=False):
    """Chamada à API do app (JSON + Bearer token, sem cookie)."""
    global contador
    cabecalhos = {'Accept': 'application/json'}
    dados = None
    if corpo is not None:
        cabecalhos['Content-Type'] = 'application/json'
        dados = json.dumps(corpo).encode()
    if token:
        cabecalhos['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(base + '/api' + caminho, data=dados, headers=cabecalhos, method=metodo)
    try:
        resp = sem_cookie.open(req)
    except urllib.error.HTTPError as e:
        resp = e
    texto = resp.read().decode('utf-8')
    assert resp.status == status, (caminho, resp.status, status, texto[:300])
    contador += 1
    if bruto:
        return texto
    j = json.loads(texto)
    assert j['sucesso'] is (status < 400), (caminho, j)
    return j


def web(caminho, dados=None, csrf=None, status=200):
    """Chamada ao site com cookie de sessão (para conferir as sessões abertas no navegador)."""
    global contador
    cabecalhos = {}
    corpo = None
    if dados is not None:
        cabecalhos['Content-Type'] = 'application/x-www-form-urlencoded'
        corpo = urllib.parse.urlencode(dados).encode()
    if csrf:
        cabecalhos['X-CSRF-Token'] = csrf
    try:
        resp = navegador.open(urllib.request.Request(base + caminho, data=corpo, headers=cabecalhos))
    except urllib.error.HTTPError as e:
        resp = e
    assert resp.status == status, (caminho, resp.status, status)
    contador += 1
    return resp.read().decode('utf-8'), resp.geturl()


def entrar(email, senha):
    return api('/login', corpo={'email': email, 'senha': senha})['token']


registros = []
try:
    for _ in range(2):
        registros.append(json.loads(subprocess.check_output(['php', fixture])))
    eu, outra = registros
    token = entrar(eu['email'], eu['senha'])
    token_outra = entrar(outra['email'], outra['senha'])

    # ─── Sem token / método errado ───────────────────────────────────────────
    for rota in ['/preferencias', '/sessoes', '/notificacoes', '/aovivo', '/dispositivos']:
        api(rota, 401)
    api('/perfil', 405, token=token)
    api('/preferencias', 405, corpo={}, token=token)
    api('/dispositivos/criar', 405, token=token)

    # ─── Preferências ────────────────────────────────────────────────────────
    pref = api('/preferencias', token=token)
    chaves = [n['chave'] for n in pref['notificacoes']]
    assert chaves == ['previsao_demanda', 'acesso_negado', 'dispositivo_offline', 'resumo_diario'], chaves
    assert all(n['ativo'] for n in pref['notificacoes'] if n['disponivel'])
    assert not pref['notificacoes'][3]['ativo'] and not pref['notificacoes'][3]['disponivel']
    assert pref['sistema'] == {'periodo_padrao': 'mes', 'itens_pagina': 20}
    assert [p['valor'] for p in pref['opcoes']['periodos']] == ['mes', '7', '30', '90']
    assert pref['opcoes']['itensPagina'] == [10, 20, 50] and 'Brasília' in pref['opcoes']['fuso']

    api('/preferencias/notificacao', 422, {'chave': 'nao_existe', 'ativo': True}, token)
    api('/preferencias/notificacao', 422, {'chave': 'acesso_negado'}, token)   # sem "ativo" não desliga nada
    api('/preferencias/notificacao', 422, {'chave': 'acesso_negado', 'ativo': 'talvez'}, token)
    r = api('/preferencias/notificacao', corpo={'chave': 'acesso_negado', 'ativo': False}, token=token)
    assert r['ativo'] is False and isinstance(r['sino'], int)
    assert not [n for n in api('/preferencias', token=token)['notificacoes'] if n['chave'] == 'acesso_negado'][0]['ativo']

    for ruim in [{'periodo_padrao': '15', 'itens_pagina': 10}, {'periodo_padrao': '7', 'itens_pagina': 15},
                 {'periodo_padrao': [], 'itens_pagina': 10}, {}]:
        api('/preferencias/sistema', 422, ruim, token)
    api('/preferencias/sistema', corpo={'periodo_padrao': '7', 'itens_pagina': 10}, token=token)
    rel = api('/relatorios', token=token)
    hoje = datetime.date.fromisoformat(rel['filtros']['ate'])   # "hoje" do servidor (horário de Brasília)
    assert rel['porPagina'] == 10 and rel['periodoPadrao'] == '7', rel['porPagina']
    assert rel['filtros']['de'] == (hoje - datetime.timedelta(days=6)).isoformat(), rel['filtros']
    # a outra conta continua com os padrões
    assert api('/relatorios', token=token_outra)['porPagina'] == 20

    # ─── Dispositivos IoT ────────────────────────────────────────────────────
    disp = api('/dispositivos', token=token)
    assert disp['podeEditar'] is True and len(disp['dispositivos']) == 2
    assert {t['valor'] for t in disp['tipos']} == {'rfid', 'biometria', 'hibrido', 'balanca'}
    assert [d['tipo'] for d in disp['desperdicioHoje']] == ['cafe', 'almoco', 'jantar', 'ceia']
    assert all('…' in d['token'] for d in disp['dispositivos'])          # token sempre mascarado na lista
    assert all(d['status'] == 'offline' for d in disp['dispositivos'])

    api('/dispositivos/criar', 422, {'nome': '', 'tipo': 'rfid'}, token)
    api('/dispositivos/criar', 422, {'nome': 'X', 'tipo': 'drone'}, token)
    api('/dispositivos/criar', 422, {'nome': 'X' * 151, 'tipo': 'rfid'}, token)
    novo = api('/dispositivos/criar', corpo={'nome': 'Catraca do App', 'tipo': 'rfid', 'localizacao': 'Portaria'}, token=token)
    assert len(novo['token']) == 64 and novo['dispositivo']['localizacao'] == 'Portaria'
    assert len(api('/dispositivos', token=token)['dispositivos']) == 3
    assert len(api('/dispositivos', token=token_outra)['dispositivos']) == 2   # outra empresa não vê

    # o token novo funciona na catraca; depois de gerar outro, o antigo deixa de valer
    req = urllib.request.Request(base + '/api/iot/ping', data=b'{}', headers={'Content-Type': 'application/json', 'X-Device-Token': novo['token']})
    assert sem_cookie.open(req).status == 200
    assert [d for d in api('/dispositivos', token=token)['dispositivos'] if d['id'] == novo['dispositivo']['id']][0]['status'] == 'online'
    trocado = api('/dispositivos/token', corpo={'id': novo['dispositivo']['id']}, token=token)
    assert trocado['token'] != novo['token'] and len(trocado['token']) == 64
    try:
        sem_cookie.open(urllib.request.Request(base + '/api/iot/ping', data=b'{}', headers={'Content-Type': 'application/json', 'X-Device-Token': novo['token']}))
        raise AssertionError('o token antigo ainda vale')
    except urllib.error.HTTPError as e:
        assert e.code == 401
    api('/dispositivos/token', 404, {'id': outra['rfid']['id']}, token)       # dispositivo de outra empresa
    api('/dispositivos/token', 404, {'id': 'abc'}, token)

    # simular leitura: cartão desconhecido é negado e vira alerta (se a notificação estiver ligada)
    api('/dispositivos/simular', 422, {'id': eu['balance']['id'], 'uid': '1234'}, token)
    api('/dispositivos/simular', 422, {'id': eu['rfid']['id'], 'uid': '   '}, token)
    api('/dispositivos/simular', 404, {'id': outra['rfid']['id'], 'uid': '1234'}, token)
    vivo = api('/aovivo', token=token)
    assert vivo['eventos'] == [] and vivo['novosAcessos'] == 0 and len(vivo['assinatura']) == 32
    sim = api('/dispositivos/simular', corpo={'id': eu['rfid']['id'], 'uid': 'de:ad be:ef'}, token=token)
    assert sim['uid'] == 'DEADBEEF' and sim['resultado']['autorizado'] is False
    depois = api('/aovivo?ultimo=%d' % vivo['ultimoLog'], token=token)
    assert depois['assinatura'] != vivo['assinatura'] and depois['novosAcessos'] == 1
    assert depois['eventos'][0]['resultado'] == 'negado'
    assert api('/aovivo?ultimo=%d' % depois['ultimoLog'], token=token)['eventos'] == []

    titulos = [a['titulo'] for a in api('/notificacoes', token=token)['alertas']]
    assert 'Acessos negados hoje' not in titulos, titulos                     # desligada acima
    api('/preferencias/notificacao', corpo={'chave': 'acesso_negado', 'ativo': True}, token=token)
    sino = api('/notificacoes', token=token)
    negados = [a for a in sino['alertas'] if a['titulo'] == 'Acessos negados hoje']
    assert len(negados) == 1 and negados[0]['link'] == '/relatorios' and sino['total'] == len(sino['alertas'])
    # o painel do app mostra a mesma lista do sino (antes só trazia os alertas de previsão)
    assert [a['titulo'] for a in api('/painel', token=token)['alertas']] == [a['titulo'] for a in sino['alertas']]
    assert 'Acessos negados hoje' not in [a['titulo'] for a in api('/notificacoes', token=token_outra)['alertas']]

    # pesagem
    api('/dispositivos/pesagem', 422, {'id': eu['rfid']['id'], 'tipo': 'almoco', 'desperdicio_kg': 1}, token)
    api('/dispositivos/pesagem', 422, {'id': eu['balance']['id'], 'tipo': 'lanche', 'desperdicio_kg': 1}, token)
    api('/dispositivos/pesagem', 422, {'id': eu['balance']['id'], 'tipo': 'almoco', 'desperdicio_kg': -1}, token)
    api('/dispositivos/pesagem', 404, {'id': outra['balance']['id'], 'tipo': 'almoco', 'desperdicio_kg': 1}, token)
    p = api('/dispositivos/pesagem', corpo={'id': eu['balance']['id'], 'tipo': 'almoco', 'desperdicio_kg': 3.25}, token=token)
    assert p['desperdicio_kg'] == 3.25 and p['mensagem']
    hoje_kg = {d['tipo']: d['kg'] for d in api('/dispositivos', token=token)['desperdicioHoje']}
    assert hoje_kg['almoco'] == 3.25 and hoje_kg['cafe'] is None

    # ─── Planilha ────────────────────────────────────────────────────────────
    modelo = api('/pessoas/modelo', token=token, bruto=True)
    assert modelo.lstrip('﻿').startswith('nome;cargo;categoria;matricula;cartao')
    api('/pessoas/importar', 422, {'csv': ''}, token)
    api('/pessoas/importar', 422, {'csv': 'a' * (1024 * 1024 + 1)}, token)
    csv = 'nome;cargo;categoria;matricula;cartao\nAna Teste;Cozinheira;operador;9001;\nBeto Teste;Supervisor;supervisor;9002;APP0001\n;Sem nome;;;\n'
    imp = api('/pessoas/importar', corpo={'csv': csv}, token=token)
    assert imp['pessoas'] == 2 and imp['cartoes'] == 1 and imp['ignoradas'] == 1 and imp['erros'][0]['linha'] == 4, imp
    cad = api('/pessoas/cadastro', token=token)
    assert {'Ana Teste', 'Beto Teste'} <= {p['nome'] for p in cad['pessoas']}

    # ─── Sessões do site ─────────────────────────────────────────────────────
    pagina, _ = web('/login')
    csrf = re.search(r'name="csrf_token" value="([^"]+)"', pagina)[1]
    web('/login', {'email': eu['email'], 'senha': eu['senha'], 'csrf_token': csrf})
    _, url = web('/configuracoes')
    assert url.endswith('/configuracoes')
    ses = api('/sessoes', token=token)
    assert ses['disponivel'] is True and len(ses['sessoes']) == 1
    assert set(ses['sessoes'][0]) == {'id', 'dispositivo', 'celular', 'ip', 'ultimo_acesso'}
    api('/sessoes/encerrar', 404, {'id': 999999}, token)
    api('/sessoes/encerrar', 404, {'id': ses['sessoes'][0]['id']}, token_outra)   # sessão de outra conta
    assert api('/sessoes/encerrar', corpo={'id': ses['sessoes'][0]['id']}, token=token)['encerradas'] == 1
    _, url = web('/configuracoes')
    assert '/login' in url, url                                               # o site foi derrubado
    pagina, _ = web('/login')
    csrf = re.search(r'name="csrf_token" value="([^"]+)"', pagina)[1]
    web('/login', {'email': eu['email'], 'senha': eu['senha'], 'csrf_token': csrf})
    assert api('/sessoes/encerrar', corpo={'todas': True}, token=token)['encerradas'] == 1
    assert api('/sessoes', token=token)['sessoes'] == []

    # ─── Perfil, senha e foto ────────────────────────────────────────────────
    api('/perfil', 422, {'nome': '', 'email': eu['email']}, token)
    api('/perfil', 422, {'nome': 'X', 'email': 'nao-e-email'}, token)
    api('/perfil', 422, {'nome': ['lista'], 'email': eu['email']}, token)
    r = api('/perfil', corpo={'nome': 'Fulana do App', 'email': eu['email'], 'cargo': 'Gestora'}, token=token)
    assert r['usuario']['nome'] == 'Fulana do App' and r['usuario']['cargo'] == 'Gestora'
    assert api('/me', token=token)['usuario']['nome'] == 'Fulana do App'
    novo_email = 'app-' + eu['email']
    api('/perfil', 422, {'nome': 'Fulana do App', 'email': novo_email}, token)                    # sem a senha
    api('/perfil', 403, {'nome': 'Fulana do App', 'email': novo_email, 'senha_atual': 'errada'}, token)
    api('/perfil', 409, {'nome': 'Fulana do App', 'email': outra['email'], 'senha_atual': eu['senha']}, token)
    r = api('/perfil', corpo={'nome': 'Fulana do App', 'email': novo_email, 'senha_atual': eu['senha']}, token=token)
    assert r['usuario']['email'] == novo_email
    eu['email'] = novo_email

    api('/perfil/senha', 422, {'senha_atual': eu['senha'], 'senha_nova': 'curta', 'senha_confirmar': 'curta'}, token)
    api('/perfil/senha', 422, {'senha_atual': eu['senha'], 'senha_nova': 'novaSenha123', 'senha_confirmar': 'outra123456'}, token)
    api('/perfil/senha', 403, {'senha_atual': 'errada', 'senha_nova': 'novaSenha123', 'senha_confirmar': 'novaSenha123'}, token)
    api('/perfil/senha', 422, {'senha_atual': eu['senha'], 'senha_nova': eu['senha'], 'senha_confirmar': eu['senha']}, token)
    assert 'usuario' not in api('/perfil/senha', corpo={'senha_atual': eu['senha'], 'senha_nova': 'novaSenha123', 'senha_confirmar': 'novaSenha123'}, token=token)
    api('/login', 401, {'email': eu['email'], 'senha': eu['senha']})
    token = entrar(eu['email'], 'novaSenha123')
    eu['senha'] = 'novaSenha123'

    api('/perfil/foto', 422, {}, token)
    api('/perfil/foto', 422, {'imagem': '%%%não é base64%%%'}, token)
    api('/perfil/foto', 422, {'imagem': base64.b64encode(b'isto nao e uma imagem').decode()}, token)
    api('/perfil/foto', 422, {'imagem': 'A' * (8 * 1024 * 1024)}, token)
    foto = api('/perfil/foto', corpo={'imagem': 'data:image/png;base64,' + base64.b64encode(PNG).decode()}, token=token)['usuario']['foto']
    assert re.search(r'/uploads/perfil/perfil_[0-9a-f]{24}\.png$', foto), foto
    caminho_foto = Path(__file__).resolve().parent.parent / 'public' / foto.split('/', 3)[3]
    assert caminho_foto.read_bytes() == PNG
    foto2 = api('/perfil/foto', corpo={'imagem': base64.b64encode(PNG).decode()}, token=token)['usuario']['foto']
    assert foto2 != foto and not caminho_foto.exists()                         # a anterior é apagada do disco
    caminho_foto = Path(__file__).resolve().parent.parent / 'public' / foto2.split('/', 3)[3]

    # ─── Usuário comum: consulta, mas não gerencia ───────────────────────────
    subprocess.run(['php', fixture, 'role', eu['uid']], check=True)
    assert api('/dispositivos', token=token)['podeEditar'] is False
    api('/dispositivos/criar', 403, {'nome': 'X', 'tipo': 'rfid'}, token)
    api('/dispositivos/simular', 403, {'id': eu['rfid']['id'], 'uid': '1234'}, token)
    api('/dispositivos/pesagem', 403, {'id': eu['balance']['id'], 'tipo': 'almoco', 'desperdicio_kg': 1}, token)
    api('/pessoas/importar', 403, {'csv': csv}, token)
    api('/preferencias/notificacao', corpo={'chave': 'previsao_demanda', 'ativo': False}, token=token)   # preferência é de cada um
    api('/perfil', corpo={'nome': 'Fulana Comum', 'email': eu['email']}, token=token)

    caminho_foto.unlink(missing_ok=True)
    print(f'PASS: {contador} requisições; perfil, senha, foto, preferências, sessões, sino, tempo real, dispositivos e planilha.')
finally:
    for r in registros:
        subprocess.run(['php', fixture, 'delete', r['uid'], r['company']], check=True)
