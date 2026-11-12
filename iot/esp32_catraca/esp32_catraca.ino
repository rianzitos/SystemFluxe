// SICAPDA by FLUXE — Catraca com RFID: ESP32 + MFRC522 + servo + 2 LEDs + visor LCD 16x2 (I2C)
// Circuito simulado no Wokwi pela equipe (mesmos pinos e mesmo comportamento da catraca).
//
// Diferença para a versão com lista fixa de UIDs: quem decide agora é o SERVIDOR.
// Cartão lido → POST /api/iot/acesso (com o token do dispositivo) → o servidor confere o
// cartão no banco, alterna entrada/saída, grava em logs_acesso e responde "autorizado" ou
// não → a ESP32 abre ou nega. Cadastrar ou desativar um cartão não exige regravar a ESP32.
//
// Visor: mostra o primeiro nome de quem passou o cartão e se foi ENTRADA ou SAIDA (o nome e a
// direção vêm na resposta do servidor). Se o visor não estiver ligado, a catraca funciona igual.
//
// Bibliotecas (libraries.txt no Wokwi): MFRC522, ESP32Servo, ArduinoJson (versão 7) e LiquidCrystal I2C

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>
#include <ESP32Servo.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

// ----- Rede e servidor -----
const char* WIFI_SSID  = "Wokwi-GUEST";   // no Wokwi é esta rede (sem senha); na vida real, o Wi-Fi da empresa
const char* WIFI_SENHA = "";
const int   WIFI_CANAL = 6;               // 6 acelera a conexão no Wokwi; use 0 (automático) fora dele

// Endereço do SICAPDA, sem a barra no final. O Wokwi no navegador roda na nuvem e NÃO enxerga o
// localhost do seu computador: use o site publicado ou um túnel (ex.: ngrok, que dá um https://...).
// Para testar o sistema local sem túnel, use "Simular leitura" em Configurações → Integrações.
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

// Visor LCD 16x2 com módulo I2C. O SCL "padrão" da ESP32 seria o GPIO 22, mas ele já é o RST do RC522,
// por isso o SCL do visor fica no GPIO 25 (o Wire aceita qualquer par de pinos).
#define LCD_SDA    21
#define LCD_SCL    25
const uint8_t LCD_ENDERECO = 0x27;               // alguns módulos reais usam 0x3F

// ----- Ajustes -----
const int ANGLE_CLOSED = 0;                      // catraca travada
const int ANGLE_OPEN = 90;                       // catraca liberada
const unsigned long OPEN_TIME_MS = 4000;         // tempo aberta para a pessoa passar
const unsigned long PING_INTERVAL_MS = 60000;    // "estou vivo" a cada 1 minuto (é o que mostra Online nas Configurações)
const unsigned long WIFI_RETRY_MS = 10000;       // intervalo entre tentativas de reconectar
const unsigned long MENSAGEM_NEGADO_MS = 3000;   // quanto tempo o aviso de acesso negado fica no visor

// Resultado de uma consulta ao servidor (ver consultarAcesso)
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
bool telaTemporaria = false;                     // true enquanto um aviso espera a hora de voltar ao "Passe o cartao"
unsigned long telaTemporariaAte = 0;
int wifiNaTela = -1;                             // último estado do Wi-Fi desenhado na tela de espera (-1 = nenhum)

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

// ----- Visor LCD 16x2 -----

// O LCD não tem letras acentuadas: "José" vira "Jose", "Conceição" vira "Conceicao".
// O texto chega do servidor em UTF-8; as letras acentuadas ocupam 2 bytes (0xC3 + letra).
String semAcentos(const String& texto) {
  // Letras de U+00C0 a U+00FF (À…ÿ), na ordem, trocadas pela letra simples.
  static const char TABELA[] = "AAAAAAACEEEEIIIIDNOOOOOxOUUUUYTs"   // U+00C0 a U+00DF
                               "aaaaaaaceeeeiiiidnooooo/ouuuuyty";  // U+00E0 a U+00FF
  String saida = "";
  for (unsigned int i = 0; i < texto.length(); i++) {
    uint8_t c = texto[i];
    if (c < 0x80) {
      saida += (char)c;
    } else if (c == 0xC3 && i + 1 < texto.length()) {
      uint8_t seguinte = texto[++i];
      if (seguinte >= 0x80 && seguinte <= 0xBF) saida += TABELA[seguinte - 0x80];
    }
    // qualquer outro byte não ASCII (outros alfabetos, emojis) é descartado
  }
  return saida;
}

// Escreve uma linha inteira (16 colunas) centralizada. Sobrescrever a linha toda dispensa
// lcd.clear(), que faria a tela piscar.
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

// Tela parada, à espera de um cartão (ou avisando que a rede caiu).
void telaEspera() {
  telaTemporaria = false;
  bool conectado = WiFi.status() == WL_CONNECTED;
  wifiNaTela = conectado ? 1 : 0;
  if (conectado) mostrarTela("SICAPDA", "Passe o cartao");
  else mostrarTela("SEM WI-FI", "Reconectando...");
}

// Mostra um aviso que se apaga sozinho (volta para a tela de espera depois de "ms").
void mostrarAviso(const String& linha1, const String& linha2, unsigned long ms) {
  mostrarTela(linha1, linha2);
  telaTemporaria = true;
  telaTemporariaAte = millis() + ms;
}

// Chamada a cada volta do loop: tira o aviso quando o tempo acaba e acompanha o Wi-Fi.
void atualizarTela() {
  if (!displayDisponivel) return;
  if (telaTemporaria) {
    if ((long)(millis() - telaTemporariaAte) >= 0) telaEspera();
    return;
  }
  if ((WiFi.status() == WL_CONNECTED ? 1 : 0) != wifiNaTela) telaEspera();
}

// Segunda linha da tela de acesso liberado, conforme o sentido devolvido pelo servidor.
String textoLiberado(const String& direcao) {
  if (direcao == "entrada") return "ENTRADA LIBERADA";
  if (direcao == "saida") return "SAIDA LIBERADA";
  return "ACESSO LIBERADO";
}

// Acesso negado ou falha: diz o motivo para a pessoa na fila saber o que fazer.
void mostrarNegado(int resultado, const String& motivo, const String& mensagem) {
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

// Sem o visor (ou com ele mal ligado) a catraca continua funcionando: só não mostra nada.
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

// ----- Wi-Fi e servidor -----

void connectWifi() {
  mostrarTela("Conectando", "ao Wi-Fi...");
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

// Pergunta ao servidor se o cartão pode passar. Por segurança, qualquer falha (sem Wi-Fi,
// servidor fora do ar, token recusado, resposta estranha) NEGA o acesso: a catraca nunca abre "no escuro".
//
// Devolve ACESSO_LIBERADO, ACESSO_NEGADO (o servidor respondeu "não"), FALHA_SEM_WIFI ou
// FALHA_SEM_RESPOSTA. Preenche, quando o servidor responde: o primeiro nome da pessoa, a direção
// ("entrada" ou "saida"), o motivo da recusa ("cartao_desconhecido" ou "inativo") e a mensagem.
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

  pinMode(LED_RED, OUTPUT);
  pinMode(LED_GREEN, OUTPUT);

  gate.setPeriodHertz(50);
  gate.attach(SERVO_PIN, 500, 2400);

  SPI.begin();
  rfid.PCD_Init();

  closeGate();
  iniciarDisplay();

  // Resumo da configuração, para conferir de relance (o token aparece só pelas pontas).
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
  mostrarTela("Lendo cartao", "Aguarde...");   // a resposta do servidor pode levar alguns segundos

  String nome, direcao, motivo, mensagem;
  int resultado = consultarAcesso(uidAsText(), nome, direcao, motivo, mensagem);

  if (resultado == ACESSO_LIBERADO) {
    Serial.println("Acesso liberado");
    mostrarTela(nome.length() ? nome : String("Acesso liberado"), textoLiberado(direcao));   // ex.: "Carlos" / "ENTRADA LIBERADA"
    openGate();
    delay(OPEN_TIME_MS);   // catraca gira / pessoa passa
    closeGate();           // trava de novo
    Serial.println("Catraca travada");
    telaEspera();
  } else {
    Serial.println("Acesso negado");
    mostrarNegado(resultado, motivo, mensagem);   // o aviso se apaga sozinho (MENSAGEM_NEGADO_MS)
    denyAccess();
  }

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
}
