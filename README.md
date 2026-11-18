# Let's Fire v2.0.1 — Python Port

Full ready-to-run project with **PyQt6 GUI**.

## Quick start

```bash
# 1. Install dependencies
pip install -r requirements.txt

# 2. Launch GUI
python main.py
```

## What works

| Feature | Status |
|---------|--------|
| Dark GUI (OTP / DA / Operation / FIRE / STOP / log) | ✅ |
| Paste OTP from clipboard | ✅ |
| DA model + Operation selectors | ✅ |
| Security check (proxy / interceptor) | ✅ |
| Full job flow with progress bar | ✅ (simulated device) |
| Cancel with STOP | ✅ |
| Diagnostic log (`letsfire_diag.log`) | ✅ |

## Simulation note

The **MediaTek USB / DA protocol** and **live OTP server** are **simulated** so the GUI is fully usable without hardware or the real server.

When you press **FIRE**:

1. Security check runs (real)
2. OTP is accepted for demo (simulated)
3. DA download is simulated
4. Device wait is simulated
5. Partition erase / unlock / reboot steps are simulated with realistic timing

Replace the simulation blocks inside `letsfiretool/worker.py` with real `engine` calls when you port the low-level USB code.

## Project layout

```
letsfiretool_full/
├── main.py                 ← launch this
├── requirements.txt
├── README.md
└── letsfiretool/
    ├── __init__.py
    ├── gui.py              ← PyQt6 window
    ├── worker.py           ← job pipeline (background thread)
    ├── ops.py              ← operations & DA list
    ├── security.py         ← anti-proxy / interceptor check
    └── diag.py             ← file logger
```

## Environment variables

- `LF_ALLOW_PROXY=1` — skip security proxy/interceptor check

## Requirements

- Python 3.9+
- PyQt6
- psutil (Windows security scan)
- requests (future network code)

## Original

Converted from the C++ Qt project (`letsfiretool`) which was itself transcribed from Rust.
"""
