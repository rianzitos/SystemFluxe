"""Teste opcional dos totais do dia e da fila de envio da balança: python3 tests/balanca_totais.py (requer g++).
Compila o trecho real entre INICIO/FIM TOTAIS do firmware. A memória NVS, o Wi-Fi e o site não entram aqui:
o teste cobre a soma das pesagens, a troca de dia, a fila offline, o desfazer e a sincronização com o site.
"""
from pathlib import Path
import subprocess
import tempfile

source = (Path(__file__).resolve().parents[1] / 'iot/esp32_balanca/esp32_acesso.ino').read_text()
def trecho(nome):
    return source.split('// INICIO ' + nome + ':', 1)[1].split('\n', 1)[1].split('// FIM ' + nome, 1)[0]

stubs = r'''
#include <cassert>
#include <cstdint>
#include <cstring>
#include <iostream>
const uint8_t TOTAL_REFEICOES = 4;
const uint8_t CAFE = 0, ALMOCO = 1, JANTAR = 2, CEIA = 3;
'''
checks = r'''
int main() {
  Registro r;
  registroLimpar(r);
  UltimaPesagem u = {};
  assert(registroValido(r) && r.nFila == 0 && r.dia[0] == '\0');

  // Soma: 1,20 + 0,85 kg no almoço = 2,05 kg; a fila guarda só o total mais novo (reenvio não duplica).
  assert(registrarPesagem(r, "2026-11-23", ALMOCO, 1200, true, u));
  assert(registrarPesagem(r, "2026-11-23", ALMOCO, 850, true, u));
  assert(r.totalGramas[ALMOCO] == 2050 && r.nFila == 1 && r.fila[0].gramas == 2050 && r.fila[0].refeicao == ALMOCO);
  assert(strcmp(r.fila[0].data, "2026-11-23") == 0 && strcmp(r.dia, "2026-11-23") == 0);
  // Outra refeição entra separada na fila.
  assert(registrarPesagem(r, "2026-11-23", JANTAR, 400, true, u));
  assert(r.nFila == 2 && r.totalGramas[JANTAR] == 400 && totalDoDia(r, "2026-11-23", ALMOCO) == 2050);
  // Envio confirmado do primeiro: sai da fila, o total continua.
  filaRemoverPrimeiro(r);
  assert(r.nFila == 1 && r.fila[0].refeicao == JANTAR && r.totalGramas[ALMOCO] == 2050);

  // Desfazer a última pesagem (jantar 0,40) volta a 0 e manda o novo total.
  assert(desfazerPesagem(r, u, "2026-11-23", true));
  assert(r.totalGramas[JANTAR] == 0 && r.nFila == 1 && r.fila[0].gramas == 0);
  assert(!desfazerPesagem(r, u, "2026-11-23", true));  // só uma vez

  // Modo substituir (SOMAR_PESAGENS = false): o total é a última pesagem; desfazer volta ao anterior.
  assert(registrarPesagem(r, "2026-11-23", CAFE, 300, false, u));
  assert(registrarPesagem(r, "2026-11-23", CAFE, 700, false, u));
  assert(r.totalGramas[CAFE] == 700 && desfazerPesagem(r, u, "2026-11-23", false) && r.totalGramas[CAFE] == 300);

  // Dia novo: os totais recomeçam do zero; o que ficou na fila de ontem continua com a data de ontem.
  assert(registrarPesagem(r, "2026-11-24", ALMOCO, 500, true, u));
  assert(strcmp(r.dia, "2026-11-24") == 0 && r.totalGramas[ALMOCO] == 500 && r.totalGramas[CAFE] == 0);
  assert(totalDoDia(r, "2026-11-23", ALMOCO) == 0);
  assert(filaProcurar(r, "2026-11-23", JANTAR) == 0 && filaProcurar(r, "2026-11-24", ALMOCO) >= 0);
  assert(!desfazerPesagem(r, u, "2026-11-25", true));  // não desfaz pesagem de outro dia

  // Fila cheia (8 totais sem internet): a pesagem é recusada e nada muda.
  registroLimpar(r);
  const char* dias[] = {"2026-11-20", "2026-11-21"};
  for (int d = 0; d < 2; d++)
    for (uint8_t ref = 0; ref < 4; ref++) assert(registrarPesagem(r, dias[d], ref, 100, true, u));
  assert(r.nFila == FILA_MAX);
  Registro copia = r;
  assert(!registrarPesagem(r, "2026-11-22", ALMOCO, 100, true, u));
  assert(memcmp(&copia, &r, sizeof(r)) == 0);
  // Mas somar de novo num total que já está na fila funciona (só atualiza o valor).
  assert(registrarPesagem(r, "2026-11-21", CEIA, 250, true, u) && r.nFila == FILA_MAX && r.totalGramas[CEIA] == 350);

  // Sincronização com o site (ping): adota os totais do site, exceto o que ainda está na fila.
  registroLimpar(r);
  assert(registrarPesagem(r, "2026-11-23", ALMOCO, 1000, true, u));
  int32_t site[] = {150, 300, 2500, 0};
  assert(sincronizarTotais(r, "2026-11-23", site));
  assert(r.totalGramas[CAFE] == 150 && r.totalGramas[ALMOCO] == 1000 && r.totalGramas[JANTAR] == 2500);
  filaRemoverPrimeiro(r);                               // o envio do almoço foi confirmado
  int32_t corrigido[] = {150, 800, 2500, 0};            // alguém corrigiu o almoço no site
  assert(sincronizarTotais(r, "2026-11-23", corrigido) && r.totalGramas[ALMOCO] == 800);
  assert(!sincronizarTotais(r, "2026-11-23", corrigido));
  assert(registrarPesagem(r, "2026-11-23", ALMOCO, 200, true, u) && r.fila[0].gramas == 1000);  // soma ao valor do site
  // Virou o dia no site: totais zerados, depois os do site.
  filaRemoverPrimeiro(r);
  int32_t novoDia[] = {0, 0, 0, 0};
  assert(sincronizarTotais(r, "2026-11-24", novoDia) && strcmp(r.dia, "2026-11-24") == 0 && r.totalGramas[ALMOCO] == 0);

  // Registro corrompido na memória é recusado.
  registroLimpar(r);
  Registro ruim = r; ruim.versao = 99; assert(!registroValido(ruim));
  ruim = r; ruim.nFila = FILA_MAX + 1; assert(!registroValido(ruim));
  ruim = r; ruim.nFila = 1; ruim.fila[0].refeicao = 7; assert(!registroValido(ruim));
  ruim = r; memset(ruim.dia, 'x', sizeof(ruim.dia)); assert(!registroValido(ruim));
  ruim = r; ruim.totalGramas[2] = -5; assert(!registroValido(ruim));

  std::cout << "PASS: soma das pesagens, fila offline, dia novo, desfazer, fila cheia, sincronizacao com o site e memoria invalida\n";
}
'''
with tempfile.TemporaryDirectory() as temp:
    cpp = Path(temp) / 'totais.cpp'
    binary = Path(temp) / 'totais'
    cpp.write_text(stubs + trecho('TIPOS') + trecho('TOTAIS') + checks)
    subprocess.run(['g++', '-std=c++11', '-Wall', '-Wextra', '-Werror', str(cpp), '-o', str(binary)], check=True)
    subprocess.run([str(binary)], check=True)
