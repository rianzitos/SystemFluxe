// SICAPDA by FLUXE — Balança de desperdício: ESP32 + HX711 (célula de carga) + LCD I2C + 2 LEDs + 3 botões
// Circuito simulado no Wokwi, seguindo a mesma estrutura da catraca RFID.
//
// Fluxo: durante o turno o desperdício é colocado na balança. Ao apertar ENCERRAR TURNO, a ESP32
// lê o peso (média de várias leituras), converte para kg e faz POST /api/iot/desperdicio com o
// token do dispositivo. O servidor grava o valor e responde com sucesso. Se o envio falhar, o valor
// fica PENDENTE e é reenviado automaticamente até o servidor confirmar (nenhuma medição se perde).
//
// Bibliotecas (libraries.txt no Wokwi): HX711 (bogde), ArduinoJson (versão 7) e LiquidCrystal I2C
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <HX711.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <Preferences.h>
#include <time.h>
#include <sys/time.h>
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>
#include <freertos/queue.h>

// ----- Rede e servidor -----
const char* WIFI_SSID  = "Wokwi-GUEST";   // no Wokwi é esta rede (sem senha); na vida real, o Wi-Fi da empresa
const char* WIFI_SENHA = "";
const int   WIFI_CANAL = 6;               // 6 acelera a conexão no Wokwi; use 0 (automático) fora dele
// Endereço do SICAPDA, sem a barra no final. O Wokwi no navegador roda na nuvem e NÃO enxerga o
// localhost do seu computador: use o site publicado ou um túnel (ex.: ngrok, que dá um https://...).
const char* URL_SERVIDOR = "https://fluxeteam.com.br";
// Token deste dispositivo: Configurações → Integrações → Adicionar dispositivo (aparece uma vez só).
// Não suba o token de verdade para o GitHub: cole só no seu computador.
const char* TOKEN_DISPOSITIVO = "COLE_AQUI_O_TOKEN_DO_DISPOSITIVO";

// O botão amarelo escolhe a refeição. A API continua recebendo os mesmos códigos.
const char* const TIPOS_REFEICAO[] = {"cafe", "almoco", "jantar", "ceia"};
const char* const NOMES_REFEICAO[] = {"Cafe", "Almoco", "Jantar", "Ceia"};
const uint8_t TOTAL_REFEICOES = 4;
uint8_t refeicaoSelecionada = 1; // primeira inicialização: almoço
int refeicaoPendente = -1;

// ----- Pinos -----
#define HX_DT_PIN   16   // terminal RX2 da ESP32 DevKit V1 no Wokwi
#define HX_SCK_PIN  17   // terminal TX2 da ESP32 DevKit V1 no Wokwi
#define LED_GREEN   27   // sistema ativo (pisca ao enviar)
#define LED_RED     26   // falha no envio / envio pendente
#define BTN_TARA    32   // zera a balança (recipiente vazio)
#define BTN_FIM     33   // encerra o turno e envia o desperdício
#define BTN_REFEICAO 25  // alterna cafe → almoco → jantar → ceia
#define LCD_SDA     21
#define LCD_SCL     22
const uint8_t LCD_ENDERECO = 0x27;

// ----- Ajustes -----
// Wokwi 50 kg: 21000 unidades / 50 kg = 420 unidades por kg.
// Na célula real, calibre com um peso conhecido e mantenha a unidade em kg.
const float FATOR_CALIBRACAO = 420.0;
const int   AMOSTRAS = 20;                        // leituras por média (mais = mais estável)
const unsigned long PING_INTERVAL_MS = 60000;     // "estou vivo" a cada 1 minuto (mostra Online nas Configurações)
const unsigned long WIFI_RETRY_MS = 10000;        // intervalo entre tentativas de reconectar
const unsigned long REENVIO_MS = 10000;           // intervalo entre tentativas de reenviar um valor pendente
const unsigned long LOG_PESO_MS = 2000;           // intervalo do peso ao vivo no Serial

HX711 balanca;
LiquidCrystal_I2C lcd(LCD_ENDERECO, 16, 2);
bool displayDisponivel = false;
unsigned long lastPing = 0;
unsigned long lastWifiTry = 0;
unsigned long lastReenvio = 0;
unsigned long lastLogPeso = 0;
bool temPendente = false;       // há um valor medido que o servidor ainda não confirmou
float pendenteKg = 0;
Preferences memoria;
String pendenteJson;
QueueHandle_t eventosBotoes = nullptr;
const uint8_t EVENTO_TARA = 1;
const uint8_t EVENTO_FIM = 2;
const uint8_t EVENTO_REFEICAO = 3;

// INICIO DEBOUNCE: lógica independente para os testes de botões.
struct EstadoBotao {
  bool candidato;
  bool estavel;
  unsigned long mudouEm;
};

// Protótipo explícito para o pré-processador de sketches Arduino.
bool detectarPressionamento(EstadoBotao& botao, bool nivelAlto, unsigned long agora);

bool detectarPressionamento(EstadoBotao& botao, bool nivelAlto, unsigned long agora) {
  if (nivelAlto != botao.candidato) {
    botao.candidato = nivelAlto;
    botao.mudouEm = agora;
  }
  if (nivelAlto != botao.estavel && agora - botao.mudouEm >= 30) {
    botao.estavel = nivelAlto;
    return !nivelAlto; // INPUT_PULLUP: LOW significa botão pressionado.
  }
  return false;
}
// FIM DEBOUNCE

// Esta tarefa continua lendo os GPIOs durante a média do HX711 e o HTTP.
// Só o loop principal lê a balança, grava na memória ou faz pedidos ao site.
void monitorarBotoes(void*) {
  EstadoBotao tara = {true, true, 0};
  EstadoBotao fim = {true, true, 0};
  EstadoBotao refeicao = {true, true, 0};
  for (;;) {
    unsigned long agora = millis();
    if (detectarPressionamento(tara, digitalRead(BTN_TARA) == HIGH, agora)) {
      uint8_t evento = EVENTO_TARA;
      xQueueSend(eventosBotoes, &evento, 0);
    }
    if (detectarPressionamento(fim, digitalRead(BTN_FIM) == HIGH, agora)) {
      uint8_t evento = EVENTO_FIM;
      xQueueSend(eventosBotoes, &evento, 0);
    }
    if (detectarPressionamento(refeicao, digitalRead(BTN_REFEICAO) == HIGH, agora)) {
      uint8_t evento = EVENTO_REFEICAO;
      xQueueSend(eventosBotoes, &evento, 0);
    }
    vTaskDelay(pdMS_TO_TICKS(5));
  }
}

bool taraFeita = false;

// INICIO REFEICAO DISPLAY: somente o loop/setup acessa LCD e Preferences.
int indiceRefeicao(const char* tipo) {
  for (uint8_t i = 0; i < TOTAL_REFEICOES; i++) {
    if (strcmp(tipo, TIPOS_REFEICAO[i]) == 0) return i;
  }
  return -1;
}

void escreverLinhaLCD(uint8_t linha, const char* texto) {
  char preenchido[17];
  snprintf(preenchido, sizeof(preenchido), "%-16.16s", texto);
  lcd.setCursor(0, linha);
  lcd.print(preenchido); // apaga o restante da linha sem piscar todo o display
}

void mostrarDisplay(const char* estado) {
  if (!displayDisponivel) return;
  int indice = temPendente ? refeicaoPendente : refeicaoSelecionada;
  const char* nome = indice >= 0 && indice < TOTAL_REFEICOES ? NOMES_REFEICAO[indice] : "Desconhecida";
  char linha[17];
  snprintf(linha, sizeof(linha), "Ref: %.11s", nome);
  escreverLinhaLCD(0, linha);
  escreverLinhaLCD(1, estado);
}

void restaurarRefeicaoEPendencia() {
  uint8_t salva = memoria.getUChar("refeicao", 1);
  refeicaoSelecionada = salva < TOTAL_REFEICOES ? salva : 1;
  pendenteJson = memoria.getString("pendente", "");
  if (pendenteJson.length()) {
    JsonDocument salvo;
    if (!deserializeJson(salvo, pendenteJson)) {
      pendenteKg = salvo["desperdicio_kg"] | 0.0f;
      refeicaoPendente = indiceRefeicao(salvo["tipo"] | "");
      temPendente = true;
    }
  }
}

void trocarRefeicao() {
  if (temPendente) {
    Serial.println("Troca bloqueada: aguarde confirmar o envio pendente.");
    mostrarDisplay("Envio pendente");
    return;
  }
  uint8_t proxima = (refeicaoSelecionada + 1) % TOTAL_REFEICOES;
  if (memoria.putUChar("refeicao", proxima) != sizeof(uint8_t)) {
    Serial.println("Nao foi possivel salvar a refeicao; selecao mantida.");
    mostrarDisplay("Falha ao salvar");
    return;
  }
  refeicaoSelecionada = proxima;
  Serial.printf("Refeicao selecionada: %s\n", NOMES_REFEICAO[refeicaoSelecionada]);
  mostrarDisplay("Selecionada");
}

// Congela a seleção e a data na pesagem persistida. Reenvios usam este JSON,
// nunca a seleção atual do botão nem a data de uma nova tentativa.
bool guardarPesagem(float peso, const char* data) {
  if (temPendente) return false;
  JsonDocument registro;
  registro["desperdicio_kg"] = roundf(peso * 100) / 100;
  registro["tipo"] = TIPOS_REFEICAO[refeicaoSelecionada];
  registro["data"] = data;
  String json;
  serializeJson(registro, json);
  if (memoria.putString("pendente", json) == 0) return false;
  pendenteJson = json;
  pendenteKg = peso;
  refeicaoPendente = refeicaoSelecionada;
  temPendente = true;
  return true;
}
// FIM REFEICAO DISPLAY

void iniciarDisplay() {
  Wire.begin(LCD_SDA, LCD_SCL);
  Wire.setTimeOut(50);
  Wire.beginTransmission(LCD_ENDERECO);
  if (Wire.endTransmission() != 0) {
    Serial.println("LCD nao encontrado em 0x27. Confira SDA=21, SCL=22, VCC e GND.");
    return;
  }
  lcd.init();
  lcd.backlight();
  displayDisponivel = true;
  mostrarDisplay(temPendente ? "Envio pendente" : "Iniciando...");
}

void setStatus(bool ativo, bool falha) {
  digitalWrite(LED_GREEN, ativo ? HIGH : LOW);
  digitalWrite(LED_RED, falha ? HIGH : LOW);
}

// Verde pisca enquanto envia; depois volta ao estado normal (verde aceso, vermelho se houver pendência).
void piscarEnvio() {
  for (int i = 0; i < 3; i++) {
    digitalWrite(LED_GREEN, LOW);
    delay(120);
    digitalWrite(LED_GREEN, HIGH);
    delay(120);
  }
}

// Toda amostra tem prazo: get_units(n)/tare() esperam indefinidamente
// se o sensor parar de responder no meio da média.
bool lerMediaBruta(float& media) {
  const unsigned long prazoMs = 4000;
  unsigned long inicio = millis();
  long soma = 0;
  for (int i = 0; i < AMOSTRAS; i++) {
    unsigned long decorrido = millis() - inicio;
    if (decorrido >= prazoMs) return false;
    unsigned long restante = prazoMs - decorrido;
    if (!balanca.wait_ready_timeout(restante < 1000 ? restante : 1000)) return false;
    soma += balanca.read();
  }
  media = (float)soma / AMOSTRAS;
  return true;
}

float lerPesoKg() {
  float media;
  if (!lerMediaBruta(media)) return NAN;
  return (media - balanca.get_offset()) / FATOR_CALIBRACAO;
}

bool fazerTara() {
  float media;
  if (!lerMediaBruta(media)) return false;
  balanca.set_offset(lroundf(media));
  return true;
}

// ----- Wi-Fi e servidor -----
void connectWifi() {
  Serial.print("Conectando ao Wi-Fi");
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_SENHA, WIFI_CANAL);
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
    delay(250);
    Serial.print(".");
  }
  Serial.println(WiFi.status() == WL_CONNECTED ? " conectado" : " falhou");
}

// Se a rede cair, tenta voltar sem travar o resto.
void keepWifi() {
  if (WiFi.status() == WL_CONNECTED) return;
  if (millis() - lastWifiTry < WIFI_RETRY_MS) return;
  lastWifiTry = millis();
  Serial.println("Wi-Fi caiu, reconectando...");
  WiFi.disconnect();
  WiFi.begin(WIFI_SSID, WIFI_SENHA, WIFI_CANAL);
}

// POST em /api/iot/<path> com o token do dispositivo.
// Devolve o código HTTP (<= 0 se nem chegou ao servidor) e o corpo da resposta em "response".
// Endereço https:// usa conexão segura; http:// (servidor na rede local, por exemplo) usa conexão comum.
int postJson(const char* path, const String& body, String& response) {
  String url = String(URL_SERVIDOR) + "/api/iot/" + path;
  WiFiClientSecure clienteSeguro;
  WiFiClient clienteComum;
  HTTPClient http;
  http.setTimeout(8000);
  bool iniciou;
  if (url.startsWith("https://")) {
    clienteSeguro.setInsecure();   // TESTE: aceita qualquer certificado. Em produção: clienteSeguro.setCACert(<certificado raiz do site>)
    iniciou = http.begin(clienteSeguro, url);
  } else {
    iniciou = http.begin(clienteComum, url);
  }
  if (!iniciou) return -1;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Token", TOKEN_DISPOSITIVO);
  int code = http.POST(body);
  if (code > 0) response = http.getString();
  else Serial.printf("Falha de conexao em %s: %s\n", url.c_str(), http.errorToString(code).c_str());
  http.end();
  return code;
}

// Diz, em português, o que cada código de resposta quer dizer (só o 401 é problema do token).
void explicarFalha(int code) {
  if (code == 401) {
    Serial.println("HTTP 401: o servidor NAO reconheceu o token. Confira TOKEN_DISPOSITIVO e se o dispositivo foi criado neste mesmo site.");
  } else if (code <= 0) {
    Serial.printf("HTTP %d: nao consegui falar com o servidor (nao e problema do token). Confira URL_SERVIDOR e a rede. "
                  "O Wokwi no navegador NAO enxerga o localhost do seu computador.\n", code);
  } else if (code == 404) {
    Serial.println("HTTP 404: o servidor nao tem essa rota. Confira URL_SERVIDOR (inclusive a subpasta, se houver) e se o codigo novo foi publicado.");
  } else if (code == 403) {
    Serial.println("HTTP 403: confira se o token pertence a uma BALANCA; consulte tambem o firewall do servidor.");
  } else if (code == 406) {
    Serial.printf("HTTP %d: o firewall do servidor bloqueou o pedido.\n", code);
  } else if (code == 405 || code == 422) {
    Serial.printf("HTTP %d: pedido recusado pelo servidor (valor invalido?).\n", code);
  } else {
    Serial.printf("HTTP %d: erro no servidor.\n", code);
  }
}

void sendPing() {
  if (WiFi.status() != WL_CONNECTED) return;
  String response;
  int code = postJson("ping", "{}", response);
  Serial.printf("Ping: HTTP %d\n", code);
  if (code != 200) { explicarFalha(code); return; }
  JsonDocument dados;
  if (!deserializeJson(dados, response)) {
    long agora = dados["epoch"] | 0L;
    if (agora > 1700000000) {
      timeval tv = {agora, 0};
      settimeofday(&tv, nullptr);
    }
  }
}

// Envia o desperdício (em kg) ao servidor. Só devolve true se o servidor confirmar a gravação.
bool enviarDesperdicio() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Sem Wi-Fi: envio adiado");
    return false;
  }
  String body = pendenteJson;

  String response;
  int code = postJson("desperdicio", body, response);
  if (code != 200 && code != 201) {
    explicarFalha(code);
    return false;
  }
  JsonDocument data;
  if (deserializeJson(data, response)) {
    Serial.println("Resposta invalida do servidor: vou considerar nao confirmado");
    return false;
  }
  bool registrado = data["sucesso"] | false;
  String message = data["mensagem"] | "";
  Serial.println(message);
  return registrado;
}

// Tenta enviar o valor pendente; atualiza os LEDs conforme o resultado.
void tentarEnvioPendente() {
  if (!temPendente) return;
  Serial.printf("Enviando %.3f kg...\n", pendenteKg);
  mostrarDisplay("Enviando...");
  piscarEnvio();
  if (enviarDesperdicio()) {
    Serial.printf("Desperdicio de %.3f kg registrado no servidor\n", pendenteKg);
    mostrarDisplay("Enviado!"); // ainda mostra a refeição da pesagem confirmada
    memoria.remove("pendente");
    pendenteJson = "";
    temPendente = false;
    refeicaoPendente = -1;
    setStatus(true, false);
  } else {
    Serial.println("Envio nao confirmado: o valor continua pendente e sera reenviado");
    mostrarDisplay("Envio pendente");
    setStatus(true, true);
  }
}

void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println("SICAPDA BALANCA v4 - display e seletor de refeicao");
  Serial.flush();
  pinMode(LED_GREEN, OUTPUT);
  pinMode(LED_RED, OUTPUT);
  pinMode(BTN_TARA, INPUT_PULLUP);
  pinMode(BTN_FIM, INPUT_PULLUP);
  pinMode(BTN_REFEICAO, INPUT_PULLUP);
  setStatus(false, false);

  Serial.println("Inicializando HX711: DT=16, SCK=17...");
  Serial.flush();
  balanca.begin(HX_DT_PIN, HX_SCK_PIN);
  Serial.println("HX711 configurado. Verificando sinal...");
  if (!balanca.wait_ready_timeout(1000)) {
    Serial.println("HX711 sem resposta: confira DT=16, SCK=17, VCC e GND no diagram.json.");
  }
  balanca.set_scale(FATOR_CALIBRACAO);
  // A tara é explícita: reiniciar com carga não deve apagar o peso.

  // Resumo da configuração, para conferir de relance (o token aparece só pelas pontas).
  int tamanhoToken = strlen(TOKEN_DISPOSITIVO);
  Serial.printf("Servidor: %s\n", URL_SERVIDOR);
  Serial.printf("Token: %d caracteres (%.6s...%s)\n", tamanhoToken, TOKEN_DISPOSITIVO,
                TOKEN_DISPOSITIVO + (tamanhoToken > 4 ? tamanhoToken - 4 : 0));
  if (tamanhoToken != 64) Serial.println("ATENCAO: o token deve ter exatamente 64 caracteres. Copie de novo da caixa amarela (nao da lista).");

  setenv("TZ", "BRT3", 1);
  tzset();
  memoria.begin("sicapda-balanca", false);
  restaurarRefeicaoEPendencia();
  iniciarDisplay();
  mostrarDisplay("Conectando WiFi");
  connectWifi();
  mostrarDisplay("Sincronizando...");
  sendPing();
  lastPing = millis();
  mostrarDisplay(temPendente ? "Envio pendente" : "Faca a tara");
  setStatus(true, temPendente);
  eventosBotoes = xQueueCreate(8, sizeof(uint8_t));
  if (!eventosBotoes || xTaskCreate(monitorarBotoes, "botoes", 2048, nullptr, 1, nullptr) != pdPASS) {
    Serial.println("ERRO: nao foi possivel iniciar os botoes. Reinicie a ESP32.");
    setStatus(false, true);
  } else {
    Serial.println("Botoes ativos: TARA = GPIO32 | ENCERRAR TURNO = GPIO33 | REFEICAO = GPIO25.");
    Serial.println("Balanca pronta. TARA = zerar | ENCERRAR TURNO = enviar desperdicio");
  }
}

void loop() {
  keepWifi();

  if (millis() - lastPing >= PING_INTERVAL_MS) {
    sendPing();
    lastPing = millis();
  }

  // Reenvio automático de um valor que o servidor ainda não confirmou
  if (temPendente && millis() - lastReenvio >= REENVIO_MS) {
    lastReenvio = millis();
    tentarEnvioPendente();
  }

  uint8_t evento;
  while (eventosBotoes && xQueueReceive(eventosBotoes, &evento, 0) == pdTRUE) {
    if (evento == EVENTO_REFEICAO) trocarRefeicao();
    if (evento == EVENTO_TARA) {
      Serial.println("Botao TARA detectado.");
      mostrarDisplay(temPendente ? "Envio pendente" : "Fazendo tara...");
      if (temPendente) {
        Serial.println("Aguarde confirmar o envio pendente antes de fazer a tara.");
      } else {
        if (fazerTara()) {
          taraFeita = true;
          mostrarDisplay("Tara feita");
          Serial.println("Tara feita. Coloque o desperdicio no recipiente.");
        } else {
          taraFeita = false;
          mostrarDisplay("Falha no sensor");
          Serial.println("Tara falhou: HX711 sem resposta. Confira a ligacao do sensor.");
        }
      }
    }
    if (evento == EVENTO_FIM) {
      Serial.println("Botao ENCERRAR TURNO detectado.");
      if (temPendente) {
        Serial.println("Aguarde o envio pendente antes de uma nova pesagem.");
        mostrarDisplay("Envio pendente");
      } else if (!taraFeita) {
        Serial.println("Faca a tara com o recipiente vazio antes de pesar.");
        mostrarDisplay("Faca a tara");
      } else if (time(nullptr) < 1700000000) {
        Serial.println("Aguarde um ping confirmado para sincronizar a data.");
        mostrarDisplay("Aguarde o ping");
      } else {
        mostrarDisplay("Pesando...");
        float peso = lerPesoKg();
        if (!isfinite(peso) || peso < 0 || peso > 50) {
          Serial.println("Peso invalido ou sensor indisponivel (capacidade: 50 kg).");
          mostrarDisplay("Peso invalido");
        } else {
          time_t agora = time(nullptr);
          tm horario;
          localtime_r(&agora, &horario);
          char data[11];
          strftime(data, sizeof(data), "%Y-%m-%d", &horario);
          if (!guardarPesagem(peso, data)) {
            Serial.println("Falha ao guardar pesagem. Tente novamente.");
            mostrarDisplay("Falha ao salvar");
          } else {
            lastReenvio = millis();
            tentarEnvioPendente();
          }
        }
      }
    }
  }
  delay(5); // a tarefa de botões cuida do debounce, inclusive durante HTTP/leitura

  // Peso ao vivo no Serial (ajuda a calibrar e a conferir no Wokwi)
  if (millis() - lastLogPeso >= LOG_PESO_MS) {
    lastLogPeso = millis();
    float peso = lerPesoKg();
    if (isfinite(peso)) Serial.printf("Peso atual: %.3f kg\n", peso);
    else Serial.println("HX711 sem resposta: leitura cancelada; confira a ligacao do sensor.");
    if (temPendente) mostrarDisplay("Envio pendente");
    else if (!isfinite(peso)) mostrarDisplay("Falha no sensor");
    else if (!taraFeita) mostrarDisplay("Faca a tara");
    else {
      char linha[17];
      snprintf(linha, sizeof(linha), "Peso: %.2f kg", peso);
      mostrarDisplay(linha);
    }
  }
}