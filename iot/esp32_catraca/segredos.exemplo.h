// MODELO — copie este arquivo para "segredos.h" (nesta mesma pasta) e preencha.
// O segredos.h fica só no seu computador: o Git ignora ele (não vai para o GitHub) e o "git pull"
// nunca mexe nele. Assim o token não vaza e atualizar o firmware não apaga o que você preencheu.
#pragma once

// Wi-Fi da catraca de verdade (no Wokwi, ligue MODO_SIMULADOR no .ino e isto é ignorado)
const char* WIFI_SSID  = "NOME_DA_REDE";
const char* WIFI_SENHA = "SENHA_DA_REDE";

// Endereço do SICAPDA, sem a barra no final
const char* URL_SERVIDOR = "https://fluxeteam.com.br";

// Token da CATRACA criado NESSE MESMO site (o do URL_SERVIDOR acima):
// Configurações → Integrações → Adicionar dispositivo → tipo Catraca → copie o token da caixa amarela
// (aparece uma vez só; se perdeu, use "Novo token" na catraca). São 64 letras e números, sem espaços.
// O token da balança não serve aqui.
const char* TOKEN_DISPOSITIVO = "COLE_AQUI_O_TOKEN_DO_DISPOSITIVO";
