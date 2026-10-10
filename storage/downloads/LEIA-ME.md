# Downloads do aplicativo

Esta pasta guarda os instaladores do aplicativo do SICAPDA que a página `/sicapda` oferece para download:
um para **Windows** e um para **Android**.
Ela fica **fora** de `public/`: os arquivos só saem pelas rotas `/app/baixar/windows` e `/app/baixar/android`
(`app/controllers/AppMobileController.php`), com os cabeçalhos corretos e suporte a retomar o download.
A rota `/app/baixar` escolhe o instalador pelo aparelho de quem acessa.

## Arquivos esperados

| Arquivo | O que é |
|---|---|
| `SICAPDA-Setup.exe` | Instalador do aplicativo para Windows 10 ou superior. |
| `app-windows.json` | Versão, tamanho e data do instalador do Windows. |
| `SICAPDA.apk` | O aplicativo para Android. |
| `app.json` | Versão, tamanho, `min_sdk` e data do aplicativo Android. |

Exemplo de `app-windows.json` (o `app.json` é parecido, com `"min_sdk": 24`):

```json
{
  "arquivo": "SICAPDA-Setup.exe",
  "versao": "1.0.0",
  "build": 1,
  "tamanho": 12345678,
  "atualizado_em": "2026-10-08T13:07:00Z"
}
```

Se o `.json` não existir, ou não corresponder ao arquivo (tamanho diferente), a página mostra só o que dá
para confirmar (tamanho e data do arquivo) e esconde a versão.
Você pode publicar só um dos sistemas: a página oferece o que existe. Sem nenhum arquivo, ela mostra "Em breve",
o botão de download fica desativado e as rotas de download respondem 404.

## Como publicar uma nova versão

**Opção 1 — GitHub Actions (não precisa de Android Studio nem de Visual Studio)**

1. No repositório `fluxe_app`, aumente `version:` no `pubspec.yaml` (ex.: `1.0.1+2`) e faça o merge na `main`
   (ou crie uma tag: `git tag v1.0.1 && git push origin v1.0.1`).
2. Abra a aba **Actions → Build do aplicativo → (execução) → Artifacts** (ou a **Release**, se usou tag)
   e baixe `SICAPDA-downloads`. Dentro estão os quatro arquivos da tabela acima.
3. Copie os arquivos para esta pasta.

**Opção 2 — compilar no seu computador (Windows)**

```powershell
cd ..\fluxe_app
.\tool\publicar_app.ps1            # compila Android e Windows e copia os arquivos para cá
```

Para o instalador do Windows é preciso ter o Inno Setup 6 (`winget install JRSoftware.InnoSetup`).
Depois é só subir esta pasta para o servidor (ou dar commit, se você publica o site pelo Git;
cada arquivo tem algumas dezenas de MB, então prefira subir por FTP/SSH quando puder).

## Atenção

- **Assinatura do Android:** o Android só deixa instalar uma versão nova **por cima** da anterior se as duas foram
  assinadas com a mesma chave. Se o `fluxe_app` ainda não tem a chave própria configurada nos *secrets* do GitHub,
  cada APK sai com uma chave de teste diferente e quem já instalou precisa desinstalar o app antes de instalar o novo.
  Configure a chave uma vez (passo a passo em "Assinatura do APK" no README do `fluxe_app`) antes de divulgar o aplicativo.
- **Aviso do Windows:** o instalador ainda não tem assinatura digital (certificado pago). Na primeira execução o
  Windows pode mostrar "O Windows protegeu o seu computador"; o usuário clica em **Mais informações → Executar assim mesmo**.
  A página já explica isso no passo a passo e nas perguntas frequentes.
