# Balança de desperdício — SICAPDA

ESP32 + HX711 + LCD1602 I2C e botão de seleção de refeição, seguindo o circuito em `diagram.json` e o firmware `esp32_acesso.ino`. A balança mede o **total de desperdício da refeição**, não a produção de alimentos. Esse total alimenta `producao_refeicoes`, a análise mensal e as consultas do assistente. A previsão em kg também depende dos dados de produção e acessos existentes.

## Publicar no site existente

1. Faça um backup do banco e execute `config/migrations/006_balanca.sql` no banco do SICAPDA (phpMyAdmin → SQL). A alteração apenas acrescenta `balanca` aos tipos de dispositivo; pode ser repetida. Importar novamente `database.sql` não altera o ENUM de uma tabela existente.
2. Publique os arquivos PHP e JS alterados, incluindo `app/models/Balanca.php`. Não envie o `.env` local, chaves, testes ou ferramentas do ambiente para a pasta pública.
3. Entre como administrador em **Configurações → Integrações → Adicionar dispositivo**. Escolha **Balança de desperdício**, nomeie e copie o token revelado. Cada aparelho tem seu próprio token.
4. A lista mantém o status Online/Offline e a ação Novo token. O painel **Desperdício de hoje** mostra os quatro totais; atualize a página após uma medição física. **Testar pesagem** grava um total real, sem marcar um aparelho desconectado como Online.

## Wokwi

1. Crie um projeto ESP32. Copie o conteúdo de `esp32_acesso.ino` para o sketch principal, `diagram.json` para o circuito e as três bibliotecas de `libraries.txt`. O nome no gerenciador é **HX711 Arduino Library**, de Bogdan Necula (bogde), e não apenas HX711.
2. Ajuste apenas `URL_SERVIDOR` e `TOKEN_DISPOSITIVO`. A refeição é escolhida no botão amarelo **REFEIÇÃO** e aparece no LCD; não existe mais `TIPO_REFEICAO` fixo no código. O token deve ser de uma balança deste mesmo site. Nunca publique um token real no Git.
3. Inicie a simulação e aguarde `Ping: HTTP 200`. O ping sincroniza a data com o servidor, no fuso de São Paulo. O Wokwi na nuvem precisa de um endereço acessível; não alcança o localhost desta máquina.
4. Com a célula em **0 kg** (ou apenas o recipiente), pressione o botão azul **TARA**. Ajuste o peso da célula para **2,5 kg** e confira aproximadamente `2.500 kg` no Serial.
5. Use o botão amarelo **REFEIÇÃO** para selecionar Café, Almoço, Jantar ou Ceia. Confira a primeira linha do LCD antes de pressionar o botão vermelho **ENCERRAR TURNO**. Confirme a mensagem de gravação e o total **2,50 kg** em Integrações. Mantenha pressionado para verificar que não há envios contínuos.
6. Teste zero, outra refeição e interrupção de rede. Uma medição não confirmada fica na memória NVS do ESP32 com peso, refeição e data originais; reiniciar o aparelho não a transforma em uma pesagem do dia seguinte. Uma nova sessão do simulador pode apagar a memória simulada. Não faça novas medições até resolver a pendência.

A célula de 50 kg do Wokwi produz 21000 unidades para 50 kg: fator **420 unidades/kg**, sem dividir por mil. Em hardware real é obrigatório calibrar com peso conhecido e conferir tara/capacidade. A tara é manual após cada inicialização para evitar zerar uma carga que já esteja no recipiente.

O firmware herdado usa `setInsecure()` para HTTPS no simulador. **Antes de instalar em hardware real**, substitua por `setCACert()` com a CA válida do servidor e sincronize o relógio antes da conexão TLS. Não utilize a configuração de teste como autenticação TLS de produção.

## Contrato da API

`POST /api/iot/desperdicio`, cabeçalhos `Content-Type: application/json` e `X-Device-Token`:

```json
{"desperdicio_kg":2.5,"tipo":"almoco","data":"2026-10-24"}
```

`data` é opcional para clientes antigos (padrão: hoje); o novo firmware sempre a envia. Aceita datas reais de 2000 até hoje, peso finito de 0 a 100000 kg e as quatro refeições. O firmware limita a célula simulada a 50 kg. Resposta 200 inclui `sucesso`, `mensagem`, `desperdicio_kg`, `tipo` e `data`. Token ausente/revogado retorna 401; outro tipo de dispositivo, 403; campos inválidos, 422. A empresa é determinada pelo token, nunca pelo JSON.

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

As linhas `rst`, `load` e `entry` são do boot do ESP32, não da aplicação. Use o sketch atualizado e copie também `libraries.txt` (HX711 0.7.5 / ArduinoJson 7.4.2) e `diagram.json`. Após iniciar deve aparecer `SICAPDA BALANCA v4 - display e seletor de refeicao`, antes da inicialização do sensor ou da conexão Wi-Fi. Se essa mensagem não aparece, confira se substituiu o sketch principal do projeto e está vendo o Serial correto a 115200 baud. Se parar em `Inicializando HX711`, confira a biblioteca selecionada. Se mostrar `HX711 sem resposta`, confira DT/GPIO16, SCK/GPIO17, VCC e GND. Tara e médias agora têm prazo de 4 segundos, com cancelamento em vez de espera indefinida. O relógio das mensagens é o tempo simulado; confira também a velocidade da simulação.

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

`python3 tests/balanca_circuito.py` valida pinos e endereços do circuito. `python3 tests/balanca_botoes.py` valida os três botões. `ARDUINOJSON_INCLUDE=/caminho/dos/headers python3 tests/balanca_refeicao.py` compila as funções reais de seleção/persistência e usa ArduinoJson 7.4.2 real, com memória, LCD e transporte simulados, cobrindo as quatro refeições, reinício, falha de memória, bloqueio e reenvio. Esses testes opcionais não são necessários para operar a balança. A compilação completa para ESP32 e a simulação do novo LCD ainda precisam ser confirmadas no Wokwi; o registro do PlatformIO retornou HTTP 403 neste ambiente.
