"""Teste opcional do seletor/LCD/persistência, com a biblioteca ArduinoJson real.
Requer g++ e ARDUINOJSON_INCLUDE apontando para a pasta com ArduinoJson.h v7.4.2.
A memória NVS, o LCD e o envio são simulados; não substitui o teste no ESP32.
"""
from pathlib import Path
import os
import subprocess
import tempfile

source = (Path(__file__).resolve().parents[1] / 'iot/esp32_balanca/esp32_acesso.ino').read_text()
constants = source[source.index('const char* const TIPOS_REFEICAO'):source.index('// ----- Pinos')]
helpers = source.split('// INICIO REFEICAO DISPLAY:', 1)[1].split('\n', 1)[1].split('// FIM REFEICAO DISPLAY', 1)[0]
retry = source[source.index('void tentarEnvioPendente()'):source.index('void setup()')]
stubs = r'''
#include <ArduinoJson.h>
#include <cassert>
#include <cmath>
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <map>
#include <string>
#include <vector>
#include <iostream>
using String = std::string;
bool temPendente=false, displayDisponivel=true;
float pendenteKg=0;
String pendenteJson;
struct Memoria {
  std::map<std::string,std::string> dados;
  bool falhar=false;
  uint8_t getUChar(const char* key, uint8_t padrao) {
    return dados.count(key) ? std::stoi(dados[key]) : padrao;
  }
  size_t putUChar(const char* key, uint8_t valor) {
    if(falhar) return 0;
    dados[key]=std::to_string(valor); return 1;
  }
  String getString(const char* key, const char* padrao) {
    return dados.count(key) ? dados[key] : padrao;
  }
  size_t putString(const char* key, String valor) {
    if(falhar) return 0;
    dados[key]=valor; return valor.length();
  }
  bool remove(const char* key) {return dados.erase(key)>0;}
} memoria;
struct Display {
  uint8_t row=0;
  std::string linhas[2];
  void setCursor(int col, uint8_t linha) {assert(col==0 && linha<2);row=linha;}
  void print(const char* texto) {assert(strlen(texto)==16);linhas[row]=texto;}
} lcd;
struct Logger {
  void println(const char*) {}
  template<class... Args> void printf(const char*, Args...) {}
} Serial;
bool respostaServidor=false;
std::vector<std::string> envios;
bool enviarDesperdicio(){envios.push_back(pendenteJson);return respostaServidor;}
void piscarEnvio(){}
void setStatus(bool, bool){}
'''
checks = r'''
void reiniciar(){temPendente=false;pendenteJson="";refeicaoSelecionada=1;refeicaoPendente=-1;restaurarRefeicaoEPendencia();}
int main(){
  restaurarRefeicaoEPendencia();
  assert(refeicaoSelecionada==1);
  const int ciclo[]={2,3,0,1};
  for(int esperado:ciclo){
    trocarRefeicao();
    assert(refeicaoSelecionada==esperado);
    assert(lcd.linhas[0].find(NOMES_REFEICAO[esperado])!=std::string::npos);
    refeicaoSelecionada=0; restaurarRefeicaoEPendencia();
    assert(refeicaoSelecionada==esperado);
  }
  // Falha de armazenamento não pode alterar a seleção mostrada.
  memoria.falhar=true; trocarRefeicao();
  assert(refeicaoSelecionada==1);
  assert(lcd.linhas[1].find("Falha ao salvar")==0);
  assert(!guardarPesagem(2.5,"2026-10-27") && !temPendente);
  memoria.falhar=false;
  // Cada uma das quatro refeições usa o código aceito pelo PHP, com peso/data.
  for(int i=0;i<4;i++){
    refeicaoSelecionada=i;
    assert(guardarPesagem(2.5,"2026-10-27"));
    JsonDocument registro; assert(!deserializeJson(registro,pendenteJson));
    assert(std::string(registro["tipo"].as<const char*>())==TIPOS_REFEICAO[i]);
    assert(registro["desperdicio_kg"].as<float>()==2.5f);
    assert(std::string(registro["data"].as<const char*>())=="2026-10-27");
    String original=pendenteJson;
    trocarRefeicao();
    assert(refeicaoSelecionada==i && pendenteJson==original);
    assert(lcd.linhas[1].find("Envio pendente")==0);
    assert(!guardarPesagem(8,"2026-10-28") && pendenteJson==original);
    // Reinício com preferência diferente: LCD deve mostrar a refeição pendente.
    memoria.putUChar("refeicao",(i+1)%4);
    reiniciar(); mostrarDisplay("Envio pendente");
    assert(refeicaoPendente==i && pendenteJson==original);
    assert(lcd.linhas[0].find(NOMES_REFEICAO[i])!=std::string::npos);
    respostaServidor=false; tentarEnvioPendente();
    assert(temPendente && pendenteJson==original && envios.back()==original);
    respostaServidor=true; tentarEnvioPendente();
    assert(!temPendente && memoria.getString("pendente","").empty());
    assert(lcd.linhas[0].find(NOMES_REFEICAO[i])!=std::string::npos);
    assert(envios.back()==original && pendenteJson.empty());
    trocarRefeicao(); assert(refeicaoSelecionada==(i+2)%4);
  }
  // Dados de preferência inválidos caem no padrão, sem índice fora do array.
  memoria.putUChar("refeicao",255);reiniciar();assert(refeicaoSelecionada==1);
  mostrarDisplay("Mensagem comprida para truncar");assert(lcd.linhas[1]=="Mensagem comprid");
  mostrarDisplay("OK");assert(lcd.linhas[1]=="OK              ");
  displayDisponivel=false;mostrarDisplay("Sem LCD");assert(lcd.linhas[1]=="OK              ");
  std::cout<<"PASS: 4 refeições, LCD, NVS, reinício, falha de gravação, bloqueio e reenvio com JSON original\n";
}
'''
include = os.environ.get('ARDUINOJSON_INCLUDE')
if not include:
    raise SystemExit('Defina ARDUINOJSON_INCLUDE com o diretório de ArduinoJson.h v7.4.2.')
with tempfile.TemporaryDirectory() as temp:
    cpp=Path(temp)/'refeicao.cpp'
    binary=Path(temp)/'refeicao'
    cpp.write_text(stubs+constants+helpers+retry+checks)
    subprocess.run(['g++','-std=c++11','-Wall','-Wextra','-Werror','-I',include,str(cpp),'-o',str(binary)],check=True)
    subprocess.run([str(binary)],check=True)
