# Integração IoT — catraca com ESP32 + RFID

O leitor de cartão é um aparelho **independente** do site: ele não se liga ao servidor por cabo
nem por Arduino. A ESP32 entra no **Wi-Fi** e conversa com o SICAPDA por **HTTPS**, como um
navegador faria. O cabo USB serve só para gravar o programa nela e alimentá-la.

```
 Cartão RFID ──► RC522 ──► ESP32 ──(Wi-Fi / HTTPS)──► SICAPDA (PHP) ──► MySQL (logs_acesso)
                             ▲                            │                    │
                             └── {autorizado, entrada} ◄──┘                    ▼
                  abre a catraca (servo) ou pisca o LED vermelho     Painel · Pessoas · Relatórios
                  e manda ao visor web o nome, o cargo e ENTRADA/SAÍDA · Previsão de demanda
```

Tudo o que o painel, a tela de pessoas, os relatórios e a previsão de refeições mostram sai da
tabela `logs_acesso`. Hoje ela é preenchida pelo `config/dados_exemplo.sql`; com o IoT, passa a
ser preenchida pelas passagens reais dos cartões, **sem mudar nenhuma tela**.

## O que existe e onde fica

| Peça | Onde | Observação |
|---|---|---|
| Firmware da ESP32 | `iot/esp32_catraca/esp32_catraca.ino` (+ `platformio.ini` para o PlatformIO) | Roda **na ESP32**, não no servidor. Fica no repositório só para versionar. Não precisa subir para a hospedagem. |
| Endpoints que a ESP32 chama | `app/controllers/IotController.php`, rota em `app/routes/web.php` | Roda **no servidor**, junto com o resto do PHP. |
| Cadastro dos dispositivos e dos tokens | Tela **Configurações → Integrações** (`ConfiguracaoController`, `app/models/Dispositivo.php`, tabela `dispositivos_iot`) | Só o administrador da empresa cria dispositivos e gera tokens. |
| Leitura, decisão e log | `app/models/Catraca.php` (a regra), `app/models/Acesso.php`, tabelas `credenciais_acesso` e `logs_acesso` | Cartão → pessoa → entrada ou saída → log. O "Simular leitura" usa a mesma regra. |
| Cadastro de pessoas e cartões | Tela **Pessoas → Cadastro** (`PessoaController`, `app/models/Pessoa.php`, `app/helpers/CsvPessoas.php`) e a mesma aba no **aplicativo** (`/api/pessoas/*`, `ApiController`). As regras ficam em `app/models/CadastroPessoas.php`, as mesmas nos dois | Adicionar pessoa, vincular cartão, ativar/desativar, importar planilha (só no site). Só o administrador altera. |
| Cartões lidos e ainda sem dono | Tabela `cartoes_nao_cadastrados` (Migration 005) | Alimenta a lista "cartões lidos nas catracas" do vínculo. |
| Cadastro por SQL (alternativa) | `iot/teste_cartao.sql` | Só se preferir o banco à tela. |

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

1. **Banco:** rode os blocos `Migration 004` e `Migration 005` do final de `config/database.sql`
   (aba SQL do phpMyAdmin; cada um só cria tabelas que ainda não existem). A 004 serve a tela de
   Configurações (preferências e sessões) e a 005 serve o vínculo de cartões em Pessoas. O endpoint da
   catraca em si só usa tabelas que já existiam: sem a 005 ele continua funcionando, só a lista de
   "cartões lidos" fica vazia.
2. **Crie o dispositivo:** entre como administrador → **Configurações → Integrações → Adicionar
   dispositivo** → dê um nome (ex.: "Catraca Wokwi") → **Copie o token** (ele aparece uma vez só; se
   perder, use "Novo token").
3. **Cadastre uma pessoa e o cartão dela**, sem SQL: **Pessoas → Cadastro → Adicionar pessoa**. Passe o
   cartão no leitor (ele é negado, mas o sistema guarda o UID): ele aparece sozinho na lista "cartões lidos
   nas catracas" do formulário. Clique nele para preencher o UID e salve. (O UID também aparece no Serial
   Monitor, `Cartao lido: 01020304`, e pode ser digitado assim.)
4. **No Wokwi:** abra o projeto do circuito, troque o código pelo de `iot/esp32_catraca/esp32_catraca.ino`
   e o `diagram.json` e o `libraries.txt` pelos desta pasta (`MFRC522`, `ESP32Servo`, `ArduinoJson`). Ponha
   `MODO_SIMULADOR = true` (usa a rede `Wokwi-GUEST`), o endereço em `URL_SERVIDOR` e o token em
   `TOKEN_DISPOSITIVO`, e rode. Na ESP32 de verdade, `MODO_SIMULADOR = false` e o Wi-Fi em `WIFI_SSID`/`WIFI_SENHA`.
5. **Passe o cartão** no leitor simulado, do mesmo jeito que já fazem. O Serial Monitor mostra a resposta
   do servidor, o LED vermelho apaga, o verde acende e o servo abre por 4 segundos. Com a ESP32 de verdade no
   USB, o visor web (`/visor/catraca/`) mostra o nome, o cargo e ENTRADA ou SAÍDA.
6. **Veja no sistema:** a pessoa aparece em **Pessoas** (presentes agora), o **Painel** e os **Relatórios**
   mostram o acesso, e o dispositivo fica **Online** em Configurações → Integrações. Passando de novo
   (depois de 10 segundos), vira a **saída**.
7. **Teste o "não":** um UID não cadastrado faz o LED vermelho piscar, não abre o servo e fica
   registrado como tentativa negada (alerta "Acessos negados hoje" no sino, se estiver ligado em
   Configurações → Notificações).

> Passar o cartão **não cria** a pessoa: ele marca a presença de quem já está cadastrado e tem o cartão
> vinculado. Pessoa nova = cadastro em **Pessoas → Cadastro** + cartão vinculado.

## Cadastrar pessoas e cartões (Pessoas → Cadastro)

A aba **Cadastro** da tela de Pessoas lista todas as pessoas da empresa com os cartões delas. O
administrador pode:

- **Adicionar pessoa:** nome, cargo, categoria, matrícula (opcional) e, se quiser, o cartão já na hora
  (tudo ou nada: se o cartão não puder ser usado, a pessoa também não é criada).
- **Vincular cartão** a quem já existe, de dois jeitos:
  1. **Pela lista de cartões lidos:** abra "Vincular cartão", passe o cartão numa catraca e ele aparece
     sozinho (destacado, a lista atualiza a cada 3 segundos); clique nele e confirme.
  2. **Digitando o UID** (letras, números, hífen ou sublinhado; espaços e `:` são ignorados).
- **Ativar/desativar** um cartão (cartão perdido) ou a pessoa inteira (desligamento). Desativada, ela é
  negada na catraca e sai das contagens, mas o histórico fica. Pessoas **não são apagadas**: apagar
  deixaria registros de acesso sem dono e distorceria os números.
- **Remover** um cartão (o UID fica livre para outra pessoa).
- **Importar planilha** (CSV): colunas `nome` e `cargo` (obrigatórias), `categoria` (operador, supervisor ou
  prestador; padrão operador), `matricula` e `cartao` (o UID). Aceita `;` ou `,` e arquivo do Excel (UTF-8
  ou Windows-1252). Baixe o modelo no próprio diálogo. Linhas com problema são ignoradas e explicadas
  (com o número da linha); importar o mesmo arquivo duas vezes não duplica ninguém (matrícula e cartão
  repetidos são ignorados). Limite: 1 MB e 1000 linhas por arquivo.

Regras que valem sempre:

- Um UID só pode existir **uma vez no sistema inteiro**, mesmo entre empresas diferentes: um cartão
  físico tem um dono só. Quem tentar usar um UID de outra empresa recebe "já está em uso por outra
  empresa", sem ver de quem.
- Usuários que não são administradores veem a lista, mas sem nenhum botão de alterar.

### Pelo aplicativo (celular, PC ou navegador)

O app do SICAPDA (`fluxe_app`) tem a mesma aba, em **Pessoas → Cadastro**, e faz tudo da lista acima, **menos
importar planilha** (essa fica só no site). Para o cadastro de um cartão novo, o fluxo no celular é o mesmo:
toque em **+ Vincular cartão**, passe o cartão numa catraca e escolha o UID que aparece destacado como *novo*.
O app fala com a API (`/api/pessoas/*`, veja o `ApiController`), com as mesmas regras do site; só o
administrador altera e uma empresa nunca vê nem mexe em pessoas de outra. Para o app funcionar o servidor
precisa estar com esta versão do SystemFluxe: com um servidor antigo, a aba mostra "O servidor ainda não
tem o cadastro pelo aplicativo".

## Simular leitura (testar sem a ESP32)

Em **Configurações → Integrações → Simular leitura**, o administrador escolhe uma catraca, digita um UID e
vê o resultado (liberado, negado, entrada ou saída), pela **mesma regra** da catraca de verdade. Serve
para testar o sistema sem Wokwi nem aparelho.

- **Registra um acesso real**: ele aparece no painel, em Pessoas e nos relatórios, e não há marca que o
  distinga de uma passagem verdadeira. Use só para testes e limpe depois.
- Não marca a catraca como Online: isso só o ping da própria ESP32 faz.
- Um cartão desconhecido simulado também entra na lista "cartões lidos", para você treinar o vínculo.

## Lendo as mensagens do Serial Monitor

Ao ligar, o firmware mostra o endereço configurado e o tamanho do token (só as pontas dele, ex.:
`Token: 64 caracteres (647834...5912)`). O token tem de ter **64 caracteres**; se o aviso disser outro
número, o texto foi copiado errado (use a caixa amarela, não a lista). Depois, cada falha vem explicada:

| Mensagem | O que é |
|---|---|
| `HTTP 401: o servidor NAO reconheceu o token` | Esse é o único caso de token errado: copiado errado, trocado por "Novo token", ou a catraca foi criada em **outro site/banco** (ex.: criada no site local e o firmware aponta para o de produção, ou o contrário). |
| `HTTP -1` (ou outro valor negativo): `nao consegui falar com o servidor` | **Não é o token.** O firmware não chegou ao servidor: endereço errado, sem internet, ou o endereço não é alcançável de onde o firmware roda. |
| `HTTP 404` | A rota não existe: `URL_SERVIDOR` com caminho errado (confira a subpasta, se houver) ou código novo ainda não publicado. |
| `HTTP 403` | O token é de uma **balança**, não de uma catraca (crie uma Catraca em Integrações e use o token dela), ou o firewall bloqueou. |
| `HTTP 406` | O firewall da hospedagem (ModSecurity) bloqueou o pedido. |

O visor web mostra a mesma explicação na tela de negado ("Token da catraca recusado", "Token de outro aparelho",
"A catraca não alcançou o site"...), então dá para achar o problema sem o Monitor Serial.

**`localhost` no Wokwi:** o Wokwi aberto no navegador roda nos servidores deles, então `localhost` ali **não é o seu
computador**: o resultado é `HTTP -1`. Para testar o sistema que roda na sua máquina, escolha um:

- **Simular leitura** (Configurações → Integrações): testa o sistema local sem o Wokwi e sem túnel.
- **Túnel** (ngrok, cloudflared): `ngrok http 80` mostra um `https://...` que o Wokwi enxerga; use-o em
  `URL_SERVIDOR` (com a subpasta, se o sistema estiver numa, como `/SystemFluxe/public`).
- **Site publicado:** a catraca precisa ser criada **nesse** site e o token copiado dele.

Endereços `http://` (sem o "s") funcionam no firmware, para um servidor na rede local de uma ESP32 de verdade.

## Circuito (o mesmo do Wokwi)

| Peça | Pino da ESP32 |
|---|---|
| RC522: SDA / SCK / MOSI / MISO / RST | GPIO 5 / 18 / 23 / 19 / 22 (3,3 V, nunca 5 V) |
| Servo (a catraca) | GPIO 13 (V+ no 5 V) |
| LED vermelho (travada; pisca 3 vezes = negado) | GPIO 27, com resistor de 220 Ω |
| LED verde (liberada) | GPIO 26, com resistor de 220 Ω |

Os LEDs estão como no protoboard da equipe (o `diagram.json` do Wokwi foi ajustado igual). Se a cor acender
trocada, troque os números de `LED_VERMELHO` e `LED_VERDE` no topo do firmware, ou os dois fios.

A catraca não tem mais o visor LCD: quem mostra o resultado é a página do visor web (abaixo).

| Situação | LEDs e servo | Visor web |
|---|---|---|
| Esperando cartão | vermelho aceso, servo em 0° | "Aproxime o cartão" (ou "Sem Wi-Fi") |
| Cartão lido, aguardando o servidor | — | "Lendo cartão..." |
| Entrada ou saída autorizada (4 s) | vermelho apaga, verde acende, servo em 90°; depois volta | primeiro nome, cargo, ENTRADA/SAÍDA e horário |
| Negado (3 s na tela) | vermelho pisca 3 vezes | o motivo: não cadastrado, desativado, sem Wi-Fi ou por que o site recusou |

O UID do cartão nunca vai para o visor (ele aparece só no Monitor Serial, para cadastrar cartões).

## Visor no navegador

Duas páginas fazem o papel do visor, uma para cada aparelho, com o visual do SICAPDA (a catraca não tem mais
LCD; a balança ainda mostra no LCD também, se ele estiver ligado). Elas ficam em
`public/visor/` e são só HTML, CSS e JS (sem banco, sem login):

| Página | Mostra |
|---|---|
| `/visor/catraca/` | primeiro nome e cargo de quem passou o cartão, ENTRADA ou SAÍDA, o horário, os negados (não cadastrado, desativado, sem Wi-Fi, site recusou) e as últimas passagens |
| `/visor/balanca/` | peso em tempo real (com "estável"), a refeição do botão amarelo, o total de hoje dela, "Enviado ao painel do SICAPDA" depois do ENVIAR, a fila sem internet e os passos da calibração |

Como abrir:

- **No computador, com o projeto:** `php -S localhost:8000 -t public app/routes/web.php` e abra
  `http://localhost:8000/visor/catraca/` ou `http://localhost:8000/visor/balanca/` (no XAMPP:
  `http://localhost/SystemFluxe/visor/catraca/`).
- **No site publicado:** `https://fluxeteam.com.br/visor/catraca/` e `.../visor/balanca/`.

Cada página tem dois modos:

1. **Demonstração** (padrão, qualquer navegador, até no celular): os botões do rodapé simulam o aparelho, com
   as mesmas mensagens e tempos do firmware. Catraca: **Passar cartão** (Espaço) e **Cartão não cadastrado** (N).
   Balança: o controle **Peso no prato** e os botões **Refeição** (R), **Tara** (T) e **Enviar** (E), nas cores dos
   botões de verdade. F liga a tela cheia.
2. **ESP32 no USB** (Chrome ou Edge no computador): ligue a ESP32 no computador pelo cabo USB, feche o Monitor
   Serial (só um programa usa a porta por vez), clique em **Conectar ESP32 (USB)** e escolha a porta. A partir
   daí a página mostra o que o aparelho de verdade está fazendo: passe um cartão ou aperte os botões da balança.
   O chip do topo fica verde (**ESP32 ao vivo**). A página só pega a porta quando alguém clica em **Conectar**
   (com a aba aberta e conectada, o Monitor Serial e o upload não conseguem usar a porta: clique em
   **Voltar à demonstração** ou feche a aba antes). Se o cabo sair, ela volta sozinha quando a mesma placa for
   religada. Ao conectar, a página reinicia a ESP32 (como o Arduino IDE faz), para ela ligar rodando o
   programa: a catraca volta em poucos segundos e a balança acerta a hora de novo com o site. Se a página disser
   que a placa está no modo de gravação ou não mandou nada, aperte o botão EN (RST).

**Nada funciona (nenhum LED, nada no Monitor Serial, nada no visor)?** A ESP32 não está rodando o programa:
1. Feche todas as abas do visor (uma aba conectada segura a porta USB).
2. Tire e ponha o cabo USB e aperte o botão **EN** da placa.
3. Abra o Monitor Serial do PlatformIO e aperte **EN** de novo. O boot mostra `SICAPDA CATRACA - visor web:
   ligado`, `Ultimo reinicio: ...` e acende o verde e depois o vermelho (teste dos LEDs).
   - Nada aparece: grave de novo (Upload) e confira no fim do upload `Hash of data verified` e `SUCCESS`.
   - `Ultimo reinicio: QUEDA DE ENERGIA (brownout)` ou reinícios seguidos: o servo e o Wi-Fi puxam corrente demais
     da porta USB. Ligue o V+ do servo numa fonte de 5 V separada (com o GND junto ao da ESP32).
   - `TRAVOU`: o monitor mostra a linha do código onde travou (`esp32_exception_decoder`); mande essa saída.

Como funciona: os dois firmwares escrevem no Serial (115200), junto com o log de sempre, uma linha
`VISOR {...}` a cada mudança de tela, e a página (Web Serial) lê essas linhas:

```
VISOR {"tela":"liberado","nome":"Maria","cargo":"Operadora de Produção","direcao":"entrada"}   ← catraca
VISOR {"l0":"Almoco Tot  0.45","l1":"Enviado +0.45kg","ref":"almoco","peso":0.455,
       "parado":true,"total":0.45,"fila":0,"wifi":true,"modo":0}       ← balança
```

- É preciso **gravar o firmware novo** (a catraca sempre manda as linhas; a balança tem `VISOR_WEB = true`). Com um firmware antigo a página
  avisa "firmware sem o visor web" e mostra a última linha que chegou. Para conferir, no boot o Monitor Serial
  mostra `SICAPDA CATRACA - visor web: ligado` (ou `SICAPDA BALANCA - visor web: ligado`).
- **Gravar pelo PlatformIO (extensão do VS Code):** cada pasta tem o seu `platformio.ini` com as bibliotecas.
  Abra a pasta do aparelho (`iot/esp32_catraca` ou `iot/esp32_balanca`) no VS Code (*File → Open Folder*), cole o
  token e o Wi-Fi no `.ino`, ligue a ESP32 no USB, feche a página do visor e clique em **Upload** (→) na barra de
  baixo. A primeira compilação baixa a plataforma ESP32 e as bibliotecas sozinha. No terminal:
  `pio run -d iot/esp32_catraca -t upload`. Depois, abra o visor e conecte.
- Na balança o LCD continua funcionando igual. Com ele ligado, os dois mostram a mesma coisa; `VISOR_WEB = false`
  desliga as linhas `VISOR` se elas atrapalharem a leitura do Monitor Serial.
- O Wokwi no navegador não tem porta USB para a página: use a demonstração para mostrar a tela.
- Diferente do LCD, o navegador mostra os acentos (`João`, `SAÍDA`).

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

## Balança de desperdício

A integração ESP32 + HX711 está em [`esp32_balanca/README.md`](esp32_balanca/README.md), com migração do banco existente, publicação, configuração no Wokwi e testes. Em Configurações → Integrações, cadastre o tipo **Balança de desperdício**.
