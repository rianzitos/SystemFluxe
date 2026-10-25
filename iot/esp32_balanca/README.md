# Balança de desperdício — SICAPDA

ESP32 + HX711, seguindo o circuito em `diagram.json` e o firmware `esp32_acesso.ino`. A balança mede o **total de desperdício da refeição**, não a produção de alimentos. Esse total alimenta `producao_refeicoes`, a análise mensal e as consultas do assistente. A previsão em kg também depende dos dados de produção e acessos existentes.

## Publicar no site existente

1. Faça um backup do banco e execute `config/migrations/006_balanca.sql` no banco do SICAPDA (phpMyAdmin → SQL). A alteração apenas acrescenta `balanca` aos tipos de dispositivo; pode ser repetida. Importar novamente `database.sql` não altera o ENUM de uma tabela existente.
2. Publique os arquivos PHP e JS alterados, incluindo `app/models/Balanca.php`. Não envie o `.env` local, chaves, testes ou ferramentas do ambiente para a pasta pública.
3. Entre como administrador em **Configurações → Integrações → Adicionar dispositivo**. Escolha **Balança de desperdício**, nomeie e copie o token revelado. Cada aparelho tem seu próprio token.
4. A lista mantém o status Online/Offline e a ação Novo token. O painel **Desperdício de hoje** mostra os quatro totais; atualize a página após uma medição física. **Testar pesagem** grava um total real, sem marcar um aparelho desconectado como Online.

## Wokwi

1. Crie um projeto ESP32. Copie o conteúdo de `esp32_acesso.ino` para o sketch principal, `diagram.json` para o circuito e as duas bibliotecas de `libraries.txt`.
2. Ajuste `URL_SERVIDOR`, `TOKEN_DISPOSITIVO` e `TIPO_REFEICAO` (`cafe`, `almoco`, `jantar` ou `ceia`). O token deve ser de uma balança deste mesmo site. Nunca publique um token real no Git.
3. Inicie a simulação e aguarde `Ping: HTTP 200`. O ping sincroniza a data com o servidor, no fuso de São Paulo. O Wokwi na nuvem precisa de um endereço acessível; não alcança o localhost desta máquina.
4. Com a célula em **0 kg** (ou apenas o recipiente), pressione o botão azul **TARA**. Ajuste o peso da célula para **2,5 kg** e confira aproximadamente `2.500 kg` no Serial.
5. Pressione o botão vermelho **ENCERRAR TURNO**. Confirme a mensagem de gravação e o total **2,50 kg** em Integrações. Mantenha pressionado para verificar que não há envios contínuos.
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

Para compilar com PlatformIO instalado: `pio run -d iot/esp32_balanca`. O projeto fixa ESP32 6.10.0, HX711 0.7.5 e ArduinoJson 7.4.2. Nesta implementação, a tentativa de compilação foi bloqueada por HTTP 403 no registro do PlatformIO; a compilação e a simulação física ainda precisam ser confirmadas no seu ambiente/Wokwi.

### Serial parado em `entry 0x400805dc`

As linhas `rst`, `load` e `entry` são do boot do ESP32, não da aplicação. Use o sketch atualizado e copie também `libraries.txt` (HX711 0.7.5 / ArduinoJson 7.4.2) e `diagram.json`. Após iniciar deve aparecer `SICAPDA BALANCA v2 - iniciando setup`, antes da inicialização do sensor ou da conexão Wi-Fi. Se essa mensagem não aparece, confira se substituiu o sketch principal do projeto e está vendo o Serial correto a 115200 baud. Se parar em `Inicializando HX711`, confira a biblioteca selecionada. Se mostrar `HX711 sem resposta`, confira DT/GPIO16, SCK/GPIO17, VCC e GND. Tara e médias agora têm prazo de 4 segundos, com cancelamento em vez de espera indefinida. O relógio das mensagens é o tempo simulado; confira também a velocidade da simulação.
