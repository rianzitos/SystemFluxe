"""Teste opcional de desenvolvimento: python3 tests/balanca_botoes.py (requer g++).
Compila a lógica real dos botões (clique e "segurar") com GPIO e escalonador simulados no computador.
Não substitui compilação ESP32 nem teste do circuito Wokwi.
"""
from pathlib import Path
import subprocess
import tempfile

source = (Path(__file__).resolve().parents[1] / 'iot/esp32_balanca/esp32_acesso.ino').read_text()
tipos = source.split('// INICIO TIPOS:', 1)[1].split('\n', 1)[1].split('// FIM TIPOS', 1)[0]
debounce = source.split('// INICIO DEBOUNCE:', 1)[1].split('\n', 1)[1].split('// FIM DEBOUNCE', 1)[0]
monitor = source[source.index('void enviarEvento('):source.index('// ================================ FILTRO')]
stubs = r'''
#include <cassert>
#include <cstdint>
#include <vector>
#include <iostream>
const uint8_t TOTAL_REFEICOES = 4;
constexpr int BTN_TARA=32, BTN_ENVIAR=33, BTN_REFEICAO=25, HIGH=1;
constexpr uint8_t EVENTO_TARA=1, EVENTO_ENVIAR=2, EVENTO_REFEICAO=3, EVENTO_CALIBRAR=4;
const unsigned long SEGURAR_CALIBRACAO_MS=4000;
void* eventosBotoes=nullptr;
unsigned long agoraMs=0;
std::vector<uint8_t> fila;
unsigned long millis() {return agoraMs;}
int digitalRead(int pin) {
  if (pin==BTN_TARA) {
    // Ruído inicial, clique rápido de 100 ms, clique posterior e um aperto de 5 s (calibração).
    if ((agoraMs>=100 && agoraMs<105) || (agoraMs>=110 && agoraMs<115)
        || (agoraMs>=120 && agoraMs<220) || (agoraMs>=5000 && agoraMs<5100)
        || (agoraMs>=6000 && agoraMs<11000)) return 0;
  }
  // Segurar ENVIAR não deve criar vários eventos nem "segurou".
  if (pin==BTN_ENVIAR && agoraMs>=500 && agoraMs<4000) return 0;
  // Seletor: um clique rápido e outro mantido; ambos devem gerar uma só troca.
  if (pin==BTN_REFEICAO && ((agoraMs>=300 && agoraMs<400)
      || (agoraMs>=4200 && agoraMs<4900))) return 0;
  return HIGH;
}
void xQueueSend(void*, const uint8_t* evento, int) {fila.push_back(*evento);}
unsigned long pdMS_TO_TICKS(unsigned long ms) {return ms;}
struct FimSimulacao {};
void vTaskDelay(unsigned long ms) {
  agoraMs+=ms;
  if(agoraMs>=12000) throw FimSimulacao{};
}
'''
checks = r'''
int main() {
  EstadoBotao b={true,true,0,0,true};
  // Pulso curto de ruído não dispara; pressão estável dispara uma vez.
  assert(detectarBotao(b,false,100,0)==BOTAO_NADA);
  assert(detectarBotao(b,true,110,0)==BOTAO_NADA);
  assert(detectarBotao(b,true,150,0)==BOTAO_NADA);
  assert(detectarBotao(b,false,200,0)==BOTAO_NADA);
  assert(detectarBotao(b,false,230,0)==BOTAO_CLIQUE);
  assert(detectarBotao(b,false,1000,0)==BOTAO_NADA);
  assert(detectarBotao(b,true,1100,0)==BOTAO_NADA);
  assert(detectarBotao(b,true,1130,0)==BOTAO_NADA);
  assert(detectarBotao(b,false,1200,0)==BOTAO_NADA);
  assert(detectarBotao(b,false,1230,0)==BOTAO_CLIQUE);
  // Segurar: um único "segurou" depois do tempo, contado a partir do clique.
  assert(detectarBotao(b,false,5229,4000)==BOTAO_NADA);
  assert(detectarBotao(b,false,5230,4000)==BOTAO_SEGURADO);
  assert(detectarBotao(b,false,9000,4000)==BOTAO_NADA);
  // Soltar antes do tempo não gera "segurou".
  EstadoBotao c={true,true,0,0,true};
  assert(detectarBotao(c,true,0,4000)==BOTAO_NADA);
  assert(detectarBotao(c,false,10,4000)==BOTAO_NADA);
  assert(detectarBotao(c,false,40,4000)==BOTAO_CLIQUE);
  assert(detectarBotao(c,true,3000,4000)==BOTAO_NADA);
  assert(detectarBotao(c,true,3030,4000)==BOTAO_NADA);
  assert(detectarBotao(c,true,8000,4000)==BOTAO_NADA);
  // A tarefa continua executando sem consumo no loop.
  try { monitorarBotoes(nullptr); } catch (FimSimulacao&) {}
  assert((fila == std::vector<uint8_t>{EVENTO_TARA, EVENTO_REFEICAO, EVENTO_ENVIAR, EVENTO_REFEICAO, EVENTO_TARA,
                                       EVENTO_TARA, EVENTO_CALIBRAR}));
  std::cout << "PASS: clique rapido, ruido, botao segurado, calibracao (TARA 4 s) e fila durante bloqueio do loop\n";
}
'''
with tempfile.TemporaryDirectory() as temp:
    cpp = Path(temp) / 'botoes.cpp'
    binary = Path(temp) / 'botoes'
    cpp.write_text(stubs + tipos + debounce + monitor + checks)
    subprocess.run(['g++', '-std=c++11', '-Wall', '-Wextra', '-Werror', str(cpp), '-o', str(binary)], check=True)
    subprocess.run([str(binary)], check=True)
