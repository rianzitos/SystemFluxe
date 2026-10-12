/*
 * SICAPDA by FLUXE — Catraca com ESP32 + leitor RFID RC522
 *
 * Fluxo: o cartão encosta no leitor → a ESP32 lê o UID → envia para o servidor
 * (POST /api/iot/acesso) → o servidor responde se está autorizado e se é entrada ou
 * saída → a ESP32 aciona o relé da catraca (ou o LED vermelho, se negado).
 * O servidor é quem decide e quem grava em logs_acesso; a ESP32 só lê e obedece.
 *
 * Bibliotecas (Gerenciador de Bibliotecas da Arduino IDE / lib_deps do Wokwi):
 *   - MFRC522 (Miguel Balboa)
 *   - ArduinoJson 7.x (Benoit Blanchon)
 *
 * Ligações do RC522 (SPI padrão da ESP32):
 *   SDA(SS) → GPIO 5   SCK → GPIO 18   MOSI → GPIO 23   MISO → GPIO 19
 *   RST     → GPIO 22  3.3V → 3V3      GND  → GND      (o RC522 é 3,3 V: nunca ligue em 5 V)
 * Saídas: relé → GPIO 26, LED verde → GPIO 25, LED vermelho → GPIO 33, buzzer → GPIO 27.
 * Mude os pinos abaixo se o seu circuito for diferente.
 */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>

// ─── CONFIGURAÇÃO ────────────────────────────────────────────────────────────

const char* WIFI_SSID  = "Wokwi-GUEST";   // no Wokwi é este; na vida real, o Wi-Fi da empresa
const char* WIFI_SENHA = "";              // Wokwi-GUEST não tem senha
const int   WIFI_CANAL = 6;               // 6 acelera a conexão no Wokwi; use 0 (automático) fora dele

// Endereço do SICAPDA, sem a barra no final. O Wokwi roda na nuvem e NÃO enxerga o localhost
// do seu computador: use o site publicado (ou um túnel, como ngrok, para testar localmente).
const char* URL_SERVIDOR = "https://fluxeteam.com.br";

// Token deste dispositivo (coluna "token" da tabela dispositivos_iot). Cada catraca tem o seu.
// Não suba o token de verdade para o GitHub: troque só no seu computador.
const char* TOKEN_DISPOSITIVO = "COLE_AQUI_O_TOKEN_DO_DISPOSITIVO";

// 0 = leitor RC522 de verdade.  1 = simulado: você digita o UID no Monitor Serial e
// pressiona Enter (serve para testar no Wokwi sem a peça do RC522).
#define LEITOR_SIMULADO 0

const uint8_t PIN_RC522_SS     = 5;
const uint8_t PIN_RC522_RST    = 22;
const uint8_t PIN_RELE         = 26;
const uint8_t PIN_LED_VERDE    = 25;
const uint8_t PIN_LED_VERMELHO = 33;
const uint8_t PIN_BUZZER       = 27;

const unsigned long TEMPO_CATRACA_LIBERADA_MS = 3000;   // quanto tempo o relé fica acionado
const unsigned long INTERVALO_PING_MS         = 60000;  // "estou vivo" a cada 1 minuto

// ─── ESTADO ──────────────────────────────────────────────────────────────────

MFRC522 leitor(PIN_RC522_SS, PIN_RC522_RST);
unsigned long ultimoPing = 0;

// ─── LEITURA DO CARTÃO ───────────────────────────────────────────────────────

/*
 * Devolve true e preenche "uid" quando um cartão foi lido (ex.: "A1B2C3D4").
 * Se o circuito do Wokwi lê o cartão de outro jeito, troque só o corpo desta função:
 * o resto do programa só precisa receber o UID como texto.
 */
bool lerCartao(String& uid) {
#if LEITOR_SIMULADO
  if (!Serial.available()) return false;
  String linha = Serial.readStringUntil('\n');
  linha.trim();
  if (linha.length() == 0) return false;
  uid = linha;
  return true;
#else
  if (!leitor.PICC_IsNewCardPresent() || !leitor.PICC_ReadCardSerial()) return false;

  uid = "";
  for (byte i = 0; i < leitor.uid.size; i++) {
    if (leitor.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(leitor.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();

  leitor.PICC_HaltA();        // para de ler o mesmo cartão enquanto ele estiver encostado
  leitor.PCD_StopCrypto1();
  return true;
#endif
}

// ─── COMUNICAÇÃO COM O SERVIDOR ──────────────────────────────────────────────

void conectarWifi() {
  if (WiFi.status() == WL_CONNECTED) return;

  Serial.print("Conectando ao Wi-Fi");
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_SENHA, WIFI_CANAL);
  unsigned long inicio = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - inicio < 15000) {
    delay(250);
    Serial.print(".");
  }
  Serial.println(WiFi.status() == WL_CONNECTED ? " conectado" : " falhou");
}

/*
 * POST em /api/iot/<rota> com o token do dispositivo. Devolve o código HTTP (ou <= 0 se nem
 * chegou ao servidor) e o corpo da resposta em "resposta".
 */
int postar(const char* rota, const String& corpo, String& resposta) {
  WiFiClientSecure cliente;
  cliente.setInsecure();   // TESTE: aceita qualquer certificado. Em produção use cliente.setCACert(<certificado raiz do site>).

  HTTPClient http;
  http.setTimeout(8000);
  if (!http.begin(cliente, String(URL_SERVIDOR) + "/api/iot/" + rota)) return -1;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Token", TOKEN_DISPOSITIVO);

  int codigo = http.POST(corpo);
  if (codigo > 0) resposta = http.getString();
  http.end();
  return codigo;
}

void enviarPing() {
  if (WiFi.status() != WL_CONNECTED) return;
  String resposta;
  int codigo = postar("ping", "{}", resposta);
  Serial.printf("Ping: HTTP %d\n", codigo);
}

// ─── SINALIZAÇÃO E CATRACA ───────────────────────────────────────────────────

void bip(int vezes, int duracaoMs) {
  for (int i = 0; i < vezes; i++) {
    digitalWrite(PIN_BUZZER, HIGH);
    delay(duracaoMs);
    digitalWrite(PIN_BUZZER, LOW);
    if (i < vezes - 1) delay(duracaoMs);
  }
}

void liberarCatraca() {
  digitalWrite(PIN_LED_VERDE, HIGH);
  digitalWrite(PIN_RELE, HIGH);   // se o seu módulo de relé aciona com LOW, inverta aqui e no setup()
  bip(1, 150);
  delay(TEMPO_CATRACA_LIBERADA_MS);
  digitalWrite(PIN_RELE, LOW);
  digitalWrite(PIN_LED_VERDE, LOW);
}

void negarAcesso() {
  digitalWrite(PIN_LED_VERMELHO, HIGH);
  bip(3, 120);
  delay(800);
  digitalWrite(PIN_LED_VERMELHO, LOW);
}

// ─── PROCESSAMENTO DE UMA LEITURA ────────────────────────────────────────────

void processarCartao(const String& uid) {
  Serial.println("Cartão lido: " + uid);   // é este UID que se cadastra em credenciais_acesso

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Sem Wi-Fi: acesso negado.");
    negarAcesso();
    return;
  }

  JsonDocument corpo;
  corpo["uid"] = uid;
  String json;
  serializeJson(corpo, json);

  String resposta;
  int codigo = postar("acesso", json, resposta);

  // Falha de rede ou do servidor: por segurança a catraca NÃO abre (fail-closed).
  if (codigo != 200) {
    Serial.printf("Servidor respondeu HTTP %d: acesso negado.\n", codigo);
    negarAcesso();
    return;
  }

  JsonDocument dados;
  if (deserializeJson(dados, resposta)) {
    Serial.println("Resposta inválida do servidor: acesso negado.");
    negarAcesso();
    return;
  }

  bool autorizado = dados["autorizado"] | false;
  String mensagem = dados["mensagem"] | "";
  String nome     = dados["nome"] | "";
  String direcao  = dados["direcao"] | "";

  Serial.println((autorizado ? "LIBERADO " : "NEGADO ") + nome + " " + direcao + " — " + mensagem);

  if (autorizado) liberarCatraca();
  else negarAcesso();
}

// ─── ARDUINO ─────────────────────────────────────────────────────────────────

void setup() {
  Serial.begin(115200);

  pinMode(PIN_RELE, OUTPUT);
  pinMode(PIN_LED_VERDE, OUTPUT);
  pinMode(PIN_LED_VERMELHO, OUTPUT);
  pinMode(PIN_BUZZER, OUTPUT);
  digitalWrite(PIN_RELE, LOW);

#if !LEITOR_SIMULADO
  SPI.begin();
  leitor.PCD_Init();
#endif

  conectarWifi();
  enviarPing();
  ultimoPing = millis();
  Serial.println("Catraca pronta. Aproxime o cartão.");
}

void loop() {
  conectarWifi();

  String uid;
  if (lerCartao(uid)) {
    processarCartao(uid);
    delay(1000);   // folga para o cartão sair do leitor antes da próxima leitura
  }

  if (millis() - ultimoPing >= INTERVALO_PING_MS) {
    enviarPing();
    ultimoPing = millis();
  }
}
