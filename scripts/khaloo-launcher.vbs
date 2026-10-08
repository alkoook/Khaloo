Set shell = CreateObject("WScript.Shell")
Set files = CreateObject("Scripting.FileSystemObject")
scriptsPath = files.GetParentFolderName(WScript.ScriptFullName)
projectRoot = files.GetParentFolderName(scriptsPath)
scriptPath = files.BuildPath(scriptsPath, "start-khaloo.ps1")
shell.CurrentDirectory = projectRoot
shell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & scriptPath & """", 0, False
