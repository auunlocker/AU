"""Security check — converted from security.h/cpp"""
from __future__ import annotations
import os
import sys
from dataclasses import dataclass
from enum import Enum, auto
from typing import List, Optional

BLOCKED = [
    "pdebuggersvc.exe", "httpdebuggersvc.exe", "httpdebugger.exe",
    "httpdebuggerui.exe", "httpanalyzerv7.exe", "httpanalyzerv8.exe",
    "james.exe", "fiddler.exe", "mitmweb.exe", "charles.exe",
    "reqable.exe", "fiddlerroot.exe", "httptoolkit.exe", "rawcap.exe",
    "fiddler4.exe", "mitmdump.exe", "proxyman.exe", "mitmproxy.exe",
    "wireshark.exe",
]

SECURITY_ERROR = (
    "Interceptor or system proxy detected. "
    "Close debugging tools, or set LF_ALLOW_PROXY=1."
)


class SecurityKind(Enum):
    Clean = auto()
    Bypassed = auto()
    Blocked = auto()


@dataclass
class Security:
    kind: SecurityKind = SecurityKind.Clean
    detail: str = ""

    @classmethod
    def clean(cls):
        return cls(SecurityKind.Clean, "")

    @classmethod
    def bypassed(cls):
        return cls(SecurityKind.Bypassed, "")

    @classmethod
    def blocked(cls, who: str):
        return cls(SecurityKind.Blocked, who)


def _find_blocked_process() -> Optional[str]:
    if sys.platform != "win32":
        return None
    try:
        import psutil
        for proc in psutil.process_iter(["name"]):
            try:
                name = (proc.info["name"] or "").lower()
                if name in BLOCKED:
                    return name
            except (psutil.NoSuchProcess, psutil.AccessDenied):
                continue
    except ImportError:
        pass
    return None


def _proxy_enabled() -> bool:
    if sys.platform != "win32":
        return False
    try:
        import winreg
        key = winreg.OpenKey(
            winreg.HKEY_CURRENT_USER,
            r"Software\Microsoft\Windows\CurrentVersion\Internet Settings",
            0, winreg.KEY_READ,
        )
        try:
            value, _ = winreg.QueryValueEx(key, "ProxyEnable")
            return bool(value)
        finally:
            winreg.CloseKey(key)
    except OSError:
        return False


def check() -> Security:
    if os.environ.get("LF_ALLOW_PROXY"):
        return Security.bypassed()
    hit = _find_blocked_process()
    if hit:
        return Security.blocked(hit)
    if _proxy_enabled():
        return Security.blocked("system proxy")
    return Security.clean()
