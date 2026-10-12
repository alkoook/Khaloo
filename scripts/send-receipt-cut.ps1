param(
    [string]$PrinterName = '',
    [ValidateSet('full', 'partial')]
    [string]$CutMode = 'partial',
    [ValidateRange(1, 10)]
    [int]$FeedLines = 3,
    [switch]$TestMode
)

$ErrorActionPreference = 'Stop'

# ESC d n feeds n lines. GS V 0/1 performs a full/partial cut.
[byte[]]$command = @(0x1B, 0x64, [byte]$FeedLines, 0x1D, 0x56, [byte]$(if ($CutMode -eq 'full') { 0 } else { 1 }))

if ($TestMode) {
    [BitConverter]::ToString($command)
    exit 0
}

if ([string]::IsNullOrWhiteSpace($PrinterName)) {
    $defaultPrinter = Get-CimInstance -ClassName Win32_Printer | Where-Object { $_.Default } | Select-Object -First 1
    if (-not $defaultPrinter) {
        throw 'No default Windows printer is configured.'
    }
    $PrinterName = $defaultPrinter.Name
}

Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;

public static class KhalooRawPrinter
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private class DOC_INFO_1
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDataType;
    }

    [DllImport("winspool.drv", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool OpenPrinter(string printerName, out IntPtr printerHandle, IntPtr defaults);
    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool ClosePrinter(IntPtr printerHandle);
    [DllImport("winspool.drv", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern int StartDocPrinter(IntPtr printerHandle, int level, [In] DOC_INFO_1 docInfo);
    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool EndDocPrinter(IntPtr printerHandle);
    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool StartPagePrinter(IntPtr printerHandle);
    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool EndPagePrinter(IntPtr printerHandle);
    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool WritePrinter(IntPtr printerHandle, byte[] bytes, int count, out int written);

    public static void Send(string printerName, byte[] bytes)
    {
        IntPtr handle;
        if (!OpenPrinter(printerName, out handle, IntPtr.Zero)) throw new Win32Exception(Marshal.GetLastWin32Error());
        try {
            var info = new DOC_INFO_1 { pDocName = "Khaloo receipt feed and cut", pDataType = "RAW" };
            if (StartDocPrinter(handle, 1, info) == 0) throw new Win32Exception(Marshal.GetLastWin32Error());
            try {
                if (!StartPagePrinter(handle)) throw new Win32Exception(Marshal.GetLastWin32Error());
                try {
                    int written;
                    if (!WritePrinter(handle, bytes, bytes.Length, out written) || written != bytes.Length)
                        throw new Win32Exception(Marshal.GetLastWin32Error());
                }
                finally { EndPagePrinter(handle); }
            }
            finally { EndDocPrinter(handle); }
        }
        finally { ClosePrinter(handle); }
    }
}
'@

[KhalooRawPrinter]::Send($PrinterName, $command)
Write-Output $PrinterName
