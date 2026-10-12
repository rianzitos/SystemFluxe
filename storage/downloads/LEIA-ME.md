# Downloads do aplicativo

Esta pasta guarda os instaladores do aplicativo do SICAPDA que a página `/sicapda` oferece para download:
um para **Android** e outro para **Windows**. (A terceira opção, **Abrir no navegador**, não passa por aqui:
é a versão web do aplicativo, que fica em `public/aplicativo/`. Veja "Versão web" mais abaixo.)

Esta pasta fica **fora** de `public/`: os arquivos só saem pelas rotas `/app/baixar/windows` e `/app/baixar/android`
(`app/controllers/AppMobileController.php`), com os cabeçalhos corretos e suporte a retomar o download.
A rota `/app/baixar` escolhe o instalador pelo aparelho de quem acessa.

## Arquivos esperados

| Arquivo | O que é |
|---|---|
| `SICAPDA.apk` | O aplicativo para Android 7.0 ou superior (celulares ARM e emuladores x86_64). |
| `app.json` | Versão, tamanho, `min_sdk`, SHA-256 e data do aplicativo Android. |
| `SICAPDA-Setup.exe` | Instalador do aplicativo para Windows 10 ou superior. |
| `app-windows.json` | Versão, tamanho e data do instalador do Windows. |

Exemplo de `app.json` (o `app-windows.json` é parecido, sem `min_sdk`):

```json
{
  "arquivo": "SICAPDA.apk",
  "versao": "1.1.0",
  "build": 2,
  "tamanho": 12345678,
  "sha256": "…",
  "min_sdk": 24,
  "atualizado_em": "2026-10-12T13:07:00Z"
}
```

Se o `.json` não existir, ou não corresponder ao arquivo (tamanho diferente), a página mostra só o que dá
para confirmar (tamanho e data do arquivo) e esconde a versão.
Você pode publicar só um dos sistemas: a página oferece o que existe. Sem nenhum instalador, a página continua
oferecendo o **Abrir no navegador** (se `public/aplicativo/` existir), o botão de download fica desativado e as
rotas de download respondem 404.

## Como publicar uma nova versão

O GitHub compila tudo sozinho (não precisa de Android Studio nem de Visual Studio) e guarda o resultado numa
**Release** do repositório `fluxe_app`.

1. No `fluxe_app`, aumente `version:` no `pubspec.yaml` (ex.: `1.1.1+3`) e faça o merge na `main`.
   O GitHub Actions compila e cria a Release `v1.1.1` com: `SICAPDA.apk`, `app.json`, `SICAPDA-Setup.exe`,
   `app-windows.json` e `SICAPDA-web.zip`. (Para testar antes de mesclar, a Release **"Prévia de teste"**,
   `ci-preview`, é atualizada a cada push nos branches de trabalho.)
2. Abra a Release (**github.com/rianzitos/fluxe_app/releases**) e baixe os arquivos.
3. **Instaladores:** copie `SICAPDA.apk` + `app.json` e `SICAPDA-Setup.exe` + `app-windows.json` para esta pasta.
4. **Versão web:** extraia o `SICAPDA-web.zip` em `public/aplicativo/`, **mantendo** o `.htaccess` que já está lá.
5. Suba para o servidor (ou dê commit, se você publica o site pelo Git). O APK tem algumas dezenas de MB;
   se o seu servidor limitar o tamanho no Git, suba esta pasta por FTP/SSH.

Para compilar no seu computador (Windows) em vez de usar o GitHub:

```powershell
cd ..\fluxe_app
.\tool\publicar_app.ps1            # compila Android, Windows e web e copia tudo para o SystemFluxe
```

Para o instalador do Windows é preciso ter o Inno Setup 6 (`winget install JRSoftware.InnoSetup`).

## Versão web (`public/aplicativo/`)

É o mesmo aplicativo, compilado para o navegador (`flutter build web`). Funciona em qualquer aparelho, inclusive
iPhone, e pode ser instalado na tela inicial. Como fica no mesmo site da API, usa o próprio endereço do site
automaticamente, sem configurar nada. Os arquivos de lá são gerados: não edite à mão; para atualizar, extraia o
`SICAPDA-web.zip` da Release. O `.htaccess` da pasta (compressão, tipo `.wasm` e cache) é do site e deve ficar.

## Atenção

- **Assinatura do Android:** o Android só deixa instalar uma versão nova **por cima** da anterior se as duas foram
  assinadas com a mesma chave. Se o `fluxe_app` ainda não tem a chave própria configurada nos *secrets* do GitHub,
  cada APK sai com uma chave de teste diferente e quem já instalou precisa desinstalar o app antes de instalar o novo.
  Configure a chave uma vez (passo a passo em "Assinatura do APK" no README do `fluxe_app`) antes de divulgar o aplicativo.
- **Aviso do Windows:** o instalador ainda não tem assinatura digital (certificado pago). Na primeira execução o
  Windows pode mostrar "O Windows protegeu o seu computador"; o usuário clica em **Mais informações → Executar assim mesmo**.
  A página já explica isso no passo a passo e nas perguntas frequentes.
- **Aviso do Android:** ao baixar um `.apk` fora da Google Play o Chrome avisa "este tipo de arquivo pode danificar o
  dispositivo" e o Android pede para permitir a instalação desta fonte; o Play Protect pode perguntar de novo
  ("Instalar mesmo assim"). A página explica os passos.
