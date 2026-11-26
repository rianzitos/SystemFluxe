// SICAPDA by FLUXE — Balança de desperdício v6
// ESP32 + HX711 + 1 célula de carga tipo barra (10 kg) + LCD 16x2 I2C + 3 botões + 2 LEDs
//
// Uso (montagem, ligação e calibração explicadas no README.md desta pasta):
//   AMARELO  (REFEICAO) troca a refeição: Almoço → Jantar → Ceia → Café. O LCD mostra a refeição e o total de hoje.
//   AZUL     (TARA)     zera a balança com o prato/recipiente vazio. SEGURE 4 s para calibrar com um peso conhecido.
//   VERMELHO (ENVIAR)   espera o peso parar, SOMA a pesagem ao total da refeição de hoje e manda o total ao SICAPDA.
//                       Sem internet, guarda na memória e reenvia sozinha quando a rede voltar.
//   O peso nunca aparece negativo: abaixo de zero o LCD mostra 0.000 kg (e pede TARA se ficar assim).
//
// Como o programa está dividido:
//   tarefa "sensor"  lê o HX711 sem parar (10 leituras/s) → mediana de 3 (derruba picos) → média móvel
//                    de 16 leituras (reinicia sozinha quando entra carga nova) → publica média e ruído
//   tarefa "botoes"  lê os 3 botões a cada 5 ms (debounce, clique e "segurar") e avisa o loop por uma fila
//   loop()           tara, fator, zero automático, LCD, Serial, Wi-Fi e envio ao site
//
// Bibliotecas (libraries.txt no Wokwi / platformio.ini): HX711 (bogde) 0.7.5, ArduinoJson 7, LiquidCrystal I2C
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
#include <string.h>
#include <time.h>
#include <sys/time.h>
#include <freertos/FreeRTOS.h>
#include <freertos/task.h>
#include <freertos/queue.h>

// ================================ CONFIGURAÇÃO ================================
// Wi-Fi da balança de verdade. No simulador (MODO_SIMULADOR = true) a rede "Wokwi-GUEST" é usada sozinha.
const char* WIFI_SSID  = "NOME_DA_REDE";
const char* WIFI_SENHA = "SENHA_DA_REDE";
// Endereço do SICAPDA, sem a barra no final. O Wokwi roda na nuvem e NÃO enxerga o localhost do computador.
const char* URL_SERVIDOR = "https://fluxeteam.com.br";
// Token deste aparelho: Configurações → Integrações → Adicionar dispositivo → Balança de desperdício.
// Não suba o token de verdade para o GitHub: cole só na cópia do seu computador.
const char* TOKEN_DISPOSITIVO = "COLE_AQUI_O_TOKEN_DO_DISPOSITIVO";

// false = balança de verdade (célula de 10 kg): calibre UMA vez com peso conhecido (segure TARA 4 s).
// true  = simulador Wokwi: a célula simulada de 50 kg já vem calibrada (420 unidades/kg, sem ruído).
const bool MODO_SIMULADOR = false;

const float CAPACIDADE_KG      = MODO_SIMULADOR ? 50.0f : 10.0f; // capacidade da célula de carga
const float PESO_CALIBRACAO_KG = 1.000f;  // peso conhecido da calibração pelos botões (confira numa balança boa)
const float DIVISAO_KG         = 0.005f;  // degrau do peso no LCD: 0.005 = 5 g (0.010 se o último dígito oscilar)
const bool  SOMAR_PESAGENS     = true;    // true: cada ENVIAR soma ao total da refeição de hoje; false: substitui

// Visor no navegador: sem o LCD, a página /visor/balanca/ do SICAPDA (Chrome ou Edge, com a ESP32 no USB)
// mostra o peso, a refeição e os envios. Ela lê as linhas "VISOR {...}" que a ESP32 escreve no Serial.
// Com o LCD ligado, os dois funcionam juntos. false = não escreve essas linhas.
const bool VISOR_WEB = true;
const unsigned long VISOR_WEB_MS = 2000;  // sem mudança, repete o estado (para quem abrir a página depois)

// ----- Ajustes finos (normalmente não precisa mexer) -----
const float ESTAVEL_KG          = 0.020f; // "peso parado": varia menos que isso (pico a pico) dentro da média
const float DEGRAU_KG           = 0.030f; // mudança maior que isso = carga nova: a média recomeça na hora
const float ZERO_AUTO_KG        = 0.004f; // parado a menos disso do zero por 1 s: o zero acompanha a deriva
const float ZERO_AUTO_LIMITE_KG = 0.100f; // o zero automático não se afasta mais que isso da última TARA
const float AVISO_NEGATIVO_KG   = 0.010f; // abaixo de -10 g parado: o LCD mostra 0.000 e alterna "Aperte TARA"
const float ENVIO_MINIMO_KG     = 0.010f; // abaixo disso o ENVIAR responde "Prato vazio"
const uint8_t JANELA_MEDIA      = 16;     // leituras na média móvel (16 a 10 leituras/s = 1,6 s)
const uint8_t AMOSTRAS_ESTAVEL  = 8;      // mínimo de leituras parecidas para aceitar um peso
const unsigned long ESPERA_ESTAVEL_MS     = 6000;   // espera máxima pelo peso parar (tara, envio, calibração)
const unsigned long SEGURAR_CALIBRACAO_MS = 4000;   // segurar TARA por esse tempo abre a calibração
const unsigned long CALIBRACAO_EXPIRA_MS  = 180000; // sem ação por 3 min, a calibração é cancelada
const unsigned long PING_MS       = 60000;  // "estou vivo" (Online em Integrações), hora e totais do site
const unsigned long PING_SEM_HORA_MS = 10000; // ainda sem data e hora (acabou de ligar): tenta o site mais cedo
const unsigned long REENVIO_MS    = 15000;  // nova tentativa de envio quando há totais na fila
const unsigned long WIFI_RETRY_MS = 15000;  // nova tentativa de conectar ao Wi-Fi
const unsigned long LOG_PESO_MS   = 2000;   // peso ao vivo no Serial
const unsigned long LOG_BRUTO_MS  = 300;    // valor bruto ao vivo (comando "r")
const float FATOR_SIMULADOR = 420.0f;       // Wokwi: 21000 unidades para 50 kg
// Fator típico de uma célula de 1 mV/V no HX711 com ganho 128: 2^23 x 2 x 128 x 0,001 / capacidade
// (cerca de 215 mil unidades por kg na célula de 10 kg). Só serve para conferir a calibração.
const float FATOR_TIPICO = 2147483.6f / CAPACIDADE_KG;

// O botão amarelo escolhe a refeição. A API recebe os códigos; o LCD mostra os nomes (sem acento).
const char* const TIPOS_REFEICAO[] = {"cafe", "almoco", "jantar", "ceia"};
const char* const NOMES_REFEICAO[] = {"Cafe", "Almoco", "Jantar", "Ceia"};
const uint8_t TOTAL_REFEICOES = 4;

// ----- Pinos -----
#define HX_DT_PIN    16  // HX711 DT  (no Wokwi, terminal RX2 da DevKit V1)
#define HX_SCK_PIN   17  // HX711 SCK (no Wokwi, terminal TX2 da DevKit V1)
#define LED_GREEN    27  // Wi-Fi conectado (pisca ao enviar)
#define LED_RED      26  // atenção: envio na fila, sensor sem sinal ou balança sem calibração
#define BTN_TARA     32  // zera a balança; segurar 4 s = calibração
#define BTN_ENVIAR   33  // pesa, soma ao total da refeição e envia
#define BTN_REFEICAO 25  // café → almoço → jantar → ceia
#define LCD_SDA      21
#define LCD_SCL      22
const uint8_t LCD_ENDERECO = 0x27;

// INICIO TIPOS: todas as estruturas ficam aqui no topo (o Arduino gera protótipos das funções antes delas).
const uint8_t JANELA_MAX = 32;
const uint8_t FILA_MAX = 8;
const uint8_t VERSAO_REGISTRO = 1;

struct EstadoBotao {
  bool candidato;              // nível lido agora (ainda pode ser ruído)
  bool estavel;                // nível confirmado após 30 ms
  unsigned long mudouEm;
  unsigned long apertouEm;
  bool seguradoAvisado;        // o evento "segurou" já saiu neste aperto
};

struct Filtro {
  long ultimas[3];             // 3 últimas leituras cruas: a mediana derruba picos isolados
  uint8_t nUltimas;
  long janela[JANELA_MAX];     // anel da média móvel
  uint8_t tamanho;
  uint8_t n;
  uint8_t pos;
  uint8_t desvios;             // leituras seguidas longe da média (carga nova)
};

struct ZeroAuto {
  bool perto;                  // está parado perto do zero
  unsigned long desde;
};

struct Pendente {              // um total que o site ainda não confirmou
  char data[11];               // AAAA-MM-DD
  uint8_t refeicao;
  int32_t gramas;
};

struct Registro {              // gravado inteiro na memória NVS da ESP32
  uint8_t versao;
  char dia[11];                // dia a que os totais se referem
  int32_t totalGramas[TOTAL_REFEICOES];
  uint8_t nFila;
  Pendente fila[FILA_MAX];
};

struct UltimaPesagem {         // para o comando "u" (desfazer)
  bool valida;
  char data[11];
  uint8_t refeicao;
  int32_t gramas;
  int32_t totalAntes;
};

struct Instantaneo {           // o que a tarefa do sensor publica para o loop
  bool ok;                     // HX711 respondendo e fora do limite do conversor
  bool saturado;
  double media;                // média da janela (unidades do HX711)
  long picoAPico;              // maior - menor da janela: o ruído
  uint8_t amostras;            // leituras na janela
  long ultimoBruto;
  uint32_t contador;           // leituras desde que ligou (mede a taxa)
  uint32_t atendidos;          // último pedido de "média nova" atendido
};
// FIM TIPOS

// ================================ ESTADO GLOBAL ================================
HX711 balanca;
LiquidCrystal_I2C lcd(LCD_ENDERECO, 16, 2);
Preferences memoria;
QueueHandle_t eventosBotoes = nullptr;
const uint8_t EVENTO_TARA = 1;
const uint8_t EVENTO_ENVIAR = 2;
const uint8_t EVENTO_REFEICAO = 3;
const uint8_t EVENTO_CALIBRAR = 4;  // TARA segurado

// Sensor: escrito pela tarefa "sensor", lido pelo loop (protegido pelo muxSensor)
portMUX_TYPE muxSensor = portMUX_INITIALIZER_UNLOCKED;
Instantaneo sensorAtual = {};
volatile uint32_t pedidosMediaNova = 0;
volatile long limiarDegrauBruto = 0;

// Calibração e tara (só o loop mexe)
float fatorCalibracao = 0;   // unidades do HX711 por kg; NEGATIVO é normal (só indica o sentido da ligação)
bool calibrada = false;
long offsetTara = 0;         // bruto com o prato vazio na última TARA (fica salvo)
long offsetAtual = 0;        // offset em uso: a TARA + as correções do zero automático
bool taraValida = false;
ZeroAuto zeroAuto = {false, 0};

// Refeição, totais do dia e fila de envio
uint8_t refeicaoSelecionada = 1;  // primeira inicialização: almoço
Registro reg;
UltimaPesagem ultima = {};

// Telas
const uint8_t MODO_NORMAL = 0, MODO_CAL_VAZIO = 1, MODO_CAL_PESO = 2;
uint8_t modo = MODO_NORMAL;
unsigned long modoDesde = 0;
bool displayDisponivel = false;
char telaAtual[2][17];
char avisoTexto[17] = "";
unsigned long avisoDesde = 0, avisoDuracao = 0;
long divisoesMostradas = 0;
unsigned long negativoDesde = 0;
bool visorPesoOk = false;    // visor web: o peso da tela vale (sensor ok, calibrada e com tara)
bool visorParado = false;    // visor web: o peso está parado
char visorUltimo[240] = "";  // visor web: última linha enviada
unsigned long visorUltimoEnvio = 0;

// Rede, Serial e LEDs
bool wifiConectado = false;
bool pingAgendado = true;
// Resultado do último ping (vai para o visor web, que explica o problema sem o Monitor Serial):
// 0 = ainda não tentou, código HTTP (<= 0: nem chegou ao site) ou PING_SEM_EPOCH (200 sem hora válida).
const int PING_SEM_EPOCH = 1;
int codigoPing = 0;
unsigned long ultimoPing = 0, ultimoReenvio = 0, ultimaTentativaWifi = 0;
unsigned long ultimaTela = 0, ultimoLog = 0, ultimaTaxa = 0;
uint32_t contadorTaxa = 0;
float leiturasPorSegundo = 0;
bool logPeso = true, modoBruto = false;
String comandoSerial;
unsigned long ultimoCharSerial = 0;

// ================================ BOTÕES ================================
// INICIO DEBOUNCE: lógica pura dos botões (testada no computador em tests/balanca_botoes.py).
const uint8_t BOTAO_NADA = 0, BOTAO_CLIQUE = 1, BOTAO_SEGURADO = 2;

// INPUT_PULLUP: nível baixo = apertado. Devolve CLIQUE uma vez no aperto (depois de 30 ms estável) e
// SEGURADO uma vez se continuar apertado por "tempoSegurar" ms (0 = sem esse evento).
uint8_t detectarBotao(EstadoBotao& b, bool nivelAlto, unsigned long agora, unsigned long tempoSegurar) {
  if (nivelAlto != b.candidato) {
    b.candidato = nivelAlto;
    b.mudouEm = agora;
  }
  if (nivelAlto != b.estavel && agora - b.mudouEm >= 30) {
    b.estavel = nivelAlto;
    if (!nivelAlto) {
      b.apertouEm = agora;
      b.seguradoAvisado = false;
      return BOTAO_CLIQUE;
    }
    return BOTAO_NADA;
  }
  if (!b.estavel && tempoSegurar > 0 && !b.seguradoAvisado && agora - b.apertouEm >= tempoSegurar) {
    b.seguradoAvisado = true;
    return BOTAO_SEGURADO;
  }
  return BOTAO_NADA;
}
// FIM DEBOUNCE

void enviarEvento(uint8_t evento) {
  xQueueSend(eventosBotoes, &evento, 0);
}

// Tarefa separada: os cliques são capturados mesmo enquanto o loop espera o peso parar ou fala com o site.
void monitorarBotoes(void*) {
  EstadoBotao tara = {true, true, 0, 0, true};
  EstadoBotao enviar = {true, true, 0, 0, true};
  EstadoBotao refeicao = {true, true, 0, 0, true};
  for (;;) {
    unsigned long agora = millis();
    uint8_t r = detectarBotao(tara, digitalRead(BTN_TARA) == HIGH, agora, SEGURAR_CALIBRACAO_MS);
    if (r == BOTAO_CLIQUE) enviarEvento(EVENTO_TARA);
    if (r == BOTAO_SEGURADO) enviarEvento(EVENTO_CALIBRAR);
    if (detectarBotao(enviar, digitalRead(BTN_ENVIAR) == HIGH, agora, 0) == BOTAO_CLIQUE) enviarEvento(EVENTO_ENVIAR);
    if (detectarBotao(refeicao, digitalRead(BTN_REFEICAO) == HIGH, agora, 0) == BOTAO_CLIQUE) enviarEvento(EVENTO_REFEICAO);
    vTaskDelay(pdMS_TO_TICKS(5));
  }
}

// ================================ FILTRO DO SENSOR ================================
// INICIO FILTRO: lógica pura do filtro (testada no computador em tests/balanca_calibracao.py).
long mediana3(long a, long b, long c) {
  long menor = a < b ? a : b;
  long maior = a < b ? b : a;
  long m = maior < c ? maior : c;
  return menor > m ? menor : m;
}

// Esvazia só a média móvel (as 3 últimas leituras continuam valendo para a mediana).
void filtroLimpar(Filtro& f) {
  f.n = 0;
  f.pos = 0;
  f.desvios = 0;
}

void filtroIniciar(Filtro& f, uint8_t tamanho) {
  f.nUltimas = 0;
  f.tamanho = tamanho < 1 ? 1 : (tamanho > JANELA_MAX ? JANELA_MAX : tamanho);
  filtroLimpar(f);
}

double filtroMedia(const Filtro& f) {
  if (f.n == 0) return 0;
  double soma = 0;
  for (uint8_t i = 0; i < f.n; i++) soma += f.janela[i];
  return soma / f.n;
}

long filtroPicoAPico(const Filtro& f) {
  if (f.n < 2) return 0;
  long menor = f.janela[0], maior = f.janela[0];
  for (uint8_t i = 1; i < f.n; i++) {
    if (f.janela[i] < menor) menor = f.janela[i];
    if (f.janela[i] > maior) maior = f.janela[i];
  }
  return maior - menor;
}

// Acrescenta uma leitura crua. Duas leituras seguidas a mais de "limiarDegrau" da média = carga nova:
// a média recomeça só com as leituras novas (resposta rápida, sem arrastar o valor antigo).
// Devolve true quando a média recomeçou.
bool filtroAdicionar(Filtro& f, long bruto, long limiarDegrau) {
  if (f.nUltimas < 3) {
    f.ultimas[f.nUltimas++] = bruto;
  } else {
    f.ultimas[0] = f.ultimas[1];
    f.ultimas[1] = f.ultimas[2];
    f.ultimas[2] = bruto;
  }
  long valor = f.nUltimas < 3 ? bruto : mediana3(f.ultimas[0], f.ultimas[1], f.ultimas[2]);
  bool recomecou = false;
  if (f.n > 0 && limiarDegrau > 0 && fabs((double)valor - filtroMedia(f)) > (double)limiarDegrau) {
    if (++f.desvios >= 2) {
      filtroLimpar(f);
      recomecou = true;
    }
  } else {
    f.desvios = 0;
  }
  f.janela[f.pos] = valor;
  f.pos = (f.pos + 1) % f.tamanho;
  if (f.n < f.tamanho) f.n++;
  return recomecou;
}
// FIM FILTRO

// Lê o HX711 assim que cada conversão fica pronta (10/s com o pino RATE do módulo no GND, o padrão).
// Só esta tarefa usa o objeto "balanca" depois do setup.
void tarefaSensor(void*) {
  Filtro filtro;
  filtroIniciar(filtro, JANELA_MEDIA);
  uint32_t atendidos = pedidosMediaNova;
  unsigned long ultimaLeitura = millis();
  for (;;) {
    uint32_t pedidos = pedidosMediaNova;
    if (pedidos != atendidos) {
      filtroLimpar(filtro);
      atendidos = pedidos;
    }
    if (balanca.is_ready()) {
      long bruto = balanca.read();  // a biblioteca já protege o tempo do SCK contra interrupções
      ultimaLeitura = millis();
      bool saturado = bruto >= 8388607L || bruto <= -8388608L;  // limite do conversor de 24 bits
      filtroAdicionar(filtro, bruto, limiarDegrauBruto);
      Instantaneo novo;
      novo.ok = !saturado;
      novo.saturado = saturado;
      novo.media = filtroMedia(filtro);
      novo.picoAPico = filtroPicoAPico(filtro);
      novo.amostras = filtro.n;
      novo.ultimoBruto = bruto;
      novo.atendidos = atendidos;
      portENTER_CRITICAL(&muxSensor);
      novo.contador = sensorAtual.contador + 1;
      sensorAtual = novo;
      portEXIT_CRITICAL(&muxSensor);
    } else if (millis() - ultimaLeitura > 1000) {
      portENTER_CRITICAL(&muxSensor);
      sensorAtual.ok = false;  // HX711 parou de responder
      portEXIT_CRITICAL(&muxSensor);
    }
    vTaskDelay(pdMS_TO_TICKS(10));
  }
}

Instantaneo lerSensor() {
  Instantaneo copia;
  portENTER_CRITICAL(&muxSensor);
  copia = sensorAtual;
  portEXIT_CRITICAL(&muxSensor);
  return copia;
}

// ================================ CONVERSÃO PARA KG ================================
// INICIO CONVERSAO: tara, fator, valor do LCD e zero automático (testados em tests/balanca_calibracao.py).
float brutoParaKg(double bruto, long offset, float fator) {
  return (float)((bruto - offset) / fator);
}

// Fator = variação do bruto / peso conhecido, mantendo o sinal. Devolve nullptr se deu certo,
// ou o motivo da recusa.
const char* calcularFator(double bruto, long offset, long ruido, float pesoKg, float& fator) {
  if (!(pesoKg > 0.0f) || pesoKg > CAPACIDADE_KG) {
    return "Peso conhecido invalido: use um valor maior que 0 e ate a capacidade da celula.";
  }
  double variacao = bruto - offset;
  double minimo = 20.0 * (ruido > 10 ? ruido : 10);
  if (fabs(variacao) < minimo) {
    return "Variacao muito pequena perto do ruido: o peso esta sobre o prato? A tara foi feita SEM o peso? Confira a ligacao e a montagem da celula.";
  }
  fator = (float)(variacao / pesoKg);
  return nullptr;
}

// Quantas divisões (de DIVISAO_KG) o LCD mostra. Negativo vira 0. O valor só muda quando o peso se afasta
// mais de 0,7 divisão do que já está na tela, para o último dígito não ficar piscando.
long divisoesParaMostrar(float kg, long anterior) {
  if (!(kg > 0.0f)) return 0;  // negativo (ou NaN) aparece como zero
  float alvo = kg / DIVISAO_KG;
  if (anterior >= 0 && fabsf(alvo - (float)anterior) <= 0.7f) return anterior;
  long arredondado = lroundf(alvo);
  return arredondado < 0 ? 0 : arredondado;
}

// Zero automático: parado a menos de ZERO_AUTO_KG do zero por 1 s, o offset acompanha a leitura
// (corrige a deriva lenta de temperatura). Nunca se afasta mais que ZERO_AUTO_LIMITE_KG da última TARA.
// Devolve true quando mudou o offset.
bool corrigirZero(ZeroAuto& z, double media, long& offset, long offsetDaTara, float fator, bool parado, unsigned long agora) {
  if (!parado || !isfinite(fator) || fator == 0.0f) {
    z.perto = false;
    return false;
  }
  if (fabsf(brutoParaKg(media, offset, fator)) > ZERO_AUTO_KG) {
    z.perto = false;
    return false;
  }
  if (!z.perto) {
    z.perto = true;
    z.desde = agora;
    return false;
  }
  if (agora - z.desde < 1000) return false;
  z.desde = agora;
  long novo = lround(media);
  if (fabsf(brutoParaKg(novo, offsetDaTara, fator)) > ZERO_AUTO_LIMITE_KG) return false;
  if (novo == offset) return false;
  offset = novo;
  return true;
}
// FIM CONVERSAO

// ================================ TOTAIS DO DIA E FILA ================================
// INICIO TOTAIS: totais por refeição e fila de envio (testados em tests/balanca_totais.py).
// O site guarda um TOTAL por empresa + dia + refeição e cada envio substitui o anterior. Por isso a balança
// manda sempre o total já somado: reenviar o mesmo valor nunca duplica.
void registroLimpar(Registro& r) {
  memset(&r, 0, sizeof(r));
  r.versao = VERSAO_REGISTRO;
}

bool textoTerminado(const char* texto, size_t tamanho) {
  return memchr(texto, '\0', tamanho) != nullptr;
}

bool registroValido(const Registro& r) {
  if (r.versao != VERSAO_REGISTRO || r.nFila > FILA_MAX || !textoTerminado(r.dia, sizeof(r.dia))) return false;
  for (uint8_t i = 0; i < TOTAL_REFEICOES; i++) {
    if (r.totalGramas[i] < 0) return false;
  }
  for (uint8_t i = 0; i < r.nFila; i++) {
    if (r.fila[i].refeicao >= TOTAL_REFEICOES || r.fila[i].gramas < 0 || !textoTerminado(r.fila[i].data, sizeof(r.fila[i].data))) return false;
  }
  return true;
}

int filaProcurar(const Registro& r, const char* data, uint8_t refeicao) {
  for (uint8_t i = 0; i < r.nFila; i++) {
    if (r.fila[i].refeicao == refeicao && strcmp(r.fila[i].data, data) == 0) return i;
  }
  return -1;
}

// Põe (ou atualiza) na fila o total de um dia + refeição. Falha só se a fila estiver cheia.
bool filaGuardar(Registro& r, const char* data, uint8_t refeicao, int32_t gramas) {
  int i = filaProcurar(r, data, refeicao);
  if (i < 0) {
    if (r.nFila >= FILA_MAX) return false;
    i = r.nFila++;
    strncpy(r.fila[i].data, data, sizeof(r.fila[i].data) - 1);
    r.fila[i].data[sizeof(r.fila[i].data) - 1] = '\0';
    r.fila[i].refeicao = refeicao;
  }
  r.fila[i].gramas = gramas;
  return true;
}

void filaRemoverPrimeiro(Registro& r) {
  if (r.nFila == 0) return;
  for (uint8_t i = 1; i < r.nFila; i++) r.fila[i - 1] = r.fila[i];
  r.nFila--;
  memset(&r.fila[r.nFila], 0, sizeof(Pendente));
}

int32_t totalDoDia(const Registro& r, const char* hoje, uint8_t refeicao) {
  return strcmp(r.dia, hoje) == 0 ? r.totalGramas[refeicao] : 0;
}

// Troca o total de hoje de uma refeição e põe o novo total na fila. Dia novo zera os totais.
bool definirTotal(Registro& r, const char* hoje, uint8_t refeicao, int32_t novoTotal) {
  Registro copia = r;  // só altera o registro se tudo der certo
  if (strcmp(copia.dia, hoje) != 0) {
    strncpy(copia.dia, hoje, sizeof(copia.dia) - 1);
    copia.dia[sizeof(copia.dia) - 1] = '\0';
    for (uint8_t i = 0; i < TOTAL_REFEICOES; i++) copia.totalGramas[i] = 0;
  }
  if (novoTotal < 0) novoTotal = 0;
  if (!filaGuardar(copia, hoje, refeicao, novoTotal)) return false;
  copia.totalGramas[refeicao] = novoTotal;
  r = copia;
  return true;
}

bool registrarPesagem(Registro& r, const char* hoje, uint8_t refeicao, int32_t gramas, bool somar, UltimaPesagem& u) {
  int32_t antes = totalDoDia(r, hoje, refeicao);
  if (!definirTotal(r, hoje, refeicao, somar ? antes + gramas : gramas)) return false;
  u.valida = true;
  strncpy(u.data, hoje, sizeof(u.data) - 1);
  u.data[sizeof(u.data) - 1] = '\0';
  u.refeicao = refeicao;
  u.gramas = gramas;
  u.totalAntes = antes;
  return true;
}

bool desfazerPesagem(Registro& r, UltimaPesagem& u, const char* hoje, bool somar) {
  if (!u.valida || strcmp(u.data, hoje) != 0) return false;
  int32_t atual = totalDoDia(r, hoje, u.refeicao);
  if (!definirTotal(r, hoje, u.refeicao, somar ? atual - u.gramas : u.totalAntes)) return false;
  u.valida = false;
  return true;
}

// O ping traz os totais de hoje que estão no site (alguém pode ter corrigido lá). Uma refeição com envio
// na fila mantém o valor da balança: o envio ainda vai chegar. Devolve true quando algo mudou.
bool sincronizarTotais(Registro& r, const char* dataServidor, const int32_t gramas[]) {
  bool mudou = false;
  if (strcmp(r.dia, dataServidor) != 0) {
    strncpy(r.dia, dataServidor, sizeof(r.dia) - 1);
    r.dia[sizeof(r.dia) - 1] = '\0';
    for (uint8_t i = 0; i < TOTAL_REFEICOES; i++) r.totalGramas[i] = 0;
    mudou = true;
  }
  for (uint8_t i = 0; i < TOTAL_REFEICOES; i++) {
    if (filaProcurar(r, dataServidor, i) >= 0 || gramas[i] < 0) continue;
    if (r.totalGramas[i] != gramas[i]) {
      r.totalGramas[i] = gramas[i];
      mudou = true;
    }
  }
  return mudou;
}
// FIM TOTAIS

// ================================ MEMÓRIA (NVS) ================================
bool salvarRegistro() {
  return memoria.putBytes("reg", &reg, sizeof(reg)) == sizeof(reg);
}

void carregarRegistro() {
  registroLimpar(reg);
  if (memoria.isKey("reg") && memoria.getBytesLength("reg") == sizeof(Registro)) {
    Registro lido;
    memoria.getBytes("reg", &lido, sizeof(lido));
    if (registroValido(lido)) reg = lido;
    else Serial.println("Registro da memoria invalido: totais e fila recomecam vazios.");
  }
  // Versão anterior (v5): um único envio pendente em JSON. Vira um item da fila nova.
  if (memoria.isKey("pendente")) {
    JsonDocument antigo;
    if (!deserializeJson(antigo, memoria.getString("pendente", ""))) {
      const char* tipo = antigo["tipo"] | "";
      const char* data = antigo["data"] | "";
      float kg = antigo["desperdicio_kg"] | -1.0f;
      for (uint8_t i = 0; i < TOTAL_REFEICOES; i++) {
        if (strcmp(tipo, TIPOS_REFEICAO[i]) == 0 && strlen(data) == 10 && kg >= 0) {
          filaGuardar(reg, data, i, (int32_t)lroundf(kg * 100.0f) * 10);
        }
      }
    }
    if (salvarRegistro()) memoria.remove("pendente");
  }
}

void carregarCalibracao() {
  float salvo = memoria.getFloat("fator", 0.0f);
  if (isfinite(salvo) && fabsf(salvo) >= 1.0f) {
    fatorCalibracao = salvo;
    calibrada = true;
  } else if (MODO_SIMULADOR) {
    fatorCalibracao = FATOR_SIMULADOR;
    calibrada = true;
  }
  if (memoria.isKey("offset")) {
    offsetTara = offsetAtual = memoria.getLong("offset", 0);
    taraValida = true;
  }
}

// O fator usado nos limites de ruído/degrau. Sem calibração, usa o típico da célula. Um fator salvo muito
// pequeno (calibração errada) não pode deixar os limites abaixo do ruído normal: a balança nunca pararia e
// não daria nem para fazer a tara e calibrar de novo.
float fatorParaLimiares() {
  if (MODO_SIMULADOR) return calibrada ? fabsf(fatorCalibracao) : FATOR_SIMULADOR;
  float fator = calibrada ? fabsf(fatorCalibracao) : FATOR_TIPICO;
  return fator < FATOR_TIPICO * 0.3f ? FATOR_TIPICO * 0.3f : fator;
}

void atualizarLimiares() {
  limiarDegrauBruto = lroundf(DEGRAU_KG * fatorParaLimiares());
}

long limiarEstavelBruto() {
  long limiar = lroundf(ESTAVEL_KG * fatorParaLimiares());
  return limiar < 3 ? 3 : limiar;
}

bool aplicarFator(float fator) {
  if (!isfinite(fator) || fabsf(fator) < 1.0f) return false;
  if (memoria.putFloat("fator", fator) != sizeof(float)) return false;
  fatorCalibracao = fator;
  calibrada = true;
  atualizarLimiares();
  return true;
}

// ================================ LCD ================================
void visorWeb();

// A tela é guardada mesmo sem o LCD: é dela que o visor web lê o que mostrar.
void escreverLinhaLCD(uint8_t linha, const char* texto) {
  char preenchido[17];
  snprintf(preenchido, sizeof(preenchido), "%-16.16s", texto);
  if (strcmp(preenchido, telaAtual[linha]) == 0) return;  // só reescreve o que mudou (sem piscar)
  strcpy(telaAtual[linha], preenchido);
  if (displayDisponivel) {
    lcd.setCursor(0, linha);
    lcd.print(preenchido);
  }
  visorWeb();  // avisos que aparecem no meio de uma espera ("Enviando...", "Tarando...") também vão
}

void mostrarTela(const char* linha0, const char* linha1) {
  escreverLinhaLCD(0, linha0);
  escreverLinhaLCD(1, linha1);
}

void iniciarDisplay() {
  Wire.begin(LCD_SDA, LCD_SCL);
  Wire.setTimeOut(50);
  Wire.beginTransmission(LCD_ENDERECO);
  if (Wire.endTransmission() != 0) {
    Serial.println("LCD nao encontrado em 0x27. Confira SDA=21, SCL=22, VCC e GND (alguns modulos usam 0x3F).");
    return;
  }
  lcd.init();
  lcd.backlight();
  memset(telaAtual, 0, sizeof(telaAtual));
  displayDisponivel = true;
}

void aviso(const char* texto, unsigned long duracaoMs) {
  strncpy(avisoTexto, texto, sizeof(avisoTexto) - 1);
  avisoTexto[sizeof(avisoTexto) - 1] = '\0';
  avisoDesde = millis();
  avisoDuracao = duracaoMs;
  escreverLinhaLCD(1, avisoTexto);
}

bool avisoAtivo() {
  return avisoDuracao > 0 && millis() - avisoDesde < avisoDuracao;
}

bool relogioOk() {
  return time(nullptr) > 1700000000;
}

void dataDeHoje(char* saida) {  // saida com 11 posições
  time_t agora = time(nullptr);
  tm horario;
  localtime_r(&agora, &horario);
  strftime(saida, 11, "%Y-%m-%d", &horario);
}

// Total de hoje da refeição (ou o último conhecido, enquanto a hora não foi sincronizada).
int32_t totalMostrado(uint8_t refeicao) {
  if (!relogioOk()) return reg.totalGramas[refeicao];
  char hoje[11];
  dataDeHoje(hoje);
  return totalDoDia(reg, hoje, refeicao);
}

void formatarKg(char* saida, size_t tamanho, int32_t gramas) {
  float kg = gramas / 1000.0f;
  if (kg < 100.0f) snprintf(saida, tamanho, "%.2f", kg);
  else if (kg < 1000.0f) snprintf(saida, tamanho, "%.1f", kg);
  else snprintf(saida, tamanho, "%.0f", kg);
}

// Linha de cima: refeição e total de hoje. "!" = há total esperando para ser enviado.
void linhaRefeicao(char* saida) {
  char marca = reg.nFila > 0 ? '!' : ' ';
  if (SOMAR_PESAGENS) {
    char total[10];
    formatarKg(total, sizeof(total), totalMostrado(refeicaoSelecionada));
    snprintf(saida, 17, "%-6s%cTot%6s", NOMES_REFEICAO[refeicaoSelecionada], marca, total);
  } else {
    snprintf(saida, 17, "Ref: %-10s%c", NOMES_REFEICAO[refeicaoSelecionada], marca);
  }
}

// Visor web: uma linha "VISOR {json}" no Serial com as duas linhas do LCD e os números por trás delas.
// Sai quando algo muda e, sem mudança, a cada VISOR_WEB_MS. O texto do LCD é ASCII fixo do programa
// (sem aspas nem barras), então entra direto no JSON.
void visorWeb() {
  if (!VISOR_WEB) return;
  char peso[16] = "null";
  if (visorPesoOk) snprintf(peso, sizeof(peso), "%.3f", divisoesMostradas * DIVISAO_KG);
  char json[sizeof(visorUltimo)];
  snprintf(json, sizeof(json),
           "{\"l0\":\"%s\",\"l1\":\"%s\",\"ref\":\"%s\",\"peso\":%s,\"parado\":%s,\"total\":%.2f,\"fila\":%u,"
           "\"wifi\":%s,\"modo\":%u,\"hora\":%s,\"site\":%d}",
           telaAtual[0], telaAtual[1], TIPOS_REFEICAO[refeicaoSelecionada], peso, visorParado ? "true" : "false",
           totalMostrado(refeicaoSelecionada) / 1000.0, (unsigned)reg.nFila, wifiConectado ? "true" : "false",
           (unsigned)modo, relogioOk() ? "true" : "false", codigoPing);
  unsigned long agora = millis();
  if (strcmp(json, visorUltimo) == 0 && agora - visorUltimoEnvio < VISOR_WEB_MS) return;
  strcpy(visorUltimo, json);
  visorUltimoEnvio = agora;
  Serial.print("VISOR ");
  Serial.println(json);
}

void linhaPeso(char* saida, long divisoes) {
  int casas = DIVISAO_KG < 0.0095f ? 3 : (DIVISAO_KG < 0.095f ? 2 : 1);
  char valor[12];
  snprintf(valor, sizeof(valor), "%.*f", casas, divisoes * DIVISAO_KG);
  snprintf(saida, 17, "Peso:%8s kg", valor);
}

float pesoAtualKg(const Instantaneo& s) {
  if (!s.ok || !calibrada || !taraValida || s.amostras == 0) return NAN;
  return brutoParaKg(s.media, offsetAtual, fatorCalibracao);
}

bool estaParado(const Instantaneo& s) {
  return s.ok && s.amostras >= AMOSTRAS_ESTAVEL && s.picoAPico <= limiarEstavelBruto();
}

void atualizarTela(const Instantaneo& s) {
  char linha0[17], linha1[17];
  unsigned long agora = millis();
  visorParado = estaParado(s);
  visorPesoOk = false;
  if (modo == MODO_CAL_VAZIO) {
    mostrarTela("CALIBRACAO", avisoAtivo() ? avisoTexto : "Esvazie + TARA");
    return;
  }
  if (modo == MODO_CAL_PESO) {
    snprintf(linha0, sizeof(linha0), "Ponha %.3f kg", PESO_CALIBRACAO_KG);
    mostrarTela(linha0, avisoAtivo() ? avisoTexto : "e aperte ENVIAR");
    return;
  }
  linhaRefeicao(linha0);
  float kg = pesoAtualKg(s);
  if (isfinite(kg)) divisoesMostradas = divisoesParaMostrar(kg, divisoesMostradas);
  visorPesoOk = isfinite(kg);
  bool negativo = isfinite(kg) && kg < -AVISO_NEGATIVO_KG && estaParado(s);
  if (!negativo) negativoDesde = 0;
  else if (!negativoDesde) negativoDesde = agora;

  if (avisoAtivo()) snprintf(linha1, sizeof(linha1), "%s", avisoTexto);
  else if (!s.ok) snprintf(linha1, sizeof(linha1), s.saturado ? "Sensor: ligacao" : "Sensor sem sinal");
  else if (!calibrada) snprintf(linha1, sizeof(linha1), "Calibre: TARA 4s");
  else if (!taraValida) snprintf(linha1, sizeof(linha1), "Aperte TARA");
  else if (isfinite(kg) && kg > CAPACIDADE_KG) snprintf(linha1, sizeof(linha1), "SOBRECARGA!");
  else if (negativoDesde && agora - negativoDesde > 2000 && (agora / 1500) % 2) snprintf(linha1, sizeof(linha1), "Aperte TARA");
  else linhaPeso(linha1, divisoesMostradas);
  mostrarTela(linha0, linha1);
}

void atualizarLeds(const Instantaneo& s) {
  digitalWrite(LED_GREEN, wifiConectado ? HIGH : LOW);
  digitalWrite(LED_RED, (reg.nFila > 0 || !s.ok || !calibrada) ? HIGH : LOW);
}

// ================================ TARA, CALIBRAÇÃO E PESAGEM ================================
// Espera o peso parar (no máximo ESPERA_ESTAVEL_MS). "mediaNova" descarta as leituras de antes do pedido.
bool esperarParado(Instantaneo& saida, bool mediaNova, uint8_t minimoLeituras) {
  uint32_t alvo = 0;
  if (mediaNova) alvo = ++pedidosMediaNova;
  unsigned long inicio = millis();
  while (millis() - inicio < ESPERA_ESTAVEL_MS) {
    Instantaneo s = lerSensor();
    if (s.ok && (!mediaNova || s.atendidos == alvo) && s.amostras >= minimoLeituras && s.picoAPico <= limiarEstavelBruto()) {
      saida = s;
      return true;
    }
    delay(20);
  }
  saida = lerSensor();
  return false;
}

float brutoEmGramas(long bruto) {
  return bruto * 1000.0f / fatorParaLimiares();
}

void dicaRuido() {
  Serial.printf("Se a ligacao ja esta soldada e firme e ainda assim nao para, aumente ESTAVEL_KG (hoje %.3f; ex.: 0.050).\n",
                ESTAVEL_KG);
}

bool executarTara() {
  escreverLinhaLCD(1, "Tarando...");
  Instantaneo s;
  if (!esperarParado(s, true, JANELA_MEDIA)) {
    if (!s.ok) {
      aviso("Sensor sem sinal", 2500);
      Serial.println("Tara cancelada: HX711 sem resposta. Confira DT=GPIO16, SCK=GPIO17, VCC (3,3 V) e GND.");
    } else {
      aviso("Instavel: tente", 2500);
      Serial.printf("Tara cancelada: o peso nao parou (ruido de ~%.0f g). Nao encoste no prato; se repetir, rode d.\n",
                    brutoEmGramas(s.picoAPico));
      dicaRuido();
    }
    return false;
  }
  offsetTara = offsetAtual = lround(s.media);
  taraValida = true;
  zeroAuto.perto = false;
  divisoesMostradas = 0;
  negativoDesde = 0;
  if (memoria.putLong("offset", offsetTara) != sizeof(int32_t)) Serial.println("Aviso: a tara nao foi gravada na memoria.");
  aviso("Tara feita", 1500);
  Serial.printf("Tara feita: offset=%ld (ruido de ~%.1f g).\n", offsetTara, brutoEmGramas(s.picoAPico));
  return true;
}

bool calibrar(float pesoKg) {
  if (!taraValida) {
    Serial.println("Faca a tara com o prato VAZIO antes de calibrar (botao TARA ou comando t).");
    aviso("Faca a TARA", 2500);
    return false;
  }
  Serial.printf("Calibrando com %.3f kg... deixe o peso parado no meio do prato.\n", pesoKg);
  escreverLinhaLCD(1, "Medindo...");
  Instantaneo s;
  if (!esperarParado(s, true, JANELA_MEDIA)) {
    Serial.println(s.ok ? "Calibracao cancelada: o peso nao parou." : "Calibracao cancelada: HX711 sem resposta.");
    if (s.ok) dicaRuido();
    aviso(s.ok ? "Instavel: tente" : "Sensor sem sinal", 2500);
    return false;
  }
  float fator;
  const char* erro = calcularFator(s.media, offsetAtual, s.picoAPico, pesoKg, fator);
  if (erro) {
    Serial.println(erro);
    aviso("Erro: sem peso?", 3000);
    return false;
  }
  if (!aplicarFator(fator)) {
    Serial.println("Nao foi possivel salvar o fator na memoria da ESP32.");
    aviso("Falha ao salvar", 2500);
    return false;
  }
  Serial.printf("Calibrado! Fator = %.2f unidades/kg (variacao=%.0f, ruido=%ld). Salvo na memoria.\n",
                fator, s.media - offsetAtual, s.picoAPico);
  if (fator < 0) Serial.println("Fator negativo e normal: so indica que a ligacao inverte o sinal. O peso sai positivo.");
  if (!MODO_SIMULADOR && (fabsf(fator) < FATOR_TIPICO * 0.3f || fabsf(fator) > FATOR_TIPICO * 3.0f)) {
    Serial.printf("Aviso: o comum para uma celula de %.0f kg e ~%.0f unidades/kg. Confira o peso informado, "
                  "a ligacao E+/E-/A+/A- e CAPACIDADE_KG.\n", CAPACIDADE_KG, FATOR_TIPICO);
  }
  if (pesoKg < CAPACIDADE_KG * 0.1f) {
    Serial.println("Dica: um peso maior (2 a 5 kg) deixa a calibracao mais precisa.");
  }
  Serial.println("Retire o peso, aperte TARA e confira com outro peso conhecido.");
  aviso("Calibrado!", 2500);
  return true;
}

void trocarRefeicao() {
  uint8_t proxima = (refeicaoSelecionada + 1) % TOTAL_REFEICOES;
  if (memoria.putUChar("refeicao", proxima) != sizeof(uint8_t)) {
    Serial.println("Nao foi possivel salvar a refeicao; selecao mantida.");
    aviso("Falha ao salvar", 2000);
    return;
  }
  refeicaoSelecionada = proxima;
  Serial.printf("Refeicao selecionada: %s\n", NOMES_REFEICAO[refeicaoSelecionada]);
}

// ================================ REDE ================================
void iniciarWifi() {
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);  // consumo constante: menos ruído na alimentação e resposta mais rápida
  if (MODO_SIMULADOR) WiFi.begin("Wokwi-GUEST", "", 6);
  else WiFi.begin(WIFI_SSID, WIFI_SENHA);
  ultimaTentativaWifi = millis();
}

void manterWifi() {
  bool conectado = WiFi.status() == WL_CONNECTED;
  if (conectado && !wifiConectado) {
    Serial.printf("Wi-Fi conectado (IP %s, sinal %d dBm)\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
    pingAgendado = true;   // sincroniza hora e totais e reenvia a fila já
    ultimoReenvio = 0;
  } else if (!conectado && wifiConectado) {
    Serial.println("Wi-Fi caiu. Pesagens continuam sendo guardadas e serao enviadas quando voltar.");
  }
  wifiConectado = conectado;
  if (!conectado && millis() - ultimaTentativaWifi >= WIFI_RETRY_MS) {
    ultimaTentativaWifi = millis();
    WiFi.disconnect();
    if (MODO_SIMULADOR) WiFi.begin("Wokwi-GUEST", "", 6);
    else WiFi.begin(WIFI_SSID, WIFI_SENHA);
  }
}

// POST em /api/iot/<caminho> com o token. Devolve o código HTTP (<= 0: nem chegou ao servidor).
int postJson(const char* caminho, const char* corpo, String& resposta) {
  String url = String(URL_SERVIDOR) + "/api/iot/" + caminho;
  WiFiClientSecure clienteSeguro;
  WiFiClient clienteComum;
  HTTPClient http;
  http.setConnectTimeout(5000);
  http.setTimeout(6000);
  bool iniciou;
  if (url.startsWith("https://")) {
    // TESTE: aceita qualquer certificado. Em produção, use clienteSeguro.setCACert(<certificado raiz do site>).
    clienteSeguro.setInsecure();
    iniciou = http.begin(clienteSeguro, url);
  } else {
    iniciou = http.begin(clienteComum, url);
  }
  if (!iniciou) return -1;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Token", TOKEN_DISPOSITIVO);
  int codigo = http.POST((uint8_t*)corpo, strlen(corpo));
  if (codigo > 0) resposta = http.getString();
  else Serial.printf("Falha de conexao em %s: %s\n", url.c_str(), http.errorToString(codigo).c_str());
  http.end();
  return codigo;
}

void explicarFalha(int codigo) {
  if (codigo == 401) {
    Serial.println("HTTP 401: o servidor NAO reconheceu o token. Confira TOKEN_DISPOSITIVO e se a balanca foi criada neste mesmo site.");
  } else if (codigo <= 0) {
    Serial.printf("HTTP %d: nao consegui falar com o servidor (nao e o token). Confira URL_SERVIDOR e a rede.\n", codigo);
  } else if (codigo == 404) {
    Serial.println("HTTP 404: o servidor nao tem essa rota. Confira URL_SERVIDOR (e a subpasta, se houver).");
  } else if (codigo == 403) {
    Serial.println("HTTP 403: o token nao e de uma BALANCA (ou o firewall do servidor bloqueou).");
  } else if (codigo == 422) {
    Serial.println("HTTP 422: o servidor recusou o valor (data futura? confira a hora).");
  } else {
    Serial.printf("HTTP %d: erro no servidor.\n", codigo);
  }
}

// O ping mantém "Online" no site, acerta o relógio e traz os totais de hoje que estão no sistema.
void enviarPing() {
  if (!wifiConectado) return;
  String resposta;
  int codigo = postJson("ping", "{}", resposta);
  codigoPing = codigo;
  if (codigo != 200) {
    Serial.printf("Ping: HTTP %d\n", codigo);
    explicarFalha(codigo);
    return;
  }
  JsonDocument dados;
  if (deserializeJson(dados, resposta)) {
    codigoPing = PING_SEM_EPOCH;
    Serial.printf("Ping: o site respondeu algo que nao e JSON (a hora nao foi acertada): %.80s\n", resposta.c_str());
    return;
  }
  long epoch = dados["epoch"] | 0L;
  if (epoch > 1700000000L) {
    timeval tv = {epoch, 0};
    settimeofday(&tv, nullptr);
  } else if (!relogioOk()) {
    codigoPing = PING_SEM_EPOCH;
    Serial.println("Ping: a resposta do site veio sem \"epoch\" (data e hora). Publique a versao atual do site.");
  }
  const char* dataServidor = dados["data"] | "";
  JsonObjectConst totais = dados["desperdicio"].as<JsonObjectConst>();
  if (strlen(dataServidor) == 10 && !totais.isNull()) {
    int32_t gramas[TOTAL_REFEICOES];
    for (uint8_t i = 0; i < TOTAL_REFEICOES; i++) {
      JsonVariantConst valor = totais[TIPOS_REFEICAO[i]];
      gramas[i] = valor.isNull() ? 0 : (int32_t)lroundf(valor.as<float>() * 100.0f) * 10;
    }
    if (sincronizarTotais(reg, dataServidor, gramas)) {
      salvarRegistro();
      Serial.println("Totais de hoje atualizados com os do site.");
    }
  }
}

// Envia um total da fila. 1 = confirmado; 0 = tentar de novo depois; -1 = recusado de vez (sai da fila).
int enviarTotal(const Pendente& p) {
  char corpo[96];
  snprintf(corpo, sizeof(corpo), "{\"desperdicio_kg\":%.2f,\"tipo\":\"%s\",\"data\":\"%s\"}",
           p.gramas / 1000.0, TIPOS_REFEICAO[p.refeicao], p.data);
  String resposta;
  int codigo = postJson("desperdicio", corpo, resposta);
  if (codigo == 422) {
    explicarFalha(codigo);
    Serial.printf("Total %s/%s descartado: %s\n", p.data, TIPOS_REFEICAO[p.refeicao], resposta.c_str());
    return -1;
  }
  if (codigo != 200 && codigo != 201) {
    explicarFalha(codigo);
    return 0;
  }
  JsonDocument dados;
  if (deserializeJson(dados, resposta) || !(dados["sucesso"] | false)) {
    Serial.println("Resposta inesperada do servidor: o total continua na fila.");
    return 0;
  }
  Serial.printf("Site confirmou: %s %s = %.2f kg\n", p.data, NOMES_REFEICAO[p.refeicao], p.gramas / 1000.0);
  return 1;
}

// Manda tudo o que está na fila, do mais antigo para o mais novo. Devolve true se a fila esvaziou.
bool enviarFila() {
  while (reg.nFila > 0) {
    if (!wifiConectado) return false;
    escreverLinhaLCD(1, "Enviando...");
    digitalWrite(LED_GREEN, LOW);
    int resultado = enviarTotal(reg.fila[0]);
    digitalWrite(LED_GREEN, HIGH);
    if (resultado == 0) return false;
    filaRemoverPrimeiro(reg);
    if (!salvarRegistro()) Serial.println("Aviso: nao consegui atualizar a fila na memoria.");
  }
  return true;
}

void registrarEEnviar() {
  if (!calibrada) {
    Serial.println("Balanca sem calibracao: segure TARA por 4 s (ou use c <kg> no Serial).");
    aviso("Calibre: TARA 4s", 2500);
    return;
  }
  if (!taraValida) {
    aviso("Aperte TARA", 2000);
    return;
  }
  if (!relogioOk() && wifiConectado) {
    // Acabou de ligar (ou reiniciou) e o ping ainda não acertou a hora: tenta agora, em vez de recusar.
    escreverLinhaLCD(1, "Acertando hora..");
    enviarPing();
    ultimoPing = millis();
  }
  if (!relogioOk()) {
    Serial.println("Ainda sem data e hora: a balanca precisa falar com o site (ping) ou com a internet (NTP) uma vez.");
    if (codigoPing != 0 && codigoPing != 200) Serial.printf("Ultimo ping ao site: codigo %d (veja a explicacao acima).\n", codigoPing);
    aviso("Sem hora: rede?", 2500);
    return;
  }
  escreverLinhaLCD(1, "Estabilizando...");
  Instantaneo s;
  // Média só com leituras de depois do clique: nada do momento em que a comida estava sendo colocada.
  if (!esperarParado(s, true, JANELA_MEDIA)) {
    Serial.println(s.ok ? "Peso nao parou: nao encoste no prato e tente de novo." : "HX711 sem resposta.");
    if (s.ok) dicaRuido();
    aviso(s.ok ? "Peso instavel" : "Sensor sem sinal", 2500);
    return;
  }
  float kg = brutoParaKg(s.media, offsetAtual, fatorCalibracao);
  if (kg > CAPACIDADE_KG) {
    Serial.printf("Acima da capacidade (%.2f kg > %.0f kg): divida em mais pesagens.\n", kg, CAPACIDADE_KG);
    aviso("Acima capacidade", 2500);
    return;
  }
  if (kg < ENVIO_MINIMO_KG) {
    if (kg < -AVISO_NEGATIVO_KG) Serial.println("Leitura abaixo de zero: aperte TARA com o prato vazio.");
    aviso("Prato vazio", 2000);
    return;
  }
  int32_t gramas = (int32_t)lroundf(kg * 100.0f) * 10;  // o site guarda em centésimos de kg (10 g)
  char hoje[11];
  dataDeHoje(hoje);
  if (!registrarPesagem(reg, hoje, refeicaoSelecionada, gramas, SOMAR_PESAGENS, ultima)) {
    Serial.println("Fila cheia: ha 8 totais sem enviar. Conecte a balanca a internet antes de pesar de novo.");
    aviso("Fila cheia: rede", 3000);
    return;
  }
  if (!salvarRegistro()) Serial.println("Aviso: nao consegui gravar na memoria; o envio segue.");
  Serial.printf("Pesagem: %.2f kg (%s). Total de hoje: %.2f kg\n", gramas / 1000.0,
                NOMES_REFEICAO[refeicaoSelecionada], totalDoDia(reg, hoje, refeicaoSelecionada) / 1000.0);
  bool enviado = enviarFila();
  char texto[17];
  snprintf(texto, sizeof(texto), enviado ? "Enviado +%.2fkg" : "Na fila +%.2fkg", gramas / 1000.0);
  aviso(texto, 3000);
  if (!enviado) Serial.println("Sem confirmacao do site: o total fica na fila e sera reenviado sozinho.");
  ultimoReenvio = millis();
}

// Serial "u" e "z": mudam o total de hoje da refeição e mandam o novo total.
void corrigirTotal(bool desfazer) {
  if (!relogioOk()) {
    Serial.println("Ainda sem data e hora.");
    return;
  }
  char hoje[11];
  dataDeHoje(hoje);
  bool ok = desfazer ? desfazerPesagem(reg, ultima, hoje, SOMAR_PESAGENS)
                     : definirTotal(reg, hoje, refeicaoSelecionada, 0);
  if (!ok) {
    Serial.println(desfazer ? "Nada para desfazer (so a ultima pesagem de hoje)." : "Fila cheia: conecte a rede.");
    return;
  }
  uint8_t r = desfazer ? ultima.refeicao : refeicaoSelecionada;
  if (!desfazer) ultima.valida = false;
  salvarRegistro();
  Serial.printf("%s: total de hoje de %s = %.2f kg. Enviando...\n", desfazer ? "Ultima pesagem desfeita" : "Total zerado",
                NOMES_REFEICAO[r], totalDoDia(reg, hoje, r) / 1000.0);
  enviarFila();
}

// ================================ SERIAL ================================
void mostrarAjuda() {
  Serial.println("Comandos (Serial 115200, termine com Enter):");
  Serial.println("  t          tara: zera a balanca (prato vazio)");
  Serial.println("  c <kg>     calibra: tara feita, ponha um peso conhecido e digite o valor. Ex.: c 2.000");
  Serial.println("  f <fator>  define o fator manualmente (unidades por kg; aceita negativo)");
  Serial.println("  d          diagnostico completo do sensor, da calibracao e da rede");
  Serial.println("  r          liga/desliga o valor bruto ao vivo");
  Serial.println("  l          liga/desliga o peso ao vivo");
  Serial.println("  e          pesa e envia (igual ao botao vermelho)");
  Serial.println("  m          troca a refeicao (igual ao botao amarelo)");
  Serial.println("  u          desfaz a ultima pesagem de hoje");
  Serial.println("  z          zera o total de HOJE da refeicao selecionada (envia 0 kg)");
}

void diagnostico() {
  Instantaneo s = lerSensor();
  Serial.println("=== DIAGNOSTICO DA BALANCA ===");
  if (!s.ok) {
    Serial.println(s.saturado ? "Sensor: valor no LIMITE do HX711 (+-8388607): fio da celula solto, invertido ou em curto (E+, E-, A+, A-)."
                              : "Sensor: HX711 SEM RESPOSTA. Confira DT=GPIO16, SCK=GPIO17, VCC (3,3 V) e GND.");
  }
  Serial.printf("Leituras: %.1f por segundo (o normal e ~10; ~80 indica o pino RATE do modulo em alto)\n", leiturasPorSegundo);
  Serial.printf("Bruto: %.0f | ruido (pico a pico em %u leituras): %ld = ~%.1f g | %s\n", s.media, s.amostras,
                s.picoAPico, brutoEmGramas(s.picoAPico), estaParado(s) ? "PARADO" : "variando");
  if (taraValida) {
    Serial.printf("Tara: offset=%ld | zero automatico corrigiu %+.1f g desde a ultima tara\n", offsetAtual,
                  calibrada ? (offsetAtual - offsetTara) * 1000.0f / fatorCalibracao : 0.0f);
  } else {
    Serial.println("Tara: NAO FEITA (botao TARA ou t)");
  }
  if (calibrada) {
    Serial.printf("Fator: %.2f unidades/kg (comum para %.0f kg: ~%.0f) | peso: %.3f kg\n", fatorCalibracao, CAPACIDADE_KG,
                  MODO_SIMULADOR ? FATOR_SIMULADOR : FATOR_TIPICO, pesoAtualKg(s));
  } else {
    Serial.println("Fator: NAO CALIBRADA (segure TARA 4 s, ou t e depois c <kg>)");
  }
  if (!MODO_SIMULADOR && s.ok && s.amostras >= AMOSTRAS_ESTAVEL && s.picoAPico == 0) {
    Serial.println("ALERTA: leitura totalmente parada; um HX711 de verdade sempre oscila um pouco. Confira a celula e o DT.");
  }
  if (s.ok && s.picoAPico > limiarEstavelBruto()) {
    Serial.println("ALERTA: ruido alto. Solde os fios (protoboard da mau contato), encurte e tranque os fios da celula,"
                   " confira se nada encosta no prato e use uma fonte USB boa.");
  }
  Serial.printf("Wi-Fi: %s", wifiConectado ? "conectado" : "desconectado");
  if (wifiConectado) Serial.printf(" (%d dBm)", WiFi.RSSI());
  if (relogioOk()) {
    char agora[20];
    time_t t = time(nullptr);
    tm h;
    localtime_r(&t, &h);
    strftime(agora, sizeof(agora), "%d/%m/%Y %H:%M", &h);
    Serial.printf(" | hora: %s", agora);
  } else {
    Serial.print(" | hora: ainda nao sincronizada");
  }
  Serial.printf(" | fila: %u envio(s)\n", reg.nFila);
  for (uint8_t i = 0; i < reg.nFila; i++) {
    Serial.printf("  pendente: %s %s = %.2f kg\n", reg.fila[i].data, NOMES_REFEICAO[reg.fila[i].refeicao], reg.fila[i].gramas / 1000.0);
  }
  Serial.printf("Totais de %s:", reg.dia[0] ? reg.dia : "(nenhum dia)");
  for (uint8_t i = 0; i < TOTAL_REFEICOES; i++) Serial.printf(" %s=%.2f", NOMES_REFEICAO[i], reg.totalGramas[i] / 1000.0);
  Serial.println(" kg");
}

// Aceita "c 2", "c 2.0", "c 2,0", "cal 2"...: pega o primeiro número da linha.
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
      executarTara();
      break;
    case 'c':
      if (numeroDoComando(linha, valor)) calibrar(valor);
      else Serial.println("Informe o peso conhecido em kg. Ex.: c 2.000");
      break;
    case 'f':
      if (numeroDoComando(linha, valor) && aplicarFator(valor)) Serial.printf("Fator definido: %.2f unidades/kg. Salvo na memoria.\n", valor);
      else Serial.println("Fator invalido. Ex.: f -214500 (aceita negativo; nao pode ser 0).");
      break;
    case 'd':
      diagnostico();
      break;
    case 'r':
      modoBruto = !modoBruto;
      Serial.println(modoBruto ? "Bruto ao vivo LIGADO (aperte o prato: o numero deve mudar sempre no mesmo sentido). r de novo desliga."
                               : "Bruto ao vivo desligado.");
      break;
    case 'l':
      logPeso = !logPeso;
      Serial.println(logPeso ? "Peso ao vivo ligado." : "Peso ao vivo desligado.");
      break;
    case 'e':
      registrarEEnviar();
      break;
    case 'm':
      trocarRefeicao();
      break;
    case 'u':
      corrigirTotal(true);
      break;
    case 'z':
      corrigirTotal(false);
      break;
    default:
      mostrarAjuda();
  }
}

// Monta a linha digitada no Serial. Se o monitor não mandar Enter, executa após 400 ms de pausa.
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

void logSerial(const Instantaneo& s) {
  unsigned long agora = millis();
  if (!(modoBruto || logPeso) || agora - ultimoLog < (modoBruto ? LOG_BRUTO_MS : LOG_PESO_MS)) return;
  ultimoLog = agora;
  if (!s.ok) {
    Serial.println(s.saturado ? "HX711 no limite: confira os fios da celula." : "HX711 sem resposta: confira a ligacao do sensor.");
  } else if (modoBruto) {
    Serial.printf("bruto=%ld  variacao desde a tara=%+ld\n", s.ultimoBruto, s.ultimoBruto - offsetAtual);
  } else if (!calibrada || !taraValida) {
    Serial.printf("Bruto=%.0f (%s)\n", s.media, !taraValida ? "aperte TARA com o prato vazio" : "sem calibracao: segure TARA 4 s");
  } else {
    Serial.printf("Peso: %.3f kg (%s, ruido ~%.0f g)\n", pesoAtualKg(s), estaParado(s) ? "parado" : "variando",
                  brutoEmGramas(s.picoAPico));
  }
}

// ================================ EVENTOS DOS BOTÕES ================================
void entrarCalibracao() {
  modo = MODO_CAL_VAZIO;
  modoDesde = millis();
  avisoDuracao = 0;
  Serial.printf("CALIBRACAO: 1) esvazie o prato e aperte TARA; 2) ponha %.3f kg no meio e aperte ENVIAR. "
                "AMARELO cancela.\n", PESO_CALIBRACAO_KG);
}

void tratarEvento(uint8_t evento) {
  if (modo != MODO_NORMAL) modoDesde = millis();
  switch (evento) {
    case EVENTO_REFEICAO:
      if (modo != MODO_NORMAL) {
        modo = MODO_NORMAL;
        aviso("Cancelado", 1500);
        Serial.println("Calibracao cancelada.");
      } else {
        trocarRefeicao();
      }
      break;
    case EVENTO_TARA:
      Serial.println("Botao TARA.");
      if (executarTara() && modo == MODO_CAL_VAZIO) {
        modo = MODO_CAL_PESO;
        Serial.printf("Agora ponha %.3f kg no meio do prato e aperte ENVIAR.\n", PESO_CALIBRACAO_KG);
      }
      break;
    case EVENTO_CALIBRAR:
      if (modo == MODO_NORMAL) entrarCalibracao();
      break;
    case EVENTO_ENVIAR:
      Serial.println("Botao ENVIAR.");
      if (modo == MODO_CAL_VAZIO) {
        aviso("Primeiro: TARA", 2000);
      } else if (modo == MODO_CAL_PESO) {
        if (calibrar(PESO_CALIBRACAO_KG)) modo = MODO_NORMAL;
      } else {
        registrarEEnviar();
      }
      break;
  }
}

// ================================ SETUP E LOOP ================================
void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println();
  Serial.println("SICAPDA BALANCA v6 - celula de carga de 10 kg + HX711");
  pinMode(LED_GREEN, OUTPUT);
  pinMode(LED_RED, OUTPUT);
  pinMode(BTN_TARA, INPUT_PULLUP);
  pinMode(BTN_ENVIAR, INPUT_PULLUP);
  pinMode(BTN_REFEICAO, INPUT_PULLUP);
  digitalWrite(LED_GREEN, LOW);
  digitalWrite(LED_RED, HIGH);

  iniciarDisplay();
  mostrarTela("SICAPDA Balanca", "Iniciando...");

  memoria.begin("sicapda-balanca", false);
  uint8_t salva = memoria.getUChar("refeicao", 1);
  refeicaoSelecionada = salva < TOTAL_REFEICOES ? salva : 1;
  carregarCalibracao();
  carregarRegistro();
  atualizarLimiares();

  balanca.begin(HX_DT_PIN, HX_SCK_PIN);  // canal A, ganho 128
  if (!balanca.wait_ready_timeout(1500)) {
    Serial.println("HX711 sem resposta: confira DT=GPIO16, SCK=GPIO17, VCC (3,3 V) e GND.");
  }
  for (int i = 0; i < 3 && balanca.wait_ready_timeout(500); i++) balanca.read();  // primeiras leituras após ligar
  if (xTaskCreatePinnedToCore(tarefaSensor, "sensor", 4096, nullptr, 2, nullptr, 1) != pdPASS) {
    Serial.println("ERRO: nao foi possivel iniciar a leitura do sensor. Reinicie a ESP32.");
  }

  int tamanhoToken = strlen(TOKEN_DISPOSITIVO);
  Serial.printf("Servidor: %s | token: %d caracteres (%.6s...%s)\n", URL_SERVIDOR, tamanhoToken, TOKEN_DISPOSITIVO,
                TOKEN_DISPOSITIVO + (tamanhoToken > 4 ? tamanhoToken - 4 : 0));
  if (tamanhoToken != 64) Serial.println("ATENCAO: o token tem 64 caracteres. Copie de novo da caixa amarela em Integracoes.");
  if (calibrada) Serial.printf("Fator de calibracao: %.2f unidades/kg\n", fatorCalibracao);
  else Serial.println("BALANCA SEM CALIBRACAO: segure TARA por 4 s (ou t e depois c <kg> no Serial).");
  if (reg.nFila) Serial.printf("%u total(is) na fila para enviar.\n", reg.nFila);

  if (!taraValida) {  // primeira vez: zera com o prato vazio
    mostrarTela("Primeira tara", "Prato vazio!");
    Serial.println("Primeira inicializacao: fazendo a tara. Deixe o prato VAZIO.");
    delay(1500);
    executarTara();
  }

  mostrarTela("SICAPDA Balanca", "Conectando WiFi");
  iniciarWifi();
  configTzTime("BRT3", "pool.ntp.org", "a.st1.ntp.br");  // relógio também pela internet (São Paulo, UTC-3)
  for (unsigned long inicio = millis(); WiFi.status() != WL_CONNECTED && millis() - inicio < 10000;) delay(100);
  manterWifi();

  eventosBotoes = xQueueCreate(8, sizeof(uint8_t));
  if (!eventosBotoes || xTaskCreate(monitorarBotoes, "botoes", 2048, nullptr, 1, nullptr) != pdPASS) {
    Serial.println("ERRO: nao foi possivel iniciar os botoes. Reinicie a ESP32.");
  } else {
    Serial.println("Botoes: TARA = GPIO32 (segure 4 s = calibrar) | ENVIAR = GPIO33 | REFEICAO = GPIO25");
  }
  Serial.println("Pronta. Digite ? no Serial para ver os comandos (t, c, d, r, e...).");
}

void loop() {
  manterWifi();
  lerComandosSerial();

  uint8_t evento;
  while (eventosBotoes && xQueueReceive(eventosBotoes, &evento, 0) == pdTRUE) tratarEvento(evento);

  unsigned long agora = millis();
  unsigned long intervaloPing = relogioOk() ? PING_MS : PING_SEM_HORA_MS;
  if (wifiConectado && (pingAgendado || agora - ultimoPing >= intervaloPing)) {
    pingAgendado = false;
    ultimoPing = agora;
    enviarPing();
  }
  if (reg.nFila > 0 && wifiConectado && (ultimoReenvio == 0 || millis() - ultimoReenvio >= REENVIO_MS)) {
    ultimoReenvio = millis();
    enviarFila();
  }
  if (modo != MODO_NORMAL && millis() - modoDesde >= CALIBRACAO_EXPIRA_MS) {
    modo = MODO_NORMAL;
    aviso("Cancelado", 1500);
    Serial.println("Calibracao cancelada por tempo.");
  }

  Instantaneo s = lerSensor();
  if (modo == MODO_NORMAL && taraValida && calibrada) {
    corrigirZero(zeroAuto, s.media, offsetAtual, offsetTara, fatorCalibracao, estaParado(s), millis());
  }
  if (millis() - ultimaTaxa >= 2000) {
    leiturasPorSegundo = (s.contador - contadorTaxa) * 1000.0f / (millis() - ultimaTaxa);
    contadorTaxa = s.contador;
    ultimaTaxa = millis();
  }
  if (millis() - ultimaTela >= 150) {
    ultimaTela = millis();
    atualizarTela(s);
    atualizarLeds(s);
    visorWeb();  // o que não aparece no LCD (peso parado, Wi-Fi) e a repetição a cada VISOR_WEB_MS
  }
  logSerial(s);
  delay(5);
}
