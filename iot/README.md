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
   Monitor, `UID lido: 01 02 03 04`, e pode ser digitado junto: `01020304`.)
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
| `HTTP 403` ou `406` | O firewall da hospedagem (ModSecurity) bloqueou o pedido. |

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
