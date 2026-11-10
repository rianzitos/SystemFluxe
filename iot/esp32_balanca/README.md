# Balança de desperdício — SICAPDA

ESP32 + HX711 + LCD1602 I2C e botão de seleção de refeição, seguindo o circuito em `diagram.json` e o firmware `esp32_acesso.ino`. A balança mede o **total de desperdício da refeição**, não a produção de alimentos. Esse total alimenta `producao_refeicoes`, a análise mensal e as consultas do assistente. A previsão em kg também depende dos dados de produção e acessos existentes.

## Publicar no site existente

1. Faça um backup do banco e execute `config/migrations/006_balanca.sql` no banco do SICAPDA (phpMyAdmin → SQL). A alteração apenas acrescenta `balanca` aos tipos de dispositivo; pode ser repetida. Importar novamente `database.sql` não altera o ENUM de uma tabela existente.
2. Publique os arquivos PHP e JS alterados, incluindo `app/models/Balanca.php`. Não envie o `.env` local, chaves, testes ou ferramentas do ambiente para a pasta pública.
3. Entre como administrador em **Configurações → Integrações → Adicionar dispositivo**. Escolha **Balança de desperdício**, nomeie e copie o token revelado. Cada aparelho tem seu próprio token.
4. A lista mantém o status Online/Offline e a ação Novo token. O painel **Desperdício de hoje** mostra os quatro totais; atualize a página após uma medição física. **Testar pesagem** grava um total real, sem marcar um aparelho desconectado como Online.

## Wokwi

1. Crie um projeto ESP32. Copie o conteúdo de `esp32_acesso.ino` para o sketch principal, `diagram.json` para o circuito e as três bibliotecas de `libraries.txt`. O nome no gerenciador é **HX711 Arduino Library**, de Bogdan Necula (bogde), e não apenas HX711.
2. Ajuste `URL_SERVIDOR` e `TOKEN_DISPOSITIVO` e troque `MODO_SIMULADOR` para `true` (a célula simulada já nasce calibrada em 420 unidades/kg e 50 kg; na balança física deixe `false`). A refeição é escolhida no botão amarelo **REFEIÇÃO** e aparece no LCD; não existe mais `TIPO_REFEICAO` fixo no código. O token deve ser de uma balança deste mesmo site. Nunca publique um token real no Git.
3. Inicie a simulação e aguarde `Ping: HTTP 200`. O ping sincroniza a data com o servidor, no fuso de São Paulo. O Wokwi na nuvem precisa de um endereço acessível; não alcança o localhost desta máquina.
4. Com a célula em **0 kg** (ou apenas o recipiente), pressione o botão azul **TARA**. Ajuste o peso da célula para **2,5 kg** e confira aproximadamente `2.500 kg` no Serial.
5. Use o botão amarelo **REFEIÇÃO** para selecionar Café, Almoço, Jantar ou Ceia. Confira a primeira linha do LCD antes de pressionar o botão vermelho **ENCERRAR TURNO**. Confirme a mensagem de gravação e o total **2,50 kg** em Integrações. Mantenha pressionado para verificar que não há envios contínuos.
6. Teste zero, outra refeição e interrupção de rede. Uma medição não confirmada fica na memória NVS do ESP32 com peso, refeição e data originais; reiniciar o aparelho não a transforma em uma pesagem do dia seguinte. Uma nova sessão do simulador pode apagar a memória simulada. Não faça novas medições até resolver a pendência.

A célula de 50 kg do Wokwi produz 21000 unidades para 50 kg: fator **420 unidades/kg**, sem dividir por mil. Esse valor vale **só no simulador**. Em hardware real é obrigatório calibrar com peso conhecido (veja [Balança física](#balança-física-4-células-de-50-kg--hx711)). A tara é manual após cada inicialização para evitar zerar uma carga que já esteja no recipiente.

## Balança física (4 células de 50 kg + HX711)

### Por que funciona no Wokwi e fica errada na bancada

O HX711 do Wokwi não reproduz uma célula real: devolve exatamente **420 unidades por kg**, com zero perfeito, sem ruído, sem deriva e com sinal sempre positivo. Na bancada nada disso vale:

| No Wokwi | Na balança física |
|---|---|
| Fator fixo de 420 unidades/kg | O fator depende das suas células e da ligação. Para 4 células de 50 kg costuma ficar na casa de **10 mil a 25 mil unidades/kg** (estimativa: o valor real sai da calibração). Com 420, 1 kg aparece como dezenas de kg → pesos irreais, rejeitados pelo limite de capacidade |
| Sinal sempre positivo | O sinal depende de como as células foram ligadas e montadas. Se o valor bruto **cai** ao colocar peso, o resultado sai negativo. O fator calibrado herda esse sinal e corrige a leitura |
| Sem ruído nem deriva | Offset de milhares de unidades, ruído de dezenas a centenas de unidades e deriva térmica nos primeiros minutos. Antes, qualquer −0,01 kg após a tara era rejeitado |
| Uma célula de 50 kg | Quatro células em ponte (capacidade 200 kg); todas precisam variar no mesmo sentido |

O firmware v5 trata isso: calibração com peso conhecido pelo Serial (fator com sinal, salvo na memória da ESP32), média aparada que descarta picos, faixa de ±0,05 kg em torno do zero e capacidade de 200 kg. Em balança física ele **recusa pesar enquanto não houver calibração**, para não enviar valores absurdos ao site. `MODO_SIMULADOR = true` volta ao comportamento do Wokwi (420 unidades/kg, limite de 50 kg).

### Ligação das 4 células

Cada célula de 50 kg é uma meia-ponte de 3 fios. Em geral o **vermelho é o ponto central** e preto/branco são as pontas. Quatro meias-pontes formam uma ponte completa assim:

| Emendas entre células | Ligações ao HX711 |
|---|---|
| preto 1 ↔ preto 2 | vermelho 1 → **E+** |
| branco 2 ↔ branco 3 | vermelho 2 → **A+** |
| preto 3 ↔ preto 4 | vermelho 3 → **E−** |
| branco 4 ↔ branco 1 | vermelho 4 → **A−** |

Além disso: HX711 VCC → 3V3, GND → GND, DT → GPIO16, SCK → GPIO17. Mantenha o HX711 em **3,3 V**: a 5 V o DT chegaria a 5 V no GPIO16, que não tolera isso.

Confirme com um multímetro (sem o HX711 energizado):

1. **Cada célula, antes de ligar:** o fio comum às duas menores resistências (cerca de 1 kΩ cada; a maior, entre as pontas, cerca de 2 kΩ) é o centro. Se não for o vermelho, use o fio central no papel do vermelho.
2. **Ponte montada:** E+↔E− e A+↔A− devem medir o mesmo valor (cerca de 2 kΩ), e cada par vizinho (E+↔A+, A+↔E−, E−↔A−, A−↔E+) cerca de 75% desse valor. Valores diferentes indicam emenda ruim ou fio trocado.

### Montagem mecânica e elétrica

- As **4 células devem ficar na mesma orientação**: uma ponta presa à base e a outra segurando a plataforma, todas flexionando no mesmo sentido. Uma célula invertida *subtrai* da soma e deixa a leitura incoerente ou negativa.
- Use espaçadores (arruelas) para a célula flexionar livremente e a plataforma **não encostar na base**. Plataforma rígida, nivelada, apoiada só nas 4 células.
- Fios finos das células não prendem bem no protoboard: **solde ou use borneira** nas emendas. Fios curtos, longe da antena Wi-Fi e do cabo USB.
- Capacitores de 100 nF e 10–100 µF entre VCC e GND do HX711, perto do módulo, e uma fonte USB estável.
- Deixe a balança ligada **5 a 10 minutos** antes de calibrar ou fazer a tara (deriva térmica).

### Calibração passo a passo

Abra o monitor Serial a 115200 baud com final de linha "Nova linha" (sem Enter o comando executa após 0,4 s de pausa). Digite `?` para ver os comandos.

| Comando | O que faz |
|---|---|
| `t` | Tara (o mesmo que o botão azul), com a plataforma vazia |
| `c <kg>` | Calibra: tara feita, peso conhecido sobre a plataforma. Ex.: `c 5.000` |
| `d` | Diagnóstico: bruto, ruído (máx−mín), offset, fator e peso, com alertas |
| `r` | Liga/desliga o bruto ao vivo, para testar cada canto |
| `f <fator>` | Define o fator manualmente (aceita negativo) |

1. Plataforma vazia: `t`, depois `d`. O ruído deve ficar abaixo de ~1000 unidades; bruto parado em ±8388607, 0 ou −1 indica entrada aberta ou fio solto.
2. Digite `r` e **aperte cada canto com a mão**: todos devem variar no **mesmo sentido** (todos sobem ou todos descem). Se um canto vai ao contrário, essa célula está invertida (montagem ou preto/branco trocados). Digite `r` de novo para sair.
3. Plataforma vazia: `t`. Coloque um **peso conhecido e centralizado**, de preferência 5 kg ou mais (pacotes de arroz/açúcar, galões de água, halteres). Digite `c 5.000` (use o valor real). Deve aparecer `Calibrado! Fator = ... Salvo na memoria`. **Fator negativo é normal.**
4. Retire o peso, faça `t` e confira com **outro peso conhecido**: o erro deve ser de 1–2%. Mova o mesmo peso pelos quatro cantos e pelo centro: a leitura deve se manter. Se variar muito, as células não estão equilibradas ou a plataforma está tocando a base.
5. Pronto: use TARA → desperdício → ENCERRAR TURNO como antes. O fator fica salvo; a tara continua manual a cada inicialização.

### Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| 1 kg aparece como dezenas de kg | Fator 420 do Wokwi, ou calibração com peso errado | Calibrar com `c <kg>` |
| Peso negativo ao colocar peso | Sinal invertido, ou tara feita com peso na plataforma | Calibrar (o fator negativo corrige); refazer a tara vazia |
| O valor muda ao mover o peso pela plataforma | Célula invertida/mal montada, plataforma tocando a base | Teste de cantos com `r`; refazer a montagem |
| Bruto parado em ±8388607, 0 ou −1 | Entrada aberta ou em curto, fio solto | Conferir E+, E−, A+, A− e as emendas |
| Ruído alto no `d`, leitura pulando | Mau contato no protoboard, fios longos, alimentação | Soldar, encurtar fios, capacitores |
| Deriva lenta após a tara | Aquecimento, temperatura | Esperar e refazer a tara |
| `Peso negativo` ao encerrar o turno | Tara com peso ou deriva | Refazer a tara com o recipiente vazio |

O firmware herdado usa `setInsecure()` para HTTPS no simulador. **Antes de instalar em hardware real**, substitua por `setCACert()` com a CA válida do servidor e sincronize o relógio antes da conexão TLS. Não utilize a configuração de teste como autenticação TLS de produção.

## Contrato da API

`POST /api/iot/desperdicio`, cabeçalhos `Content-Type: application/json` e `X-Device-Token`:

```json
{"desperdicio_kg":2.5,"tipo":"almoco","data":"2026-10-24"}
```

`data` é opcional para clientes antigos (padrão: hoje); o novo firmware sempre a envia. Aceita datas reais de 2000 até hoje, peso finito de 0 a 100000 kg e as quatro refeições. O firmware descarta leituras acima de 200 kg (balança física com 4 células de 50 kg) ou 50 kg (`MODO_SIMULADOR`). Resposta 200 inclui `sucesso`, `mensagem`, `desperdicio_kg`, `tipo` e `data`. Token ausente/revogado retorna 401; outro tipo de dispositivo, 403; campos inválidos, 422. A empresa é determinada pelo token, nunca pelo JSON.

**É um total por empresa + dia + refeição.** Repetir o mesmo envio não soma nem duplica. Um novo total substitui o anterior, preservando produção e previsão. Se houver várias balanças, coordene um único total final: não há soma automática nem histórico de cada pesagem. Um reenvio antigo para a mesma refeição/dia também substitui o total. Use um dispositivo responsável por refeição para evitar disputa.

## Validação local

Com PHP (PDO MySQL e mbstring), banco local migrado e servidor iniciado na raiz do projeto:

```sh
php -S 127.0.0.1:8000 -t public app/routes/web.php
# Em outro terminal:
python3 tests/balanca_http.py
```

O teste cria e apaga empresas temporárias; execute apenas no banco de desenvolvimento. Verifica gravação/reenvio, datas, validação, isolamento entre empresas, CSRF, cadastro, revogação e restrição de tipo. Não substitui o teste do circuito no Wokwi.

Para compilar com PlatformIO instalado: `pio run -d iot/esp32_balanca`. O projeto fixa ESP32 6.10.0, HX711 0.7.5, ArduinoJson 7.4.2 e LiquidCrystal I2C 1.1.2. Nesta implementação, a tentativa de compilação foi bloqueada por HTTP 403 no registro do PlatformIO; a compilação e a simulação física ainda precisam ser confirmadas no seu ambiente/Wokwi.

### Serial parado em `entry 0x400805dc`

As linhas `rst`, `load` e `entry` são do boot do ESP32, não da aplicação. Use o sketch atualizado e copie também `libraries.txt` (HX711 0.7.5 / ArduinoJson 7.4.2) e `diagram.json`. Após iniciar deve aparecer `SICAPDA BALANCA v5 - calibracao pelo Serial`, antes da inicialização do sensor ou da conexão Wi-Fi. Se essa mensagem não aparece, confira se substituiu o sketch principal do projeto e está vendo o Serial correto a 115200 baud. Se parar em `Inicializando HX711`, confira a biblioteca selecionada. Se mostrar `HX711 sem resposta`, confira DT/GPIO16, SCK/GPIO17, VCC e GND. Tara e médias agora têm prazo de 4 segundos, com cancelamento em vez de espera indefinida. O relógio das mensagens é o tempo simulado; confira também a velocidade da simulação.

### Ligações da ESP32 DevKit V1 no Wokwi

Nesta placa, GPIO16 se chama **RX2** no circuito e GPIO17 se chama **TX2**. Portanto, o `diagram.json` usa `cell1:DT → esp:RX2` e `cell1:SCK → esp:TX2`. Os terminais `esp:D16` e `esp:D17` não existem neste componente. No firmware, mantenha os números 16 e 17. Copie o diagrama atualizado ou refaça essas duas ligações antes de iniciar a simulação.

### Cliques nos botões

O firmware v4 lê os três botões em uma tarefa FreeRTOS separada a cada 5 ms, com debounce de 30 ms. Assim, a média de peso e o HTTP não impedem a detecção do clique. A tarefa entrega eventos ao loop (fila de até oito cliques); cada pressionamento gera uma ação e manter segurado não repete. A execução pode aguardar a medição ou requisição atual terminar. O monitoramento usa FreeRTOS, que já faz parte do ESP32. O LCD requer a biblioteca adicional indicada abaixo.

Após aparecer `Botoes ativos`, clique em TARA com o recipiente vazio e aguarde `Botao TARA detectado` / `Tara feita`. Adicione peso e clique em ENCERRAR TURNO; deve aparecer `Botao ENCERRAR TURNO detectado`, seguido do resultado do envio. Mantenha o recipiente parado até a confirmação. Se o evento não aparecer, confira `btnTara:1.l → esp:D32`, `btnFim:1.l → esp:D33` e os terminais `2.l` de ambos no GND. Os pinos GPIO32/33 usam INPUT_PULLUP.

Teste opcional da lógica de cliques no computador: `python3 tests/balanca_botoes.py` (requer g++). Ele simula clique rápido, ruído, botão segurado e eventos durante uma espera do loop; não substitui validação do firmware completo no Wokwi.

## LCD e seleção de refeição (v4)

Para atualizar um projeto Wokwi que já funciona, substitua **os três arquivos**: sketch (`esp32_acesso.ino`), `diagram.json` e `libraries.txt`. Recoloque somente a URL e o token do seu dispositivo no sketch. Não é necessário alterar o PHP ou executar outra migração no banco para esta atualização.

Bibliotecas no `libraries.txt` (nomes exatos do gerenciador):

```text
HX711 Arduino Library@0.7.5
ArduinoJson@7.4.2
LiquidCrystal I2C@1.1.2
```

| Componente | Ligação no circuito Wokwi |
|---|---|
| LCD1602 em modo I2C, endereço 0x27 | SDA → D21; SCL → D22; VCC → VIN; GND → GND.1 |
| Botão amarelo REFEIÇÃO | 1.l → D25; 2.l → GND.1 (INPUT_PULLUP) |
| HX711, tara e encerramento | Mantêm as ligações anteriores |

Essas ligações de alimentação são do simulador. Ao montar o LCD físico, confira a tensão do módulo e os pull-ups I2C: GPIOs do ESP32 operam em 3,3 V; módulos com pull-ups em 5 V precisam de adaptação de nível.

O LCD mostra `Ref: Almoco` na primeira inicialização. Cada clique amarelo avança **Almoço → Jantar → Ceia → Café → Almoço**; manter pressionado não repete. Os textos do LCD usam caracteres sem acento por compatibilidade. A segunda linha mostra peso em kg ou o estado (tara, envio, pendência ou erro). Aguarde a tela refletir sua escolha antes de encerrar. Os cliques continuam sendo capturados durante medições/rede e são processados em ordem depois da operação atual.

A seleção fica salva na memória NVS, inclusive após reiniciar (uma nova sessão do Wokwi pode reiniciar a memória simulada). A troca de refeição não altera o peso nem faz uma nova tara. Ao encerrar, o código salva peso, **refeição selecionada** e data no mesmo JSON pendente de antes. Se houver pendência, o LCD prioriza a refeição desse registro e o botão amarelo não a modifica enquanto o envio não for confirmado. Uma confirmação mostra a refeição enviada; em seguida a tela volta à seleção salva.

### Conferência no Wokwi

1. Inicie e confira `Botoes ativos` e a refeição no LCD.
2. Clique no amarelo quatro vezes, aguardando cada mudança, e confira a volta à refeição inicial. Segure-o: deve avançar apenas uma vez.
3. Faça a tara, coloque 2,5 kg, escolha Jantar e encerre. Confira 2,50 kg em Jantar no site. Repita com Ceia para confirmar registros separados.
4. Reinicie a ESP32 e confira a seleção salva. Faça a tara novamente antes de uma nova medição, como já era necessário.
5. Após sincronizar o horário, interrompa a rede e gere uma pesagem. Confira `Envio pendente`; a troca de refeição deve ser recusada. Ao voltar a rede, a pesagem deve chegar na refeição e data originais.

### Testes de desenvolvimento

`python3 tests/balanca_circuito.py` valida pinos e endereços do circuito. `python3 tests/balanca_botoes.py` valida os três botões. `python3 tests/balanca_calibracao.py` (requer g++) valida a média aparada, a tara, o fator de calibração (inclusive negativo), a persistência e a faixa de zero. `ARDUINOJSON_INCLUDE=/caminho/dos/headers python3 tests/balanca_refeicao.py` compila as funções reais de seleção/persistência e usa ArduinoJson 7.4.2 real, com memória, LCD e transporte simulados, cobrindo as quatro refeições, reinício, falha de memória, bloqueio e reenvio. Esses testes opcionais não são necessários para operar a balança. A compilação completa para ESP32 e a simulação do novo LCD ainda precisam ser confirmadas no Wokwi; o registro do PlatformIO retornou HTTP 403 neste ambiente.
