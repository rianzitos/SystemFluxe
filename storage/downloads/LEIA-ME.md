# Downloads do aplicativo mobile

Esta pasta guarda o instalador do aplicativo (APK) que a página `/sicapda` oferece para download.
Ela fica **fora** de `public/`: o arquivo só sai pela rota `/app/baixar`
(`app/controllers/AppMobileController.php`), com os cabeçalhos corretos e suporte a retomar o download.

## Arquivos esperados

| Arquivo | O que é |
|---|---|
| `SICAPDA.apk` | O aplicativo para Android. |
| `app.json` | Versão, tamanho, SHA-256 e data. Aparece na página e permite ao usuário conferir a integridade. |

Exemplo de `app.json`:

```json
{
  "arquivo": "SICAPDA.apk",
  "versao": "1.0.0",
  "build": 1,
  "tamanho": 37386558,
  "sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
  "min_sdk": 24,
  "atualizado_em": "2026-10-08T13:07:00Z"
}
```

Se o `app.json` não existir, ou não corresponder ao APK (tamanho diferente), a página mostra só o que dá
para confirmar (tamanho e data do arquivo) e esconde versão e SHA-256.
Sem o `SICAPDA.apk`, a página mostra "Em breve" e o botão de download fica desativado.

## Como publicar uma nova versão

**Opção 1 — GitHub Actions (não precisa de Android Studio)**

1. No repositório `fluxe_app`, aumente `version:` no `pubspec.yaml` (ex.: `1.0.1+2`) e faça o merge na `main`
   (ou crie uma tag: `git tag v1.0.1 && git push origin v1.0.1`).
2. Abra a aba **Actions → Build APK → (execução) → Artifacts** (ou a **Release**, se usou tag)
   e baixe `SICAPDA-apk`. Dentro estão `SICAPDA.apk` e `app.json`.
3. Copie os dois arquivos para esta pasta.

**Opção 2 — compilar no seu computador (Windows)**

```powershell
cd ..\fluxe_app
.\tool\publicar_apk.ps1            # compila e copia SICAPDA.apk + app.json para cá
```

Depois é só subir esta pasta para o servidor (ou dar commit, se você publica o site pelo Git;
cada APK tem algumas dezenas de MB, então prefira subir por FTP/SSH quando puder).

## Atenção: a assinatura do APK

O Android só deixa instalar uma versão nova **por cima** da anterior se as duas foram assinadas com a mesma chave.
Se o `fluxe_app` ainda não tem a chave própria configurada nos *secrets* do GitHub, cada APK sai com uma chave de teste
diferente e quem já instalou precisa desinstalar o app antes de instalar o novo. Configure a chave uma vez
(passo a passo em "Assinatura do APK" no README do `fluxe_app`) antes de divulgar o aplicativo para os usuários.

## Conferir a integridade

```powershell
Get-FileHash .\SICAPDA.apk -Algorithm SHA256     # deve ser igual ao SHA-256 do app.json e da página
```
