"""Teste opcional do filtro, da tara, da calibração e do zero automático: python3 tests/balanca_calibracao.py (requer g++).
Compila os trechos reais entre INICIO/FIM FILTRO e CONVERSAO do firmware e alimenta com uma célula de 10 kg
simulada (ruído, picos, deriva e sinal invertido). Não substitui a calibração com peso conhecido na balança física.
"""
from pathlib import Path
import subprocess
import tempfile

source = (Path(__file__).resolve().parents[1] / 'iot/esp32_balanca/esp32_acesso.ino').read_text()
def trecho(nome):
    return source.split('// INICIO ' + nome + ':', 1)[1].split('\n', 1)[1].split('// FIM ' + nome, 1)[0]

stubs = r'''
#include <cassert>
#include <cmath>
#include <cstdint>
#include <cstdio>
#include <iostream>
#include <random>
#include <vector>
using std::isfinite;  // o toolchain da ESP32 expõe isfinite globalmente
const uint8_t TOTAL_REFEICOES = 4;
const float CAPACIDADE_KG = 10.0f;
const float DIVISAO_KG = 0.005f;
const float ZERO_AUTO_KG = 0.004f;
const float ZERO_AUTO_LIMITE_KG = 0.100f;
'''
checks = r'''
bool perto(double a, double b, double tolerancia) {return std::fabs(a - b) <= tolerancia;}

// Célula de 10 kg simulada: ~ -214748 unidades/kg (ligação invertida), zero em 85000, ruído gaussiano.
const float FATOR = -214748.0f;
const long ZERO = 85000;
std::mt19937 gerador(42);
std::normal_distribution<double> ruido(0.0, 250.0);  // ~1,2 g de desvio padrão
long leitura(double kg, double deriva = 0) {return lround(ZERO + deriva + kg * FATOR + ruido(gerador));}

int main() {
  // ---- mediana de 3 ----
  assert(mediana3(1, 2, 3) == 2 && mediana3(3, 1, 2) == 2 && mediana3(2, 3, 1) == 2);
  assert(mediana3(5, 5, -9) == 5 && mediana3(-7, -7, -7) == -7 && mediana3(9, -1000000, 10) == 9);

  // ---- média, pico a pico e picos isolados ----
  Filtro f;
  filtroIniciar(f, 16);
  for (int i = 0; i < 20; i++) filtroAdicionar(f, 1000, 5000);
  assert(f.n == 16 && filtroMedia(f) == 1000 && filtroPicoAPico(f) == 0);
  filtroAdicionar(f, 8000000, 5000);              // pico isolado (fio mexido, Wi-Fi)
  filtroAdicionar(f, 1000, 5000);
  assert(filtroMedia(f) == 1000 && filtroPicoAPico(f) == 0);  // a mediana derrubou o pico
  // Janela maior que o máximo é limitada, sem estourar o vetor.
  Filtro g; filtroIniciar(g, 200);
  for (int i = 0; i < 100; i++) filtroAdicionar(g, 42, 0);
  assert(g.tamanho == JANELA_MAX && g.n == JANELA_MAX && filtroMedia(g) == 42);

  // ---- degrau: carga nova recomeça a média em 2 leituras ----
  filtroIniciar(f, 16);
  for (int i = 0; i < 16; i++) filtroAdicionar(f, 0, 5000);
  assert(!filtroAdicionar(f, -200000, 5000));      // 1a leitura nova: a mediana ainda segura o valor antigo
  assert(!filtroAdicionar(f, -200000, 5000));      // 2a: mediana muda, 1o desvio
  assert(filtroAdicionar(f, -200000, 5000));       // 3a: 2o desvio seguido = recomeça
  assert(f.n == 1 && filtroMedia(f) == -200000);
  // Variação pequena (abaixo do limiar) não recomeça: vira média.
  filtroIniciar(f, 16);
  for (int i = 0; i < 16; i++) filtroAdicionar(f, i % 2 ? 300 : -300, 5000);
  assert(f.n == 16 && perto(filtroMedia(f), 0, 1) && filtroPicoAPico(f) == 600);
  // Pedido de "média nova" (tara) esvazia a janela.
  filtroLimpar(f);
  assert(f.n == 0 && filtroMedia(f) == 0 && filtroPicoAPico(f) == 0);

  // ---- calibração ----
  float fator;
  assert(calcularFator(1050, 0, 0, 2.5f, fator) == nullptr && perto(fator, 420, 0.01));  // Wokwi
  assert(calcularFator(ZERO + 2.0 * FATOR, ZERO, 900, 2.0f, fator) == nullptr && perto(fator, FATOR, 0.5));
  assert(perto(brutoParaKg(ZERO + 1.234 * FATOR, ZERO, fator), 1.234, 1e-4));  // sinal invertido sai positivo
  assert(calcularFator(1050, 0, 0, 0.0f, fator) != nullptr);
  assert(calcularFator(1050, 0, 0, -1.0f, fator) != nullptr);
  assert(calcularFator(ZERO + 11.0 * FATOR, ZERO, 0, 11.0f, fator) != nullptr);  // acima da capacidade
  assert(calcularFator(ZERO - 5000, ZERO, 400, 1.0f, fator) != nullptr);          // quase nada perto do ruído
  assert(calcularFator(80, 0, 0, 1.0f, fator) != nullptr);                        // tara feita com o peso em cima

  // ---- valor no LCD: nunca negativo, sem piscar ----
  assert(divisoesParaMostrar(-0.250f, 0) == 0 && divisoesParaMostrar(-0.001f, 12) == 0 && divisoesParaMostrar(NAN, 3) == 0);
  assert(divisoesParaMostrar(0.002f, 0) == 0);    // menos de meia divisão: 0.000
  assert(divisoesParaMostrar(1.2345f, 0) == 247); // 1.235 kg
  long mostrado = 247;
  for (float kg : {1.2338f, 1.2362f, 1.2340f, 1.2364f}) {  // oscila entre 1.235 e 1.236
    mostrado = divisoesParaMostrar(kg, mostrado);
    assert(mostrado == 247);
  }
  assert(divisoesParaMostrar(1.2390f, 247) == 248);  // mudou de verdade: acompanha

  // ---- zero automático ----
  ZeroAuto z = {false, 0};
  long offset = ZERO;
  double deriva = 0.002 * FATOR;                   // 2 g de deriva
  assert(!corrigirZero(z, ZERO + deriva, offset, ZERO, FATOR, true, 0));
  assert(!corrigirZero(z, ZERO + deriva, offset, ZERO, FATOR, true, 999));
  assert(corrigirZero(z, ZERO + deriva, offset, ZERO, FATOR, true, 1000) && offset == lround(ZERO + deriva));
  assert(perto(brutoParaKg(ZERO + deriva, offset, FATOR), 0, 1e-5));
  // Peso de verdade (50 g) ou balança variando: não mexe no zero.
  long antes = offset;
  for (unsigned long t = 2000; t < 6000; t += 100) corrigirZero(z, offset + 0.050 * FATOR, offset, ZERO, FATOR, true, t);
  for (unsigned long t = 6000; t < 9000; t += 100) corrigirZero(z, offset + 0.001 * FATOR, offset, ZERO, FATOR, false, t);
  assert(offset == antes);
  // Não se afasta mais de 100 g da última tara, mesmo aos poucos.
  z = {false, 0}; offset = ZERO;
  unsigned long t = 0;
  for (int passo = 0; passo < 400; passo++) {
    double media = offset + 0.003 * FATOR;
    corrigirZero(z, media, offset, ZERO, FATOR, true, t += 600);
  }
  assert(fabsf(brutoParaKg(offset, ZERO, FATOR)) <= 0.100f + 1e-4f);

  // ---- célula de 10 kg simulada do começo ao fim ----
  Filtro s; filtroIniciar(s, 16);
  const long degrau = lround(0.030 * std::fabs(FATOR));
  const long parado = lround(0.020 * std::fabs(FATOR));
  for (int i = 0; i < 40; i++) {
    long v = leitura(0);
    if (i == 25) v = 8388607;                       // pico absurdo no meio
    filtroAdicionar(s, v, degrau);
  }
  assert(s.n == 16 && filtroPicoAPico(s) <= parado);
  long tara = lround(filtroMedia(s));
  assert(std::labs(tara - ZERO) < 200);             // tara a menos de 1 g do zero verdadeiro
  // Coloca 2,345 kg: em 3 leituras a média recomeça; com 8 leituras o peso está parado e certo.
  int ate = 0;
  bool recomecou = false;
  for (; ate < 30; ate++) {
    recomecou |= filtroAdicionar(s, leitura(2.345), degrau);
    if (recomecou && s.n >= 8 && filtroPicoAPico(s) <= parado) break;
  }
  assert(recomecou && ate < 12);
  float kg = brutoParaKg(filtroMedia(s), tara, FATOR);
  assert(perto(kg, 2.345, 0.003));
  for (int i = 0; i < 16; i++) filtroAdicionar(s, leitura(2.345), degrau);
  assert(perto(brutoParaKg(filtroMedia(s), tara, FATOR), 2.345, 0.002));
  // Tira o peso com deriva de -15 g: o LCD mostra 0.000, nunca "-0.015".
  for (int i = 0; i < 30; i++) filtroAdicionar(s, leitura(0, -0.015 * FATOR), degrau);
  kg = brutoParaKg(filtroMedia(s), tara, FATOR);
  assert(kg < -0.010f && divisoesParaMostrar(kg, 469) == 0);

  std::cout << "PASS: mediana, media movel, degrau, calibracao (inclusive negativa), LCD sem negativo e sem piscar, "
               "zero automatico e celula de 10 kg simulada\n";
}
'''
with tempfile.TemporaryDirectory() as temp:
    cpp = Path(temp) / 'calibracao.cpp'
    binary = Path(temp) / 'calibracao'
    cpp.write_text(stubs + trecho('TIPOS') + trecho('FILTRO') + trecho('CONVERSAO') + checks)
    subprocess.run(['g++', '-std=c++11', '-Wall', '-Wextra', '-Werror', str(cpp), '-o', str(binary)], check=True)
    subprocess.run([str(binary)], check=True)
