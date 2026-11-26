# 1 "/tmp/tmp4p5xpg2f"
#include <Arduino.h>
# 1 "/home/user/SystemFluxe/iot/esp32_catraca/esp32_catraca.ino"
# 14 "/home/user/SystemFluxe/iot/esp32_catraca/esp32_catraca.ino"
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>
#include <ESP32Servo.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>


const char* WIFI_SSID = "Wokwi-GUEST";
const char* WIFI_SENHA = "";
const int WIFI_CANAL = 6;




const char* URL_SERVIDOR = "https://fluxeteam.com.br";



const char* TOKEN_DISPOSITIVO = "COLE_AQUI_O_TOKEN_DO_DISPOSITIVO";


#define SS_PIN 5
#define RST_PIN 22
#define SERVO_PIN 13
#define LED_RED 26
#define LED_GREEN 27





#define LCD_SDA 21
#define LCD_SCL 25
const uint8_t LCD_ENDERECO = 0x27;




const bool VISOR_WEB = true;
const unsigned long VISOR_WEB_MS = 3000;


const int ANGLE_CLOSED = 0;
const int ANGLE_OPEN = 90;
const unsigned long OPEN_TIME_MS = 4000;
const unsigned long PING_INTERVAL_MS = 60000;
const unsigned long WIFI_RETRY_MS = 10000;
const unsigned long MENSAGEM_NEGADO_MS = 3000;


const int ACESSO_LIBERADO = 1;
const int ACESSO_NEGADO = 0;
const int FALHA_SEM_WIFI = -1;
const int FALHA_SEM_RESPOSTA = -2;

MFRC522 rfid(SS_PIN, RST_PIN);
Servo gate;
LiquidCrystal_I2C lcd(LCD_ENDERECO, 16, 2);

unsigned long lastPing = 0;
unsigned long lastWifiTry = 0;

bool displayDisponivel = false;
bool telaTemporaria = false;
unsigned long telaTemporariaAte = 0;
int wifiNaTela = -1;
unsigned long visorUltimoEnvio = 0;
void closeGate();
void openGate();
void denyAccess();
void printUid();
String uidAsText();
String semAcentos(const String& texto);
void escreverLinhaLCD(uint8_t linha, const String& texto);
void mostrarTela(const String& linha1, const String& linha2);
void visorWebDados(const char* tela, const char* chave1, const String& valor1, const char* chave2, const String& valor2);
void visorWeb(const char* tela);
void telaEspera();
void mostrarAviso(const String& linha1, const String& linha2, unsigned long ms);
void atualizarTela();
String textoLiberado(const String& direcao);
void mostrarNegado(int resultado, const String& motivo, const String& mensagem);
void iniciarDisplay();
void connectWifi();
void keepWifi();
int postJson(const char* path, const String& body, String& response);
void explicarFalha(int code);
void sendPing();
int consultarAcesso(const String& uid, String& nome, String& direcao, String& motivo, String& mensagem);
void setup();
void loop();
#line 86 "/home/user/SystemFluxe/iot/esp32_catraca/esp32_catraca.ino"
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



String uidAsText() {
  String uid = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(rfid.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  return uid;
}





String semAcentos(const String& texto) {

  static const char TABELA[] = "AAAAAAACEEEEIIIIDNOOOOOxOUUUUYTs"
                               "aaaaaaaceeeeiiiidnooooo/ouuuuyty";
  String saida = "";
  for (unsigned int i = 0; i < texto.length(); i++) {
    uint8_t c = texto[i];
    if (c < 0x80) {
      saida += (char)c;
    } else if (c == 0xC3 && i + 1 < texto.length()) {
      uint8_t seguinte = texto[++i];
      if (seguinte >= 0x80 && seguinte <= 0xBF) saida += TABELA[seguinte - 0x80];
    }

  }
  return saida;
}



void escreverLinhaLCD(uint8_t linha, const String& texto) {
  String t = semAcentos(texto);
  if (t.length() > 16) t = t.substring(0, 16);
  int espacosAntes = (16 - (int)t.length()) / 2;

  String pronta = "";
  for (int i = 0; i < espacosAntes; i++) pronta += ' ';
  pronta += t;
  while (pronta.length() < 16) pronta += ' ';

  lcd.setCursor(0, linha);
  lcd.print(pronta);
}

void mostrarTela(const String& linha1, const String& linha2) {
  if (!displayDisponivel) return;
  escreverLinhaLCD(0, linha1);
  escreverLinhaLCD(1, linha2);
}




void visorWebDados(const char* tela, const char* chave1, const String& valor1, const char* chave2, const String& valor2) {
  if (!VISOR_WEB) return;
  JsonDocument doc;
  doc["tela"] = tela;
  if (chave1) doc[chave1] = valor1;
  if (chave2) doc[chave2] = valor2;
  Serial.print("VISOR ");
  serializeJson(doc, Serial);
  Serial.println();
  visorUltimoEnvio = millis();
}

void visorWeb(const char* tela) {
  visorWebDados(tela, nullptr, "", nullptr, "");
}


void telaEspera() {
  telaTemporaria = false;
  bool conectado = WiFi.status() == WL_CONNECTED;
  wifiNaTela = conectado ? 1 : 0;
  if (conectado) mostrarTela("SICAPDA", "Passe o cartao");
  else mostrarTela("SEM WI-FI", "Reconectando...");
  visorWeb(conectado ? "espera" : "sem_wifi");
}


void mostrarAviso(const String& linha1, const String& linha2, unsigned long ms) {
  mostrarTela(linha1, linha2);
  telaTemporaria = true;
  telaTemporariaAte = millis() + ms;
}



void atualizarTela() {
  if (telaTemporaria) {
    if ((long)(millis() - telaTemporariaAte) >= 0) telaEspera();
    return;
  }
  if ((WiFi.status() == WL_CONNECTED ? 1 : 0) != wifiNaTela) telaEspera();
  else if (VISOR_WEB && millis() - visorUltimoEnvio >= VISOR_WEB_MS) visorWeb(wifiNaTela ? "espera" : "sem_wifi");
}


String textoLiberado(const String& direcao) {
  if (direcao == "entrada") return "ENTRADA LIBERADA";
  if (direcao == "saida") return "SAIDA LIBERADA";
  return "ACESSO LIBERADO";
}


void mostrarNegado(int resultado, const String& motivo, const String& mensagem) {
  String motivoVisor = resultado == FALHA_SEM_WIFI ? String("sem_wifi")
                     : resultado == FALHA_SEM_RESPOSTA ? String("sem_resposta") : motivo;
  visorWebDados("negado", "motivo", motivoVisor, "mensagem", mensagem);
  if (resultado == FALHA_SEM_WIFI) {
    mostrarAviso("SEM WI-FI", "Acesso negado", MENSAGEM_NEGADO_MS);
  } else if (resultado == FALHA_SEM_RESPOSTA) {
    mostrarAviso("SEM RESPOSTA", "Tente de novo", MENSAGEM_NEGADO_MS);
  } else if (motivo == "cartao_desconhecido") {
    mostrarAviso("ACESSO NEGADO", "Nao cadastrado", MENSAGEM_NEGADO_MS);
  } else if (motivo == "inativo") {
    mostrarAviso("ACESSO NEGADO", "Desativado", MENSAGEM_NEGADO_MS);
  } else {
    mostrarAviso("ACESSO NEGADO", mensagem, MENSAGEM_NEGADO_MS);
  }
}


void iniciarDisplay() {
  Wire.begin(LCD_SDA, LCD_SCL);
  Wire.setTimeOut(50);
  Wire.beginTransmission(LCD_ENDERECO);
  if (Wire.endTransmission() != 0) {
    Serial.printf("LCD nao encontrado em 0x%02X. Confira SDA=%d, SCL=%d, VCC e GND. A catraca funciona normalmente, so sem o visor.\n",
                  LCD_ENDERECO, LCD_SDA, LCD_SCL);
    return;
  }
  lcd.init();
  lcd.backlight();
  displayDisponivel = true;
  mostrarTela("SICAPDA", "Iniciando...");
}



void connectWifi() {
  mostrarTela("Conectando", "ao Wi-Fi...");
  visorWeb("conectando");
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


void keepWifi() {
  if (WiFi.status() == WL_CONNECTED) return;
  if (millis() - lastWifiTry < WIFI_RETRY_MS) return;
  lastWifiTry = millis();
  Serial.println("Wi-Fi caiu, reconectando...");
  WiFi.disconnect();
  WiFi.begin(WIFI_SSID, WIFI_SENHA, WIFI_CANAL);
}




int postJson(const char* path, const String& body, String& response) {
  String url = String(URL_SERVIDOR) + "/api/iot/" + path;

  WiFiClientSecure clienteSeguro;
  WiFiClient clienteComum;
  HTTPClient http;
  http.setTimeout(8000);

  bool iniciou;
  if (url.startsWith("https://")) {
    clienteSeguro.setInsecure();
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


void explicarFalha(int code) {
  if (code == 401) {
    Serial.println("HTTP 401: o servidor NAO reconheceu o token. Confira TOKEN_DISPOSITIVO e se a catraca foi criada neste mesmo site.");
  } else if (code <= 0) {
    Serial.printf("HTTP %d: nao consegui falar com o servidor (nao e problema do token). Confira URL_SERVIDOR e a rede. "
                  "O Wokwi no navegador NAO enxerga o localhost do seu computador.\n", code);
  } else if (code == 404) {
    Serial.println("HTTP 404: o servidor nao tem essa rota. Confira URL_SERVIDOR (inclusive a subpasta, se houver) e se o codigo novo foi publicado.");
  } else if (code == 403 || code == 406) {
    Serial.printf("HTTP %d: o firewall do servidor bloqueou o pedido.\n", code);
  } else if (code == 405 || code == 422) {
    Serial.printf("HTTP %d: pedido recusado pelo servidor.\n", code);
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







int consultarAcesso(const String& uid, String& nome, String& direcao, String& motivo, String& mensagem) {
  nome = "";
  direcao = "";
  motivo = "";
  mensagem = "";

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Sem Wi-Fi: acesso negado");
    return FALHA_SEM_WIFI;
  }

  JsonDocument request;
  request["uid"] = uid;
  String body;
  serializeJson(request, body);

  String response;
  int code = postJson("acesso", body, response);
  if (code != 200) {
    explicarFalha(code);
    Serial.println("Acesso negado (sem resposta valida do servidor)");
    return FALHA_SEM_RESPOSTA;
  }

  JsonDocument data;
  if (deserializeJson(data, response)) {
    Serial.println("Resposta invalida do servidor: acesso negado");
    return FALHA_SEM_RESPOSTA;
  }

  bool authorized = data["autorizado"] | false;
  nome = data["nome"] | "";
  direcao = data["direcao"] | "";
  motivo = data["motivo"] | "";
  mensagem = data["mensagem"] | "";
  Serial.println(nome + " " + direcao + " - " + mensagem);
  return authorized ? ACESSO_LIBERADO : ACESSO_NEGADO;
}

void setup() {
  Serial.begin(115200);
  Serial.println();
  Serial.printf("SICAPDA CATRACA - visor web: %s\n", VISOR_WEB ? "ligado (/visor/catraca/)" : "desligado");

  pinMode(LED_RED, OUTPUT);
  pinMode(LED_GREEN, OUTPUT);

  gate.setPeriodHertz(50);
  gate.attach(SERVO_PIN, 500, 2400);

  SPI.begin();
  rfid.PCD_Init();

  closeGate();
  iniciarDisplay();


  int tamanhoToken = strlen(TOKEN_DISPOSITIVO);
  Serial.printf("Servidor: %s\n", URL_SERVIDOR);
  Serial.printf("Token: %d caracteres (%.6s...%s)\n", tamanhoToken, TOKEN_DISPOSITIVO,
                TOKEN_DISPOSITIVO + (tamanhoToken > 4 ? tamanhoToken - 4 : 0));
  if (tamanhoToken != 64) Serial.println("ATENCAO: o token deve ter exatamente 64 caracteres. Copie de novo da caixa amarela (nao da lista).");

  connectWifi();
  sendPing();
  lastPing = millis();
  telaEspera();
  Serial.println("Catraca pronta. Aproxime um cartao...");
}

void loop() {
  keepWifi();
  atualizarTela();

  if (millis() - lastPing >= PING_INTERVAL_MS) {
    sendPing();
    lastPing = millis();
  }

  if (!rfid.PICC_IsNewCardPresent()) return;
  if (!rfid.PICC_ReadCardSerial()) return;

  printUid();
  mostrarTela("Lendo cartao", "Aguarde...");
  visorWeb("lendo");

  String nome, direcao, motivo, mensagem;
  int resultado = consultarAcesso(uidAsText(), nome, direcao, motivo, mensagem);

  if (resultado == ACESSO_LIBERADO) {
    Serial.println("Acesso liberado");
    mostrarTela(nome.length() ? nome : String("Acesso liberado"), textoLiberado(direcao));
    visorWebDados("liberado", "nome", nome, "direcao", direcao);
    openGate();
    delay(OPEN_TIME_MS);
    closeGate();
    Serial.println("Catraca travada");
    telaEspera();
  } else {
    Serial.println("Acesso negado");
    mostrarNegado(resultado, motivo, mensagem);
    denyAccess();
  }

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
}