"""Teste local dos e-mails automáticos: Contato das páginas públicas, boas-vindas do cadastro, "Esqueceu a senha?"
(site e aplicativo), aviso de senha alterada, alerta de desperdício alto (balança) e o resumo diário (tarefa agendada).

Precisa de: PHP no PATH, banco migrado até a 007 (config/.env), MAIL_DRIVER=log e CRON_SECRET no config/.env, e o
servidor rodando em SICAPDA_TEST_URL (padrão http://127.0.0.1:8000):
    php -S 127.0.0.1:8000 -t public app/routes/web.php
Os e-mails são lidos de storage/emails/ (MAIL_DRIVER=log). Cria e apaga empresas temporárias. Não usar em produção.
Executar: python3 tests/emails_http.py
"""
import email
import email.policy
import http.cookiejar
import json
import os
import re
import secrets
import subprocess
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
CRON = env('CRON_SECRET')
assert len(CRON) >= 32, 'Defina CRON_SECRET (32+ caracteres) no config/.env'


def php(*args):
    return subprocess.run(['php', *args], cwd=raiz, check=True, capture_output=True, text=True).stdout


def fixture(*args):
    return php('tests/emails_fixture.php', *map(str, args))


vistos = set(p.name for p in pasta_emails.glob('*.eml')) if pasta_emails.exists() else set()


def novos_emails():
    """E-mails gravados desde a última chamada: [{para, assunto, texto, html, reply_to}]."""
    if not pasta_emails.exists():
        return []
    saida = []
    for p in sorted(pasta_emails.glob('*.eml')):
        if p.name in vistos:
            continue
        vistos.add(p.name)
        msg = email.message_from_bytes(p.read_bytes(), policy=email.policy.default)
        saida.append({
            'para': str(msg['To']), 'assunto': str(msg['Subject']), 'reply_to': str(msg['Reply-To'] or ''),
            'texto': msg.get_body(('plain',)).get_content(), 'html': msg.get_body(('html',)).get_content(),
        })
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
    assert resp.status == status, (caminho, resp.status, status, texto[:400])
    contador += 1
    return texto, resp


def contato(dados, status=200, origem=base, json_=True):
    cab = {'Origin': origem} if origem else {}
    if json_:
        cab['Accept'] = 'application/json'
    texto, resp = pedir('/contato', dados, cab, status=status)
    return (json.loads(texto) if json_ else resp)


def navegador():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), SemRedirecionar())


def csrf(html):
    return re.search(r'name="csrf_token" value="([a-f0-9]{64})"', html).group(1)


empresas = []
marca = secrets.token_hex(4)
email_contato = f'visitante-{marca}@example.com'
try:
    novos_emails()

    # ─── Contato (páginas / e /sicapda) ──────────────────────────────────────
    for pagina, origem in (('/', 'fluxe'), ('/sicapda', 'sicapda')):
        html, _ = pedir(pagina)
        assert 'action="/contato"' in html and f'name="origem" value="{origem}"' in html and 'contato.js' in html, pagina

    base_msg = {'nome': 'Ana Teste', 'email': email_contato, 'mensagem': 'Quero conhecer o SICAPDA para a minha escola.', 'origem': 'sicapda'}
    assert contato(base_msg, 403, origem='https://outro-site.com')['sucesso'] is False      # outro site
    assert contato(base_msg, 403, origem=None)['sucesso'] is False                           # sem Origin/Referer
    assert contato({**base_msg, 'email': 'nao-e-email'}, 422)['mensagem'].startswith('Informe um e-mail')
    assert contato({**base_msg, 'mensagem': 'curta'}, 422)['sucesso'] is False
    assert contato({**base_msg, 'telefone': 'abc'}, 422)['sucesso'] is False
    assert novos_emails() == []

    # Robô (campo isca preenchido): responde sucesso, mas não guarda nem envia nada
    assert contato({**base_msg, 'empresa_site': 'http://spam'})['sucesso'] is True
    assert novos_emails() == [] and json.loads(fixture('contatos', email_contato)) == []

    r = contato({**base_msg, 'telefone': '(19) 99999-0000'})
    assert r['sucesso'] and email_contato in r['mensagem'], r
    enviados = novos_emails()
    assert len(enviados) == 2, enviados
    equipe = next(e for e in enviados if 'fluxeteam@gmail.com' in e['para'])
    assert 'Contato de Ana Teste' in equipe['assunto'] and 'Página do SICAPDA' in equipe['assunto']
    assert email_contato in equipe['reply_to'] and 'minha escola' in equipe['texto'] and '(19) 99999-0000' in equipe['texto']
    confirmacao = next(e for e in enviados if email_contato in e['para'])
    assert 'Recebemos a sua mensagem' in confirmacao['assunto'] and 'Olá, Ana!' in confirmacao['texto']
    assert 'minha escola' not in confirmacao['texto']   # a confirmação não repete a mensagem (não vira disparador de spam)
    linhas = json.loads(fixture('contatos', email_contato))
    assert len(linhas) == 1 and linhas[0]['enviado_em'] and linhas[0]['telefone'] == '(19) 99999-0000'

    # Sem JavaScript: volta para a página com ?contato=ok
    resp = contato({**base_msg, 'origem': 'fluxe'}, 303, json_=False)
    assert resp.headers['Location'] == '/?contato=ok#contato', resp.headers['Location']
    novos_emails()

    # Limite: 3 mensagens a cada 10 minutos por IP
    assert contato(base_msg, 200)['sucesso']
    novos_emails()
    r = contato(base_msg, 429)
    assert 'várias mensagens' in r['mensagem'] and novos_emails() == []

    # ─── Cadastro → boas-vindas ──────────────────────────────────────────────
    nav = navegador()
    html, _ = pedir('/cadastro', abridor=nav)
    cnpj = str(10**13 + secrets.randbelow(10**13))
    admin_email = f'admin-{marca}@example.com'
    inst_email = f'contato-{marca}@example.com'
    cadastro = {
        'csrf_token': csrf(html), 'admNome': 'Bruno Admin Teste', 'admCpf': str(10**10 + secrets.randbelow(10**10)),
        'admTelefone': '19999990000', 'admEmail': admin_email, 'admCargo': 'Gerente', 'senha': 'SenhaInicial123',
        'confirmaSenha': 'SenhaInicial123', 'razaoSocial': 'Escola Teste LTDA', 'nomeFantasia': 'Escola Teste ' + marca,
        'cnpj': cnpj, 'segmento': 'Educação', 'qtdColab': '50', 'endereco': 'Rua A, 1', 'cep': '13730000',
        'cidade': 'Mococa', 'estado': 'SP', 'telEmpresa': '1935550000', 'horarioFunc': '07h às 17h',
        'responsavel': 'Bruno', 'emailInst': inst_email, 'aceiteTermos': '1', 'refeitorio': 'sim', 'mealAlmoco': '40',
    }
    texto, _ = pedir('/cadastro', cadastro, abridor=nav)
    assert json.loads(texto)['sucesso'], texto
    empresa = int(fixture('empresa-por-cnpj', cnpj))
    empresas.append(empresa)
    enviados = novos_emails()
    assert len(enviados) == 2, [e['assunto'] for e in enviados]
    boas = next(e for e in enviados if admin_email in e['para'])
    assert boas['assunto'] == 'Bem-vindo ao SICAPDA, Bruno!' and 'Escola Teste ' + marca in boas['texto']
    assert '/login' in boas['html'] and 'Pessoas → Cadastro' in boas['texto']
    assert any(inst_email in e['para'] and 'Cadastro da Escola Teste' in e['assunto'] for e in enviados)

    # ─── Esqueceu a senha? (site) ────────────────────────────────────────────
    html, _ = pedir('/login')
    assert 'href="/senha/esqueci"' in html
    nav = navegador()
    html, _ = pedir('/senha/esqueci', abridor=nav)
    token_csrf = csrf(html)
    html, _ = pedir('/senha/esqueci', {'email': 'x'}, abridor=nav)  # sem CSRF
    assert 'Sessão expirada' in html
    html, _ = pedir('/senha/esqueci', {'email': 'ninguem-' + marca + '@example.com', 'csrf_token': token_csrf}, abridor=nav)
    assert 'Se este e-mail estiver cadastrado' in html and novos_emails() == []   # mesma resposta, nenhum e-mail
    html, _ = pedir('/senha/esqueci', {'email': admin_email, 'csrf_token': token_csrf}, abridor=nav)
    assert 'Se este e-mail estiver cadastrado' in html
    [pedido] = novos_emails()
    assert admin_email in pedido['para'] and pedido['assunto'] == 'Redefinir a senha do SICAPDA'
    link = re.search(r'(https?://\S+/senha/redefinir\?token=([a-f0-9]{64}))', pedido['texto'])
    assert link, pedido['texto']
    token = link.group(2)

    pedir('/senha/redefinir?token=' + '0' * 64, abridor=nav)  # token inexistente → tela "link inválido"
    html, _ = pedir('/senha/redefinir?token=' + token, abridor=nav)
    assert 'Crie uma senha nova' in html and admin_email in html
    token_csrf = csrf(html)
    html, _ = pedir('/senha/redefinir', {'token': token, 'senha': 'NovaSenha456', 'confirmar': 'outra', 'csrf_token': token_csrf}, abridor=nav)
    assert 'não coincidem' in html
    html, _ = pedir('/senha/redefinir', {'token': token, 'senha': 'curta', 'confirmar': 'curta', 'csrf_token': token_csrf}, abridor=nav)
    assert 'pelo menos 8' in html
    _, resp = pedir('/senha/redefinir', {'token': token, 'senha': 'NovaSenha456', 'confirmar': 'NovaSenha456', 'csrf_token': token_csrf}, abridor=nav, status=303)
    assert resp.headers['Location'] == '/login'
    [aviso] = novos_emails()
    assert aviso['assunto'] == 'A sua senha do SICAPDA foi alterada' and 'Esqueceu a senha' in aviso['texto']
    html, _ = pedir('/senha/redefinir?token=' + token, abridor=nav)   # link de uso único
    assert 'Link inválido ou vencido' in html

    def login_api(senha, status=200):
        texto, _ = pedir('/api/login', {'email': admin_email, 'senha': senha}, {'Content-Type': 'application/json'}, status=status)
        return json.loads(texto)
    login_api('SenhaInicial123', 401)
    token_app = login_api('NovaSenha456')['token']

    # ─── Esqueci a senha (aplicativo) ────────────────────────────────────────
    texto, _ = pedir('/api/senha/esqueci', {'email': admin_email}, {'Content-Type': 'application/json'})
    assert json.loads(texto)['mensagem'].startswith('Se este e-mail estiver cadastrado')
    [pedido_app] = novos_emails()
    assert '/senha/redefinir?token=' in pedido_app['texto']
    pedir('/api/senha/esqueci', {'email': 'invalido'}, {'Content-Type': 'application/json'}, status=422)
    pedir('/api/senha/esqueci', metodo='GET', status=405)

    # Senha trocada pelo aplicativo (Configurações → Segurança) também avisa por e-mail
    pedir('/api/perfil/senha', {'senha_atual': 'NovaSenha456', 'senha_nova': 'Terceira789', 'senha_confirmar': 'Terceira789'},
          {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token_app})
    [aviso_app] = novos_emails()
    assert 'Configurações → Segurança' in aviso_app['texto']

    # ─── Desperdício alto (balança) ──────────────────────────────────────────
    balanca = json.loads(php('tests/balanca_fixture.php'))
    empresas.append(int(balanca['company']))
    fixture('historico', balanca['company'], 10)

    def pesar(kg, tipo='almoco'):
        texto, _ = pedir('/api/iot/desperdicio', {'desperdicio_kg': kg, 'tipo': tipo},
                         {'Content-Type': 'application/json', 'X-Device-Token': balanca['balance']['token']})
        return json.loads(texto)

    pesar(9.5)                                       # dentro da média: nenhum e-mail
    assert novos_emails() == []
    pesar(18)                                        # 18 kg contra ~10,5 kg de média → alerta
    [alerta] = novos_emails()
    assert balanca['email'] in alerta['para'] and alerta['assunto'].startswith('Alerta: desperdício alto')
    assert 'acima da média diária' in alerta['texto'] and 'Almoço' in alerta['texto']
    pesar(25)                                        # mesmo dia: não repete
    assert novos_emails() == []

    # ─── Tarefa agendada: resumo diário ──────────────────────────────────────
    pedir('/tarefas/emails?chave=errada', status=404)
    texto, _ = pedir('/tarefas/emails?chave=' + CRON)
    assert json.loads(texto)['sucesso']
    assert not [e for e in novos_emails() if balanca['email'] in e['para']]   # resumo vem desligado por padrão

    fixture('pref', balanca['uid'], 'resumo_diario', 1)
    saida = php('bin/emails.php')
    hora = int(php('-r', 'date_default_timezone_set("America/Sao_Paulo"); echo date("G");'))
    resumos = [e for e in novos_emails() if balanca['email'] in e['para']]
    if hora >= 8:
        assert len(resumos) == 1 and resumos[0]['assunto'].startswith('Resumo do dia'), (saida, resumos)
        assert 'PREVISÃO PARA HOJE' in resumos[0]['texto'] and 'Entradas' in resumos[0]['texto'] and 'Previsão para hoje' in resumos[0]['html']
        php('bin/emails.php')
        assert not [e for e in novos_emails() if balanca['email'] in e['para']]   # uma vez por dia
    else:
        assert resumos == [], 'antes das 8h o resumo não sai'
    tipos = {e['tipo'] for e in json.loads(fixture('enviados', balanca['company']))}
    assert 'desperdicio_alto' in tipos

    # Desligar o alerta de desperdício: no dia seguinte (simulado com outra data) ninguém recebe
    fixture('pref', balanca['uid'], 'email_desperdicio', 0)
    php('-r', 'require "config/app.php"; (new Producao())->registrarDesperdicio(%d, "jantar", 40, date("Y-m-d", strtotime("-1 day"))); '
              'echo EmailsAutomaticos::desperdicioAlto(%d, date("Y-m-d", strtotime("-1 day")));' % (int(balanca['company']), int(balanca['company'])))
    assert novos_emails() == []

    print(f'PASS: {contador} requisições; contato, boas-vindas, recuperação de senha, aviso de senha, desperdício alto e resumo diário.')
finally:
    fixture('limpar-contatos', f'%-{marca}@example.com', '127.0.0.1')
    for empresa in empresas:
        fixture('limpar-empresa', empresa)
