// SICAPDA by FLUXE — Catraca RFID: ESP32 DevKit + leitor MFRC522 + servo + LED vermelho + LED verde
//
// Como funciona
//   Parada:   LED vermelho aceso, catraca travada (servo em 0°).
//   Cartão:   a ESP32 lê o UID e pergunta ao SICAPDA (POST /api/iot/acesso, com o token do aparelho).
//             Quem decide é o servidor: confere o cartão, alterna entrada/saída e grava em logs_acesso.
//   Liberado: o vermelho apaga, o verde acende e o servo gira (catraca aberta por 4 s); depois trava de novo.
//   Negado:   o vermelho pisca 3 vezes (cartão não cadastrado ou desativado, sem Wi-Fi ou servidor recusou).
//
// Tela: a página /visor/catraca/ do SICAPDA (Chrome ou Edge, com a ESP32 no USB do computador). A cada mudança
// a ESP32 escreve no Serial uma linha "VISOR {json}" e a página desenha: nome e cargo de quem passou, ENTRADA ou
// SAÍDA e o horário, ou o motivo da negação. O UID do cartão nunca vai para a tela.
//
// Bibliotecas: platformio.ini (PlatformIO) ou libraries.txt (Wokwi): MFRC522, ESP32Servo e ArduinoJson 7.

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>
#include <ESP32Servo.h>

// ================================ CONFIGURAÇÃO ================================
// Wi-Fi da catraca de verdade. No simulador (MODO_SIMULADOR = true) a rede "Wokwi-GUEST" é usada sozinha.
const char* WIFI_SSID  = "NOME_DA_REDE";
const char* WIFI_SENHA = "SENHA_DA_REDE";
const bool MODO_SIMULADOR = false;

// Endereço do SICAPDA, sem a barra no final. O Wokwi roda na nuvem e não enxerga o localhost do computador.
const char* URL_SERVIDOR = "https://fluxeteam.com.br";

// Token da CATRACA: Configurações → Integrações → Adicionar dispositivo → Catraca (aparece uma vez só).
// O token da balança não serve aqui. Não suba o token de verdade para o GitHub: cole só na sua cópia.
const char* TOKEN_DISPOSITIVO = "COLE_AQUI_O_TOKEN_DO_DISPOSITIVO";

// ================================ PINOS ================================
// Leitor RC522: SDA 5, RST 22 e o SPI padrão da ESP32 (SCK 18, MISO 19, MOSI 23), alimentado em 3,3 V.
#define SS_PIN       5
#define RST_PIN      22
#define SERVO_PIN    13
// LEDs como estão ligados no protoboard. Se acender a cor errada, troque os dois números (ou os dois fios).
#define LED_VERMELHO 27   // aceso = catraca travada; pisca 3 vezes = acesso negado
#define LED_VERDE    26   // aceso = catraca liberada

// ================================ AJUSTES ================================
const int ANGULO_FECHADA = 0;
const int ANGULO_ABERTA  = 90;
const unsigned long TEMPO_ABERTA_MS = 4000;   // tempo para a pessoa passar
const unsigned long TELA_NEGADO_MS  = 3000;   // quanto tempo o motivo da negação fica no visor
const unsigned long PING_MS         = 60000;  // "estou vivo": é o que mostra Online em Integrações
const unsigned long WIFI_RETRY_MS   = 10000;  // nova tentativa de conectar ao Wi-Fi
const unsigned long VISOR_REPETE_MS = 3000;   // repete a tela de espera (para o visor aberto depois)

// ================================ TIPOS ================================
// Ficam no topo: o Arduino cria os protótipos das funções antes do resto do código.
enum Resultado { LIBERADO, NEGADO };

struct Resposta {
  Resultado resultado;
  String nome;       // primeiro nome, como o servidor manda
  String cargo;
  String direcao;    // "entrada" ou "saida"
  String motivo;     // negado: cartao_desconhecido, inativo, sem_wifi ou sem_resposta
  String mensagem;   // texto do servidor (também nas recusas, ex.: "Token de dispositivo inválido.")
  int codigoHttp;    // sem_resposta: o código que o site devolveu (<= 0: nem chegou ao site)
};

// ================================ ESTADO ================================
MFRC522 leitor(SS_PIN, RST_PIN);
Servo catraca;

unsigned long ultimoPing = 0;
unsigned long ultimaTentativaWifi = 0;
unsigned long ultimoVisor = 0;
unsigned long voltarParaEsperaEm = 0;   // != 0: o visor mostra uma negação e volta à espera nessa hora
int wifiNoVisor = -1;                   // Wi-Fi mostrado na tela de espera (1, 0 ou -1 = nenhum ainda)

// ================================ LEDS E SERVO ================================
void travar() {
  catraca.write(ANGULO_FECHADA);
  digitalWrite(LED_VERDE, LOW);
  digitalWrite(LED_VERMELHO, HIGH);
}

void abrir() {
  digitalWrite(LED_VERMELHO, LOW);
  digitalWrite(LED_VERDE, HIGH);
  catraca.write(ANGULO_ABERTA);
}

// Negação: o vermelho pisca 3 vezes e continua aceso (a catraca segue travada).
void piscarNegado() {
  for (int i = 0; i < 3; i++) {
    digitalWrite(LED_VERMELHO, LOW);
    delay(150);
    digitalWrite(LED_VERMELHO, HIGH);
    delay(150);
  }
}

// ================================ VISOR WEB ================================
// Uma linha "VISOR {json}" no Serial por tela; a página /visor/catraca/ lê e desenha.
void enviarVisor(JsonDocument& tela) {
  Serial.print("VISOR ");
  serializeJson(tela, Serial);
  Serial.println();
  ultimoVisor = millis();
}

void visorSimples(const char* tela) {
  JsonDocument doc;
  doc["tela"] = tela;
  enviarVisor(doc);
}

// Tela parada: "Aproxime o cartão" ou, sem rede, "Sem Wi-Fi".
void visorEspera() {
  bool conectado = WiFi.status() == WL_CONNECTED;
  wifiNoVisor = conectado ? 1 : 0;
  voltarParaEsperaEm = 0;
  visorSimples(conectado ? "espera" : "sem_wifi");
}

void visorLiberado(const Resposta& r) {
  JsonDocument doc;
  doc["tela"] = "liberado";
  doc["nome"] = r.nome;
  doc["cargo"] = r.cargo;
  doc["direcao"] = r.direcao;
  enviarVisor(doc);
}

void visorNegado(const Resposta& r) {
  JsonDocument doc;
  doc["tela"] = "negado";
  doc["motivo"] = r.motivo;
  doc["mensagem"] = r.mensagem;
  if (r.motivo == "sem_resposta") doc["codigo"] = r.codigoHttp;
  enviarVisor(doc);
  voltarParaEsperaEm = millis() + TELA_NEGADO_MS;
}

// A cada volta do loop: tira a negação depois de TELA_NEGADO_MS, acompanha o Wi-Fi e repete a espera
// de tempos em tempos (a página pode ter sido aberta agora).
void atualizarVisor() {
  if (voltarParaEsperaEm) {
    if ((long)(millis() - voltarParaEsperaEm) >= 0) visorEspera();
    return;
  }
  int conectado = WiFi.status() == WL_CONNECTED ? 1 : 0;
  if (conectado != wifiNoVisor || millis() - ultimoVisor >= VISOR_REPETE_MS) visorEspera();
}

// ================================ WI-FI ================================
void iniciarWifi() {
  WiFi.mode(WIFI_STA);
  if (MODO_SIMULADOR) WiFi.begin("Wokwi-GUEST", "", 6);   // canal 6 acelera a conexão no Wokwi
  else WiFi.begin(WIFI_SSID, WIFI_SENHA);
  ultimaTentativaWifi = millis();
}

void conectarWifi() {
  visorSimples("conectando");
  Serial.print("Conectando ao Wi-Fi");
  iniciarWifi();
  unsigned long inicio = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - inicio < 15000) {
    delay(250);
    Serial.print(".");
  }
  if (WiFi.status() == WL_CONNECTED) Serial.printf(" conectado (IP %s)\n", WiFi.localIP().toString().c_str());
  else Serial.println(" falhou. Confira WIFI_SSID e WIFI_SENHA; a catraca tenta de novo sozinha.");
}

// Se a rede cair, tenta voltar sem travar a leitura dos cartões.
void manterWifi() {
  if (WiFi.status() == WL_CONNECTED || millis() - ultimaTentativaWifi < WIFI_RETRY_MS) return;
  Serial.println("Wi-Fi fora do ar, reconectando...");
  WiFi.disconnect();
  iniciarWifi();
}

// ================================ SERVIDOR ================================
// POST em /api/iot/<caminho> com o token. Devolve o código HTTP (<= 0: nem chegou ao servidor).
// https:// usa conexão segura; http:// (servidor na rede local) usa conexão comum.
int postJson(const char* caminho, const String& corpo, String& resposta) {
  String url = String(URL_SERVIDOR) + "/api/iot/" + caminho;
  WiFiClientSecure clienteSeguro;
  WiFiClient clienteComum;
  HTTPClient http;
  http.setConnectTimeout(5000);
  http.setTimeout(8000);

  bool iniciou;
  if (url.startsWith("https://")) {
    clienteSeguro.setInsecure();   // TESTE: aceita qualquer certificado. Em produção: setCACert(<certificado raiz>)
    iniciou = http.begin(clienteSeguro, url);
  } else {
    iniciou = http.begin(clienteComum, url);
  }
  if (!iniciou) return -1;

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Token", TOKEN_DISPOSITIVO);
  int codigo = http.POST(corpo);
  if (codigo > 0) resposta = http.getString();
  else Serial.printf("Falha de conexao em %s: %s\n", url.c_str(), http.errorToString(codigo).c_str());
  http.end();
  return codigo;
}

// O que cada código quer dizer, em português (o mesmo aparece no visor).
void explicarFalha(int codigo) {
  if (codigo == 401) {
    Serial.println("HTTP 401: o servidor NAO reconheceu o token. Copie de novo o token da CATRACA (Configuracoes > Integracoes).");
  } else if (codigo == 403) {
    Serial.println("HTTP 403: o token e de uma BALANCA, nao de uma catraca (ou o firewall do servidor bloqueou).");
  } else if (codigo <= 0) {
    Serial.printf("HTTP %d: nao consegui falar com o servidor. Confira URL_SERVIDOR e se a rede tem internet.\n", codigo);
  } else if (codigo == 404) {
    Serial.println("HTTP 404: o servidor nao tem essa rota. Confira URL_SERVIDOR (inclusive a subpasta, se houver).");
  } else if (codigo == 406) {
    Serial.println("HTTP 406: o firewall do servidor bloqueou o pedido.");
  } else if (codigo != 200) {
    Serial.printf("HTTP %d: erro no servidor.\n", codigo);
  }
}

void enviarPing() {
  ultimoPing = millis();
  if (WiFi.status() != WL_CONNECTED) return;
  String resposta;
  int codigo = postJson("ping", "{}", resposta);
  Serial.printf("Ping: HTTP %d\n", codigo);
  explicarFalha(codigo);
}

// Pergunta ao servidor se o cartão pode passar. Qualquer falha (sem Wi-Fi, servidor fora do ar, token
// recusado, resposta estranha) NEGA: a catraca nunca abre "no escuro".
Resposta consultarServidor(const String& uid) {
  Resposta r;
  r.resultado = NEGADO;
  r.codigoHttp = 0;

  if (WiFi.status() != WL_CONNECTED) {
    r.motivo = "sem_wifi";
    return r;
  }

  JsonDocument pedido;
  pedido["uid"] = uid;
  String corpo;
  serializeJson(pedido, corpo);

  String texto;
  int codigo = postJson("acesso", corpo, texto);
  JsonDocument dados;
  bool json = codigo > 0 && !deserializeJson(dados, texto);
  if (json) r.mensagem = dados["mensagem"] | "";

  if (codigo != 200 || !json) {
    explicarFalha(codigo);
    if (codigo == 200) Serial.printf("Resposta do servidor nao e JSON: %.80s\n", texto.c_str());
    r.motivo = "sem_resposta";
    r.codigoHttp = codigo;
    return r;
  }

  r.resultado = (dados["autorizado"] | false) ? LIBERADO : NEGADO;
  r.nome = dados["nome"] | "";
  r.cargo = dados["cargo"] | "";
  r.direcao = dados["direcao"] | "";
  r.motivo = dados["motivo"] | "";
  return r;
}

// ================================ LEITOR RFID ================================
// UID como o servidor guarda: hexadecimal em maiúsculas, sem espaços (ex.: "01020304").
bool lerCartao(String& uid) {
  if (!leitor.PICC_IsNewCardPresent() || !leitor.PICC_ReadCardSerial()) return false;
  uid = "";
  for (byte i = 0; i < leitor.uid.size; i++) {
    if (leitor.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(leitor.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  leitor.PICC_HaltA();        // o mesmo cartão só é lido de novo quando sair e voltar
  leitor.PCD_StopCrypto1();
  return true;
}

// ================================ SETUP E LOOP ================================
void setup() {
  Serial.begin(115200);
  Serial.println();
  Serial.println("SICAPDA CATRACA - visor web: ligado (/visor/catraca/)");

  pinMode(LED_VERMELHO, OUTPUT);
  pinMode(LED_VERDE, OUTPUT);
  catraca.setPeriodHertz(50);
  catraca.attach(SERVO_PIN, 500, 2400);
  travar();

  SPI.begin();
  leitor.PCD_Init();
  byte versao = leitor.PCD_ReadRegister(MFRC522::VersionReg);
  if (versao == 0x00 || versao == 0xFF) {
    Serial.println("Leitor RC522 nao respondeu: confira SDA=5, SCK=18, MOSI=23, MISO=19, RST=22, 3.3V e GND.");
  }

  // Resumo da configuração, para conferir de relance (o token aparece só pelas pontas).
  int tamanhoToken = strlen(TOKEN_DISPOSITIVO);
  Serial.printf("Servidor: %s | token: %d caracteres (%.6s...%s)\n", URL_SERVIDOR, tamanhoToken, TOKEN_DISPOSITIVO,
                TOKEN_DISPOSITIVO + (tamanhoToken > 4 ? tamanhoToken - 4 : 0));
  if (tamanhoToken != 64) Serial.println("ATENCAO: o token tem 64 caracteres. Copie de novo da caixa amarela em Integracoes.");

  conectarWifi();
  enviarPing();
  visorEspera();
  Serial.println("Catraca pronta. Aproxime um cartao...");
}

void loop() {
  manterWifi();
  atualizarVisor();
  if (millis() - ultimoPing >= PING_MS) enviarPing();

  String uid;
  if (!lerCartao(uid)) return;

  Serial.printf("Cartao lido: %s\n", uid.c_str());
  visorSimples("lendo");
  Resposta r = consultarServidor(uid);

  if (r.resultado == LIBERADO) {
    Serial.printf("Liberado: %s (%s), %s\n", r.nome.c_str(), r.cargo.c_str(), r.direcao.c_str());
    visorLiberado(r);
    abrir();
    delay(TEMPO_ABERTA_MS);   // a pessoa passa
    travar();
    visorEspera();
  } else {
    Serial.printf("Negado: %s %s\n", r.motivo.c_str(), r.mensagem.c_str());
    visorNegado(r);
    piscarNegado();
  }
}
