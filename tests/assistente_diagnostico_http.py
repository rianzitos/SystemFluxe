"""Teste local do diagnóstico da IA pelo aplicativo (GET/POST /api/assistente/diagnostico, fluxe_app 1.4).

Confere que só o administrador vê, que a configuração vem no formato que o app lê (rotulo, valor, estado), que a
chave nunca aparece inteira e que o teste para na primeira etapa que falha, com o motivo explicado.

Precisa de: PHP no PATH, banco migrado (config/.env) e o servidor rodando em SICAPDA_TEST_URL
(padrão http://127.0.0.1:8000):  php -S 127.0.0.1:8000 -t public app/routes/web.php
Roda melhor SEM IA_API_KEY no config/.env (assim o teste não gasta a cota da IA): a etapa 1 falha explicando
que a chave não foi lida. Cria e apaga uma empresa temporária (tests/balanca_fixture.php). Não usar em produção.
Executar: python3 tests/assistente_diagnostico_http.py   (com SALVAR_JSON=pasta, grava as respostas para os
testes do aplicativo)
"""
import json
import os
import subprocess
import urllib.error
import urllib.request
from pathlib import Path

raiz = Path(__file__).resolve().parent.parent
base = os.getenv('SICAPDA_TEST_URL', 'http://127.0.0.1:8000')
contador = 0


def php(*args):
    return subprocess.run(['php', *map(str, args)], cwd=raiz, check=True, capture_output=True, text=True).stdout


def api(caminho, status=200, corpo=None, token=None, metodo=None):
    global contador
    cab = {'Accept': 'application/json'}
    dados = None
    if corpo is not None:
        cab['Content-Type'] = 'application/json'
        dados = json.dumps(corpo).encode()
    if token:
        cab['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(base + caminho, data=dados, headers=cab, method=metodo)
    contador += 1
    try:
        with urllib.request.urlopen(req, timeout=200) as r:
            codigo, texto = r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        codigo, texto = e.code, e.read().decode()
    assert codigo == status, f'{caminho}: esperado {status}, veio {codigo}: {texto[:300]}'
    return json.loads(texto)


def salvar(nome, dados):
    pasta = os.getenv('SALVAR_JSON')
    if pasta:
        Path(pasta, nome).write_text(json.dumps(dados, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')


emp = json.loads(php('tests/balanca_fixture.php'))
try:
    token = api('/api/login', corpo={'email': emp['email'], 'senha': emp['senha']})['token']

    # GET: configuração, sem chamar a IA
    g = api('/api/assistente/diagnostico', token=token)
    assert isinstance(g['ia'], bool)
    rotulos = [l['rotulo'] for l in g['configuracao']]
    for esperado in ('PHP', 'Extensão cURL', 'IA_API_KEY', 'IA_MODEL', 'IA ligada no assistente'):
        assert esperado in rotulos, rotulos
    assert all(l['estado'] in ('ok', 'aviso', 'erro') and isinstance(l['valor'], str) for l in g['configuracao'])
    env = (raiz / 'config' / '.env').read_text(encoding='utf-8') if (raiz / 'config' / '.env').exists() else ''
    chave = next((l.split('=', 1)[1].strip().strip('"') for l in env.splitlines() if l.startswith('IA_API_KEY=')), '')
    if len(chave) > 8:
        assert chave not in json.dumps(g), 'a chave da IA apareceu inteira'
    assert 'etapas' not in g
    salvar('diagnostico_ia.json', g)

    # POST: o teste passo a passo
    p = api('/api/assistente/diagnostico', corpo={}, token=token)
    assert p['etapas'], p
    for e in p['etapas']:
        assert set(e) >= {'titulo', 'ok', 'detalhe', 'ms'}, e
        assert isinstance(e['ok'], bool) and isinstance(e['ms'], int)
    falhou = [e for e in p['etapas'] if not e['ok']]
    assert p['tudoCerto'] == (not falhou)
    if falhou:
        assert p['etapas'][-1] is falhou[0] or p['etapas'][-1] == falhou[0], 'deveria parar na primeira que falhou'
        assert falhou[0]['detalhe'], 'a falha precisa vir explicada'
    if not g['ia']:
        assert p['etapas'][0]['ok'] is False and 'chave' in p['etapas'][0]['detalhe'], p['etapas'][0]
    salvar('diagnostico_ia_teste.json', p)

    # Método errado e quem não é administrador
    api('/api/assistente/diagnostico', 405, corpo={}, token=token, metodo='PUT')
    php('tests/balanca_fixture.php', 'role', emp['uid'])
    negado = api('/api/assistente/diagnostico', 403, token=token)
    assert 'administrador' in negado['mensagem']
    api('/api/assistente/diagnostico', 403, corpo={}, token=token)

    # Sem token
    api('/api/assistente/diagnostico', 401)
finally:
    php('tests/balanca_fixture.php', 'delete', emp['uid'], emp['company'])

print(f'PASS: {contador} requisições; diagnóstico da IA pelo app (configuração, teste, só administrador).')
