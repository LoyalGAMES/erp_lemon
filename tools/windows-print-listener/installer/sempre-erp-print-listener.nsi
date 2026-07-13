Unicode true
SetCompressor /SOLID zlib
CRCCheck force

!include "MUI2.nsh"
!include "LogicLib.nsh"
!include "nsDialogs.nsh"
!include "x64.nsh"

!ifndef APP_VERSION
  !error "APP_VERSION is required"
!endif
!ifndef APP_FILE_VERSION
  !error "APP_FILE_VERSION is required"
!endif
!ifndef BUILD_DIR
  !error "BUILD_DIR is required"
!endif
!ifndef OUTPUT_DIR
  !error "OUTPUT_DIR is required"
!endif

!define APP_NAME "Sempre ERP Print Listener"
!define APP_PUBLISHER "Sempre ERP"
!define APP_EXE "lemon-print-listener.exe"
!define CONFIGURATOR_EXE "SempreERP-PrintListener-Configure.exe"
!define APP_ID "SempreERP.PrintListener"
!define SERVICE_NAME "SempreERPPrintListener"
!define LEGACY_FIREWALL_RULE "Sempre ERP Print Listener (Private LAN)"
!define UNINSTALL_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_ID}"
; With SetShellVarContext all, $APPDATA resolves to the machine-wide ProgramData.
!define CONFIG_DIR "$APPDATA\Sempre ERP\Print Listener"
!define CONFIG_FILE "${CONFIG_DIR}\config.ini"
!define CONFIG_STAGED "${CONFIG_DIR}\config.ini.new"

Name "${APP_NAME} ${APP_VERSION}"
OutFile "${OUTPUT_DIR}\SempreERP-PrintListener-Setup.exe"
InstallDir "$PROGRAMFILES64\Sempre ERP\Print Listener"
InstallDirRegKey HKLM "Software\Sempre ERP\Print Listener" "InstallDir"
RequestExecutionLevel admin
BrandingText "Sempre ERP"
ShowInstDetails show
ShowUninstDetails show
ManifestDPIAware true
ManifestSupportedOS all
AllowRootDirInstall false

VIProductVersion "${APP_FILE_VERSION}"
VIAddVersionKey /LANG=1033 "ProductName" "${APP_NAME}"
VIAddVersionKey /LANG=1033 "ProductVersion" "${APP_VERSION}"
VIAddVersionKey /LANG=1033 "FileVersion" "${APP_VERSION}"
VIAddVersionKey /LANG=1033 "CompanyName" "${APP_PUBLISHER}"
VIAddVersionKey /LANG=1033 "FileDescription" "Installs the Sempre ERP outbound Windows print bridge service"
VIAddVersionKey /LANG=1033 "LegalCopyright" "Copyright (c) Sempre ERP"

Var ConfigDialog
Var BaseUrlField
Var TokenField
Var StationField
Var WorkerField
Var BaseUrl
Var BridgeToken
Var Station
Var Worker
Var WriteConfig
Var ServiceExisted
Var InternalRootAdded
Var InternalPublisherAdded
Var InternalTrustCommitted
Var SetupMutex

!define MUI_ABORTWARNING
!define MUI_CUSTOMFUNCTION_ABORT RollbackInternalTrust
!define MUI_FINISHPAGE_NOAUTOCLOSE
!define MUI_UNFINISHPAGE_NOAUTOCLOSE
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
Page custom ConfigPageCreate ConfigPageLeave
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_UNPAGE_FINISH
!insertmacro MUI_LANGUAGE "Polish"
!insertmacro MUI_LANGUAGE "English"

!ifdef SIGN_ARTIFACTS
  !ifndef SIGN_SCRIPT
    !error "SIGN_SCRIPT is required when SIGN_ARTIFACTS is enabled"
  !endif
  ; The signing script reads only an ephemeral certificate thumbprint and TSA URL
  ; from the environment. No PFX password is placed on a process command line.
  !uninstfinalize 'pwsh.exe -NoLogo -NoProfile -NonInteractive -File "${SIGN_SCRIPT}" "%1"' = 0
  !finalize 'pwsh.exe -NoLogo -NoProfile -NonInteractive -File "${SIGN_SCRIPT}" "%1"' = 0
!endif

!ifdef INTERNAL_TRUST_BOOTSTRAP
  !ifndef SIGN_ARTIFACTS
    !error "INTERNAL_TRUST_BOOTSTRAP requires a signed build"
  !endif
  !ifndef INTERNAL_ROOT_CERT
    !error "INTERNAL_ROOT_CERT is required for the internal trust bootstrap"
  !endif
  !ifndef INTERNAL_PUBLISHER_CERT
    !error "INTERNAL_PUBLISHER_CERT is required for the internal trust bootstrap"
  !endif
  !ifndef INTERNAL_ROOT_THUMBPRINT
    !error "INTERNAL_ROOT_THUMBPRINT is required for the internal trust bootstrap"
  !endif
  !ifndef INTERNAL_PUBLISHER_THUMBPRINT
    !error "INTERNAL_PUBLISHER_THUMBPRINT is required for the internal trust bootstrap"
  !endif
  !ifndef INTERNAL_ROOT_SHA256
    !error "INTERNAL_ROOT_SHA256 is required for the internal trust bootstrap"
  !endif
  !ifndef INTERNAL_PUBLISHER_SHA256
    !error "INTERNAL_PUBLISHER_SHA256 is required for the internal trust bootstrap"
  !endif
!endif

Function .onInit
  SetShellVarContext all
  StrCpy $WriteConfig "0"
  StrCpy $InternalRootAdded "0"
  StrCpy $InternalPublisherAdded "0"
  StrCpy $InternalTrustCommitted "0"
  System::Call 'kernel32::CreateMutexW(p 0, i 0, w "Global\SempreERP.PrintListener.Setup") p .r0 ?e'
  Pop $1
  StrCpy $SetupMutex "$0"
  ${If} $SetupMutex == 0
    MessageBox MB_ICONSTOP|MB_OK "Nie udało się utworzyć blokady instalatora Sempre ERP. Spróbuj ponownie jako administrator." /SD IDOK
    SetErrorLevel 10
    Abort
  ${EndIf}
  ${If} $1 == 183
    MessageBox MB_ICONEXCLAMATION|MB_OK "Instalator Sempre ERP jest już uruchomiony. Dokończ lub zamknij poprzednie okno." /SD IDOK
    SetErrorLevel 10
    Abort
  ${EndIf}
  ${IfNot} ${RunningX64}
    MessageBox MB_ICONSTOP|MB_OK "Sempre ERP Print Listener wymaga 64-bitowej wersji Windows." /SD IDOK
    SetErrorLevel 1
    Abort
  ${EndIf}
FunctionEnd

Function RollbackInternalTrust
  !ifdef INTERNAL_TRUST_BOOTSTRAP
    ${If} $InternalTrustCommitted != "1"
      ${If} $InternalRootAdded == "1"
        nsExec::ExecToLog /TIMEOUT=30000 '"$SYSDIR\certutil.exe" -delstore Root "${INTERNAL_ROOT_THUMBPRINT}"'
        Pop $0
        ${If} $0 == 0
          StrCpy $InternalRootAdded "0"
        ${EndIf}
      ${EndIf}
      ${If} $InternalPublisherAdded == "1"
        nsExec::ExecToLog /TIMEOUT=30000 '"$SYSDIR\certutil.exe" -delstore TrustedPublisher "${INTERNAL_PUBLISHER_THUMBPRINT}"'
        Pop $0
        ${If} $0 == 0
          StrCpy $InternalPublisherAdded "0"
        ${EndIf}
      ${EndIf}
    ${EndIf}
  !endif
FunctionEnd

Function .onInstFailed
  Call RollbackInternalTrust
FunctionEnd

Function ConfigPageCreate
  ; A silent enterprise install must pre-provision the protected config file.
  IfSilent 0 +2
    Abort

  ReadINIStr $BaseUrl "${CONFIG_FILE}" "bridge" "base_url"
  ReadINIStr $BridgeToken "${CONFIG_FILE}" "bridge" "token"
  ReadINIStr $Station "${CONFIG_FILE}" "bridge" "station"
  ReadINIStr $Worker "${CONFIG_FILE}" "bridge" "worker_name"
  ${If} $BaseUrl == ""
    StrCpy $BaseUrl "https://"
  ${EndIf}
  ${If} $Worker == ""
    ReadEnvStr $Worker "COMPUTERNAME"
  ${EndIf}

  nsDialogs::Create 1018
  Pop $ConfigDialog
  ${If} $ConfigDialog == error
    Abort
  ${EndIf}

  ${NSD_CreateLabel} 0 0 100% 18u "Połączenie wychodzące do ERP (port przychodzący nie jest otwierany):"
  Pop $0

  ${NSD_CreateLabel} 0 22u 30% 12u "Adres ERP (HTTPS)"
  Pop $0
  ${NSD_CreateText} 32% 19u 68% 14u "$BaseUrl"
  Pop $BaseUrlField

  ${NSD_CreateLabel} 0 45u 30% 12u "Token mostu wydruku"
  Pop $0
  ${NSD_CreatePassword} 32% 42u 68% 14u "$BridgeToken"
  Pop $TokenField

  ${NSD_CreateLabel} 0 68u 30% 12u "Kod stanowiska"
  Pop $0
  ${NSD_CreateText} 32% 65u 68% 14u "$Station"
  Pop $StationField

  ${NSD_CreateLabel} 0 91u 30% 12u "Nazwa komputera/worker"
  Pop $0
  ${NSD_CreateText} 32% 88u 68% 14u "$Worker"
  Pop $WorkerField

  ${NSD_CreateLabel} 0 114u 100% 42u "Token zostanie zapisany w ProgramData z dostępem wyłącznie dla SYSTEM i Administratorów. Renderer PDF jest dołączony do instalatora i przed uruchomieniem weryfikowany kryptograficznie."
  Pop $0

  nsDialogs::Show
FunctionEnd

Function ConfigPageLeave
  IfSilent config_page_done

  ${NSD_GetText} $BaseUrlField $BaseUrl
  ${NSD_GetText} $TokenField $BridgeToken
  ${NSD_GetText} $StationField $Station
  ${NSD_GetText} $WorkerField $Worker

  StrCpy $0 $BaseUrl 8
  ${If} $0 != "https://"
    MessageBox MB_ICONEXCLAMATION|MB_OK "Adres produkcyjnego ERP musi zaczynać się od https://." /SD IDOK
    Abort
  ${EndIf}
  ${If} $BridgeToken == ""
    MessageBox MB_ICONEXCLAMATION|MB_OK "Wpisz token pokazany w ustawieniach pakowania ERP." /SD IDOK
    Abort
  ${EndIf}
  ${If} $Station == ""
    MessageBox MB_ICONEXCLAMATION|MB_OK "Wpisz kod stanowiska skonfigurowany w ERP, np. station-1." /SD IDOK
    Abort
  ${EndIf}
  ${If} $Worker == ""
    MessageBox MB_ICONEXCLAMATION|MB_OK "Wpisz nazwę workera/komputera." /SD IDOK
    Abort
  ${EndIf}

  ; The values remain only in installer memory until the signed application
  ; has created a directory with an exact protected DACL in the install section.
  StrCpy $WriteConfig "1"

  config_page_done:
FunctionEnd

Section "Sempre ERP Print Listener" SEC_MAIN
  SectionIn RO
  SetShellVarContext all
  SetRegView 64

  !ifdef INTERNAL_TRUST_BOOTSTRAP
    ; Enabled only for a signed internal release. It can make later upgrades
    ; trusted, but cannot make Windows trust the first launch before this
    ; elevated section is allowed to run.
    InitPluginsDir
    SetOutPath "$PLUGINSDIR"
    File /oname=SempreERP-Internal-Publisher.cer "${INTERNAL_PUBLISHER_CERT}"
    File /oname=SempreERP-Internal-Root.cer "${INTERNAL_ROOT_CERT}"

    ; Check both exact SHA-1 store locators before asking for consent. The
    ; release pipeline and embedded signed payload pin the stronger SHA-256.
    nsExec::ExecToLog /TIMEOUT=30000 '"$SYSDIR\certutil.exe" -store TrustedPublisher "${INTERNAL_PUBLISHER_THUMBPRINT}"'
    Pop $0
    StrCpy $1 "$0"
    nsExec::ExecToLog /TIMEOUT=30000 '"$SYSDIR\certutil.exe" -store Root "${INTERNAL_ROOT_THUMBPRINT}"'
    Pop $0
    StrCpy $2 "$0"

    ${If} $1 != 0
    ${OrIf} $2 != 0
      IfSilent internal_trust_confirmed
      MessageBox MB_ICONEXCLAMATION|MB_YESNO|MB_DEFBUTTON2 "Instalator jednorazowo doda zaufanie Sempre ERP na tym komputerze.$\r$\n$\r$\nWydawca SHA-256: ${INTERNAL_PUBLISHER_SHA256}$\r$\nRoot SHA-256: ${INTERNAL_ROOT_SHA256}$\r$\n$\r$\nKontynuować?" /SD IDYES IDYES internal_trust_confirmed
      SetErrorLevel 9
      Abort
    internal_trust_confirmed:
    ${EndIf}

    ; Add publisher first. Without the root it cannot establish a working
    ; chain, which is the safer partial state if power is lost mid-bootstrap.
    ${If} $1 != 0
      DetailPrint "Dodawanie wewnętrznego wydawcy Sempre ERP do zaufanych wydawców..."
      ; The precheck proved this exact thumbprint absent, so rollback may safely
      ; attempt deletion even if certutil reports a partial/timeout failure.
      StrCpy $InternalPublisherAdded "1"
      nsExec::ExecToLog /TIMEOUT=30000 '"$SYSDIR\certutil.exe" -f -addstore TrustedPublisher "$PLUGINSDIR\SempreERP-Internal-Publisher.cer"'
      Pop $0
      ${If} $0 != 0
        MessageBox MB_ICONSTOP|MB_OK "Nie udało się dodać certyfikatu wydawcy Sempre ERP. Instalacja została przerwana." /SD IDOK
        SetErrorLevel 9
        Abort
      ${EndIf}
      nsExec::ExecToLog /TIMEOUT=30000 '"$SYSDIR\certutil.exe" -store TrustedPublisher "${INTERNAL_PUBLISHER_THUMBPRINT}"'
      Pop $0
      ${If} $0 != 0
        MessageBox MB_ICONSTOP|MB_OK "Nie udało się potwierdzić certyfikatu wydawcy Sempre ERP. Instalacja została wycofana." /SD IDOK
        SetErrorLevel 9
        Abort
      ${EndIf}
    ${EndIf}

    ${If} $2 != 0
      DetailPrint "Dodawanie głównego certyfikatu Sempre ERP do zaufanych urzędów..."
      StrCpy $InternalRootAdded "1"
      nsExec::ExecToLog /TIMEOUT=30000 '"$SYSDIR\certutil.exe" -f -addstore Root "$PLUGINSDIR\SempreERP-Internal-Root.cer"'
      Pop $0
      ${If} $0 != 0
        MessageBox MB_ICONSTOP|MB_OK "Nie udało się dodać głównego certyfikatu Sempre ERP. Instalacja została wycofana." /SD IDOK
        SetErrorLevel 9
        Abort
      ${EndIf}
      nsExec::ExecToLog /TIMEOUT=30000 '"$SYSDIR\certutil.exe" -store Root "${INTERNAL_ROOT_THUMBPRINT}"'
      Pop $0
      ${If} $0 != 0
        MessageBox MB_ICONSTOP|MB_OK "Nie udało się potwierdzić głównego certyfikatu Sempre ERP. Instalacja została wycofana." /SD IDOK
        SetErrorLevel 9
        Abort
      ${EndIf}
    ${EndIf}
  !endif

  ; Preserve the existing service registration during upgrades. This avoids
  ; DeleteService races and keeps recovery/security settings intact.
  StrCpy $ServiceExisted "0"
  nsExec::ExecToLog '"$SYSDIR\sc.exe" query "${SERVICE_NAME}"'
  Pop $0
  ${If} $0 == 0
    StrCpy $ServiceExisted "1"
  ${EndIf}
  ${If} $ServiceExisted == "1"
    IfFileExists "$INSTDIR\${APP_EXE}" existing_listener_present existing_listener_missing
  existing_listener_present:
    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service stop'
    Pop $0
    ${If} $0 != 0
      MessageBox MB_ICONSTOP|MB_OK "Nie udało się bezpiecznie zatrzymać istniejącej usługi. Pliki nie zostały zastąpione." /SD IDOK
      SetErrorLevel 5
      Abort
    ${EndIf}
    Goto service_prepared
  existing_listener_missing:
    MessageBox MB_ICONSTOP|MB_OK "Usługa ${SERVICE_NAME} istnieje, ale brakuje jej pliku wykonywalnego. Najpierw usuń uszkodzoną usługę poleceniem sc.exe delete ${SERVICE_NAME}." /SD IDOK
    SetErrorLevel 5
    Abort
  service_prepared:
  ${EndIf}

  SetOutPath "$INSTDIR"
  File /oname=${APP_EXE} "${BUILD_DIR}\${APP_EXE}"
  File /oname=SumatraPDF.exe "${BUILD_DIR}\SumatraPDF.exe"
  File /oname=SumatraPDF-COPYING.txt "${BUILD_DIR}\SumatraPDF-COPYING.txt"
  File /oname=README.txt "${BUILD_DIR}\README.txt"
  WriteUninstaller "$INSTDIR\uninstall.exe"

  ; Keep an exact, signed copy of the setup in Program Files. Running it again
  ; is the supported configuration flow: UAC elevates it, the existing secrets
  ; are read from the protected file, and the service is updated in place.
  StrCmp $EXEPATH "$INSTDIR\${CONFIGURATOR_EXE}" configurator_present
  System::Call 'kernel32::CopyFileW(w "$EXEPATH", w "$INSTDIR\${CONFIGURATOR_EXE}", i 0) i .r0'
  ${If} $0 == 0
    MessageBox MB_ICONSTOP|MB_OK "Nie udało się zapisać podpisanej kopii konfiguratora ustawień. Instalacja została przerwana." /SD IDOK
    SetErrorLevel 7
    Abort
  ${EndIf}
  Goto configurator_ready
  configurator_present:
  IfFileExists "$INSTDIR\${CONFIGURATOR_EXE}" configurator_ready 0
    MessageBox MB_ICONSTOP|MB_OK "Nie udało się zainstalować konfiguratora ustawień. Instalacja została przerwana." /SD IDOK
    SetErrorLevel 7
    Abort
  configurator_ready:

  nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -validate-renderer'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP|MB_OK "Dołączony renderer PDF nie przeszedł weryfikacji integralności. Instalacja została przerwana." /SD IDOK
    SetErrorLevel 7
    Abort
  ${EndIf}

  ; The application applies an exact protected DACL, instead of relying on
  ; localized account names or inherited ProgramData permissions.
  nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -protect-config-directory "${CONFIG_DIR}"'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP|MB_OK "Nie udało się zabezpieczyć katalogu konfiguracji ACL. Instalacja została przerwana." /SD IDOK
    SetErrorLevel 3
    Abort
  ${EndIf}

  ${If} $WriteConfig == "1"
    ; Build and validate the replacement next to the active file. Never destroy
    ; a known-good service configuration merely because a newly entered value
    ; is invalid or the machine loses power before validation finishes.
    Delete "${CONFIG_STAGED}"
    ClearErrors
    SetDetailsPrint none
    WriteINIStr "${CONFIG_STAGED}" "bridge" "base_url" "$BaseUrl"
    WriteINIStr "${CONFIG_STAGED}" "bridge" "token" "$BridgeToken"
    WriteINIStr "${CONFIG_STAGED}" "bridge" "station" "$Station"
    WriteINIStr "${CONFIG_STAGED}" "bridge" "worker_name" "$Worker"
    WriteINIStr "${CONFIG_STAGED}" "bridge" "poll_seconds" "2"
    WriteINIStr "${CONFIG_STAGED}" "bridge" "sumatra_path" ""
    IfErrors config_write_failed
    SetDetailsPrint both

    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -protect-config-file "${CONFIG_STAGED}"'
    Pop $0
    ${If} $0 != 0
      MessageBox MB_ICONSTOP|MB_OK "Nie udało się zabezpieczyć nowej konfiguracji ACL. Dotychczasowe ustawienia nie zostały zmienione." /SD IDOK
      SetErrorLevel 3
      Goto staged_config_failed
    ${EndIf}

    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -mode bridge -config "${CONFIG_STAGED}" -validate-config'
    Pop $0
    ${If} $0 != 0
      MessageBox MB_ICONSTOP|MB_OK "Nowa konfiguracja mostu jest nieprawidłowa. Dotychczasowe ustawienia nie zostały zmienione; sprawdź adres HTTPS, token i kod stanowiska." /SD IDOK
      SetErrorLevel 4
      Goto staged_config_failed
    ${EndIf}

    ; MOVEFILE_REPLACE_EXISTING | MOVEFILE_WRITE_THROUGH makes the switch
    ; atomic and asks Windows to flush it before the installer continues.
    System::Call 'kernel32::MoveFileExW(w "${CONFIG_STAGED}", w "${CONFIG_FILE}", i 0x9) i .r0'
    ${If} $0 == 0
      MessageBox MB_ICONSTOP|MB_OK "Nie udało się atomowo aktywować nowej konfiguracji. Dotychczasowe ustawienia nie zostały zmienione." /SD IDOK
      SetErrorLevel 2
      Goto staged_config_failed
    ${EndIf}

    StrCpy $BridgeToken ""
  ${EndIf}

  IfFileExists "${CONFIG_FILE}" config_present 0
    MessageBox MB_ICONSTOP|MB_OK "Brak konfiguracji mostu wydruku. Instalacja cicha wymaga wcześniejszego utworzenia chronionego config.ini." /SD IDOK
    SetErrorLevel 2
    Abort
  config_present:

  ${If} $WriteConfig != "1"
    ; Silent enterprise installs keep their pre-provisioned file. Re-assert its
    ; ACL and validate it here; interactive installs already did both before
    ; the atomic switch above and cannot fail after replacing the old config.
    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -protect-config-file "${CONFIG_FILE}"'
    Pop $0
    ${If} $0 != 0
      MessageBox MB_ICONSTOP|MB_OK "Nie udało się zabezpieczyć pliku konfiguracji ACL. Instalacja została przerwana." /SD IDOK
      SetErrorLevel 3
      Abort
    ${EndIf}

    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -mode bridge -config "${CONFIG_FILE}" -validate-config'
    Pop $0
    ${If} $0 != 0
      MessageBox MB_ICONSTOP|MB_OK "Konfiguracja mostu jest nieprawidłowa. Sprawdź adres HTTPS, token i kod stanowiska." /SD IDOK
      SetErrorLevel 4
      Abort
    ${EndIf}
  ${EndIf}
  Goto config_ready

  config_write_failed:
    SetDetailsPrint both
    StrCpy $BridgeToken ""
    MessageBox MB_ICONSTOP|MB_OK "Nie udało się zapisać nowej konfiguracji. Dotychczasowe ustawienia nie zostały zmienione." /SD IDOK
    SetErrorLevel 2
    Goto staged_config_failed

  staged_config_failed:
    SetDetailsPrint both
    StrCpy $BridgeToken ""
    Delete "${CONFIG_STAGED}"
    ${If} $ServiceExisted == "1"
      ; The service was stopped before replacing the application binary. Its
      ; original config is still intact, so bring it back instead of leaving a
      ; working warehouse station offline after a rejected edit.
      nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service start'
      Pop $1
    ${EndIf}
    Abort
  config_ready:

  ; Remove the inbound firewall exception created by legacy releases. The
  ; production bridge connects outbound to HTTPS and opens no LAN port.
  nsExec::ExecToLog '"$SYSDIR\netsh.exe" advfirewall firewall delete rule name="${LEGACY_FIREWALL_RULE}"'
  Pop $0

  ${If} $ServiceExisted == "1"
    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -mode bridge -config "${CONFIG_FILE}" -log-file "${CONFIG_DIR}\listener.log" -service update'
  ${Else}
    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -mode bridge -config "${CONFIG_FILE}" -log-file "${CONFIG_DIR}\listener.log" -service install'
  ${EndIf}
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP|MB_OK "Nie udało się zainstalować lub zaktualizować usługi Windows (${SERVICE_NAME}). Instalacja zostanie przerwana." /SD IDOK
    SetErrorLevel 5
    ${If} $ServiceExisted != "1"
      Delete "$INSTDIR\uninstall.exe"
      Delete "$INSTDIR\README.txt"
      Delete "$INSTDIR\SumatraPDF.exe"
      Delete "$INSTDIR\SumatraPDF-COPYING.txt"
      Delete "$INSTDIR\${CONFIGURATOR_EXE}"
      Delete "$INSTDIR\${APP_EXE}"
      RMDir "$INSTDIR"
    ${EndIf}
    Abort
  ${EndIf}

  nsExec::ExecToLog '"$SYSDIR\sc.exe" failure "${SERVICE_NAME}" reset= 86400 actions= restart/5000/restart/15000/none/0'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP|MB_OK "Nie udało się ustawić polityki odzyskiwania usługi Windows." /SD IDOK
    SetErrorLevel 5
    Abort
  ${EndIf}
  nsExec::ExecToLog '"$SYSDIR\sc.exe" failureflag "${SERVICE_NAME}" 1'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP|MB_OK "Nie udało się włączyć polityki odzyskiwania usługi Windows." /SD IDOK
    SetErrorLevel 5
    Abort
  ${EndIf}
  nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service start'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP|MB_OK "Usługa została zainstalowana, ale nie połączyła się poprawnie. Sprawdź konfigurację i dziennik w ProgramData\Sempre ERP\Print Listener." /SD IDOK
    SetErrorLevel 6
    ${If} $ServiceExisted != "1"
      nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service uninstall'
      Pop $1
      Delete "$INSTDIR\uninstall.exe"
      Delete "$INSTDIR\README.txt"
      Delete "$INSTDIR\SumatraPDF.exe"
      Delete "$INSTDIR\SumatraPDF-COPYING.txt"
      Delete "$INSTDIR\${CONFIGURATOR_EXE}"
      Delete "$INSTDIR\${APP_EXE}"
      RMDir "$INSTDIR"
    ${EndIf}
    Abort
  ${EndIf}

  DetailPrint "Sprawdzanie autoryzowanego połączenia usługi z ERP..."
  nsExec::ExecToLog /TIMEOUT=30000 '"$INSTDIR\${APP_EXE}" -check-connection'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONEXCLAMATION|MB_OK "Usługa została uruchomiona, ale nie udało się jeszcze potwierdzić połączenia z ERP. Użyj skrótu „Sprawdź połączenie” w menu Start; w razie błędu otwórz „Ustawienia połączenia” i popraw dane." /SD IDOK
  ${Else}
    DetailPrint "Autoryzowane połączenie z ERP zostało potwierdzone."
  ${EndIf}

  ; Register the application only after the service has been proven runnable.
  WriteRegStr HKLM "Software\Sempre ERP\Print Listener" "InstallDir" "$INSTDIR"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayName" "${APP_NAME}"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayVersion" "${APP_VERSION}"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "Publisher" "${APP_PUBLISHER}"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayIcon" "$INSTDIR\${APP_EXE}"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "InstallLocation" "$INSTDIR"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "UninstallString" '"$INSTDIR\uninstall.exe"'
  WriteRegStr HKLM "${UNINSTALL_KEY}" "QuietUninstallString" '"$INSTDIR\uninstall.exe" /S'
  WriteRegStr HKLM "${UNINSTALL_KEY}" "ModifyPath" '"$INSTDIR\${CONFIGURATOR_EXE}"'
  DeleteRegValue HKLM "${UNINSTALL_KEY}" "NoModify"
  WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoRepair" 1

  CreateDirectory "$SMPROGRAMS\Sempre ERP Print Listener"
  CreateShortCut "$SMPROGRAMS\Sempre ERP Print Listener\Ustawienia połączenia.lnk" "$INSTDIR\${CONFIGURATOR_EXE}" "" "$INSTDIR\${APP_EXE}" 0 SW_SHOWNORMAL "" "Zmień adres ERP, token i dane stanowiska (wymaga UAC)"
  CreateShortCut "$SMPROGRAMS\Sempre ERP Print Listener\Sprawdź połączenie.lnk" "$SYSDIR\cmd.exe" '/K ""$INSTDIR\${APP_EXE}" -check-connection"' "$INSTDIR\${APP_EXE}" 0 SW_SHOWNORMAL "" "Sprawdź połączenie usługi drukowania z ERP"
  CreateShortCut "$SMPROGRAMS\Sempre ERP Print Listener\Dokumentacja.lnk" "$INSTDIR\README.txt"
  CreateShortCut "$SMPROGRAMS\Sempre ERP Print Listener\Odinstaluj.lnk" "$INSTDIR\uninstall.exe"
  StrCpy $InternalTrustCommitted "1"
SectionEnd

Section "Uninstall"
  SetShellVarContext all
  SetRegView 64

  IfFileExists "$INSTDIR\${APP_EXE}" 0 service_file_missing
    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service uninstall'
    Pop $0
    ${If} $0 != 0
      MessageBox MB_ICONSTOP|MB_OK "Nie udało się zatrzymać i usunąć usługi ${SERVICE_NAME}. Pliki programu pozostają na dysku." /SD IDOK
      SetErrorLevel 8
      Abort
    ${EndIf}
  service_file_missing:

  nsExec::ExecToLog '"$SYSDIR\netsh.exe" advfirewall firewall delete rule name="${LEGACY_FIREWALL_RULE}"'
  Pop $0

  Delete "$SMPROGRAMS\Sempre ERP Print Listener\Dokumentacja.lnk"
  Delete "$SMPROGRAMS\Sempre ERP Print Listener\Ustawienia połączenia.lnk"
  Delete "$SMPROGRAMS\Sempre ERP Print Listener\Sprawdź połączenie.lnk"
  Delete "$SMPROGRAMS\Sempre ERP Print Listener\Odinstaluj.lnk"
  RMDir "$SMPROGRAMS\Sempre ERP Print Listener"

  Delete "$INSTDIR\${APP_EXE}"
  Delete "$INSTDIR\SumatraPDF.exe"
  Delete "$INSTDIR\SumatraPDF-COPYING.txt"
  Delete "$INSTDIR\README.txt"
  Delete "$INSTDIR\${CONFIGURATOR_EXE}"
  Delete "$INSTDIR\uninstall.exe"
  RMDir "$INSTDIR"

  Delete "${CONFIG_DIR}\config.ini"
  Delete "${CONFIG_DIR}\config.ini.new"
  Delete "${CONFIG_DIR}\listener.log"
  Delete "${CONFIG_DIR}\listener.log.1"
  Delete "${CONFIG_DIR}\listener.log.2"
  Delete "${CONFIG_DIR}\listener.log.3"
  Delete "${CONFIG_DIR}\print-journal.json"
  RMDir "${CONFIG_DIR}"
  RMDir "$APPDATA\Sempre ERP"

  DeleteRegKey HKLM "${UNINSTALL_KEY}"
  DeleteRegKey HKLM "Software\Sempre ERP\Print Listener"
  DeleteRegKey /ifempty HKLM "Software\Sempre ERP"
SectionEnd
