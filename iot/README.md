# Integração IoT — catraca com ESP32 + RFID

O leitor de cartão é um aparelho **independente** do site: ele não se liga ao servidor por cabo
nem por Arduino. A ESP32 entra no **Wi-Fi** e conversa com o SICAPDA por **HTTPS**, como um
navegador faria. O cabo USB serve só para gravar o programa nela e alimentá-la.

```
 Cartão RFID ──► RC522 ──► ESP32 ──(Wi-Fi / HTTPS)──► SICAPDA (PHP) ──► MySQL (logs_acesso)
                             ▲                            │                    │
                             └── {autorizado, entrada} ◄──┘                    ▼
                  abre a catraca (relé) ou acende o LED vermelho     Painel · Pessoas · Relatórios
                                                                     · Previsão de demanda
```

Tudo o que o painel, a tela de pessoas, os relatórios e a previsão de refeições mostram sai da
tabela `logs_acesso`. Hoje ela é preenchida pelo `config/dados_exemplo.sql`; com o IoT, passa a
ser preenchida pelas passagens reais dos cartões, **sem mudar nenhuma tela**.

## O que existe e onde fica

| Peça | Onde | Observação |
|---|---|---|
| Firmware da ESP32 | `iot/esp32_catraca/esp32_catraca.ino` | Roda **na ESP32**, não no servidor. Fica no repositório só para versionar. Não precisa subir para a hospedagem. |
| Endpoints que a ESP32 chama | `app/controllers/IotController.php`, rota em `app/routes/web.php` | Roda **no servidor**, junto com o resto do PHP. |
| Dispositivo (token) | `app/models/Dispositivo.php`, tabela `dispositivos_iot` | Cada catraca tem o seu token. |
| Leitura, decisão e log | `app/models/Acesso.php`, tabelas `credenciais_acesso` e `logs_acesso` | Cartão → pessoa → entrada ou saída → log. |

## Endpoints

Todos são `POST`, sob `/api/iot`, com o cabeçalho `X-Device-Token: <token do dispositivo>`.
Sem token ou com token errado, a resposta é `401`.

### `POST /api/iot/acesso`

Corpo: `{"uid": "A1B2C3D4"}`. Opcional: `"direcao": "entrada" | "saida"` para um leitor dedicado
só à entrada ou só à saída. Sem isso o servidor alterna sozinho.

```json
{"sucesso": true, "autorizado": true,  "direcao": "entrada", "nome": "Carlos", "mensagem": "Entrada liberada."}
{"sucesso": true, "autorizado": false, "motivo": "cartao_desconhecido", "mensagem": "Cartão não cadastrado."}
{"sucesso": true, "autorizado": false, "motivo": "inativo", "mensagem": "Acesso desativado."}
```

A resposta é `200` sempre que o dispositivo é válido: **quem decide abrir a catraca é o campo
`autorizado`**, não o código HTTP.

Regras do servidor:

- **Entrada ou saída:** se o último registro autorizado da pessoa *hoje* foi uma entrada, esta
  passagem é a saída; senão, é entrada. É a mesma regra que o painel usa para "pessoas presentes agora".
- **Cartão encostado:** a mesma pessoa lida de novo no mesmo leitor em menos de 10 segundos não gera
  outro registro (a resposta vem com `"repetido": true`).
- **UID:** o servidor ignora espaços e `:` e converte para maiúsculas (`a1:b2 c3` vira `A1B2C3`).
  O que se cadastra em `credenciais_acesso.identificador` tem de estar nesse formato.
- **Cartão desconhecido, de outra empresa, cartão desativado ou pessoa desligada:** é negado e
  fica registrado em `logs_acesso` com `resultado = 'negado'` (não entra nos relatórios nem na previsão).
- **Empresa:** vem do dispositivo, nunca do cartão. Um leitor da empresa A não reconhece cartões da empresa B.
- O nome devolvido é só o primeiro nome, porque o visor do leitor fica à vista da fila.

### `POST /api/iot/ping`

Sem corpo. Marca o dispositivo como `online` e atualiza `ultimo_ping`. A ESP32 manda a cada 1 minuto.

## Colocando para funcionar

1. **Gere um token** para a catraca (64 caracteres) e cadastre o dispositivo:

   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```

   ```sql
   INSERT INTO dispositivos_iot (empresa_id, nome, tipo, localizacao, token, status, criado_em)
   VALUES (1, 'Catraca Principal', 'rfid', 'Portaria', 'COLE_O_TOKEN_AQUI', 'offline', NOW());
   ```

2. **Descubra o UID do cartão:** grave o firmware, abra o Monitor Serial (115200) e passe o
   cartão. A ESP32 imprime `Cartão lido: A1B2C3D4` mesmo que ele ainda não esteja cadastrado.

3. **Cadastre o cartão** para uma pessoa (`funcionarios.id`):

   ```sql
   INSERT INTO credenciais_acesso (funcionario_id, tipo, identificador, ativo, criado_em)
   VALUES (12, 'rfid', 'A1B2C3D4', 1, NOW());
   ```

4. **Configure o firmware** (`WIFI_SSID`, `WIFI_SENHA`, `URL_SERVIDOR`, `TOKEN_DISPOSITIVO`) e grave na ESP32.

5. **Teste sem a ESP32**, só com o `curl`:

   ```bash
   curl -X POST https://SEU-SITE/api/iot/acesso \
     -H "X-Device-Token: COLE_O_TOKEN_AQUI" \
     -H "Content-Type: application/json" \
     -d '{"uid":"A1B2C3D4"}'
   ```

   Para testar com os dados de exemplo (`config/dados_exemplo.sql`) já existem dispositivos e cartões:
   token `demo-catraca-principal-<id da empresa>` e cartões `DEMO-RFID-<id da empresa>-000001`, `...000002` etc.

## Simulando no Wokwi

- Use a rede **`Wokwi-GUEST`** (sem senha). Ela dá acesso à internet, então a ESP32 simulada consegue
  chamar o site publicado.
- O Wokwi roda na nuvem: ele **não enxerga o `localhost`** do seu computador (XAMPP). Para testar com
  o site rodando na sua máquina, publique-o ou abra um túnel (por exemplo `ngrok http 8000`) e use o
  endereço HTTPS dele em `URL_SERVIDOR`.
- Se o circuito do Wokwi não tem a peça do RC522, ponha `#define LEITOR_SIMULADO 1` no firmware e
  digite o UID no Monitor Serial.
- Se o circuito já lê o cartão de outro jeito, troque só o corpo da função `lerCartao()`:
  o resto do programa só precisa do UID como texto.

## Antes de ir para produção

- **Certificado HTTPS:** o firmware usa `setInsecure()` (não valida o certificado) para facilitar o
  teste. Em produção, troque por `cliente.setCACert(...)` com o certificado raiz do site.
- **Sem internet = catraca fechada.** Se o servidor não responde, a ESP32 nega o acesso (é o lado
  seguro). Funcionar offline exigiria guardar na ESP32 a lista de cartões autorizados e enviar os
  registros depois; isso não está implementado.
- **Catraca de verdade:** o relé da ESP32 aciona o contato seco de uma catraca comercial; ele não
  move a catraca por conta própria. Confira a ligação com o manual do fabricante.
- **Leitor do refeitório:** esta rota trata toda leitura como passagem de portaria (entrada/saída).
  Um leitor que registre *refeição consumida* precisa de um tratamento próprio e hoje não existe.
- **Não grave tokens reais no Git.** O token do firmware é só um marcador; troque-o no seu computador.
