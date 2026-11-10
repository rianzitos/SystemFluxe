"""Teste opcional da leitura filtrada, tara e calibração da balança: python3 tests/balanca_calibracao.py (requer g++).
Compila o trecho real entre INICIO/FIM CALIBRACAO do firmware com o HX711, a memória NVS e o Serial simulados.
Não substitui a calibração com peso conhecido na balança física.
"""
from pathlib import Path
import subprocess
import tempfile

source = (Path(__file__).resolve().parents[1] / 'iot/esp32_balanca/esp32_acesso.ino').read_text()
calibracao = source.split('// INICIO CALIBRACAO:', 1)[1].split('\n', 1)[1].split('// FIM CALIBRACAO', 1)[0]
stubs = r'''
#include <cassert>
#include <cmath>
#include <cstdint>
#include <cstdio>
#include <iostream>
#include <map>
#include <string>
#include <vector>
using std::isfinite;  // o toolchain da ESP32 expõe isfinite globalmente
bool MODO_SIMULADOR = false;
const float FATOR_SIMULADOR = 420.0;
const float PESO_MAXIMO_KG = 200.0;
const float TOLERANCIA_ZERO_KG = 0.05;
const int AMOSTRAS = 20;
unsigned long agoraMs = 0;
unsigned long millis() {return agoraMs += 10;}
struct Sensor {
  std::vector<long> fila;
  size_t proximo = 0;
  long offset = 0;
  void carregar(std::vector<long> valores) {fila = valores; proximo = 0;}
  bool wait_ready_timeout(unsigned long) {return proximo < fila.size();}
  long read() {return fila[proximo++];}
  long get_offset() {return offset;}
  void set_offset(long novo) {offset = novo;}
} balanca;
struct Memoria {
  std::map<std::string, float> dados;
  bool falhar = false;
  float getFloat(const char* chave, float padrao) {return dados.count(chave) ? dados[chave] : padrao;}
  size_t putFloat(const char* chave, float valor) {
    if (falhar) return 0;
    dados[chave] = valor;
    return sizeof(float);
  }
} memoria;
struct Logger {
  void println(const char*) {}
  template<class... Args> void printf(const char*, Args...) {}
} Serial;
'''
checks = r'''
std::vector<long> repetir(long valor, int vezes) {return std::vector<long>(vezes, valor);}
bool perto(double a, double b, double tolerancia) {return std::fabs(a - b) <= tolerancia;}
void reiniciar() {calibrada = false; fatorCalibracao = 0; balanca.offset = 0; memoria.dados.clear(); memoria.falhar = false;}

int main() {
  double media; long dispersao;

  // Média aparada: dois picos absurdos entre 20 amostras não mexem no resultado.
  std::vector<long> amostras = repetir(1000, 18);
  amostras.insert(amostras.begin() + 3, 1000000);
  amostras.insert(amostras.begin() + 11, -1000000);
  balanca.carregar(amostras);
  assert(lerMediaBruta(media, 20, dispersao));
  assert(media == 1000);
  assert(dispersao == 2000000);  // a dispersão é medida antes de aparar: acusa o ruído
  // Valores negativos do HX711 (24 bits com sinal) são preservados.
  balanca.carregar(repetir(-7500000, 10));
  assert(lerMediaBruta(media, 10, dispersao) && media == -7500000 && dispersao == 0);
  // Sensor sem resposta no meio da média: falha em vez de esperar para sempre.
  balanca.carregar(repetir(5, 7));
  assert(!lerMediaBruta(media, 20, dispersao));
  // Mais amostras que o limite são reduzidas ao máximo, sem estourar o vetor.
  balanca.carregar(repetir(42, 100));
  assert(lerMediaBruta(media, 100, dispersao) && media == 42);

  // Tara guarda a média como offset.
  reiniciar();
  balanca.carregar(repetir(123456, 20));
  assert(fazerTara() && balanca.get_offset() == 123456);
  balanca.carregar(repetir(1, 3));
  assert(!fazerTara() && balanca.get_offset() == 123456);

  // Calibração do simulador Wokwi: 2,5 kg = 1050 unidades => 420 unidades/kg.
  float fator;
  assert(calcularFator(1050, 0, 0, 2.5f, fator) == nullptr && perto(fator, 420, 0.01));
  // Hardware real com sinal invertido: o bruto CAI ao colocar peso => fator negativo, e o peso sai positivo.
  assert(calcularFator(8000000 - 75000, 8000000, 20, 5.0f, fator) == nullptr && perto(fator, -15000, 0.01));
  assert(perto(brutoParaKg(8000000 - 30000, 8000000, fator), 2.0, 1e-4));
  assert(perto(brutoParaKg(8000000, 8000000, fator), 0.0, 1e-4));
  // Recusa peso inválido, variação pequena perto do ruído e capacidade excedida.
  assert(calcularFator(1050, 0, 0, 0.0f, fator) != nullptr);
  assert(calcularFator(1050, 0, 0, -1.0f, fator) != nullptr);
  assert(calcularFator(1050, 0, 0, 250.0f, fator) != nullptr);
  assert(calcularFator(500, 0, 400, 5.0f, fator) != nullptr);   // 500 unidades com ruído de 400: não dá para calibrar
  assert(calcularFator(80, 0, 0, 5.0f, fator) != nullptr);      // quase nada saiu do zero

  // Fator salvo na NVS sobrevive ao reinício; fator 0, NaN ou falha de gravação não alteram nada.
  reiniciar();
  carregarCalibracao();
  assert(!calibrada);                                           // balança física nunca calibrada
  assert(!aplicarFator(0) && !aplicarFator(NAN) && !calibrada);
  memoria.falhar = true;
  assert(!aplicarFator(-15000) && !calibrada);
  memoria.falhar = false;
  assert(aplicarFator(-15000) && calibrada && fatorCalibracao == -15000);
  calibrada = false; fatorCalibracao = 0;
  carregarCalibracao();
  assert(calibrada && fatorCalibracao == -15000);
  // No simulador, sem fator salvo, vale 420; um fator salvo pela calibração prevalece.
  reiniciar(); MODO_SIMULADOR = true;
  carregarCalibracao();
  assert(calibrada && fatorCalibracao == 420);
  assert(aplicarFator(430)); calibrada = false; carregarCalibracao();
  assert(fatorCalibracao == 430);
  MODO_SIMULADOR = false;

  // lerPesoKg: sem fator devolve NAN mas registra o bruto; com fator, aplica a faixa de zero.
  reiniciar(); balanca.offset = 100000;
  balanca.carregar(repetir(100500, 10));
  assert(std::isnan(lerPesoKg(10)) && sensorOk && brutoAtual == 100500);
  fatorCalibracao = -15000; calibrada = true;
  balanca.carregar(repetir(100000 - 150000, 10));
  assert(perto(lerPesoKg(10), 10.0, 1e-4));                    // 150000 unidades / 15000 = 10 kg
  balanca.carregar(repetir(100000 + 300, 10));
  assert(lerPesoKg(10) == 0);                                  // -0,02 kg de ruído vira 0 kg
  balanca.carregar(repetir(100000 + 3000, 10));
  assert(perto(lerPesoKg(10), -0.2, 1e-4));                    // negativo real (-0,2 kg) continua negativo
  balanca.carregar({});
  assert(std::isnan(lerPesoKg(10)) && !sensorOk);              // HX711 sem resposta

  std::cout << "PASS: media aparada, tara, fator (inclusive negativo), persistencia, faixa de zero e falha do sensor\n";
}
'''
with tempfile.TemporaryDirectory() as temp:
    cpp = Path(temp) / 'calibracao.cpp'
    binary = Path(temp) / 'calibracao'
    cpp.write_text(stubs + calibracao + checks)
    subprocess.run(['g++', '-std=c++11', '-Wall', '-Wextra', '-Werror', str(cpp), '-o', str(binary)], check=True)
    subprocess.run([str(binary)], check=True)
