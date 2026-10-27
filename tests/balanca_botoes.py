"""Teste opcional de desenvolvimento: python3 tests/balanca_botoes.py (requer g++).
Compila a lógica real dos botões com GPIO e escalonador simulados no computador.
Não substitui compilação ESP32 nem teste do circuito Wokwi.
"""
from pathlib import Path
import subprocess
import tempfile

source = (Path(__file__).resolve().parents[1] / 'iot/esp32_balanca/esp32_acesso.ino').read_text()
debounce = source.split('// INICIO DEBOUNCE:', 1)[1].split('\n', 1)[1].split('// FIM DEBOUNCE', 1)[0]
monitor = source[source.index('void monitorarBotoes('):source.index('bool taraFeita')]
stubs = r'''
#include <cassert>
#include <cstdint>
#include <vector>
#include <iostream>
constexpr int BTN_TARA=32, BTN_FIM=33, BTN_REFEICAO=25, HIGH=1;
constexpr uint8_t EVENTO_TARA=1, EVENTO_FIM=2, EVENTO_REFEICAO=3;
void* eventosBotoes=nullptr;
unsigned long agoraMs=0;
std::vector<uint8_t> fila;
unsigned long millis() {return agoraMs;}
int digitalRead(int pin) {
  if (pin==BTN_TARA) {
    // Ruído inicial, clique rápido de 100 ms e outro clique posterior.
    if ((agoraMs>=100 && agoraMs<105) || (agoraMs>=110 && agoraMs<115)
        || (agoraMs>=120 && agoraMs<220) || (agoraMs>=5000 && agoraMs<5100)) return 0;
  }
  // Segurar FIM não deve criar vários eventos.
  if (pin==BTN_FIM && agoraMs>=500 && agoraMs<4000) return 0;
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
  if(agoraMs>=6000) throw FimSimulacao{};
}
'''
checks = r'''
int main() {
  EstadoBotao b={true,true,0};
  // Pulso curto de ruído não dispara; pressão estável dispara uma vez.
  assert(!detectarPressionamento(b,false,100));
  assert(!detectarPressionamento(b,true,110));
  assert(!detectarPressionamento(b,true,150));
  assert(!detectarPressionamento(b,false,200));
  assert(detectarPressionamento(b,false,230));
  assert(!detectarPressionamento(b,false,1000));
  assert(!detectarPressionamento(b,true,1100));
  assert(!detectarPressionamento(b,true,1130));
  assert(!detectarPressionamento(b,false,1200));
  assert(detectarPressionamento(b,false,1230));
  // A tarefa continua executando durante seis segundos sem consumo no loop.
  try { monitorarBotoes(nullptr); } catch (FimSimulacao&) {}
  assert((fila == std::vector<uint8_t>{EVENTO_TARA, EVENTO_REFEICAO, EVENTO_FIM, EVENTO_REFEICAO, EVENTO_TARA}));
  std::cout << "PASS: clique rápido, ruído, botão segurado, novo clique e fila durante bloqueio do loop\n";
}
'''
with tempfile.TemporaryDirectory() as temp:
    cpp = Path(temp) / 'botoes.cpp'
    binary = Path(temp) / 'botoes'
    cpp.write_text(stubs + debounce + monitor + checks)
    subprocess.run(['g++', '-std=c++11', '-Wall', '-Wextra', '-Werror', str(cpp), '-o', str(binary)], check=True)
    subprocess.run([str(binary)], check=True)
