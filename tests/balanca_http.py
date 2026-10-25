"""Integração local: PHP no PATH, banco migrado e servidor em SICAPDA_TEST_URL.
Cria empresas temporárias e remove os registros ao concluir. Não usar em produção.
Executar: python3 tests/balanca_http.py
"""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import subprocess
import urllib.request
import urllib.error
import urllib.parse

fixture = str(Path(__file__).with_name('balanca_fixture.php'))
base = os.getenv('SICAPDA_TEST_URL', 'http://127.0.0.1:8000')
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
count = 0

def call(path, status=200, data=None, token=None, csrf=None, form=False):
    global count
    headers = {}
    body = None
    if data is not None:
        headers['Content-Type'] = 'application/x-www-form-urlencoded' if form else 'application/json'
        body = (urllib.parse.urlencode(data) if form else json.dumps(data)).encode()
    if token: headers['X-Device-Token'] = token
    if csrf: headers['X-CSRF-Token'] = csrf
    req = urllib.request.Request(base + path, data=body, headers=headers)
    try: response = client.open(req)
    except urllib.error.HTTPError as e: response = e
    body = response.read().decode()
    assert response.status == status, (path, response.status, status)
    count += 1
    return json.loads(body) if 'application/json' in response.headers.get('Content-Type', '') else body

records = []
try:
    for _ in range(2):
        records.append(json.loads(subprocess.check_output(['php', fixture])))
    user, other = records
    token = user['balance']['token']
    payload = {'tipo':'almoco', 'desperdicio_kg':2.5}
    call('/api/iot/desperdicio',401,data=payload)
    call('/api/iot/desperdicio',403,data=payload,token=user['rfid']['token'])
    call('/api/iot/acesso',403,data={'uid':'1234'},token=token)
    assert call('/api/iot/ping',data={},token=token)['epoch'] > 1700000000
    for invalid in [None, True, [], -1, 100001, 'NaN', 'abc']:
        call('/api/iot/desperdicio',422,data=dict(payload,desperdicio_kg=invalid),token=token)
    for data in [{'tipo':'invalido'}, {'tipo':[]}, {'data':'2026-02-30'}, {'data':'2999-01-01'}]:
        call('/api/iot/desperdicio',422,data=dict(payload,**data),token=token)
    for _ in range(2):
        assert call('/api/iot/desperdicio',data=payload,token=token)['desperdicio_kg']==2.5
    # Uma pesagem de outra empresa não altera o total desta empresa.
    call('/api/iot/desperdicio',data=dict(payload,desperdicio_kg=9),token=other['balance']['token'])
    # Reenvio atrasado mantém a data original.
    call('/api/iot/desperdicio',data=dict(payload,data='2020-01-02',desperdicio_kg=8),token=token)
    totals=json.loads(subprocess.check_output(['php',fixture,'totals',user['company']]))
    assert len(totals)==2 and totals[0]['data']=='2020-01-02'
    assert float(totals[0]['quantidade_desperdicada_kg'])==8 and float(totals[1]['quantidade_desperdicada_kg'])==2.5
    page=call('/login')
    csrf=re.search(r'name="csrf_token" value="([^"]+)"',page)[1]
    call('/login',data={'email':user['email'],'senha':user['senha'],'csrf_token':csrf},form=True)
    page=call('/configuracoes')
    assert 'Balança de desperdício' in page and '2,50 kg' in page and '9,00 kg' not in page
    csrf=re.search(r'name="csrf-token" content="([^"]+)"',page)[1]
    manual=dict(payload,id=user['balance']['id'])
    call('/configuracoes/dispositivos/pesagem',403,data=manual,form=True)
    call('/configuracoes/dispositivos/pesagem',404,data=dict(manual,id=other['balance']['id']),csrf=csrf,form=True)
    call('/configuracoes/dispositivos/pesagem',422,data=dict(manual,id=user['rfid']['id']),csrf=csrf,form=True)
    call('/configuracoes/dispositivos/pesagem',data=dict(manual,desperdicio_kg=0),csrf=csrf,form=True)
    assert '0,00 kg' in call('/configuracoes')
    call('/configuracoes/dispositivos/simular',422,data={'id':user['balance']['id'],'uid':'1234'},csrf=csrf,form=True)
    new=call('/configuracoes/dispositivos',data={'nome':'Nova balança','tipo':'balanca'},csrf=csrf,form=True)
    assert new['dispositivo']['tipo']=='balanca'
    call('/configuracoes/dispositivos/token',data={'id':user['balance']['id']},csrf=csrf,form=True)
    call('/api/iot/desperdicio',401,data=payload,token=token)
    call('/logout')
    subprocess.run(['php',fixture,'role',user['uid']],check=True)
    page=call('/login')
    csrf=re.search(r'name="csrf_token" value="([^"]+)"',page)[1]
    call('/login',data={'email':user['email'],'senha':user['senha'],'csrf_token':csrf},form=True)
    page=call('/configuracoes')
    assert 'id="formPesagem"' not in page
    csrf=re.search(r'name="csrf-token" content="([^"]+)"',page)[1]
    call('/configuracoes/dispositivos/pesagem',403,data=manual,csrf=csrf,form=True)
    print(f'PASS: {count} requisições; validação, isolamento, CSRF, cadastro, token, total e reenvio.')
finally:
    for row in records:
        subprocess.run(['php',fixture,'delete',row['uid'],row['company']],check=True)
