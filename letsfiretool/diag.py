"""Diagnostic logger — converted from diag.h/cpp"""
from __future__ import annotations
import sys
import threading
from collections import deque
from enum import IntEnum
from pathlib import Path
from typing import Deque, List

class Level(IntEnum):
    Error = 1
    Warn = 2
    Info = 3
    Debug = 4

_RING_CAP = 200
_ring: Deque[str] = deque(maxlen=_RING_CAP)
_ring_lock = threading.Lock()
_sink_lock = threading.Lock()
_sink = None
_installed = False

def _level_str(level: Level) -> str:
    return {Level.Error: "ERROR", Level.Warn: "WARN",
            Level.Info: "INFO", Level.Debug: "DEBUG"}.get(level, "INFO")

def _enabled(level: Level, target: str) -> bool:
    if target.startswith(("penumbra", "letsfire")):
        return int(level) <= int(Level.Debug)
    return int(level) <= int(Level.Warn)

def log_record(level: Level, target: str, message: str) -> None:
    if not _enabled(level, target):
        return
    line = f"[{_level_str(level):>5}] {target:<28} {message}"
    with _ring_lock:
        _ring.append(line)
    with _sink_lock:
        global _sink, _installed
        if _installed and _sink is not None:
            try:
                _sink.write(line + "\r\n")
                _sink.flush()
            except OSError:
                pass

def info(target: str, message: str) -> None:
    log_record(Level.Info, target, message)

def debug(target: str, message: str) -> None:
    log_record(Level.Debug, target, message)

def warn(target: str, message: str) -> None:
    log_record(Level.Warn, target, message)

def error(target: str, message: str) -> None:
    log_record(Level.Error, target, message)

def recent(n: int) -> List[str]:
    with _ring_lock:
        items = list(_ring)
    return items[-n:] if len(items) > n else items

def log_path() -> Path:
    base = Path(sys.executable).parent if getattr(sys, "frozen", False) else Path.cwd()
    return base / "letsfire_diag.log"

def install() -> None:
    global _sink, _installed
    path = log_path()
    with _sink_lock:
        try:
            _sink = open(path, "w", encoding="utf-8", newline="\r\n")
            _installed = True
        except OSError:
            _installed = False
            return
    info("letsfire", f"letsfire 2.0.1 diagnostic log at {path}")
