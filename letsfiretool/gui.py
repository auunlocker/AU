"""
PyQt6 GUI — visual port of the original Let's Fire window
(dark theme, fixed size, OTP / DA / Operation / Fire / Stop / log).
"""
from __future__ import annotations

import sys
import threading
from typing import Optional

from PyQt6.QtCore import Qt, QThread, pyqtSignal, QObject
from PyQt6.QtGui import QFont, QColor, QTextCursor, QClipboard
from PyQt6.QtWidgets import (
    QApplication, QMainWindow, QWidget, QLabel, QLineEdit,
    QPushButton, QComboBox, QTextEdit, QProgressBar, QMessageBox,
    QVBoxLayout, QHBoxLayout, QGridLayout, QSizePolicy,
)

from . import diag, ops
from .worker import run_job


# ── colours matching original ──────────────────────────────────────────────
BG          = "#0e0e1c"
FIELD_BG    = "#1e203a"
FIELD_FG    = "#ebedff"
LABEL_FG    = "#ffb932"
BORDER      = "#50302d"
BTN_BG      = "#2a2d4a"
BTN_HOVER   = "#3a3d5a"
FIRE_BG     = "#8b1a1a"
FIRE_HOVER  = "#a82222"
STOP_BG     = "#3a3a3a"
LOG_BG      = "#0a0a14"
LOG_FG      = "#c8c8d8"
PROGRESS_BG = "#1a1a2e"
PROGRESS_FG = "#ffb932"


STYLE = f"""
QMainWindow, QWidget#central {{
    background-color: {BG};
}}
QLabel {{
    color: {LABEL_FG};
    background: transparent;
    font-family: "Segoe UI";
    font-size: 8pt;
    font-weight: bold;
}}
QLineEdit {{
    background-color: {FIELD_BG};
    color: {FIELD_FG};
    border: 1px inset {BORDER};
    padding-left: 4px;
    font-family: "Consolas", "Courier New";
    font-size: 9pt;
    selection-background-color: #505080;
}}
QComboBox {{
    background-color: {FIELD_BG};
    color: {FIELD_FG};
    border: 1px inset {BORDER};
    padding-left: 4px;
    font-family: "Segoe UI";
    font-size: 9pt;
}}
QComboBox::drop-down {{
    border: none;
    width: 18px;
}}
QComboBox QAbstractItemView {{
    background-color: {FIELD_BG};
    color: {FIELD_FG};
    selection-background-color: #505080;
}}
QPushButton {{
    background-color: {BTN_BG};
    color: {FIELD_FG};
    border: 1px solid {BORDER};
    font-family: "Segoe UI";
    font-size: 8pt;
    font-weight: bold;
    padding: 2px 6px;
}}
QPushButton:hover {{
    background-color: {BTN_HOVER};
}}
QPushButton:disabled {{
    color: #666680;
    background-color: #1a1a28;
}}
QPushButton#fire_btn {{
    background-color: {FIRE_BG};
    color: #ffffff;
    font-size: 10pt;
    font-weight: bold;
}}
QPushButton#fire_btn:hover {{
    background-color: {FIRE_HOVER};
}}
QPushButton#stop_btn {{
    background-color: {STOP_BG};
    color: #cccccc;
}}
QTextEdit {{
    background-color: {LOG_BG};
    color: {LOG_FG};
    border: 1px inset {BORDER};
    font-family: "Consolas", "Courier New";
    font-size: 9pt;
}}
QProgressBar {{
    background-color: {PROGRESS_BG};
    border: 1px inset {BORDER};
    text-align: center;
    color: {FIELD_FG};
    font-size: 8pt;
}}
QProgressBar::chunk {{
    background-color: {PROGRESS_FG};
}}
"""


class WorkerBridge(QObject):
    """Signals from background thread → GUI thread."""
    progress = pyqtSignal(int)
    log = pyqtSignal(str)
    finished = pyqtSignal(bool, str)


class JobThread(QThread):
    def __init__(self, otp, da_name, da_file, op_index, cancel_event, bridge):
        super().__init__()
        self.otp = otp
        self.da_name = da_name
        self.da_file = da_file
        self.op_index = op_index
        self.cancel_event = cancel_event
        self.bridge = bridge

    def run(self):
        run_job(
            otp=self.otp,
            da_name=self.da_name,
            da_file=self.da_file,
            op_index=self.op_index,
            cancel_event=self.cancel_event,
            on_progress=lambda v: self.bridge.progress.emit(v),
            on_log=lambda s: self.bridge.log.emit(s),
            on_done=lambda ok, err: self.bridge.finished.emit(ok, err),
        )


class MainWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("Let's Fire v2.0.1 — MTK Auth Tool")
        self.setFixedSize(534, 360)
        self.setStyleSheet(STYLE)

        self._cancel = threading.Event()
        self._job_thread: Optional[JobThread] = None
        self._running = False
        self._da_list = ops.da_items()

        self._build_ui()
        self._populate_combos()

        diag.install()
        diag.info("letsfire", "GUI started")

    # ── UI construction ───────────────────────────────────────────────────
    def _build_ui(self):
        central = QWidget()
        central.setObjectName("central")
        self.setCentralWidget(central)

        root = QVBoxLayout(central)
        root.setContentsMargins(10, 6, 10, 8)
        root.setSpacing(4)

        # OTP row
        root.addWidget(QLabel("OTP CODE"))
        otp_row = QHBoxLayout()
        otp_row.setSpacing(6)
        self.otp_edit = QLineEdit()
        self.otp_edit.setPlaceholderText("Paste OTP here…")
        otp_row.addWidget(self.otp_edit, stretch=1)
        self.paste_btn = QPushButton("PASTE")
        self.paste_btn.setFixedWidth(68)
        self.paste_btn.clicked.connect(self._on_paste)
        otp_row.addWidget(self.paste_btn)
        root.addLayout(otp_row)

        # Models + Operation
        grid = QGridLayout()
        grid.setSpacing(6)
        grid.addWidget(QLabel("MODELS (DA)"), 0, 0)
        grid.addWidget(QLabel("OPERATION"), 0, 1)

        self.da_combo = QComboBox()
        self.da_combo.setMinimumWidth(220)
        grid.addWidget(self.da_combo, 1, 0)

        self.op_combo = QComboBox()
        grid.addWidget(self.op_combo, 1, 1)

        self.refresh_btn = QPushButton("REFRESH")
        self.refresh_btn.setFixedWidth(80)
        self.refresh_btn.clicked.connect(self._on_refresh)
        grid.addWidget(self.refresh_btn, 1, 2)
        root.addLayout(grid)

        # Fire / Stop
        btn_row = QHBoxLayout()
        btn_row.setSpacing(8)
        self.fire_btn = QPushButton("  FIRE  ")
        self.fire_btn.setObjectName("fire_btn")
        self.fire_btn.setFixedHeight(32)
        self.fire_btn.clicked.connect(self._on_fire)
        btn_row.addWidget(self.fire_btn)

        self.stop_btn = QPushButton(" STOP ")
        self.stop_btn.setObjectName("stop_btn")
        self.stop_btn.setFixedHeight(32)
        self.stop_btn.setEnabled(False)
        self.stop_btn.clicked.connect(self._on_stop)
        btn_row.addWidget(self.stop_btn)
        btn_row.addStretch()
        root.addLayout(btn_row)

        # Progress
        self.progress = QProgressBar()
        self.progress.setRange(0, 100)
        self.progress.setValue(0)
        self.progress.setFixedHeight(16)
        root.addWidget(self.progress)

        # Output
        root.addWidget(QLabel("OUTPUT"))
        self.output = QTextEdit()
        self.output.setReadOnly(True)
        self.output.setMinimumHeight(140)
        root.addWidget(self.output)

    def _populate_combos(self):
        self.da_combo.clear()
        for item in self._da_list:
            self.da_combo.addItem(item.name, item.file)
        self.op_combo.clear()
        for name in ops.OPERATIONS:
            self.op_combo.addItem(name)

    # ── helpers ───────────────────────────────────────────────────────────
    def _append(self, text: str):
        self.output.moveCursor(QTextCursor.MoveOperation.End)
        self.output.insertPlainText(text)
        self.output.moveCursor(QTextCursor.MoveOperation.End)

    def _set_running(self, running: bool):
        self._running = running
        self.fire_btn.setEnabled(not running)
        self.stop_btn.setEnabled(running)
        self.paste_btn.setEnabled(not running)
        self.refresh_btn.setEnabled(not running)
        self.otp_edit.setEnabled(not running)
        self.da_combo.setEnabled(not running)
        self.op_combo.setEnabled(not running)

    # ── slots ─────────────────────────────────────────────────────────────
    def _on_paste(self):
        clip = QApplication.clipboard()
        if clip:
            text = clip.text().strip()
            if text:
                self.otp_edit.setText(text)

    def _on_refresh(self):
        self._append("Refreshing DA catalog.. ")
        # In a full port this would hit DA_MODELS_URL.
        # For now re-load built-in list.
        self._da_list = ops.da_items()
        self._populate_combos()
        self._append("OK (built-in list)\n")

    def _on_fire(self):
        otp = self.otp_edit.text().strip()
        if not otp:
            self._append("Enter OTP first\n")
            return

        idx = self.da_combo.currentIndex()
        if idx < 0:
            self._append("Select a DA model\n")
            return
        da_name = self.da_combo.currentText()
        da_file = self.da_combo.currentData()
        op_index = self.op_combo.currentIndex()

        self._cancel.clear()
        self._set_running(True)
        self.progress.setValue(0)
        self._append(f"\n── Starting: {ops.OPERATIONS[op_index]} ──\n")

        bridge = WorkerBridge()
        bridge.progress.connect(self.progress.setValue)
        bridge.log.connect(self._append)
        bridge.finished.connect(self._on_job_finished)

        self._job_thread = JobThread(
            otp=otp,
            da_name=da_name,
            da_file=da_file,
            op_index=op_index,
            cancel_event=self._cancel,
            bridge=bridge,
        )
        # keep bridge alive
        self._job_thread._bridge = bridge
        self._job_thread.start()

    def _on_stop(self):
        self._cancel.set()
        self._append("\n[STOP requested]\n")

    def _on_job_finished(self, ok: bool, err: str):
        self._set_running(False)
        if not ok and err:
            self._append(f"ERROR: {err}\n")
        if self._job_thread:
            self._job_thread.wait(2000)
            self._job_thread = None

    def closeEvent(self, event):
        if self._running:
            self._cancel.set()
            if self._job_thread:
                self._job_thread.wait(3000)
        event.accept()


def main():
    app = QApplication(sys.argv)
    app.setStyle("Fusion")
    win = MainWindow()
    win.show()
    sys.exit(app.exec())


if __name__ == "__main__":
    main()
