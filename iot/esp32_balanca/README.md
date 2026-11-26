# Balança de desperdício — SICAPDA (firmware v6)

ESP32 + HX711 + **uma célula de carga tipo barra de 10 kg** + LCD 16x2 I2C + 3 botões + 2 LEDs. A balança mede o
**desperdício de cada refeição** (café, almoço, jantar e ceia) e manda o total do dia para o SICAPDA, que alimenta
`producao_refeicoes`, o painel, a análise mensal, os alertas e o assistente.

| Arquivo | O que é |
|---|---|
| `esp32_acesso.ino` | Firmware (roda na ESP32; não vai para a hospedagem) |
| `diagram.json`, `libraries.txt` | Circuito e bibliotecas do simulador Wokwi |
| `platformio.ini` | Para compilar com PlatformIO (`pio run -d iot/esp32_balanca`) |

## Uso no dia a dia

```
 ┌────────────────┐   AMARELO  REFEIÇÃO  troca: Almoço → Jantar → Ceia → Café
 │Almoco Tot  2.35│   AZUL     TARA      zera com o prato vazio (segure 4 s = calibrar)
 │Peso:   0.845 kg│   VERMELHO ENVIAR    pesa, soma ao total da refeição e manda ao site
 └────────────────┘
  linha 1: refeição selecionada e total de HOJE dessa refeição ("!" = total esperando internet)
  linha 2: peso ao vivo (nunca negativo) ou a mensagem do momento
```

1. Escolha a refeição no **amarelo**.
2. Com o prato (ou o recipiente que vai ficar em cima) **vazio**, aperte **TARA**. Espere `Tara feita`.
3. Coloque o desperdício. O peso aparece em degraus de 5 g.
4. Aperte **ENVIAR**. A balança espera o peso parar (`Estabilizando...`), **soma** a pesagem ao total da refeição de
   hoje e manda o total ao site: `Enviado +0.85kg`. Sem internet aparece `Na fila +0.85kg`, a linha de cima ganha um
   `!`, o LED vermelho acende e a balança reenvia sozinha quando a rede voltar (mesmo se for desligada).
5. Passou de 10 kg? Esvazie no lixo, faça **TARA** e pese o resto: as pesagens se somam no total da refeição.

Sem o LCD, a página `/visor/balanca/` do SICAPDA mostra a mesma coisa no navegador, com a ESP32 ligada no
computador pelo USB (Chrome ou Edge). Veja [Visor no navegador](../README.md#visor-no-navegador-enquanto-o-lcd-não-chega).

| LED | Aceso quer dizer |
|---|---|
| Verde | Conectado ao Wi-Fi (pisca enquanto envia) |
| Vermelho | Atenção: total esperando envio, sensor sem sinal ou balança ainda não calibrada |

## Peças

- ESP32 DevKit (30 ou 38 pinos) e cabo USB de dados
- Módulo HX711 (o verde comum) e **célula de carga tipo barra de 10 kg** (4 fios: vermelho, preto, branco, verde)
- LCD 1602 com módulo I2C (endereço 0x27; alguns vêm com 0x3F)
- 3 botões (azul, vermelho, amarelo), 2 LEDs (verde, vermelho) e 2 resistores de 220 Ω
- Base rígida (madeira grossa ou MDF de 15 mm ou mais), prato/bandeja rígido, 2 espaçadores e parafusos da célula

## Arquitetura mecânica (o que mais influencia a precisão)

A célula de carga é uma barra de alumínio com **extensômetros** colados no meio (a parte coberta de cola branca, perto
dos furos grandes). O peso só é medido se a barra puder **dobrar um pouquinho no meio**. Por isso ela fica **elevada**,
presa em balanço entre a base e o prato, como um "Z":

```
  VISTA DE LADO

                    ┌──────────── prato / bandeja (centrado sobre o MEIO da barra) ────────────┐
  ══════════════════╧═══════════════════════════════════════════════════════════════════════╧══
                                                          ┌────┐  ← espaçador de cima
          ┌───────────────────────────────────────────────┴────┴──┐
          │ (o)(o)          [ ( )  ( ) ]  ← extensômetros  (o)(o) │  ← célula de 10 kg, seta ↓
          └──┬────┬───────────────────────────────────────────────┘
             └────┘  ← espaçador de baixo                    ▲
  ═══════════╧════╧══════════════════════════════════════════│════════  base fixa, plana e rígida
          PONTA FIXA                                    PONTA LIVRE
     (parafusada na base)                         (parafusada no prato)
                    ◄────── vão livre: nada pode encostar no meio da barra ──────►
```

Regras da montagem:

1. **Uma ponta na base, a outra no prato.** A ponta fixa é parafusada na base com um espaçador; a ponta livre é
   parafusada no prato com outro espaçador. Nunca prenda as duas pontas na mesma peça (a barra não dobra e não mede).
2. **Espaçadores de 5 a 10 mm** (bloco de madeira, arruelas empilhadas ou peça impressa) dos dois lados. Assim a
   barra fica no ar: o meio não encosta na base e o prato não encosta na barra. É o "sensor elevado".
3. **Seta da barra para baixo**, no sentido do peso. Ao contrário o sinal inverte (a calibração até corrige com um
   fator negativo, mas o certo é a seta para baixo).
4. **Prato centrado sobre o meio da barra**, não pendurado na ponta. A barra tolera bem o peso mais para frente ou para
   trás, mas erra quando o peso fica muito para os lados (torção). Use um prato de 20 a 25 cm e o recipiente no centro.
5. **Base rígida e pesada**, apoiada numa mesa nivelada (de preferência com pés de borracha). Base fina que entorta
   rouba parte do peso.
6. **Parafusos certos e curtos**: use os furos roscados de cada ponta (geralmente M4 ou M5; confira na sua barra) e
   aperte bem. Parafuso comprido que passa do furo e encosta na base ou no prato trava a célula.
7. **Nada encostando no prato**: fios, parede, a borda da base, o recipiente vizinho. O cabo da célula fica preso na
   base com folga, sem puxar.
8. **Não aperte a cola branca** e não passe de ~15 kg (150% da capacidade): a barra deforma para sempre. Nada de
   apoiar a mão com força ou deixar cair coisas pesadas no prato.

Na foto de referência (célula presa numa madeira e uma placa branca na outra ponta) a ideia é exatamente essa. Para
pesar comida, prefira um prato maior **centrado sobre a barra** e uma base mais grossa.

## Ligação elétrica

| Célula de carga | HX711 |
|---|---|
| Vermelho | E+ |
| Preto | E− |
| Branco | A− |
| Verde | A+ |

Se o número **cair** quando você coloca peso, não tem problema: a calibração gera um fator negativo e o peso sai
positivo. Se preferir, troque o branco com o verde.

| De | Para a ESP32 |
|---|---|
| HX711 VCC / GND | **3V3** / GND (não ligue o HX711 em 5 V: o DT chegaria a 5 V no GPIO16) |
| HX711 DT / SCK | GPIO16 / GPIO17 |
| LCD SDA / SCL | GPIO21 / GPIO22 |
| LCD VCC / GND | VIN (5 V do USB) / GND |
| Botão TARA (azul) | GPIO32 e GND |
| Botão ENVIAR (vermelho) | GPIO33 e GND |
| Botão REFEIÇÃO (amarelo) | GPIO25 e GND |
| LED verde / vermelho | GPIO27 / GPIO26 → resistor de 220 Ω → LED → GND |

Para a leitura não ficar pulando:

- **Solde** os 4 fios da célula no HX711 (ou use borne). Os fios finos não prendem bem no protoboard: é a causa mais
  comum de valor oscilando, "andando" ou negativo.
- Fios da célula e do HX711 **curtos e trançados**, longe da antena da ESP32 (a ponta com a trilha em zigue-zague).
- O módulo verde vem com o pino **RATE no GND (10 leituras/s)**, o modo com menos ruído. O diagnóstico (`d`) mostra a
  taxa: perto de 80/s indica RATE em alto.
- Use uma fonte/porta USB boa. Opcional: capacitor de 100 nF + 10 µF entre VCC e GND do HX711.
- O módulo do LCD tem resistores que puxam SDA/SCL para o VCC dele (5 V). Na prática funciona com a ESP32, mas o
  correto é um conversor de nível I2C ou tirar esses resistores do módulo.

## Como o firmware funciona

```
 HX711 ──10 leituras/s──► tarefa "sensor": mediana de 3 ─► média móvel de 16 leituras ─► média + ruído ──┐
                          (derruba picos)    (recomeça na hora quando entra carga nova)                 │
 botões ──a cada 5 ms───► tarefa "botoes": debounce, clique e "segurou 4 s" ─► fila de eventos ──┐       │
                                                                                                 ▼       ▼
                                  loop(): − tara ─► ÷ fator ─► zero automático ─► degraus de 5 g sem piscar (nunca < 0)
                                     ├─► LCD: refeição + total de hoje / peso
                                     ├─► memória da ESP32 (NVS): fator, tara, refeição, totais do dia e fila de envio
                                     └─► Wi-Fi/HTTPS ─► SICAPDA: POST /api/iot/desperdicio e /api/iot/ping
```

O sensor é lido numa tarefa separada, então a média continua certa mesmo enquanto a ESP32 conversa com o site, e os
botões nunca perdem um clique. O que cada parte resolve:

| Problema na bancada | O que o firmware v6 faz |
|---|---|
| Valor pulando | Mediana de 3 descarta picos isolados; média de 16 leituras; o LCD só troca o último dígito quando o peso muda de verdade (histerese) |
| Valor negativo | O LCD **nunca mostra negativo**: abaixo de zero aparece `0.000 kg`. O **zero automático** corrige sozinho a deriva de temperatura (até 4 g, com a balança parada perto do zero). Se ficar abaixo de −10 g, o LCD alterna com `Aperte TARA`, e o ENVIAR responde `Prato vazio` |
| Tara errada | O TARA espera o peso parar e usa 16 leituras novas. A tara fica **salva**: ao religar, a balança já começa zerada (na primeira vez ela faz a tara sozinha) |
| Calibração difícil | **Segure TARA 4 s** e siga o LCD, ou use `c <kg>` no Serial. O fator fica salvo, com o sinal certo |
| Valor errado enviado | O ENVIAR usa só leituras de **depois do clique** e espera o peso parar. Recusa prato vazio e mais de 10 kg |
| Internet caiu | Os totais ficam numa fila na memória da ESP32 e são reenviados sozinhos, com a data e a refeição originais |
| Mais de 10 kg de desperdício | Pese em partes: cada ENVIAR **soma** ao total da refeição |

### Soma das pesagens e o site

O site guarda **um total por empresa + dia + refeição** e cada envio substitui o anterior. A balança soma as pesagens e
manda o **total já somado**, então reenviar nunca duplica. A cada minuto o ping traz os totais de hoje que estão no
site: se alguém corrigir o desperdício em **Produção** ou em **Testar pesagem**, a próxima pesagem soma a partir do
valor corrigido (espere ~1 minuto depois da correção). No Serial, `u` desfaz a última pesagem e `z` zera o total de hoje
da refeição selecionada. Para o total ser sempre "a última pesagem", mude `SOMAR_PESAGENS` para `false`.

## Primeira vez: configurar e calibrar

1. No sketch, preencha `WIFI_SSID`, `WIFI_SENHA`, `URL_SERVIDOR` e `TOKEN_DISPOSITIVO` (o token de uma **Balança de
   desperdício** criada em Configurações → Integrações). Deixe `MODO_SIMULADOR = false`. Grave na ESP32 com a Arduino
   IDE (placa "ESP32 Dev Module") ou com PlatformIO, com as bibliotecas de `libraries.txt`.
2. Ligue com o prato **vazio** e abra o Monitor Serial a **115200** baud. Na primeira vez ela faz a tara sozinha.
3. Deixe ligada **5 a 10 minutos** (o HX711 e a célula esquentam e o zero se acomoda) e aperte **TARA**.
4. Separe um **peso conhecido** de 1 kg ou mais (melhor 2 a 5 kg): pacote de arroz ou feijão, garrafa d'água, haltere.
   Confira o peso dele numa balança de cozinha ou de mercado. Escreva o valor em `PESO_CALIBRACAO_KG` (padrão 1.000).
5. **Segure TARA por 4 s**. O LCD mostra `CALIBRACAO` / `Esvazie + TARA`. Com o prato vazio, aperte **TARA**.
6. O LCD pede `Ponha 1.000 kg`. Coloque o peso **no meio do prato**, tire a mão e aperte **ENVIAR**. Aparece
   `Calibrado!`. (O amarelo cancela.)
7. Retire o peso, aperte TARA e confira com **outro** peso conhecido. Mova o mesmo peso pelo prato: a leitura deve
   variar no máximo alguns gramas. Se variar muito, reveja a montagem (prato encostando, base torta, parafuso longo).

Pelo Serial dá para calibrar com qualquer peso: `t` com o prato vazio, coloque o peso e digite `c 2.345` (o valor em kg).
Para uma célula de 10 kg o fator costuma ficar perto de **±215 mil unidades/kg**; **negativo é normal**. A calibração e
a tara ficam salvas: só repita se trocar a célula, a montagem ou o HX711.

## Comandos do Serial (115200 baud, "Nova linha")

| Comando | O que faz |
|---|---|
| `?` | Lista os comandos |
| `t` | Tara (igual ao botão azul) |
| `c <kg>` | Calibra com um peso conhecido sobre o prato (tara feita antes, vazia). Ex.: `c 2.000` |
| `f <fator>` | Define o fator na mão (aceita negativo) |
| `d` | Diagnóstico: leituras por segundo, ruído em gramas, tara, fator, zero automático, Wi-Fi, hora, fila e totais |
| `r` | Liga/desliga o valor bruto ao vivo (aperte o prato com o dedo: o número deve mudar sempre no mesmo sentido) |
| `l` | Liga/desliga o peso ao vivo (a cada 2 s) |
| `e` / `m` | Pesa e envia / troca a refeição (iguais aos botões) |
| `u` / `z` | Desfaz a última pesagem de hoje / zera o total de hoje da refeição selecionada |

## Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Número pulando muito, `Peso instavel`, `Instavel: tente` | Fios da célula no protoboard, fios longos, prato encostando, mesa balançando | Solde os fios, encurte, rode `d` e veja o ruído em gramas (o bom é menos de 5 g) |
| `0.000 kg` alternando com `Aperte TARA` | A leitura ficou abaixo do zero: tara feita com algo em cima que foi tirado | Prato vazio e TARA |
| Peso certo, mas "anda" alguns gramas depois de ligar | Aquecimento (deriva térmica) | Espere 5 a 10 min; o zero automático corrige o resto |
| O peso muda quando o objeto muda de lugar no prato | Montagem: prato não centrado, prato ou parafuso encostando, base entortando | Reveja a [arquitetura mecânica](#arquitetura-mecânica-o-que-mais-influencia-a-precisão) |
| Lê bem pouco ou não muda com peso | Barra sem vão livre (as duas pontas presas na mesma peça, meio encostando) | Espaçadores dos dois lados; ponta fixa na base e ponta livre no prato |
| 1 kg aparece como outro valor | Calibração com peso errado, ou fator de outra montagem | Calibre de novo com um peso conferido |
| `Sensor sem sinal` | HX711 sem resposta | Confira DT=GPIO16, SCK=GPIO17, VCC em 3V3 e GND |
| `Sensor: ligacao` / bruto parado em ±8388607 | Fio da célula solto ou em curto | Confira E+, E−, A+, A− e as soldas |
| `Calibre: TARA 4s` | Nunca calibrada | [Primeira vez](#primeira-vez-configurar-e-calibrar) |
| `Sem hora: rede?` ao enviar | A ESP32 ainda não sabe a data: ela perde a hora toda vez que reinicia e só recupera com um ping que dê certo (ou pela internet, NTP) | Sem hora, a balança tenta o site a cada 10 s e de novo ao apertar ENVIAR (`Acertando hora..`). Se continuar, o ping está falhando: o visor web mostra o motivo em "Conexão da balança" (token recusado, endereço errado, sem acesso ao site); no Monitor Serial aparece como `Ping: HTTP ...` |
| `!` na linha de cima e LED vermelho | Totais esperando envio | Confira Wi-Fi, `URL_SERVIDOR` e o token; o Serial explica o código HTTP (401 = token) |
| `Acima capacidade` / `SOBRECARGA!` | Mais de 10 kg | Divida em mais pesagens (elas se somam) |
| LCD apagado ou só quadrados | Endereço I2C ou contraste | O Serial avisa se não achou o LCD em 0x27 (tente 0x3F em `LCD_ENDERECO`); gire o potenciômetro azul atrás do LCD |

## Ajustes no código

| Constante | Padrão | Para que serve |
|---|---|---|
| `CAPACIDADE_KG` | 10 | Capacidade da célula (limite de envio e conferência do fator) |
| `PESO_CALIBRACAO_KG` | 1.000 | Peso conhecido da calibração pelos botões |
| `DIVISAO_KG` | 0.005 | Degrau do LCD (5 g). Use 0.010 se o último dígito ainda oscilar |
| `SOMAR_PESAGENS` | true | Soma as pesagens no total da refeição (false: o total é a última pesagem) |
| `ESTAVEL_KG` | 0.020 | Variação máxima para considerar o peso parado |
| `ZERO_AUTO_KG` / `ZERO_AUTO_LIMITE_KG` | 0.004 / 0.100 | Faixa do zero automático e o máximo que ele corrige desde a última tara |
| `MODO_SIMULADOR` | false | `true` só no Wokwi |

## Publicar no site

1. Faça backup do banco. Se o site ainda não tem a balança, execute `config/migrations/006_balanca.sql` (pode repetir).
2. Publique `app/controllers/IotController.php` e `app/models/Balanca.php`: o ping da balança passou a devolver os
   totais de hoje. Um site antigo continua aceitando a balança v6 (ela só não recebe as correções feitas no site).
3. Em **Configurações → Integrações → Adicionar dispositivo**, escolha **Balança de desperdício** e copie o token
   (aparece uma vez). Cada aparelho tem o seu; use uma balança por refeição/local para não haver disputa de totais.
4. O quadro **Desperdício de hoje** mostra os quatro totais. **Testar pesagem** grava um total sem precisar do aparelho.

O firmware usa `setInsecure()` no HTTPS (aceita qualquer certificado), o que serve para o projeto e o simulador. Em
produção, troque por `setCACert()` com o certificado raiz do site.

## Contrato da API

`POST /api/iot/desperdicio`, cabeçalhos `Content-Type: application/json` e `X-Device-Token`:

```json
{"desperdicio_kg":2.35,"tipo":"almoco","data":"2026-11-23"}
```

`data` é opcional (padrão: hoje). Aceita datas de 2000 até hoje, peso de 0 a 100000 kg e as quatro refeições
(`cafe`, `almoco`, `jantar`, `ceia`). Resposta 200 com `sucesso`, `mensagem`, `desperdicio_kg`, `tipo` e `data`. Token
ausente/revogado: 401; dispositivo que não é balança: 403; campos inválidos: 422. A empresa vem do token, nunca do JSON.
É **um total por empresa + dia + refeição**: um novo envio substitui o anterior (a balança manda o total somado).

`POST /api/iot/ping` (corpo `{}`) marca a balança como Online e responde, para balanças:

```json
{"sucesso":true,"hora":"2026-11-23 12:00:00","epoch":1795446000,"data":"2026-11-23",
 "desperdicio":{"cafe":0.0,"almoco":2.35,"jantar":0.0,"ceia":0.0}}
```

## Simulador Wokwi

1. Crie um projeto ESP32 e copie `esp32_acesso.ino` (sketch), `diagram.json` (circuito) e `libraries.txt`. O nome da
   biblioteca no gerenciador é **HX711 Arduino Library** (bogde).
2. Mude `MODO_SIMULADOR` para `true` (usa a rede `Wokwi-GUEST` sozinho e a célula simulada de 50 kg, já calibrada em
   420 unidades/kg) e preencha `URL_SERVIDOR` e `TOKEN_DISPOSITIVO`. O Wokwi roda na nuvem: não enxerga o localhost.
3. Inicie e aguarde `Wi-Fi conectado`. Com a célula em 0 kg, aperte o azul (TARA). Ajuste a célula para 2,5 kg (clique
   no HX711), escolha a refeição no amarelo e aperte o vermelho: `Enviado +2.50kg` e 2,50 kg em Integrações.
4. Aperte o vermelho de novo: o total vira 5,00 kg (as pesagens se somam). `u` no Serial desfaz.
5. Na DevKit V1 do Wokwi, o GPIO16 se chama **RX2** e o GPIO17 **TX2** (por isso o `diagram.json` liga DT → RX2 e
   SCK → TX2). No firmware os números continuam 16 e 17.

O Wokwi não tem ruído, deriva nem montagem mecânica: o que funciona nele pode precisar de calibração e cuidado com a
montagem na balança de verdade.

## Testes de desenvolvimento

Opcionais, rodam no computador (requerem g++) e compilam os trechos reais do firmware:

- `python3 tests/balanca_calibracao.py`: mediana, média móvel, degrau, calibração (inclusive negativa), LCD sem
  negativo e sem piscar, zero automático e uma célula de 10 kg simulada com ruído, picos e deriva.
- `python3 tests/balanca_totais.py`: soma das pesagens, fila offline, troca de dia, desfazer e sincronização com o site.
- `python3 tests/balanca_botoes.py`: clique, ruído, botão segurado e calibração (TARA 4 s).
- `python3 tests/balanca_circuito.py`: pinos do firmware × `diagram.json`.
- `python3 tests/balanca_http.py`: API no servidor local (`php -S 127.0.0.1:8000 -t public app/routes/web.php` com
  banco de teste; cria e apaga empresas temporárias).

Para compilar o firmware: `pio run -d iot/esp32_balanca`.
