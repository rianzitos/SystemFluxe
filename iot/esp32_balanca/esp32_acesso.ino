// SICAPDA by FLUXE — Balança de desperdício: ESP32 + HX711 (célula de carga) + 2 LEDs + 2 botões
// Circuito simulado no Wokwi, seguindo a mesma estrutura da catraca RFID.
//
// Fluxo: durante o turno o desperdício é colocado na balança. Ao apertar ENCERRAR TURNO, a ESP32
// lê o peso (média de várias leituras), converte para kg e faz POST /api/iot/desperdicio com o
// token do dispositivo. O servidor grava o valor e responde com sucesso. Se o envio falhar, o valor
// fica PENDENTE e é reenviado automaticamente até o servidor confirmar (nenhuma medição se perde).
//
// Bibliotecas (libraries.txt no Wokwi): HX711 (bogde) e ArduinoJson (versão 7)
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <HX711.h>

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

// ----- Pinos -----
#define HX_DT_PIN   16
#define HX_SCK_PIN  17
#define LED_GREEN   27   // sistema ativo (pisca ao enviar)
#define LED_RED     26   // falha no envio / envio pendente
#define BTN_TARA    32   // zera a balança (recipiente vazio)
#define BTN_FIM     33   // encerra o turno e envia o desperdício

// ----- Ajustes -----
// Fator de calibração: com a balança lendo em gramas, ajuste até um peso conhecido bater.
// No Wokwi, ~420 costuma dar gramas; na célula real, calibre com um peso de referência.
const float FATOR_CALIBRACAO = 420.0;
const int   AMOSTRAS = 20;                        // leituras por média (mais = mais estável)
const unsigned long PING_INTERVAL_MS = 60000;     // "estou vivo" a cada 1 minuto (mostra Online nas Configurações)
const unsigned long WIFI_RETRY_MS = 10000;        // intervalo entre tentativas de reconectar
const unsigned long REENVIO_MS = 10000;           // intervalo entre tentativas de reenviar um valor pendente
const unsigned long LOG_PESO_MS = 2000;           // intervalo do peso ao vivo no Serial

HX711 balanca;
unsigned long lastPing = 0;
unsigned long lastWifiTry = 0;
unsigned long lastReenvio = 0;
unsigned long lastLogPeso = 0;
bool temPendente = false;       // há um valor medido que o servidor ainda não confirmou
float pendenteKg = 0;

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

// Peso atual em kg (nunca negativo).
float lerPesoKg() {
  float gramas = balanca.get_units(AMOSTRAS);
  if (gramas < 0) gramas = 0;
  return gramas / 1000.0;
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
  } else if (code == 403 || code == 406) {
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
  if (code != 200) explicarFalha(code);
}

// Envia o desperdício (em kg) ao servidor. Só devolve true se o servidor confirmar a gravação.
bool enviarDesperdicio(float kg) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Sem Wi-Fi: envio adiado");
    return false;
  }
     String body = "{\"desperdicio_kg\":" + String(kg, 3) + ",\"tipo\":\"" + TIPO_REFEICAO + "\"}";

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
  piscarEnvio();
  if (enviarDesperdicio(pendenteKg)) {
    Serial.printf("Desperdicio de %.3f kg registrado no servidor\n", pendenteKg);
    temPendente = false;
    setStatus(true, false);
  } else {
    Serial.println("Envio nao confirmado: o valor continua pendente e sera reenviado");
    setStatus(true, true);
  }
}

void setup() {
  Serial.begin(115200);
  pinMode(LED_GREEN, OUTPUT);
  pinMode(LED_RED, OUTPUT);
  pinMode(BTN_TARA, INPUT_PULLUP);
  pinMode(BTN_FIM, INPUT_PULLUP);
  setStatus(false, false);

  balanca.begin(HX_DT_PIN, HX_SCK_PIN);
  balanca.set_scale(FATOR_CALIBRACAO);
  balanca.tare();

  // Resumo da configuração, para conferir de relance (o token aparece só pelas pontas).
  int tamanhoToken = strlen(TOKEN_DISPOSITIVO);
  Serial.printf("Servidor: %s\n", URL_SERVIDOR);
  Serial.printf("Token: %d caracteres (%.6s...%s)\n", tamanhoToken, TOKEN_DISPOSITIVO,
                TOKEN_DISPOSITIVO + (tamanhoToken > 4 ? tamanhoToken - 4 : 0));
  if (tamanhoToken != 64) Serial.println("ATENCAO: o token deve ter exatamente 64 caracteres. Copie de novo da caixa amarela (nao da lista).");

  connectWifi();
  sendPing();
  lastPing = millis();
  setStatus(true, false);   // LED verde aceso = sistema ativo
  Serial.println("Balanca pronta. TARA = zerar | ENCERRAR TURNO = enviar desperdicio");
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

  // Botão TARA: zera a balança
  if (digitalRead(BTN_TARA) == LOW) {
    balanca.tare();
    Serial.println("Balanca zerada");
    delay(400);   // evita repetir enquanto o botão está apertado
  }

  // Botão ENCERRAR TURNO: mede e envia
  if (digitalRead(BTN_FIM) == LOW) {
    if (temPendente) {
      Serial.println("Ja existe um envio pendente: aguarde a confirmacao do servidor");
    } else {
      pendenteKg = lerPesoKg();
      temPendente = true;
      Serial.printf("Turno encerrado. Desperdicio medido: %.3f kg\n", pendenteKg);
      lastReenvio = millis();
      tentarEnvioPendente();
    }
    delay(600);
  }

  // Peso ao vivo no Serial (ajuda a calibrar e a conferir no Wokwi)
  if (millis() - lastLogPeso >= LOG_PESO_MS) {
    lastLogPeso = millis();
    Serial.printf("Peso atual: %.3f kg\n", lerPesoKg());
  }
}