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
#include <ctype.h>
#include <math.h>
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
// true  = simulador Wokwi: a célula simulada de 50 kg gera 21000 unidades / 50 kg = 420 unidades por kg
//         e já nasce calibrada (não tem ruído, deriva nem sinal invertido).
// false = balança física (4 células de 50 kg + HX711): o fator de calibração (unidades do HX711 por kg)
//         é diferente em cada montagem, costuma ficar na casa de 10 mil a 25 mil e PODE SER NEGATIVO.
//         Calibre pelo Serial com um peso conhecido (comando "c <kg>"): o fator fica salvo na memória
//         da ESP32 e vale nas próximas inicializações, sem recompilar. Digite "?" no Serial para ver os comandos.
const bool  MODO_SIMULADOR = false;
const float FATOR_SIMULADOR = 420.0;
const float PESO_MAXIMO_KG = MODO_SIMULADOR ? 50.0 : 200.0;  // simulador: 1 célula de 50 kg; real: 4 x 50 kg
const float TOLERANCIA_ZERO_KG = 0.05;            // ruído/deriva menor que isso, após a tara, conta como 0 kg
const int   AMOSTRAS = 20;                        // leituras por média na tara e na pesagem (mais = mais estável)
const int   AMOSTRAS_AO_VIVO = 10;                // leituras por média no peso ao vivo do Serial/LCD
const unsigned long PING_INTERVAL_MS = 60000;     // "estou vivo" a cada 1 minuto (mostra Online nas Configurações)
const unsigned long WIFI_RETRY_MS = 10000;        // intervalo entre tentativas de reconectar
const unsigned long REENVIO_MS = 10000;           // intervalo entre tentativas de reenviar um valor pendente
const unsigned long LOG_PESO_MS = 2000;           // intervalo do peso ao vivo no Serial
const unsigned long LOG_BRUTO_MS = 300;           // intervalo do bruto ao vivo (comando "r" no Serial)

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

// INICIO CALIBRACAO: leitura filtrada, tara e fator de calibração (testável no computador).
float fatorCalibracao = 0;   // unidades do HX711 por kg; pode ser NEGATIVO (sinal invertido pela ligação)
bool calibrada = false;      // só há peso em kg depois de existir um fator (salvo ou do simulador)
bool sensorOk = false;       // a última leitura do HX711 respondeu dentro do prazo
double brutoAtual = 0;       // última média bruta lida (unidades do HX711), para o log e o diagnóstico
long dispersaoAtual = 0;     // maior - menor valor dessa leitura: mede o ruído

const int AMOSTRAS_MAX = 32;

// Lê "amostras" valores do HX711 e devolve a média APARADA: ordena e descarta os ~20% menores e os
// ~20% maiores, então um pico isolado (Wi-Fi, fio mal encaixado no protoboard) não distorce o resultado.
// "dispersao" = maior - menor valor lido; com a balança parada deve ser pequena.
// Toda amostra tem prazo: read()/get_units() esperam indefinidamente se o sensor parar de responder.
bool lerMediaBruta(double& media, int amostras, long& dispersao) {
  long valores[AMOSTRAS_MAX];
  if (amostras < 1) amostras = 1;
  if (amostras > AMOSTRAS_MAX) amostras = AMOSTRAS_MAX;
  const unsigned long prazoMs = 1000UL + 150UL * amostras;  // o HX711 faz 10 leituras/s: 20 amostras = ~2 s
  unsigned long inicio = millis();
  for (int i = 0; i < amostras; i++) {
    unsigned long decorrido = millis() - inicio;
    if (decorrido >= prazoMs) return false;
    unsigned long restante = prazoMs - decorrido;
    if (!balanca.wait_ready_timeout(restante < 1000 ? restante : 1000)) return false;
    valores[i] = balanca.read();
  }
  for (int i = 1; i < amostras; i++) {  // ordenação por inserção (no máximo 32 valores)
    long v = valores[i];
    int j = i - 1;
    while (j >= 0 && valores[j] > v) {
      valores[j + 1] = valores[j];
      j--;
    }
    valores[j + 1] = v;
  }
  dispersao = valores[amostras - 1] - valores[0];
  int corte = amostras / 5;
  double soma = 0;
  for (int i = corte; i < amostras - corte; i++) soma += valores[i];
  media = soma / (amostras - 2 * corte);
  return true;
}

// Bruto -> kg. O fator pode ser negativo: o sinal só indica o sentido em que a ligação inverte a leitura.
float brutoParaKg(double bruto, long offset, float fator) {
  return (float)((bruto - offset) / fator);
}

// Peso em kg (média filtrada). NAN se o HX711 não respondeu (sensorOk = false) ou se ainda não há fator.
float lerPesoKg(int amostras) {
  double media;
  long dispersao;
  sensorOk = lerMediaBruta(media, amostras, dispersao);
  if (!sensorOk) return NAN;
  brutoAtual = media;
  dispersaoAtual = dispersao;
  if (!calibrada) return NAN;
  float kg = brutoParaKg(media, balanca.get_offset(), fatorCalibracao);
  if (fabsf(kg) < TOLERANCIA_ZERO_KG) kg = 0;  // ruído em torno do zero não vira "-0,01 kg"
  return kg;
}

bool fazerTara() {
  double media;
  long dispersao;
  if (!lerMediaBruta(media, AMOSTRAS, dispersao)) return false;
  balanca.set_offset(lround(media));
  Serial.printf("Tara: bruto=%.0f (ruido=%ld)\n", media, dispersao);
  return true;
}

// Fator = variação do bruto / peso conhecido, mantendo o sinal. Devolve nullptr se deu certo
// ou o motivo da recusa (variação pequena demais perto do ruído, peso inválido...).
const char* calcularFator(double bruto, long offset, long dispersao, float pesoKg, float& fator) {
  if (!(pesoKg > 0.0f) || pesoKg > PESO_MAXIMO_KG) {
    return "Peso conhecido invalido: use um valor maior que 0 e dentro da capacidade.";
  }
  double variacao = bruto - offset;
  double minimo = 10.0 * (dispersao > 10 ? dispersao : 10);
  if (fabs(variacao) < minimo) {
    return "Variacao muito pequena perto do ruido: confira se o peso esta sobre a plataforma, se a tara foi feita SEM o peso e a ligacao das celulas.";
  }
  fator = (float)(variacao / pesoKg);
  return nullptr;
}

bool aplicarFator(float fator) {
  if (!isfinite(fator) || fabsf(fator) < 1.0f) return false;
  if (memoria.putFloat("fator", fator) != sizeof(float)) return false;
  fatorCalibracao = fator;
  calibrada = true;
  return true;
}

// Usa o fator salvo pela calibração; sem ele, só o simulador tem um valor padrão.
void carregarCalibracao() {
  float salvo = memoria.getFloat("fator", 0.0f);
  if (isfinite(salvo) && fabsf(salvo) >= 1.0f) {
    fatorCalibracao = salvo;
    calibrada = true;
  } else if (MODO_SIMULADOR) {
    fatorCalibracao = FATOR_SIMULADOR;
    calibrada = true;
  }
}
// FIM CALIBRACAO

// ----- Comandos pelo Serial (calibração e diagnóstico) -----
bool modoBruto = false;           // comando "r": mostra o valor bruto ao vivo
String comandoSerial;
unsigned long ultimoCharSerial = 0;

void mostrarAjuda() {
  Serial.println("Comandos (Serial 115200; termine com Enter):");
  Serial.println("  t          tara: zera a balanca (plataforma vazia)");
  Serial.println("  c <kg>     calibra: faca a tara, ponha um peso conhecido e digite o valor. Ex.: c 5.000");
  Serial.println("  d          diagnostico: bruto, ruido, offset, fator e peso");
  Serial.println("  r          liga/desliga o bruto ao vivo (aperte cada canto e veja se todas as celulas variam no mesmo sentido)");
  Serial.println("  f <fator>  define o fator manualmente (unidades por kg; aceita negativo)");
}

void executarTara() {
  mostrarDisplay(temPendente ? "Envio pendente" : "Fazendo tara...");
  if (temPendente) {
    Serial.println("Aguarde confirmar o envio pendente antes de fazer a tara.");
    return;
  }
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

void calibrarComPeso(float pesoKg) {
  if (!taraFeita) {
    Serial.println("Faca a tara (comando t) com a plataforma VAZIA antes de calibrar.");
    return;
  }
  Serial.printf("Calibrando com %.3f kg... mantenha o peso parado e centralizado.\n", pesoKg);
  double bruto;
  long dispersao;
  if (!lerMediaBruta(bruto, AMOSTRAS, dispersao)) {
    Serial.println("HX711 sem resposta: confira a ligacao do sensor.");
    return;
  }
  float fator;
  const char* erro = calcularFator(bruto, balanca.get_offset(), dispersao, pesoKg, fator);
  if (erro) {
    Serial.println(erro);
    return;
  }
  if (!aplicarFator(fator)) {
    Serial.println("Nao foi possivel salvar o fator na memoria da ESP32.");
    return;
  }
  Serial.printf("Calibrado! Fator = %.2f unidades/kg (bruto=%.0f, offset=%ld, ruido=%ld). Salvo na memoria.\n",
                fator, bruto, balanca.get_offset(), dispersao);
  if (fator < 0) Serial.println("Fator negativo e normal: so indica que a ligacao inverte o sinal da leitura.");
  if (fabsf(fator) < 1000 || fabsf(fator) > 100000) {
    Serial.println("Aviso: fator fora do comum para 4 celulas de 50 kg. Confira o peso informado e a ligacao das celulas.");
  }
  Serial.println("Agora retire o peso, faca nova tara (t) e confira com outros pesos conhecidos.");
}

void diagnostico() {
  float peso = lerPesoKg(AMOSTRAS);
  if (!sensorOk) {
    Serial.println("HX711 sem resposta: confira DT=GPIO16, SCK=GPIO17, VCC (3,3 V) e GND.");
    return;
  }
  Serial.println("=== DIAGNOSTICO DA BALANCA ===");
  Serial.printf("Bruto=%.0f | ruido (max-min)=%ld | offset da tara=%ld | variacao=%.0f\n",
                brutoAtual, dispersaoAtual, balanca.get_offset(), brutoAtual - balanca.get_offset());
  if (calibrada) Serial.printf("Fator=%.2f unidades/kg | peso=%.3f kg\n", fatorCalibracao, peso);
  else Serial.println("Fator: NAO CALIBRADA (faca a tara e use c <kg>)");
  if (fabs(brutoAtual) > 8300000) {
    Serial.println("ALERTA: bruto no limite do HX711 (+-8388607): entrada aberta/em curto. Confira os fios E+/E-/A+/A- e as emendas.");
  }
  if (dispersaoAtual > 1000) {
    Serial.println("ALERTA: ruido alto. Procure contato solto (protoboard), fios longos/sem trancar, plataforma tocando a base ou alimentacao instavel.");
  }
}

// Aceita "c 5", "c 5.0", "c 5,0", "cal 5"...: pega o primeiro número da linha.
bool numeroDoComando(const String& linha, float& valor) {
  int i = 0;
  while (i < (int)linha.length() && !isdigit(linha[i]) && linha[i] != '-' && linha[i] != '+' && linha[i] != '.' && linha[i] != ',') i++;
  if (i >= (int)linha.length()) return false;
  String numero = linha.substring(i);
  numero.replace(',', '.');
  valor = numero.toFloat();
  return isfinite(valor);
}

void processarComando(String linha) {
  linha.trim();
  if (!linha.length()) return;
  float valor;
  switch (tolower(linha[0])) {
    case 't':
      Serial.println("Comando TARA recebido.");
      executarTara();
      break;
    case 'c':
      if (numeroDoComando(linha, valor)) calibrarComPeso(valor);
      else Serial.println("Informe o peso conhecido em kg. Ex.: c 5.000");
      break;
    case 'f':
      if (numeroDoComando(linha, valor) && aplicarFator(valor)) Serial.printf("Fator definido: %.2f unidades/kg. Salvo na memoria.\n", valor);
      else Serial.println("Fator invalido. Ex.: f -15000 (aceita negativo; nao pode ser 0).");
      break;
    case 'd':
      diagnostico();
      break;
    case 'r':
      modoBruto = !modoBruto;
      Serial.println(modoBruto ? "Bruto ao vivo LIGADO (digite r de novo para voltar ao peso)." : "Bruto ao vivo desligado.");
      break;
    default:
      mostrarAjuda();
  }
}

// Monta a linha digitada no Serial. Se o monitor não enviar Enter, executa após uma pausa de 400 ms.
void lerComandosSerial() {
  while (Serial.available()) {
    char c = Serial.read();
    ultimoCharSerial = millis();
    if (c == '\n' || c == '\r') {
      String linha = comandoSerial;
      comandoSerial = "";
      processarComando(linha);
    } else if (comandoSerial.length() < 40) {
      comandoSerial += c;
    }
  }
  if (comandoSerial.length() && millis() - ultimoCharSerial > 400) {
    String linha = comandoSerial;
    comandoSerial = "";
    processarComando(linha);
  }
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
  Serial.println("SICAPDA BALANCA v5 - calibracao pelo Serial");
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
    Serial.println("HX711 sem resposta: confira DT=16, SCK=17, VCC e GND (no Wokwi, no diagram.json).");
  }
  // Logo após energizar, as primeiras conversões do HX711 podem vir fora do normal: descarta algumas.
  for (int i = 0; i < 3 && balanca.wait_ready_timeout(500); i++) balanca.read();
  // O fator de calibração vem da memória (carregarCalibracao, mais abaixo); a conversão para kg é feita em lerPesoKg.
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
  carregarCalibracao();
  if (calibrada) {
    Serial.printf("Fator de calibracao: %.2f unidades/kg%s\n", fatorCalibracao, MODO_SIMULADOR ? " (simulador ou salvo)" : " (salvo)");
  } else {
    Serial.println("BALANCA SEM CALIBRACAO: faca a tara (t) e calibre com um peso conhecido (c <kg>). Digite ? para ver os comandos.");
  }
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
  Serial.println("Comandos pelo Serial: t = tara | c <kg> = calibrar | d = diagnostico | r = bruto ao vivo | ? = ajuda");
}

void loop() {
  keepWifi();
  lerComandosSerial();

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
      executarTara();
    }
    if (evento == EVENTO_FIM) {
      Serial.println("Botao ENCERRAR TURNO detectado.");
      if (temPendente) {
        Serial.println("Aguarde o envio pendente antes de uma nova pesagem.");
        mostrarDisplay("Envio pendente");
      } else if (!taraFeita) {
        Serial.println("Faca a tara com o recipiente vazio antes de pesar.");
        mostrarDisplay("Faca a tara");
      } else if (!calibrada) {
        Serial.println("Balanca sem calibracao: calibre com um peso conhecido (c <kg> no Serial) antes de pesar.");
        mostrarDisplay("Calibre (Serial)");
      } else if (time(nullptr) < 1700000000) {
        Serial.println("Aguarde um ping confirmado para sincronizar a data.");
        mostrarDisplay("Aguarde o ping");
      } else {
        mostrarDisplay("Pesando...");
        float peso = lerPesoKg(AMOSTRAS);
        if (!isfinite(peso)) {
          Serial.println("Sensor indisponivel: HX711 sem resposta. Confira a ligacao do sensor.");
          mostrarDisplay("Falha no sensor");
        } else if (peso < 0) {
          // lerPesoKg já zera o ruído pequeno; aqui o negativo é real (tara com peso na plataforma, deriva ou ligação).
          Serial.printf("Peso negativo (%.3f kg): refaca a tara com a plataforma vazia. Se repetir, rode d e r no Serial e confira a ligacao/montagem das celulas.\n", peso);
          mostrarDisplay("Peso negativo");
        } else if (peso > PESO_MAXIMO_KG) {
          Serial.printf("Peso acima da capacidade (%.1f kg > %.0f kg): leitura descartada. Confira a calibracao (d no Serial).\n", peso, PESO_MAXIMO_KG);
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

  // Peso ao vivo no Serial (ajuda a calibrar e a conferir). No modo "r", mostra o valor bruto do HX711.
  if (millis() - lastLogPeso >= (modoBruto ? LOG_BRUTO_MS : LOG_PESO_MS)) {
    lastLogPeso = millis();
    float peso = lerPesoKg(modoBruto ? 3 : AMOSTRAS_AO_VIVO);
    if (!sensorOk) Serial.println("HX711 sem resposta: leitura cancelada; confira a ligacao do sensor.");
    else if (modoBruto) Serial.printf("bruto=%.0f  variacao desde a tara=%+.0f\n", brutoAtual, brutoAtual - balanca.get_offset());
    else if (!calibrada) Serial.printf("Bruto=%.0f (sem calibracao: faca a tara com t e calibre com c <kg>)\n", brutoAtual);
    else Serial.printf("Peso atual: %.3f kg (bruto=%.0f, ruido=%ld)\n", peso, brutoAtual, dispersaoAtual);
    if (temPendente) mostrarDisplay("Envio pendente");
    else if (!sensorOk) mostrarDisplay("Falha no sensor");
    else if (!taraFeita) mostrarDisplay("Faca a tara");
    else if (!calibrada) mostrarDisplay("Calibre (Serial)");
    else {
      char linha[17];
      snprintf(linha, sizeof(linha), "Peso: %.2f kg", peso);
      mostrarDisplay(linha);
    }
  }
}