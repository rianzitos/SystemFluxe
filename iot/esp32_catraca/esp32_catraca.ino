// SICAPDA by FLUXE — Catraca com RFID: ESP32 + MFRC522 + servo + 2 LEDs
// Circuito simulado no Wokwi pela equipe (mesmos pinos e mesmo comportamento da catraca).
//
// Diferença para a versão com lista fixa de UIDs: quem decide agora é o SERVIDOR.
// Cartão lido → POST /api/iot/acesso (com o token do dispositivo) → o servidor confere o
// cartão no banco, alterna entrada/saída, grava em logs_acesso e responde "autorizado" ou
// não → a ESP32 abre ou nega. Cadastrar ou desativar um cartão não exige regravar a ESP32.
//
// Bibliotecas (libraries.txt no Wokwi): MFRC522, ESP32Servo e ArduinoJson (versão 7)

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>
#include <ESP32Servo.h>

// ----- Rede e servidor -----
const char* WIFI_SSID  = "Wokwi-GUEST";   // no Wokwi é esta rede (sem senha); na vida real, o Wi-Fi da empresa
const char* WIFI_SENHA = "";
const int   WIFI_CANAL = 6;               // 6 acelera a conexão no Wokwi; use 0 (automático) fora dele

// Endereço do SICAPDA, sem a barra no final. O Wokwi roda na nuvem e NÃO enxerga o localhost
// do seu computador: use o site publicado (ou um túnel, como ngrok).
const char* URL_SERVIDOR = "https://fluxeteam.com.br";

// Token deste dispositivo: Configurações → Integrações → Adicionar dispositivo (aparece uma vez só).
// Não suba o token de verdade para o GitHub: cole só no seu computador.
const char* TOKEN_DISPOSITIVO = "COLE_AQUI_O_TOKEN_DO_DISPOSITIVO";

// ----- Pinos -----
#define SS_PIN     5    // SDA do RC522
#define RST_PIN    22   // RST do RC522
#define SERVO_PIN  13
#define LED_RED    26   // catraca fechada
#define LED_GREEN  27   // catraca aberta

// SCK = 18, MISO = 19, MOSI = 23 (SPI padrão do ESP32)

// ----- Ajustes -----
const int ANGLE_CLOSED = 0;                      // catraca travada
const int ANGLE_OPEN = 90;                       // catraca liberada
const unsigned long OPEN_TIME_MS = 4000;         // tempo aberta para a pessoa passar
const unsigned long PING_INTERVAL_MS = 60000;    // "estou vivo" a cada 1 minuto (é o que mostra Online nas Configurações)
const unsigned long WIFI_RETRY_MS = 10000;       // intervalo entre tentativas de reconectar

MFRC522 rfid(SS_PIN, RST_PIN);
Servo gate;

unsigned long lastPing = 0;
unsigned long lastWifiTry = 0;

void closeGate() {
  gate.write(ANGLE_CLOSED);
  digitalWrite(LED_GREEN, LOW);
  digitalWrite(LED_RED, HIGH);
}

void openGate() {
  digitalWrite(LED_RED, LOW);
  digitalWrite(LED_GREEN, HIGH);
  gate.write(ANGLE_OPEN);
}

void denyAccess() {
  // Acesso negado: LED vermelho pisca 3 vezes e a catraca continua fechada
  for (int i = 0; i < 3; i++) {
    digitalWrite(LED_RED, LOW);
    delay(150);
    digitalWrite(LED_RED, HIGH);
    delay(150);
  }
}

void printUid() {
  Serial.print("UID lido:");
  for (byte i = 0; i < rfid.uid.size; i++) {
    Serial.print(rfid.uid.uidByte[i] < 0x10 ? " 0" : " ");
    Serial.print(rfid.uid.uidByte[i], HEX);
  }
  Serial.println();
}

// UID como o servidor guarda: hexadecimal em maiúsculas, sem espaços (ex.: "01020304").
// É este texto que se cadastra em credenciais_acesso.identificador.
String uidAsText() {
  String uid = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(rfid.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  return uid;
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

// Se a rede cair, tenta voltar sem travar a leitura dos cartões.
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
int postJson(const char* path, const String& body, String& response) {
  WiFiClientSecure client;
  client.setInsecure();   // TESTE: aceita qualquer certificado. Em produção: client.setCACert(<certificado raiz do site>)

  HTTPClient http;
  http.setTimeout(8000);
  if (!http.begin(client, String(URL_SERVIDOR) + "/api/iot/" + path)) return -1;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Token", TOKEN_DISPOSITIVO);

  int code = http.POST(body);
  if (code > 0) response = http.getString();
  http.end();
  return code;
}

void sendPing() {
  if (WiFi.status() != WL_CONNECTED) return;
  String response;
  int code = postJson("ping", "{}", response);
  Serial.printf("Ping: HTTP %d\n", code);
}

// Pergunta ao servidor se o cartão pode passar. Por segurança, qualquer falha (sem Wi-Fi,
// servidor fora do ar, token recusado, resposta estranha) NEGA o acesso: a catraca nunca abre "no escuro".
bool isAuthorized(const String& uid) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Sem Wi-Fi: acesso negado");
    return false;
  }

  JsonDocument request;
  request["uid"] = uid;
  String body;
  serializeJson(request, body);

  String response;
  int code = postJson("acesso", body, response);
  if (code != 200) {
    Serial.printf("Servidor respondeu HTTP %d (401 = token errado): acesso negado\n", code);
    return false;
  }

  JsonDocument data;
  if (deserializeJson(data, response)) {
    Serial.println("Resposta invalida do servidor: acesso negado");
    return false;
  }

  bool authorized = data["autorizado"] | false;
  String name = data["nome"] | "";
  String direction = data["direcao"] | "";
  String message = data["mensagem"] | "";
  Serial.println(name + " " + direction + " - " + message);
  return authorized;
}

void setup() {
  Serial.begin(115200);

  pinMode(LED_RED, OUTPUT);
  pinMode(LED_GREEN, OUTPUT);

  gate.setPeriodHertz(50);
  gate.attach(SERVO_PIN, 500, 2400);

  SPI.begin();
  rfid.PCD_Init();

  closeGate();
  connectWifi();
  sendPing();
  lastPing = millis();
  Serial.println("Catraca pronta. Aproxime um cartao...");
}

void loop() {
  keepWifi();

  if (millis() - lastPing >= PING_INTERVAL_MS) {
    sendPing();
    lastPing = millis();
  }

  if (!rfid.PICC_IsNewCardPresent()) return;
  if (!rfid.PICC_ReadCardSerial()) return;

  printUid();

  if (isAuthorized(uidAsText())) {
    Serial.println("Acesso liberado");
    openGate();
    delay(OPEN_TIME_MS);   // catraca gira / pessoa passa
    closeGate();           // trava de novo
    Serial.println("Catraca travada");
  } else {
    Serial.println("Acesso negado");
    denyAccess();
  }

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
}
