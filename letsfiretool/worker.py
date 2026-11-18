"""
Job worker thread — simplified port of worker.cpp flow.

Real device USB / DA protocol is simulated so the GUI is fully usable.
Replace the simulation blocks with real engine calls when ready.
"""
from __future__ import annotations

import time
from typing import Callable, Optional

from . import diag, ops, security


ProgressCb = Callable[[int], None]
LogCb = Callable[[str], None]
DoneCb = Callable[[bool, str], None]


def run_job(
    otp: str,
    da_name: str,
    da_file: str,
    op_index: int,
    cancel_event,
    on_progress: ProgressCb,
    on_log: LogCb,
    on_done: DoneCb,
) -> None:
    """Run the full job pipeline (called from a background QThread)."""

    def cancelled() -> bool:
        return cancel_event.is_set()

    try:
        # 1. Security check
        on_progress(0)
        on_log("Security check.. ")
        sec = security.check()
        if sec.kind == security.SecurityKind.Blocked:
            on_log("FAILED\n")
            on_done(False, security.SECURITY_ERROR)
            return
        if sec.kind == security.SecurityKind.Bypassed:
            on_log("BYPASS (LF_ALLOW_PROXY)\n")
        else:
            on_log("OK\n")
        on_progress(5)

        if cancelled():
            on_done(False, "Cancelled")
            return

        # 2. OTP verify (simulated)
        on_log("Verifying OTP.. ")
        if not otp or not otp.strip():
            on_log("FAILED\n")
            on_done(False, "Enter OTP first")
            return
        time.sleep(0.4)
        if cancelled():
            on_done(False, "Cancelled")
            return
        on_log(f"OK (sim) [OTP accepted for demo]\n")
        on_progress(15)

        # 3. Download DA (simulated)
        on_log(f"Downloading files ({da_name}).. ")
        time.sleep(0.6)
        if cancelled():
            on_done(False, "Cancelled")
            return
        on_log("OK (cached/sim)\n")
        on_progress(30)

        # 4. Wait for device (simulated)
        on_log("Waiting for MediaTek device (VID 0x0E8D).. ")
        for i in range(8):
            if cancelled():
                on_log("CANCELLED\n")
                on_done(False, "Cancelled")
                return
            time.sleep(0.35)
        on_log("OK (simulated port)\n")
        on_progress(45)

        # 5. Init / handshake (simulated)
        on_log("Entering DA mode.. ")
        time.sleep(0.5)
        if cancelled():
            on_done(False, "Cancelled")
            return
        on_log("OK\n")
        on_log("Chip: MTXXXX  HW_CODE: 0x0000 (sim)\n")
        on_progress(55)

        # 6. Execute tasks
        tasks = ops.tasks_for(op_index)
        if not tasks:
            on_done(False, "Unknown operation")
            return

        step = 55
        step_inc = max(1, (90 - 55) // max(1, len(tasks)))
        for task in tasks:
            if cancelled():
                on_done(False, "Cancelled")
                return
            on_log(f"{task.label}.. ")
            time.sleep(0.7)
            on_log("OK\n")
            step = min(90, step + step_inc)
            on_progress(step)

        # 7. Finish
        on_log("Reboot.. OK\n")
        on_progress(100)
        on_log("\n=== JOB COMPLETED (simulation) ===\n")
        on_done(True, "")

    except Exception as e:
        diag.error("worker", str(e))
        on_done(False, f"Internal error: {e}")
