# Integração IoT — catraca com ESP32 + RFID

O leitor de cartão é um aparelho **independente** do site: ele não se liga ao servidor por cabo
nem por Arduino. A ESP32 entra no **Wi-Fi** e conversa com o SICAPDA por **HTTPS**, como um
navegador faria. O cabo USB serve só para gravar o programa nela e alimentá-la.

```
 Cartão RFID ──► RC522 ──► ESP32 ──(Wi-Fi / HTTPS)──► SICAPDA (PHP) ──► MySQL (logs_acesso)
                             ▲                            │                    │
                             └── {autorizado, entrada} ◄──┘                    ▼
                  abre a catraca (servo) ou pisca o LED vermelho     Painel · Pessoas · Relatórios
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
| Cadastro dos dispositivos e dos tokens | Tela **Configurações → Integrações** (`ConfiguracaoController`, `app/models/Dispositivo.php`, tabela `dispositivos_iot`) | Só o administrador da empresa cria dispositivos e gera tokens. |
| Leitura, decisão e log | `app/models/Acesso.php`, tabelas `credenciais_acesso` e `logs_acesso` | Cartão → pessoa → entrada ou saída → log. |
| Cadastro de pessoa + cartão para testar | `iot/teste_cartao.sql` | Ainda não há tela para cadastrar cartões: por enquanto é por SQL. |

## Testar agora, sem a ESP32 nem o RFID de verdade (Wokwi + internet)

Dá para testar o caminho inteiro (circuito simulado → internet → banco → painel). O que precisa
estar de pé é o **SICAPDA acessível pela internet por HTTPS**, porque o Wokwi roda na nuvem e não
enxerga o `localhost` do seu computador. Duas formas:

- **Site publicado** (ex.: `https://fluxeteam.com.br`): publique esta versão do código e rode a
  migration abaixo no banco de lá. Os testes gravam no banco de produção; use o bloco de limpeza
  de `iot/teste_cartao.sql` depois.
- **Site local + túnel**: com o XAMPP rodando, abra um túnel (por exemplo `ngrok http 80`) e use o
  endereço `https://...` que ele mostra em `URL_SERVIDOR` (se o sistema estiver numa subpasta, como
  `/SystemFluxe/public`, inclua a subpasta). Os testes gravam no seu banco local.

Passo a passo:

1. **Banco:** rode o bloco `Migration 004` do final de `config/database.sql` (aba SQL do
   phpMyAdmin). Ele cria as tabelas de preferências e de sessões usadas pela tela de Configurações.
   O endpoint da catraca em si só usa tabelas que já existiam.
2. **Crie o dispositivo:** entre como administrador → **Configurações → Integrações → Adicionar
   dispositivo** → dê um nome (ex.: "Catraca Wokwi") → **Copie o token** (ele aparece uma vez só; se
   perder, use "Novo token").
3. **Cadastre uma pessoa e o cartão dela** com `iot/teste_cartao.sql`. O UID é o que o Serial Monitor
   mostra em `UID lido: 01 02 03 04`, escrito junto: `01020304`.
4. **No Wokwi:** abra o projeto do circuito, troque o código pelo de `iot/esp32_catraca/esp32_catraca.ino`,
   confira o `libraries.txt` (`MFRC522`, `ESP32Servo`, `ArduinoJson`), ponha o endereço em `URL_SERVIDOR` e o
   token em `TOKEN_DISPOSITIVO`, e rode.
5. **Passe o cartão** no leitor simulado, do mesmo jeito que já fazem. O Serial Monitor mostra a resposta
   do servidor, o servo abre por 4 segundos e o LED verde acende.
6. **Veja no sistema:** a pessoa aparece em **Pessoas** (presentes agora), o **Painel** e os **Relatórios**
   mostram o acesso, e o dispositivo fica **Online** em Configurações → Integrações. Passando de novo
   (depois de 10 segundos), vira a **saída**.
7. **Teste o "não":** um UID não cadastrado faz o LED vermelho piscar, não abre o servo e fica
   registrado como tentativa negada (alerta "Acessos negados hoje" no sino, se estiver ligado em
   Configurações → Notificações).

> Passar o cartão **não cria** a pessoa: ele marca a presença de quem já está cadastrado e tem o cartão
> vinculado. Pessoa nova = cadastro (`teste_cartao.sql` ou, no futuro, a tela de Pessoas) + cartão.

## Circuito (o mesmo do Wokwi)

| Peça | Pino da ESP32 |
|---|---|
| RC522: SDA / SCK / MOSI / MISO / RST | GPIO 5 / 18 / 23 / 19 / 22 (3,3 V, nunca 5 V) |
| Servo (a catraca) | GPIO 13 |
| LED vermelho (fechada) | GPIO 26 |
| LED verde (aberta) | GPIO 27 |

Para mudar os pinos, edite os `#define` no topo do firmware.

## Endpoints

Todos são `POST`, sob `/api/iot`, com o cabeçalho `X-Device-Token: <token do dispositivo>`.
Sem token ou com token errado, a resposta é `401`.

### `POST /api/iot/acesso`

Corpo: `{"uid": "01020304"}`. Opcional: `"direcao": "entrada" | "saida"` para um leitor dedicado
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
Na tela de Configurações, o dispositivo aparece **Offline** se passar de 3 minutos sem sinal (e, se o
alerta estiver ligado, o sino avisa).

## Tokens

- O token é gerado pelo servidor (64 caracteres aleatórios) e a tela mostra o valor inteiro **uma
  única vez**, ao criar o dispositivo ou ao pedir "Novo token". Depois só aparece mascarado.
- "Novo token" invalida o antigo na hora: o aparelho que ainda o usa passa a receber `401` até ser
  regravado com o token novo.
- Apagar dispositivos não é oferecido na tela de propósito: no banco, apagar um dispositivo apaga em
  cascata todo o histórico de acessos dele.

## Antes de ir para produção

- **Certificado HTTPS:** o firmware usa `setInsecure()` (não valida o certificado) para facilitar o
  teste. Em produção, troque por `client.setCACert(...)` com o certificado raiz do site.
- **Sem internet = catraca fechada.** Se o servidor não responde, a ESP32 nega o acesso (é o lado
  seguro). Funcionar offline exigiria guardar na ESP32 a lista de cartões autorizados e enviar os
  registros depois; isso não está implementado.
- **Catraca de verdade:** o servo do circuito é a simulação da trava. Uma catraca comercial costuma
  ser acionada por um relé (contato seco) ligado ao controlador dela; confira a ligação com o manual
  do fabricante.
- **Leitor do refeitório:** esta rota trata toda leitura como passagem de portaria (entrada/saída).
  Um leitor que registre *refeição consumida* precisa de um tratamento próprio e hoje não existe.
- **Não grave tokens reais no Git.** O token do firmware é só um marcador; troque-o no seu computador.
