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
!define APP_ID "SempreERP.PrintListener"
!define SERVICE_NAME "SempreERPPrintListener"
!define LEGACY_FIREWALL_RULE "Sempre ERP Print Listener (Private LAN)"
!define UNINSTALL_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_ID}"
; With SetShellVarContext all, $APPDATA resolves to the machine-wide ProgramData.
!define CONFIG_DIR "$APPDATA\Sempre ERP\Print Listener"
!define CONFIG_FILE "${CONFIG_DIR}\config.ini"

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
Var SumatraField
Var BaseUrl
Var BridgeToken
Var Station
Var Worker
Var SumatraPath
Var WriteConfig

!define MUI_ABORTWARNING
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
  ; The signing script reads only an ephemeral certificate thumbprint and TSA URL
  ; from the environment. No PFX password is placed on a process command line.
  !uninstfinalize 'pwsh.exe -NoLogo -NoProfile -NonInteractive -File ".\scripts\sign-artifact.ps1" "%1"'
  !finalize 'pwsh.exe -NoLogo -NoProfile -NonInteractive -File ".\scripts\sign-artifact.ps1" "%1"'
!endif

Function .onInit
  SetShellVarContext all
  StrCpy $WriteConfig "0"
  ${IfNot} ${RunningX64}
    MessageBox MB_ICONSTOP|MB_OK "Sempre ERP Print Listener wymaga 64-bitowej wersji Windows." /SD IDOK
    SetErrorLevel 1
    Abort
  ${EndIf}
FunctionEnd

Function ConfigPageCreate
  ; A silent enterprise install must pre-provision the protected config file.
  IfSilent 0 +2
    Abort

  ReadINIStr $BaseUrl "${CONFIG_FILE}" "bridge" "base_url"
  ReadINIStr $BridgeToken "${CONFIG_FILE}" "bridge" "token"
  ReadINIStr $Station "${CONFIG_FILE}" "bridge" "station"
  ReadINIStr $Worker "${CONFIG_FILE}" "bridge" "worker_name"
  ReadINIStr $SumatraPath "${CONFIG_FILE}" "bridge" "sumatra_path"
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

  ${NSD_CreateLabel} 0 114u 30% 20u "SumatraPDF.exe (opcjonalnie)"
  Pop $0
  ${NSD_CreateText} 32% 111u 68% 14u "$SumatraPath"
  Pop $SumatraField

  ${NSD_CreateLabel} 0 137u 100% 30u "Token zostanie zapisany w ProgramData z dostępem wyłącznie dla SYSTEM i Administratorów. Nie trafia do argumentów usługi ani do rejestru."
  Pop $0

  nsDialogs::Show
FunctionEnd

Function ConfigPageLeave
  IfSilent config_page_done

  ${NSD_GetText} $BaseUrlField $BaseUrl
  ${NSD_GetText} $TokenField $BridgeToken
  ${NSD_GetText} $StationField $Station
  ${NSD_GetText} $WorkerField $Worker
  ${NSD_GetText} $SumatraField $SumatraPath

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

  ; Stop a previous service before replacing its binary. Missing services are
  ; expected on first install and do not weaken the installation result.
  IfFileExists "$INSTDIR\${APP_EXE}" 0 listener_not_installed
    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service stop'
    Pop $0
    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service uninstall'
    Pop $0
    Sleep 1000
  listener_not_installed:

  SetOutPath "$INSTDIR"
  File /oname=${APP_EXE} "${BUILD_DIR}\${APP_EXE}"
  File /oname=README.txt "${BUILD_DIR}\README.txt"
  WriteUninstaller "$INSTDIR\uninstall.exe"

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
    ; Recreate the file inside the protected directory so no old explicit ACEs
    ; survive an upgrade. WriteINIStr never exposes the token in a process argv.
    Delete "${CONFIG_FILE}"
    ClearErrors
    SetDetailsPrint none
    WriteINIStr "${CONFIG_FILE}" "bridge" "base_url" "$BaseUrl"
    WriteINIStr "${CONFIG_FILE}" "bridge" "token" "$BridgeToken"
    WriteINIStr "${CONFIG_FILE}" "bridge" "station" "$Station"
    WriteINIStr "${CONFIG_FILE}" "bridge" "worker_name" "$Worker"
    WriteINIStr "${CONFIG_FILE}" "bridge" "poll_seconds" "2"
    WriteINIStr "${CONFIG_FILE}" "bridge" "sumatra_path" "$SumatraPath"
    IfErrors config_write_failed
    SetDetailsPrint both
    StrCpy $BridgeToken ""
  ${EndIf}

  IfFileExists "${CONFIG_FILE}" config_present 0
    MessageBox MB_ICONSTOP|MB_OK "Brak konfiguracji mostu wydruku. Instalacja cicha wymaga wcześniejszego utworzenia chronionego config.ini." /SD IDOK
    SetErrorLevel 2
    Abort
  config_present:

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
  Goto config_ready

  config_write_failed:
    SetDetailsPrint both
    StrCpy $BridgeToken ""
    MessageBox MB_ICONSTOP|MB_OK "Nie udało się zapisać chronionej konfiguracji. Instalacja została przerwana." /SD IDOK
    Delete "${CONFIG_FILE}"
    SetErrorLevel 2
    Abort
  config_ready:

  ; Remove the inbound firewall exception created by legacy releases. The
  ; production bridge connects outbound to HTTPS and opens no LAN port.
  nsExec::ExecToLog '"$SYSDIR\netsh.exe" advfirewall firewall delete rule name="${LEGACY_FIREWALL_RULE}"'
  Pop $0

  nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -mode bridge -config "${CONFIG_FILE}" -log-file "${CONFIG_DIR}\listener.log" -service install'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP|MB_OK "Nie udało się zainstalować usługi Windows (${SERVICE_NAME}). Instalacja zostanie przerwana." /SD IDOK
    SetErrorLevel 5
    Delete "$INSTDIR\uninstall.exe"
    Delete "$INSTDIR\README.txt"
    Delete "$INSTDIR\${APP_EXE}"
    RMDir "$INSTDIR"
    Abort
  ${EndIf}

  nsExec::ExecToLog '"$SYSDIR\sc.exe" failure "${SERVICE_NAME}" reset= 86400 actions= restart/5000/restart/15000/none/0'
  Pop $0
  nsExec::ExecToLog '"$SYSDIR\sc.exe" failureflag "${SERVICE_NAME}" 1'
  Pop $0
  nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service start'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP|MB_OK "Usługa została zainstalowana, ale nie połączyła się poprawnie. Sprawdź konfigurację i dziennik w ProgramData\Sempre ERP\Print Listener." /SD IDOK
    SetErrorLevel 6
    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service uninstall'
    Pop $1
    Delete "$INSTDIR\uninstall.exe"
    Delete "$INSTDIR\README.txt"
    Delete "$INSTDIR\${APP_EXE}"
    RMDir "$INSTDIR"
    Abort
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
  WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoModify" 1
  WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoRepair" 1

  CreateDirectory "$SMPROGRAMS\Sempre ERP Print Listener"
  CreateShortCut "$SMPROGRAMS\Sempre ERP Print Listener\Dokumentacja.lnk" "$INSTDIR\README.txt"
  CreateShortCut "$SMPROGRAMS\Sempre ERP Print Listener\Odinstaluj.lnk" "$INSTDIR\uninstall.exe"
SectionEnd

Section "Uninstall"
  SetShellVarContext all
  SetRegView 64

  IfFileExists "$INSTDIR\${APP_EXE}" 0 service_file_missing
    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service stop'
    Pop $0
    nsExec::ExecToLog '"$INSTDIR\${APP_EXE}" -service uninstall'
    Pop $0
    Sleep 1000
  service_file_missing:

  nsExec::ExecToLog '"$SYSDIR\netsh.exe" advfirewall firewall delete rule name="${LEGACY_FIREWALL_RULE}"'
  Pop $0

  Delete "$SMPROGRAMS\Sempre ERP Print Listener\Dokumentacja.lnk"
  Delete "$SMPROGRAMS\Sempre ERP Print Listener\Odinstaluj.lnk"
  RMDir "$SMPROGRAMS\Sempre ERP Print Listener"

  Delete "$INSTDIR\${APP_EXE}"
  Delete "$INSTDIR\README.txt"
  Delete "$INSTDIR\uninstall.exe"
  RMDir "$INSTDIR"

  Delete "${CONFIG_DIR}\config.ini"
  Delete "${CONFIG_DIR}\listener.log"
  RMDir "${CONFIG_DIR}"
  RMDir "$APPDATA\Sempre ERP"

  DeleteRegKey HKLM "${UNINSTALL_KEY}"
  DeleteRegKey HKLM "Software\Sempre ERP\Print Listener"
  DeleteRegKey /ifempty HKLM "Software\Sempre ERP"
SectionEnd
