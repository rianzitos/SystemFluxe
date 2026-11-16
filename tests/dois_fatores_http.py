"""Teste local da verificação em duas etapas, de ponta a ponta, no site e na API do aplicativo.

Cobre: escolha no cadastro (fica pendente e o primeiro login leva à Segurança), ativação pelo aplicativo autenticador
(QR Code + código TOTP calculado aqui, independente do PHP) e por e-mail, login do site e do app com o código, o mesmo
código não entra duas vezes, códigos de recuperação (uso único), limite de tentativas, bloqueio da conta, reenvio,
app antigo (sem "duas_etapas") recebe a explicação, desativar e gerar novos códigos (com a senha), avisos por e-mail.

Precisa de: PHP no PATH, banco migrado até a 008 (config/.env), MAIL_DRIVER=log no config/.env e o servidor rodando
em SICAPDA_TEST_URL (padrão http://127.0.0.1:8000):
    php -S 127.0.0.1:8000 -t public app/routes/web.php
Cria e apaga uma empresa temporária. Leva cerca de 1 minuto (espera o código do aplicativo mudar). Não usar em produção.
Executar: python3 tests/dois_fatores_http.py
"""
import base64
import email
import email.policy
import hashlib
import hmac
import http.cookiejar
import json
import os
import re
import secrets
import struct
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

raiz = Path(__file__).resolve().parent.parent
pasta_emails = raiz / 'storage' / 'emails'
base = os.getenv('SICAPDA_TEST_URL', 'http://127.0.0.1:8000')
contador = 0


class SemRedirecionar(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def env(chave):
    for linha in (raiz / 'config' / '.env').read_text().splitlines():
        if linha.startswith(chave + '='):
            return linha.split('=', 1)[1].strip().strip('"')
    return ''


assert env('MAIL_DRIVER') == 'log', 'Rode este teste com MAIL_DRIVER=log no config/.env'


def php(*args):
    return subprocess.run(['php', *args], cwd=raiz, check=True, capture_output=True, text=True).stdout


def fixture(*args):
    return json.loads(php('tests/dois_fatores_fixture.php', *map(str, args)) or 'null')


vistos = set(p.name for p in pasta_emails.glob('*.eml')) if pasta_emails.exists() else set()


def novos_emails():
    if not pasta_emails.exists():
        return []
    saida = []
    for p in sorted(pasta_emails.glob('*.eml')):
        if p.name in vistos:
            continue
        vistos.add(p.name)
        msg = email.message_from_bytes(p.read_bytes(), policy=email.policy.default)
        saida.append({'para': str(msg['To']), 'assunto': str(msg['Subject']),
                      'texto': msg.get_body(('plain',)).get_content(), 'html': msg.get_body(('html',)).get_content()})
    return saida


def pedir(caminho, dados=None, cabecalhos=None, abridor=None, status=200, metodo=None):
    global contador
    corpo = None
    cab = dict(cabecalhos or {})
    if dados is not None:
        if cab.get('Content-Type') == 'application/json':
            corpo = json.dumps(dados).encode()
        else:
            cab.setdefault('Content-Type', 'application/x-www-form-urlencoded')
            corpo = urllib.parse.urlencode(dados).encode()
    req = urllib.request.Request(base + caminho, data=corpo, headers=cab, method=metodo)
    try:
        resp = (abridor or urllib.request.build_opener(SemRedirecionar())).open(req)
    except urllib.error.HTTPError as e:
        resp = e
    texto = resp.read().decode('utf-8')
    assert resp.status == status, (caminho, resp.status, status, texto[:500])
    contador += 1
    return texto, resp


def api(caminho, dados=None, status=200, token=None, metodo=None):
    cab = {'Content-Type': 'application/json', 'Accept': 'application/json'}
    if token:
        cab['Authorization'] = 'Bearer ' + token
    texto, _ = pedir('/api' + caminho, dados, cab, status=status, metodo=metodo)
    return json.loads(texto)


def navegador():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), SemRedirecionar())


def csrf(html):
    m = re.search(r'name="csrf_token" value="([a-f0-9]{64})"', html) or re.search(r'name="csrf-token" content="([a-f0-9]{64})"', html)
    return m.group(1)


# ─── TOTP calculado aqui (RFC 6238), sem usar o código PHP ────────────────────

def totp(segredo, passo):
    chave = base64.b32decode(segredo.replace(' ', ''))
    h = hmac.new(chave, struct.pack('>Q', passo), hashlib.sha1).digest()
    o = h[19] & 0x0F
    return f'{(int.from_bytes(h[o:o + 4], "big") & 0x7FFFFFFF) % 1000000:06d}'


def passo_atual():
    return int(time.time()) // 30


def esperar_proximo_passo():
    """O mesmo código não entra duas vezes: para o próximo login, espera o aplicativo "virar" o código."""
    alvo = (passo_atual() + 1) * 30
    time.sleep(max(0, alvo - time.time()) + 0.3)


# ─── Site ────────────────────────────────────────────────────────────────────

def login_site(email_, senha, status_esperado=302):
    nav = navegador()
    html, _ = pedir('/login', abridor=nav)
    _, resp = pedir('/login', {'email': email_, 'senha': senha, 'csrf_token': csrf(html)}, abridor=nav, status=status_esperado)
    return nav, resp.headers.get('Location')


def config_csrf(nav):
    html, _ = pedir('/configuracoes', abridor=nav)
    return html, csrf(html)


def dfa(nav, token_csrf, acao, dados=None, status=200):
    texto, _ = pedir('/configuracoes/duas-etapas/' + acao, {**(dados or {}), 'csrf_token': token_csrf},
                     {'Accept': 'application/json'}, abridor=nav, status=status)
    return json.loads(texto)


def verificar_site(nav, codigo, status=302, recuperacao=False):
    html, _ = pedir('/login/verificacao', abridor=nav)
    dados = {'codigo': codigo, 'csrf_token': csrf(html)}
    if recuperacao:
        dados['recuperacao'] = '1'
    texto, resp = pedir('/login/verificacao', dados, abridor=nav, status=status)
    return texto, resp.headers.get('Location')


def logado(nav):
    req = urllib.request.Request(base + '/painel')
    try:
        r = nav.open(req)
    except urllib.error.HTTPError as e:
        r = e
    r.read()
    return r.status == 200


empresa = None
marca = secrets.token_hex(4)
admin_email = f'dfa-{marca}@example.com'
senha = 'SenhaForte123'
try:
    novos_emails()

    # ─── Cadastro com a verificação escolhida: fica pendente, sem trancar ninguém ────
    nav = navegador()
    html, _ = pedir('/cadastro', abridor=nav)
    assert 'value="sms"' not in html and 'value="app"' in html and 'value="email"' in html   # SMS saiu (não há provedor)
    cnpj = str(10**13 + secrets.randbelow(10**13))
    cadastro = {
        'csrf_token': csrf(html), 'admNome': 'Diana Duas Etapas', 'admCpf': str(10**10 + secrets.randbelow(10**10)),
        'admTelefone': '19999990000', 'admEmail': admin_email, 'admCargo': 'Gerente', 'senha': senha,
        'confirmaSenha': senha, 'razaoSocial': 'Escola 2FA LTDA', 'nomeFantasia': 'Escola 2FA ' + marca,
        'cnpj': cnpj, 'segmento': 'Educação', 'qtdColab': '50', 'endereco': 'Rua A, 1', 'cep': '13730000',
        'cidade': 'Mococa', 'estado': 'SP', 'telEmpresa': '1935550000', 'horarioFunc': '07h às 17h',
        'responsavel': 'Diana', 'emailInst': admin_email, 'aceiteTermos': '1', 'refeitorio': 'nao',
        'fa2': 'sim', 'fa2metodo': 'app',
    }
    texto, _ = pedir('/cadastro', cadastro, abridor=nav)
    assert json.loads(texto)['sucesso'], texto
    empresa = int(php('tests/emails_fixture.php', 'empresa-por-cnpj', cnpj))
    u = fixture('usuario', admin_email)
    uid = u['id']
    assert u['dois_fatores_habilitado'] == 0 and u['dois_fatores_metodo'] == 'app' and u['dois_fatores_ativado_em'] is None
    novos_emails()

    # Primeiro login: só a senha (nada configurado ainda) e leva direto a Configurações → Segurança
    nav, destino = login_site(admin_email, senha)
    assert destino == '/configuracoes#seguranca', destino
    html, token_csrf = config_csrf(nav)
    assert 'id="bloco2fa"' in html and 'Em breve' not in html.split('id="bloco2fa"')[1].split('Sessões ativas')[0]
    estado = json.loads(re.search(r'data-estado="([^"]+)"', html).group(1).replace('&quot;', '"'))
    assert estado['disponivel'] and not estado['ativa'] and estado['pendente'] == 'app', estado

    # "Agora não": some o convite e o login volta a ir direto para o painel
    r = dfa(nav, token_csrf, 'dispensar')
    assert r['estado']['pendente'] is None and fixture('usuario', admin_email)['dois_fatores_metodo'] is None
    _, destino = login_site(admin_email, senha)
    assert destino == '/painel', destino

    # ─── Ativar pelo aplicativo autenticador ─────────────────────────────────
    assert dfa(nav, token_csrf, 'iniciar', {'metodo': 'sms'}, 422)['sucesso'] is False
    r = dfa(nav, token_csrf, 'iniciar', {'metodo': 'app'})
    assert r['qr'].startswith('<svg') and 'viewBox' in r['qr'] and '<script' not in r['qr']
    assert r['uri'].startswith('otpauth://totp/SICAPDA:') and 'issuer=SICAPDA' in r['uri']
    segredo = r['chave'].replace(' ', '')
    assert len(segredo) == 32 and f'secret={segredo}' in r['uri']
    guardado = fixture('usuario', admin_email)['dois_fatores_segredo']
    assert guardado.startswith('v1:') and segredo not in guardado      # cifrado no banco
    assert fixture('usuario', admin_email)['dois_fatores_habilitado'] == 0   # ainda não vale para o login

    # Senha errada, código errado, código certo
    codigo = totp(segredo, passo_atual())
    r = dfa(nav, token_csrf, 'ativar', {'metodo': 'app', 'codigo': codigo, 'senha_atual': 'errada'}, 403)
    assert r['mensagem'] == 'Senha atual incorreta.'
    errado = f'{(int(codigo) + 1) % 1000000:06d}'
    r = dfa(nav, token_csrf, 'ativar', {'metodo': 'app', 'codigo': errado, 'senha_atual': senha}, 422)
    assert r['mensagem'].startswith('Código incorreto')
    r = dfa(nav, token_csrf, 'ativar', {'metodo': 'app', 'codigo': codigo[:3] + ' ' + codigo[3:], 'senha_atual': senha})
    assert r['sucesso'] and len(r['codigos']) == 10 and len(set(r['codigos'])) == 10
    assert all(re.fullmatch(r'[2-9A-HJ-NP-Z]{5}-[2-9A-HJ-NP-Z]{5}', c) for c in r['codigos']), r['codigos']
    assert r['estado']['ativa'] and r['estado']['metodo'] == 'app' and r['estado']['codigos_restantes'] == 10
    assert r['estado']['pendente'] is None
    recuperacao = r['codigos']
    rec = fixture('recuperacao', uid)
    assert int(rec['total']) == 10 and int(rec['usados'] or 0) == 0 and int(rec['tamanho']) == 64   # só o SHA-256
    [aviso] = novos_emails()
    assert aviso['assunto'].startswith('Verificação em duas etapas ativada') and admin_email in aviso['para']
    assert dfa(nav, token_csrf, 'iniciar', {'metodo': 'app'}, 409)['sucesso'] is False      # já ativa
    passo_ativacao = fixture('usuario', admin_email)['dois_fatores_ultimo_passo']

    # ─── Login do site com o código do aplicativo ────────────────────────────
    nav, destino = login_site(admin_email, senha)
    assert destino == '/login/verificacao', destino
    assert not logado(nav)                                    # senha certa ainda não abre a sessão
    html, _ = pedir('/login/verificacao', abridor=nav)
    assert 'aplicativo autenticador' in html and 'autocomplete="one-time-code"' in html and 'código de recuperação' in html
    assert 'Reenviar código' not in html                       # no aplicativo não há o que reenviar

    texto, _ = verificar_site(nav, '000000' if codigo != '000000' else '111111', status=200)
    assert 'Código incorreto. Restam 4 tentativas.' in texto
    proximo = totp(segredo, passo_ativacao + 1)                # o seguinte ao usado na ativação (dentro da janela)
    _, destino = verificar_site(nav, proximo)
    assert destino == '/painel', destino
    assert logado(nav)

    # O mesmo código não entra duas vezes
    nav2, _ = login_site(admin_email, senha)
    texto, _ = verificar_site(nav2, proximo, status=200)
    assert 'Código incorreto' in texto and not logado(nav2)

    # 5 erros (o primeiro foi o código repetido acima): volta ao login e precisa recomeçar
    for i in range(3):
        texto, _ = verificar_site(nav2, '999999' if proximo != '999999' else '888888', status=200)
    texto, destino = verificar_site(nav2, '999999' if proximo != '999999' else '888888')
    assert destino == '/login', destino
    html, _ = pedir('/login', abridor=nav2)
    assert 'errou o código 5 vezes' in html
    _, destino = pedir('/login/verificacao', abridor=nav2, status=302)
    fixture('limpar-desafios', uid)

    # ─── Código de recuperação (uso único) ───────────────────────────────────
    nav3, _ = login_site(admin_email, senha)
    _, destino = verificar_site(nav3, recuperacao[0].lower().replace('-', ' '), recuperacao=True)
    assert destino == '/painel' and logado(nav3)
    [aviso] = novos_emails()
    assert 'código de recuperação foi usado' in aviso['assunto'] and 'Restam 9' in aviso['texto']
    nav4, _ = login_site(admin_email, senha)
    texto, _ = verificar_site(nav4, recuperacao[0], status=200, recuperacao=True)
    assert 'Código incorreto' in texto
    assert int(fixture('recuperacao', uid)['usados']) == 1
    fixture('limpar-desafios', uid)

    # ─── API do aplicativo ───────────────────────────────────────────────────
    # App antigo (sem "duas_etapas"): explica em vez de devolver um token
    r = api('/login', {'email': admin_email, 'senha': senha}, 403)
    assert 'Atualize o aplicativo' in r['mensagem']
    assert api('/login', {'email': admin_email, 'senha': 'errada', 'duas_etapas': True}, 401)['mensagem'] == 'E-mail ou senha inválidos.'

    r = api('/login', {'email': admin_email, 'senha': senha, 'duas_etapas': True})
    assert r['duas_etapas'] is True and r['metodo'] == 'app' and 'token' not in r and re.fullmatch(r'[a-f0-9]{64}', r['desafio'])
    desafio = r['desafio']
    assert api('/me', status=401)['sucesso'] is False
    r = api('/login/verificar', {'desafio': desafio, 'codigo': '12345'}, 422)
    assert 'Restam 4' in r['mensagem']
    assert api('/login/verificar', {'desafio': 'f' * 64, 'codigo': '123456'}, 410)['sucesso'] is False
    assert api('/login/reenviar', {'desafio': desafio}, 409)['sucesso'] is False     # app: nada a reenviar

    esperar_proximo_passo()
    codigo_app = totp(segredo, passo_atual() + 1)   # seguinte ao último aceito, dentro da janela de ±30 s
    r = api('/login/verificar', {'desafio': desafio, 'codigo': codigo_app})
    token = r['token']
    assert r['usuario']['email'] == admin_email
    assert api('/me', token=token)['usuario']['id'] == uid
    assert api('/login/verificar', {'desafio': desafio, 'codigo': codigo_app}, 410)['sucesso'] is False  # já usado

    # Configurações pelo app: estado, novos códigos, desativar (com a senha)
    e = api('/duas-etapas', token=token)
    assert e['ativa'] and e['metodo'] == 'app' and e['codigos_restantes'] == 9 and e['metodo_rotulo'] == 'Aplicativo autenticador'
    assert api('/duas-etapas/codigos', {'senha_atual': 'errada'}, 403, token=token)['mensagem'] == 'Senha atual incorreta.'
    r = api('/duas-etapas/codigos', {'senha_atual': senha}, token=token)
    assert len(r['codigos']) == 10 and r['estado']['codigos_restantes'] == 10 and recuperacao[1] not in r['codigos']
    novos_emails()
    nav5, _ = login_site(admin_email, senha)
    texto, _ = verificar_site(nav5, recuperacao[1], status=200, recuperacao=True)   # os antigos não valem mais
    assert 'Código incorreto' in texto
    fixture('limpar-desafios', uid)

    r = api('/duas-etapas/desativar', {'senha_atual': senha}, token=token)
    assert r['estado']['ativa'] is False and r['estado']['pendente'] is None
    [aviso] = novos_emails()
    assert aviso['assunto'].startswith('Verificação em duas etapas desativada')
    u = fixture('usuario', admin_email)
    assert u['dois_fatores_segredo'] is None and u['dois_fatores_habilitado'] == 0 and int(fixture('recuperacao', uid)['total']) == 0
    nav, destino = login_site(admin_email, senha)
    assert destino == '/painel'                                 # de volta a só senha
    assert 'token' in api('/login', {'email': admin_email, 'senha': senha})   # e o app antigo entra de novo

    # ─── Código por e-mail (pela API) ────────────────────────────────────────
    r = api('/duas-etapas/iniciar', {'metodo': 'email'}, token=token)
    assert '•••@example.com' in r['mensagem']
    [envio] = novos_emails()
    assert envio['assunto'].endswith('é o código para ativar a verificação em duas etapas') and admin_email in envio['para']
    codigo_email = re.search(r'Código: (\d{3}) (\d{3})', envio['texto'])
    codigo_email = codigo_email.group(1) + codigo_email.group(2)
    assert api('/duas-etapas/iniciar', {'metodo': 'email'}, 429, token=token)['mensagem'].startswith('Aguarde')
    r = api('/duas-etapas/ativar', {'metodo': 'email', 'codigo': '000000' if codigo_email != '000000' else '111111', 'senha_atual': senha}, 422, token=token)
    assert 'Restam 4' in r['mensagem']
    r = api('/duas-etapas/ativar', {'metodo': 'email', 'codigo': codigo_email, 'senha_atual': senha}, token=token)
    assert r['estado']['ativa'] and r['estado']['metodo'] == 'email' and len(r['codigos']) == 10
    novos_emails()

    # Login do app com o código do e-mail, e o reenvio
    r = api('/login', {'email': admin_email, 'senha': senha, 'duas_etapas': True})
    assert r['metodo'] == 'email' and r['destino'].endswith('•••@example.com')
    desafio = r['desafio']
    [primeiro] = novos_emails()
    assert primeiro['assunto'].endswith('é o seu código de acesso ao SICAPDA')
    assert re.search(r'\d{3} \d{3}', primeiro['html'])
    assert api('/login/reenviar', {'desafio': desafio}, 429)['mensagem'].startswith('Aguarde')
    fixture('atrasar-envio', uid)
    assert 'código novo' in api('/login/reenviar', {'desafio': desafio})['mensagem']
    [segundo] = novos_emails()
    velho, novo = primeiro['assunto'][:6], segundo['assunto'][:6]
    if velho != novo:
        assert api('/login/verificar', {'desafio': desafio, 'codigo': velho}, 422)['sucesso'] is False   # o anterior caiu
    r = api('/login/verificar', {'desafio': desafio, 'codigo': novo})
    assert r['token'] and r['usuario']['id'] == uid

    # Login do site com o código do e-mail
    nav, destino = login_site(admin_email, senha)
    assert destino == '/login/verificacao'
    html, _ = pedir('/login/verificacao', abridor=nav)
    assert '•••@example.com' in html and 'Reenviar código' in html
    [envio] = novos_emails()
    _, destino = verificar_site(nav, envio['assunto'][:6])
    assert destino == '/painel' and logado(nav)
    fixture('limpar-desafios', uid)

    # ─── Bloqueio da conta: 10 erros em 15 minutos ───────────────────────────
    for rodada in range(2):
        r = api('/login', {'email': admin_email, 'senha': senha, 'duas_etapas': True})
        for i in range(4):
            api('/login/verificar', {'desafio': r['desafio'], 'codigo': '000000'}, 422)
        api('/login/verificar', {'desafio': r['desafio'], 'codigo': '000000'}, 410)
    novos_emails()
    assert 'aguarde 15 minutos' in api('/login', {'email': admin_email, 'senha': senha, 'duas_etapas': True}, 429)['mensagem']
    _, destino = login_site(admin_email, senha)
    assert destino == '/login'
    assert all(d['tamanho'] == 64 for d in fixture('desafios', uid))   # só o hash do identificador fica no banco
    fixture('limpar-desafios', uid)

    # ─── Desativar pelo site (com CSRF e a senha) ───────────────────────────
    nav, _ = login_site(admin_email, senha)
    [envio] = novos_emails()
    verificar_site(nav, envio['assunto'][:6])
    _, token_csrf = config_csrf(nav)
    pedir('/configuracoes/duas-etapas/desativar', {'senha_atual': senha}, {'Accept': 'application/json'}, abridor=nav, status=403)  # sem CSRF
    assert dfa(nav, token_csrf, 'desativar', {'senha_atual': 'errada'}, 403)['mensagem'] == 'Senha atual incorreta.'
    r = dfa(nav, token_csrf, 'desativar', {'senha_atual': senha})
    assert not r['estado']['ativa'] and r['mensagem'].startswith('Verificação em duas etapas desativada')
    assert dfa(nav, token_csrf, 'desativar', {'senha_atual': senha}, 409)['sucesso'] is False
    novos_emails()
    _, destino = login_site(admin_email, senha)
    assert destino == '/painel'

    print(f'OK: verificação em duas etapas ({contador} requisições)')
finally:
    if empresa:
        php('tests/emails_fixture.php', 'limpar-empresa', str(empresa))
